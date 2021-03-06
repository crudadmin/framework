<?php

namespace Admin\Core\Facades;

use Illuminate\Support\Facades\Facade;

class AdminCore extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'admin.core';
    }
}
