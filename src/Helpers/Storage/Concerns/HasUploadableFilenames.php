<?php

namespace Admin\Core\Helpers\Storage\Concerns;

use File;
use Cache;

trait HasUploadableFilenames
{
    /**
     * Get filename from request or url
     * (Get count of files in upload directory and set new filename)
     *
     * @return  string
     */
    protected function setUploadableFilename()
    {
        $file = $this->fileOrPath;
        $filename = null;

        //Get filename by options setter
        if ( isset($this->options['filename']) ) {
            $pathinfo = pathinfo($this->options['filename']);

            $filename = ($pathinfo['filename'] ?? null) ?: $this->options['filename'];

            if ( $pathinfo['extension'] ?? null ){
                $this->extension = $pathinfo['extension'];
            }
        }

        //If file is uploaded
        elseif ( $this->isUploadedFileType($file, 'upload') ) {
            if ( method_exists($file, 'getClientOriginalName') ){
                $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            }

            //If extension is from request
            if ( method_exists($file, 'getClientOriginalExtension') ) {
                $this->extension = $file->getClientOriginalExtension();
            }
        }

        //If file exists or is from external url
        else if ( is_file($file) || $this->isUploadedFileType($file, 'url') ) {
            $pathinfo = pathinfo(basename($file));
            $filename = $pathinfo['filename'] ?? null;

            if ( $pathinfo['extension'] ?? null ){
                $extension = $pathinfo['extension'];
                $extension = explode('?', $extension)[0];

                $this->extension = $extension;
            }
        }

        //If no filename has been quessted
        if ( !$filename ){
            $filename = str_random(20);
        }

        //Lowecase + remove all special characters
        $this->extension = str_slug($this->extension);

        //Trim filename
        $this->filename = $this->mergeExtensionName(
            substr(str_slug($filename), 0, 40),
            $this->extension
        );

        $this->verifyUniqueFilename();
    }

    /**
     * Create unique filename, to be sure we wont override existing files
     *
     * @return  string
     */
    protected function verifyUniqueFilename()
    {
        $this->filename = $this->model->filenameFromAttribute($this->filename, $this->fieldKey);

        $this->mutateUploadedFile(function($mutator){
            //We can mutate filename or extension during mutate process
            $this->filename = $mutator->getFilename();
            $this->extension = $mutator->getExtension();
        });

        $postfix = ($this->options['postfix'] ?? false) === true;

        //If is cloud storage or other storage solution
        if ( $this->getFieldStorage() !== $this->getLocalUploadsStorage() || $postfix === true ) {
            if ( $filename = $this->getFilenamePostfix() ){
                $this->filename = $filename;
            }
        }

        else {
            //If field destination is crudadmin storage, we can check file existance iterate through existing files
            //with increment assignemt at the end of file
            $this->filename = $this->getFilenameIncrement();
        }
    }

    /**
     * When we change/mas filename extension, eg. via .encrypted postfix
     * we need to be able receive base filename, and final extension parts
     *
     * @return  array
     */
    public function getFinalFilenameParts()
    {
        $name = File::name($this->filename);
        $extension = File::extension($this->filename);

        if ( $extension != $this->extension ){
            $extension = $this->extension.'.'.$extension;
            $name = trim_end($name, '.'.$this->extension);
        }

        return [$name, $extension];
    }

    /**
     * Generate file name without extension by incrementing numbers after existing file
     *
     * @return  string
     */
    private function getFilenameIncrement()
    {
        $path = $this->getUploadDir();
        $fieldStorage = $this->getFieldStorage();

        [$name, $extension] = $this->getFinalFilenameParts();

        $maxIncrements = 10;
        $i = null;
        $cacheKey = $this->getIncrementCacheKey($name);

        if ( Cache::has($cacheKey) ){
            return $this->getFilenamePostfix();
        }

        while ( $fieldStorage->exists($path.'/'.($filename = $this->getFileNameWithIncrement($i))) ) {
            $i = (is_null($i) ? 0 : $i) + 1;

            if ( $i >= $maxIncrements ){
                Cache::forever($cacheKey, true);

                return $this->getFilenamePostfix();
            }
        }

        return $filename;
    }

    private function getFileNameWithIncrement($i)
    {
        [$name, $extension] = $this->getFinalFilenameParts();

        return $this->mergeExtensionName(
            $name.(is_null($i) ? '' : '-'.$i),
            $extension
        );
    }

    /**
     * Create filename postfix
     *
     * @param  string  $filenameWithoutExtension
     * @param  number  $randomKeyLength
     *
     * @return  string
     */
    private function getFilenamePostfix($randomKeyLength = 10)
    {
        [$name, $extension] = $this->getFinalFilenameParts();

        //If file already has generated key, we can let it be and may not generate new one..
        if ( preg_match("/_[a-z|A-Z|0-9]{".$randomKeyLength."}$/", $name) ) {
            return;
        }

        //To save resource in cloud storage, better will be random article name
        return $name.'_'.str_random($randomKeyLength).'.'.$extension;
    }

    private function getIncrementCacheKey($name)
    {
        return 'files.admin_file_increment.'.$name;
    }
}