<?php

namespace Admin\Core\Migrations\Columns;

use Admin\Core\Eloquent\AdminModel;
use Illuminate\Database\Schema\Blueprint;

class UpdatedAt extends Column
{
    public $column = 'updated_at';

    /**
     * Check if can apply given column.
     * @param  AdminModel  $model
     * @return bool
     */
    public function isEnabled(AdminModel $model)
    {
        // Check if columns is disabled in model
        if ( $model::UPDATED_AT === null ) {
            return false;
        }

        return $model->getProperty('timestamps');
    }

    /**
     * Register static column.
     * @param  Blueprint    $table
     * @param  AdminModel   $model
     * @param  bool         $update
     * @return Blueprint
     */
    public function registerStaticColumn(Blueprint $table, AdminModel $model, bool $update, $columnExists = null)
    {
        //Add Sluggable column support
        if ($columnExists) {
            return;
        }

        return $table->timestamp($this->column)->nullable();
    }
}
