<?php

namespace Admin\Core\Helpers\Storage;

use AdminCore;
use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Helpers\Storage\Mutators\EncryptorMutator;
use Admin\Core\Helpers\Storage\Mutators\ImageUploadMutator;
use File;
use Illuminate\Http\UploadedFile;
use Storage;

class AdminUploader
{
    protected $model;

    protected $fieldKey;

    protected $fileOrPathToUpload;

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

    public function __construct(AdminModel $model, $fieldKey, $fileOrPathToUpload, $options = [])
    {
        $this->model = $model;

        $this->fieldKey = $fieldKey;

        $this->fileOrPathToUpload = $fileOrPathToUpload;

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
            return Storage::disk($this->disk);
        }

        return $this->model->getFieldStorage($this->fieldKey);
    }

    public function upload()
    {
        $fieldKey = $this->fieldKey;

        $fileOrPathToUpload = $this->fileOrPathToUpload;

        $uploadDirectory = $this->model->getStorageFilePath($fieldKey);

        //Get count of files in upload directory and set new filename
        $filenameWithoutExt = $this->getUploadableFilename($uploadDirectory, $fileOrPathToUpload);

        //We need bind this variables depending on given uploaded file type
        $filename = null;
        $extension = null;

        //File input is file from request
        if ($fileOrPathToUpload instanceof UploadedFile) {
            //Get extension of file
            $extension = strtolower($fileOrPathToUpload->getClientOriginalExtension());

            //Build and mutate filename
            $filename = $this->mergeExtensionName($filenameWithoutExt, $extension);
            $filename = $this->model->filenameFromAttribute($filename, $fieldKey);

            //Try upload from request file
            if ( $this->uploadLocalyFromRequest($fileOrPathToUpload, $filename) === false ) {
                return false;
            }
        }

        //Upload from local basepath or from website
        else {
            [
                $filename,
                $extension
            ] = $this->uploadFileFromLocal($fileOrPathToUpload, $filenameWithoutExt, $uploadDirectory);
        }

        $this->filename = $filename;

        $this->extension = $extension;

        $this->mutateUploadedFile();

        //We can retrieve final storage path, which can be mutated from mutators.
        $fileStoragePath = $this->getLocalStoragePath();

        $this->model->moveToFinalStorage(
            $this->fieldKey,
            $fileStoragePath,
            $this->getFieldStorage()
        );

        return $fileStoragePath;
    }

    private function getLocalStoragePath()
    {
        return $this->model->getStorageFilePath($this->fieldKey).'/'.$this->filename;
    }

    /**
     * Upload file from local directory or server
     *
     * @param  string  $file
     * @param  string  $filename
     * @param  string  $uploadPath / destinationPath
     *
     * @return  array
     */
    private function uploadFileFromLocal($file, $filenameWithoutExtension, $uploadPath)
    {
        $filename = $filenameWithoutExtension;

        //If extension is available, we want mutate file name
        if ($extension = File::extension($file)) {
            $filename = $this->model->filenameFromAttribute(
                $this->mergeExtensionName($filename, $extension),
                $this->fieldKey
            );

            $filenameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);
        }

        $filenameWithoutExtension = $this->createUniqueFilename($uploadPath, $filenameWithoutExtension, $extension);
        $filename = $filenameWithoutExtension.'.'.$extension;
        $destinationPath = $uploadPath.'/'.$filename;

        //Copy file from server, or directory into uploads for field
        $this->getLocalUploadsStorage()->put(
            $destinationPath,
            file_get_contents($file)
        );

        //If file is url adress, we want verify extension type
        if ( filter_var($file, FILTER_VALIDATE_URL) && !file_exists($file) ) {
            $gussedExtension = $this->guessExtensionFromRemoteFile($destinationPath, $filename);

            if ( $gussedExtension != $extension ) {
                $newFilename = $this->createUniqueFilename($uploadPath, $filenameWithoutExtension, $gussedExtension);

                //Modified filename
                $newFilename = $this->model->filenameFromAttribute(
                    $this->mergeExtensionName($newFilename, $gussedExtension),
                    $this->fieldKey
                );

                $newDestinationPath = $uploadPath.'/'.$newFilename;

                $this->getLocalUploadsStorage()->move($destinationPath, $newDestinationPath);

                $filename = $newFilename;
            }
        }

        return [
            $filename,
            $extension,
        ];
    }

    /*
     * Guess extension type from mimeType
     */
    private function guessExtensionFromRemoteFile($path, $filename)
    {
        $mimeType = $this->getLocalUploadsStorage()->mimeType($path);

        $replace = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
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
    private function mutateUploadedFile()
    {
        $localStorage = $this->getLocalUploadsStorage();

        foreach (self::$uploadMutators as $classname) {
            $mutator = new $classname(
                $localStorage,
                $this->model,
                $this->fieldKey,
                $this->getLocalStoragePath(),
                $this->filename,
                $this->extension
            );

            if ( $mutator->isActive() === false ){
                continue;
            }

            $mutator->mutate();

            //We can mutate filename or extension during mutate process
            $this->filename = $mutator->getFilename();
            $this->extension = $mutator->getExtension();
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
     * @param  UploadedFile  $uploadedFile
     * @param  string  $filename
     *
     * @return  bool
     */
    private function uploadLocalyFromRequest(UploadedFile $uploadedFile, $filename)
    {
        $fieldKey = $this->fieldKey;

        //If is file aviable, but is not valid
        if (! $uploadedFile->isValid()) {
            $this->addUploadError(_('Súbor "'.$fieldKey.'" nebol uložený na server, pre jeho chybnú štruktúru.'));

            return false;
        }

        $disk = AdminCore::getUploadsStorageName(
            $this->model->isPrivateFile($this->fieldKey)
        );

        //Move photo from request to directory
        $uploadedFile = $uploadedFile->storeAs(
            $this->model->getStorageFilePath($fieldKey),
            $filename,
            [ 'disk' => $disk ]
        );

        return true;
    }

    /**
     * Get filename from request or url
     *
     * @param  string  $path
     * @param  UploadedFile|string  $file
     *
     * @return  string
     */
    private function getUploadableFilename($path, $file)
    {
        $extension = null;

        //If file exists and is not from server, when is from server make unique name
        if (method_exists($file, 'getClientOriginalName')) {
            $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        } else {
            $pathinfo = @pathinfo(basename($file));
            $filename = @$pathinfo['filename'] ?: uniqid();

            if ( $pathinfo['extension'] ?? null ){
                $extension = $pathinfo['extension'];
            }
        }

        //Trim filename
        $filename = substr(str_slug($filename), 0, 40);

        //If extension is from request
        if ( method_exists($file, 'getClientOriginalExtension') ) {
            $extension = $file->getClientOriginalExtension();
        }

        return $this->createUniqueFilename($path, $filename, $extension);
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

    /**
     * Create unique filename, to be sure we wont override existing files
     *
     * @param  string  $path
     * @param  string  $filenameWithoutExtension
     * @param  string  $extension
     *
     * @return  string
     */
    private function createUniqueFilename($path, $filenameWithoutExtension, $extension)
    {
        $postfix = ($this->options['postfix'] ?? false) === true;

        //If is cloud storage or other storage solution
        if ( $this->getFieldStorage() !== $this->getLocalUploadsStorage() || $postfix === true ) {
            return $this->generateFilenamePostfix($filenameWithoutExtension);
        }

        //If field destination is crudadmin storage, we can check file existance iterate through existing files
        //with increment assignemt at the end of file
        return $this->createFilenameIncrement($path, $filenameWithoutExtension, $extension);
    }

    /**
     * Create filename postfix
     *
     * @param  string  $filenameWithoutExtension
     * @param  number  $randomKeyLength
     *
     * @return  string
     */
    private function generateFilenamePostfix($filenameWithoutExtension, $randomKeyLength = 20)
    {
        //If file already has generated key, we can let it be and may not generate new one..
        if ( preg_match("/_[a-z|A-Z|0-9]{".$randomKeyLength."}$/", $filenameWithoutExtension) ) {
            return $filenameWithoutExtension;
        }

        //To save resource in cloud storage, better will be random article name
        return $filenameWithoutExtension.'_'.str_random($randomKeyLength);
    }

    /**
     * Generate file name without extension by incrementing numbers after existing file
     *
     * @param  string  $path
     * @param  string  $filename
     * @param  string  $extension
     *
     * @return  string
     */
    private function createFilenameIncrement($path, $filename, $extension)
    {
        $fieldStorage = $this->getFieldStorage();

        $basepath = $path.'/'.$this->mergeExtensionName($filename, $extension);

        //If filename exists, then add number prefix of file
        if ( $fieldStorage->exists($basepath) ) {
            $i = 0;

            //Check all numbers till file does not exists
            while ( $fieldStorage->exists($path.'/'.$this->mergeExtensionName($filename.'-'.$i, $extension)) ) {
                $i++;
            }

            $filename = $filename.'-'.$i;
        }

        return $filename;
    }
}