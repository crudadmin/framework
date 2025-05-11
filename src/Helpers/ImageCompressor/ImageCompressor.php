<?php

namespace Admin\Core\Helpers\ImageCompressor;

use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Helpers\File;
use Admin\Core\Helpers\ImageCompressor\CustomImageOptimizerChainFactory;
use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Image;
use Log;

class ImageCompressor
{
    /*
     * Return filesize of image
     */
    public function getFilesize($path)
    {
        return round(filesize($path) / 1024, 2);
    }

    /**
     * Compress images with shell libraries
     *
     * @param  string  $sourcePath
     * @param  string  $destinationPath
     *
     * @return  bool
     */
    public function tryShellCompression($sourcePath, $destinationPath = null)
    {
        $destinationPath = $destinationPath ?: (string)$sourcePath;

        //Check if file exists on host machine
        if (! file_exists($sourcePath)) {
            return false;
        }

        //Compress with linux commands if available
        try {
            //TODO: compare?
            // $origSize = $this->getFilesize($sourcePath);

            $optimizerChain = CustomImageOptimizerChainFactory::create();
            $optimizerChain->optimize($sourcePath, $destinationPath);

            return true;
        } catch (Exception $e) {
            Log::error($e);

            return false;
        }
    }

    /**
     * Compress original image with loss compression
     *
     * @param  Intervention\Image\Image  $image
     * @param  AdminModel  $model
     * @param  Intervention\Image\Image  $image
     * @param  string  $path
     * @param  string  $extension
     *
     * @return  void
     */
    public function saveImageWithCompression(FilesystemAdapter $storage, AdminModel $model, $image, $path, $extension)
    {
        //Default compression quality
        $qualityPercentage = $this->getQualityPercentage($model);

        //Encode JPEG images when has been resized, or should be compressed
        if (in_array($extension, ['jpg', 'jpeg'])) {
            $encodedImage = $image->encode('jpg', $qualityPercentage);
        }

        //Encode PNG image if has been resized
        else if (in_array($extension, ['png'])) {
            $encodedImage = $image->encode('png');
        }

        else {
            $encodedImage = (string)$image->encode();
        }

        $storage->put($path, $encodedImage);

        //Process lossless compression if is available
        //on local crudadmin storage.
        if (
            $model->getProperty('imageLosslessCompression') === true
            && config('admin.image_lossless_compression', true) === true
        ) {
            $this->tryShellCompression(
                $storage->path($path)
            );
        }
    }

    /**
     * Returns compression setted by default, or by given model
     *
     * @param AdminModel $model
     *
     * @return  int
     */
    private function getQualityPercentage(AdminModel $model)
    {
        $modelQuality = $model->getProperty('imageLossyCompression');

        //Default compression quality
        $defaultQuality = 85;

        $quality = config('admin.image_lossy_compression_quality', $defaultQuality);

        //Set default compress quality in config is true
        if ($quality === true) {
            $quality = $defaultQuality;
        }

        //If model has set custom quality
        if ( is_numeric($modelQuality) ) {
            $quality = $modelQuality;
        }

        //If model has disabled compression
        else if ( $modelQuality === false ) {
            $quality = 100;
        }

        return $quality;
    }
}
