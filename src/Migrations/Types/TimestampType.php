<?php

namespace Admin\Core\Migrations\Types;

use Admin\Core\Eloquent\AdminModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class TimestampType extends Type
{
    /**
     * Check if can apply given column.
     * @param  AdminModel  $model
     * @param  string      $key
     * @return bool
     */
    public function isEnabled(AdminModel $model, string $key)
    {
        return $model->isFieldType($key, ['timestamp']);
    }

    /**
     * Register column.
     * @param  Blueprint    $table
     * @param  AdminModel   $model
     * @param  string       $key
     * @param  bool         $update
     * @return Blueprint
     */
    public function registerColumn(Blueprint $table, AdminModel $model, string $key, bool $update)
    {
        //Check for correct values
        if ($update === true) {
            $type = $model->getConnection()->getDoctrineColumn($model->getTable(), $key)->getType()->getName();

            //If previoius column has not been datetime and has some value
            if (
                ! in_array($type, ['date', 'datetime', 'time', 'timestamp'])
                && $this->getCommand()->confirm('You are updating '.$key.' column from non-date "'.$type.'" type to datetime type. Would you like to update this non-date values to null values?')
            ) {
                $model->getConnection()->table($model->getTable())->update([$key => null]);
            }
        }

        $column = $table->timestamp($key)->nullable();

        return $column;
    }

    /**
     * Set default value.
     * @param mixed $column
     * @param AdminModel       $model
     * @param string           $key
     * @param bool           $updating
     */
    public function setDefault($column, AdminModel $model, string $key, $updating)
    {
        //If default value has not been set
        if (! ($default = $model->getFieldParam($key, 'default'))) {
            return;
        }

        //Set default timestamp
        if (strtoupper($default) == 'CURRENT_TIMESTAMP') {
            //Check if column does exists
            $columnExists = $updating === false
                                ? false
                                : $model->getSchema()->hasColumn($model->getTable(), $key);

            if ( $columnExists ) {
                $column->default('CURRENT_TIMESTAMP');
            }

            //For new columns we want use this method, because previous methot throws error
            else {
                $column->useCurrent();
            }
        }
    }
}
