<?php

namespace Admin\Core\Migrations\Types;

use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Migrations\Concerns\SchemeSupport;
use Admin\Core\Migrations\Concerns\SupportJson;
use Illuminate\Database\Schema\Blueprint;

class JsonType extends Type
{
    use SupportJson;

    /**
     * Check if can apply given column.
     * @param  AdminModel  $model
     * @param  string      $key
     * @return bool
     */
    public function isEnabled(AdminModel $model, string $key)
    {
        return $model->isFieldType($key, ['json', 'array']) || $model->hasFieldParam($key, ['locale', 'multiple']);
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
        return $this->setJsonColumn($table, $key, $model, $update, $model->hasFieldParam($key, ['locale']));
    }
}
