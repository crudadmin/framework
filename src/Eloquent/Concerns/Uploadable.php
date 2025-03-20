<?php

namespace Admin\Core\Eloquent\Concerns;

use Admin\Core\Helpers\Storage\AdminFile;
use Admin\Core\Helpers\Storage\AdminUploader;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use File;
use Image;
use ImageCompressor;
use AdminCore;

trait Uploadable
{
    /*
     * Message with error will be stored in this property
     */
    private $uploadErrors = [];

    /**
     * Determine if all uploaded file are privated
     *
     * @var  bool
     */
    protected $privateUploads = false;

    /**
     * Determine if field has private uploads folder
     *
     * @param  string  $field
     *
     * @return  bool
     */
    public function isPrivateFile($field)
    {
        if ( $this->privateUploads === true ){
            return true;
        }

        return $this->hasFieldParam($field, 'private');
    }

    /*
     * Returns error in upload
     */
    public function getUploadError()
    {
        return implode(', ', $this->uploadErrors);
    }

    /**
     * Returns disk path for uploaded files from actual model
     * [DEPREACED - WE SHOULD REMOVE THIS FUNCTION in v4]
     *
     * @param  string  $fieldKey
     * @param  string  $filename
     * @param  bool  $relative
     * @return string
     */
    public function filePath($fieldKey, $filename = null, $relative = false)
    {
        $directory = $this->getStorageFilePath($fieldKey);

        if ( $relative ) {
            $path = $directory;
        } else {
            $path = $this->getFieldStorage($fieldKey)->path($directory);
        }

        if ($filename) {
            return $path.'/'.$filename;
        }

        return $path;
    }

    /*
     * Check if model has filename modifier
     */
    public function filenameFromAttribute($filename, $field)
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
     * Automaticaly check, upload, and make resizing and other function on file object.
     *
     * @param  string                   $fieldKey
     * @param  string|UploadedFile      $file
     * @param  array                    $options
     * @return object
     */
    public function upload(string $fieldKey, $fileOrPath, $options = [])
    {
        $options = array_merge([
            'postfix' => config('admin.uploads_postfix', false),
        ], $options);

        $uploader = new AdminUploader($this, $fieldKey, $fileOrPath, $options);

        if ( !($path = $uploader->upload()) ) {
            return;
        }

        $filename = basename($path);

        return $this->getAdminFile($fieldKey, $filename, $options['disk'] ?? null);
    }

    /**
     * Upload file locally
     *
     * @param  string                   $fieldKey
     * @param  string|UploadedFile      $file
     * @param  array                    $options
     *
     * @return object
     */
    public function uploadLocaly(string $fieldKey, $fileOrPath, $options = [])
    {
        $options = $options + [
            'disk' => AdminCore::getUploadsStorageName($this->isPrivateFile($fieldKey))
        ];

        return $this->upload($fieldKey, $fileOrPath, $options);
    }

    /*
     * Check if files can be permamently deleted
     */
    public function canPermanentlyDeleteFiles()
    {
        return config('admin.reduce_space', true) === true && $this->delete_files === true;
    }

    /**
     * Remove all uploaded files in existing field attribute
     *
     * @param  string  $key
     * @param  string|array  $newFiles remove only files which are not in array, or given string.
     */
    public function deleteFiles($key, $newFiles = null)
    {
        $storage = $this->getFieldStorage($key);

        //Remove fixed thumbnails
        if (($fieldValueFile = $this->getValue($key)) && ! $this->hasFieldParam($key, 'multiple', true)) {
            //Parse from localization collection
            $files = $fieldValueFile instanceof Collection ? $fieldValueFile->all() : $fieldValueFile;
            $files = array_wrap($files);

            $isAllowedDeleting = $this->canPermanentlyDeleteFiles();

            //Remove also multiple uploaded files
            foreach ($files as $adminFile) {
                $field = $this->getField($key);

                $cachePath = AdminFile::getCacheDirectory(
                    $this->getStorageFilePath($key)
                );

                $needDelete = $newFiles === null
                               || is_array($newFiles) && ! in_array($adminFile->filename, array_flatten($newFiles))
                               || is_string($newFiles) && $adminFile->filename != $newFiles;


                if ( $needDelete ) {
                    //Remove dynamicaly cached thumbnails
                    $this->removeCachedImages($storage, $adminFile, $cachePath);

                    //Removing original files
                    if ($isAllowedDeleting) {
                        $adminFile->delete();
                    }
                }
            }
        }

        return $this;
    }

    /**
     * When file has been deleted, we need remove files from cache directories
     *
     * @param  FilesystemAdapter  $storage
     * @param  AdminFile  $adminFile
     * @param  string  $cachePath
     */
    private function removeCachedImages(FilesystemAdapter $storage, AdminFile $adminFile, $cachePath)
    {
        $resizedImages = $storage->allFiles($cachePath);

        foreach ($resizedImages as $pathToCheck) {
            $cachedFilename = basename($pathToCheck);

            //If filename is same
            if ( $cachedFilename != $adminFile->filename ) {
                continue;
            }

            //Delete filename
            $storage->delete($pathToCheck);

            $webpPath = $pathToCheck.'.webp';

            //Remove also webp version of image
            if ( $storage->exists($webpPath) ) {
                $storage->delete($webpPath);
            }
        }
    }
}
