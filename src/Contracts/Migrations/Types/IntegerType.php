<?php

namespace Admin\Core\Contracts\Migrations\Types;

use Admin\Core\Contracts\Migrations\Types\Type;
use Admin\Core\Eloquent\AdminModel;
use Illuminate\Database\Schema\Blueprint;

class IntegerType extends Type
{
    /**
     * Check if can apply given column
     * @param  AdminModel  $model
     * @param  string      $key
     * @return boolean
     */
    public function isEnabled(AdminModel $model, string $key)
    {
        return $model->isFieldType($key, 'integer');
    }

    /**
     * Register column
     * @param  Blueprint    $table
     * @param  AdminModel   $model
     * @param  string       $key
     * @param  bool         $update
     * @return Blueprint
     */
    public function registerColumn(Blueprint $table, AdminModel $model, string $key, bool $update)
    {
        $column = $table->integer($key);

        //Check if is integer unsigned or not
        if ($model->hasFieldParam($key, 'min') && $model->getFieldParam($key, 'min') >= 0)
            $column->unsigned();

        return $column;
    }
}