<?php

namespace Admin\Core\Facades;

use Illuminate\Support\Facades\Facade;

class ImageCompressor extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'imagecompressor';
    }
}
