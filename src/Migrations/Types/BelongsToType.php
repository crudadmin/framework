<?php

namespace Admin\Core\Migrations\Types;

use AdminCore;
use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Migrations\Concerns\SupportRelations;
use Admin\Core\Migrations\MigrationBuilder;
use Illuminate\Database\Schema\Blueprint;

class BelongsToType extends Type
{
    use SupportRelations;

    /**
     * Check if can apply given column.
     * @param  AdminModel  $model
     * @param  string      $key
     * @return bool
     */
    public function isEnabled(AdminModel $model, string $key)
    {
        return $model->hasFieldParam($key, 'belongsTo');
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
        $properties = $model->getRelationProperty($key, 'belongsTo');

        $parent = AdminCore::getModelByTable($properties[0]);

        //If table in belongsTo relation does not exists
        if (! $parent) {
            $this->getCommand()->line('<error>Table '.$properties[0].' does not exists.</error>');
            die;
        }

        //Skip adding new foreign key if exists from belongsToModel property
        if ($this->isForeignInBelongsToModel($table, $key)) {
            return true;
        }

        //If foreign key in table exists
        $keyExists = false;

        //Check if actual table and key exists
        if ($tableExists = $model->getSchema()->hasTable($model->getTable())) {
            $keyExists = $this->hasIndex($model, $key);
        }

        //If table has not foreign column
        if ($keyExists == false) {
            //Checks if table has already inserted rows which won't allow insert foreign key without NULL value
            if ($tableExists === true && $model->withoutGlobalScopes()->count() > 0 && $model->hasFieldParam($key, 'required', true)) {
                $this->checkForReferenceTable($model, $key, $properties[0]);
            }

            $this->registerAfterAllMigrations($model, function ($table) use ($key, $properties, $model, $parent) {
                if ($parent->getSchema()->hasTable($parent->getTable())) {
                    $table->foreign($key, $this->getIndexName($model, $key))
                          ->references($properties[2])->on($properties[0]);
                }
            });
        }

        return $table->integer($key)->unsigned();
    }

    /**
     * Set default value.
     * @param mixed $column
     * @param AdminModel       $model
     * @param string           $key
     */
    public function setDefault($column, AdminModel $model, string $key)
    {
        $column->default(null);
    }

    /**
     * Set default value.
     * @param mixed $column
     * @param AdminModel       $model
     * @param string           $key
     * @param MigrationBuilder           $builder
     */
    public function setNullable($column, AdminModel $model, string $key, MigrationBuilder $builder)
    {
        $tableExists = $model->getSchema()->hasTable($model->getTable());

        $tableColumn = $tableExists ? $builder->getTableColumn($model, $key) : null;

        //When we are changing nullable need change nullable, because is set to wrong state
        if ( $tableColumn ) {
            //Set previously nullable state
            $column->nullable($tableColumn['nullable']);

            if ( $builder->isNullable($model, $key) !== $tableColumn['nullable'] ) {
                $this->registerAfterMigration($model, function () use ($model, $key, $builder) {
                    //We we want set realtion to null, we need check if there exists rows with null values
                    if ( $builder->isNullable($model, $key) === false ) {
                        $nullableRows = $model->withoutGlobalScopes()->whereNull($key)->count();

                        if ( $nullableRows ) {
                            $this->getCommand()->error('Column '.$key.' in table '.$model->getTable().' could not be set to nullable. Because there are some rows with NULL values');
                        }

                        return;
                    }

                    $model->getSchema()->disableForeignKeyConstraints();

                    $model->getSchema()->table($model->getTable(), function (Blueprint $table) use ($model, $key, $builder) {
                        $column = $this->registerColumn($table, $model, $key, true);

                        $builder->setNullable($model, $key, $column);

                        $column->change();
                    });

                    $model->getSchema()->enableForeignKeyConstraints();
                }, false);
            }
        }

        //If column does not exists. We can define nullable as usually
        else {
            $builder->setNullable($model, $key, $column);
        }
    }

    /**
     * Check if column is also foreign key from belongsToModel property.
     * @param  string  $table
     * @param  string  $key
     * @return bool
     */
    private function isForeignInBelongsToModel($table, $key)
    {
        $has_column = array_filter($table->getColumns(), function ($column) use ($key) {
            return $column->name == $key;
        });

        //Check if relationship column has been already added from belongsToModelProperty
        return count($has_column) > 0;
    }
}
