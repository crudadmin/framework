<?php

namespace Admin\Core\Helpers\Storage;

use AdminCore;
use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Helpers\Storage\Concerns\HasUploadableFilenames;
use Admin\Core\Helpers\Storage\Mutators\EncryptorMutator;
use Admin\Core\Helpers\Storage\Mutators\ImageUploadMutator;
use File;
use Illuminate\Http\UploadedFile;
use Storage;

class AdminUploader
{
    use HasUploadableFilenames;

    protected $model;

    protected $fieldKey;

    protected $fileOrPath;

    protected $compression;

    protected $uploadErrors = [];

    protected $disk;

    protected $options;

    public $filename;

    public $extension;

    static $uploadMutators = [
        ImageUploadMutator::class,
        EncryptorMutator::class,
    ];

    public function __construct(AdminModel $model, $fieldKey, $fileOrPath, $options = [])
    {
        $this->model = $model;

        $this->fieldKey = $fieldKey;

        $this->fileOrPath = $fileOrPath;

        $this->options = $options;

        $this->disk = $options['disk'] ?? null;

        $this->compression = $options['compression'] ?? true;
    }

    public function getLocalUploadsStorage()
    {
        return $this->model->getLocalFieldStorage($this->fieldKey);
    }

    public function getFieldStorage()
    {
        if ( $this->disk ) {
            return AdminCore::getDiskByName($this->disk);
        }

        return $this->model->getFieldStorage($this->fieldKey);
    }

    public function getUploadDir()
    {
        return $this->model->getStorageFilePath($this->fieldKey);
    }

    private function getLocalFilePath()
    {
        return $this->getUploadDir().'/'.$this->filename;
    }

    public function upload()
    {
        $this->setUploadableFilename();

        //File input is file from request
        if ( $this->isUploadedFileType($this->fileOrPath, 'upload') ) {
            //Try upload from request file (localy)
            if ( $this->uploadFileFromRequestLocaly() === false ) {
                return false;
            }
        }

        //Upload from local basepath or from website
        else {
            $this->uploadFileFromExternalUrlLocaly();
        }

        $this->mutateUploadedFile(function($mutator){
            $mutator->mutate();
        });

        //We can retrieve final storage path, which can be mutated from mutators.
        $fileStoragePath = $this->getLocalFilePath();

        $this->model->moveToFinalStorage(
            $this->fieldKey,
            $fileStoragePath,
            $this->getFieldStorage()
        );

        return $fileStoragePath;
    }

    /**
     * Upload file from local directory or server
     *
     * @return  array
     */
    private function uploadFileFromExternalUrlLocaly()
    {
        $file = $this->fileOrPath;

        $destinationPath = $this->getLocalFilePath();

        $isUrl = $this->isUploadedFileType($file, 'url');

        $fileData = $isUrl
                        ? file_get_contents($file)
                        : $file;

        //Copy file from server, or directory into uploads for field
        $this->getLocalUploadsStorage()->put($destinationPath, $fileData);

        //If file is url adress, we want verify extension type due to some malicious files
        if ( $isUrl && !file_exists($file) ) {
            $gussedExtension = $this->guessExtensionFromRemoteFile($destinationPath);

            if ( $gussedExtension != $this->extension ) {
                $this->filename = File::name($this->filename).'.'.$gussedExtension;
                $this->extension = $gussedExtension;

                $this->verifyUniqueFilename();

                $this->getLocalUploadsStorage()->move(
                    $destinationPath,
                    $this->getLocalFilePath()
                );
            }
        }
    }

    /*
     * Guess extension type from mimeType
     */
    private function guessExtensionFromRemoteFile($path)
    {
        $mimeType = $this->getLocalUploadsStorage()->mimeType($path);

        $replace = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/webp' => 'webp',
            'application/x-zip' => 'zip',
            'application/x-rar' => 'rar',
            'text/css' => 'css',
            'text/html' => 'html',
            'audio/mpeg' => 'mp3',
        ];

        if (array_key_exists($mimeType, $replace)) {
            return $replace[$mimeType];
        }

        return false;
    }

    /**
     * We can run file mutators to make operations with uploaded files
     *
     * @param  string  $storagePath
     * @param  string  $filename
     * @param  string  $extension
     */
    private function mutateUploadedFile($callback)
    {
        $localStorage = $this->getLocalUploadsStorage();

        foreach (self::$uploadMutators as $classname) {
            $mutator = new $classname(
                $localStorage,
                $this->model,
                $this->fieldKey,
                $this->getLocalFilePath(),
                $this->filename,
                $this->extension
            );

            if ( $mutator->isActive() === false ){
                continue;
            }

            $callback($mutator);
        }
    }

    /**
     * Push upload mutator
     *
     * @param  string  $classname
     */
    public static function addUploadMutator($classname)
    {
        self::$uploadMutators[] = $classname;
    }

    /**
     * Add upload error
     *
     * @param  string  $message
     */
    public function addUploadError($message)
    {
        $this->uploadErrors[] = $message;
    }

    /**
     * Add upload errors
     *
     * @return  array
     */
    public function getUploadErrors()
    {
        return $this->uploadErrors;
    }

    /*
     * Check if model has filename modifier
     */
    private function filenameModifier($filename, $field)
    {
        //Filename modifier
        $method_filename_modifier = 'set'.studly_case($field).'Filename';

        //Check if exists filename modifier
        if (method_exists($this, $method_filename_modifier)) {
            $filename = $this->{$method_filename_modifier}($filename);
        }

        return $filename;
    }

    /**
     * Upload file into local crudadmin storage from quest
     *
     * @return  bool
     */
    private function uploadFileFromRequestLocaly()
    {
        $uploadedFile = $this->fileOrPath;

        //If is file aviable, but is not valid
        if (! $uploadedFile->isValid()) {
            $this->addUploadError(_('Súbor "'.$this->fieldKey.'" nebol uložený na server, pre jeho chybnú štruktúru.'));

            return false;
        }

        $disk = AdminCore::getUploadsStorageName(
            $this->model->isPrivateFile($this->fieldKey)
        );

        //Move photo from request to directory
        $uploadedFile = $uploadedFile->storeAs(
            $this->getUploadDir(),
            $this->filename,
            [ 'disk' => $disk ]
        );

        return true;
    }

    /**
     * Merge extension name if is present
     *
     * @param  string  $filename
     * @param  string  $extension
     *
     * @return  string
     */
    private function mergeExtensionName($filename, $extension)
    {
        return $extension ? ($filename.'.'.$extension) : $filename;
    }

    public function isUploadedFileType($fileOrData, $type)
    {
        //Url address
        if ( $type == 'url' ) {
            return filter_var($fileOrData, FILTER_VALIDATE_URL) || file_exists($fileOrData) ? true : false;
        }

        //Uploader request
        else if ( 'upload' ) {
            return $fileOrData instanceof UploadedFile;
        }

        return false;
    }
}
