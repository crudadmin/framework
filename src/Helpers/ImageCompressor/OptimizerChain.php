<?php

namespace Admin\Core\Helpers\ImageCompressor;

use InvalidArgumentException;
use Spatie\ImageOptimizer\Image;
use Spatie\ImageOptimizer\OptimizerChain as BaseOptimizerChain;

class OptimizerChain extends BaseOptimizerChain
{
    public function __construct()
    {
        $this->useLogger(new Logger());
    }

    public function optimize(string $pathToImage, ?string $pathToOutput = null)
    {
        if ($pathToOutput && $pathToImage != $pathToOutput) {
            $check = copy($pathToImage, $pathToOutput);
            if ($check == false) {
                throw new InvalidArgumentException("Cannot copy file");
            }
            $pathToImage = $pathToOutput;
        }

        $image = new Image($pathToImage);
        $this->logger->info("Start optimizing {$pathToImage}");

        foreach ($this->optimizers as $optimizer) {
            $this->applyOptimizer($optimizer, $image);
        }
    }
}
