<?php

namespace Admin\Core\Migrations\Types;

use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Migrations\Concerns\SupportRelations;
use Illuminate\Database\Schema\Blueprint;
use AdminCore;

class BelongsToManyType extends Type
{
    use SupportRelations;

    /*
     * This column type does not contain of column in database
     */
    public $hasColumn = false;

    /**
     * Check if can apply given column.
     * @param  AdminModel  $model
     * @param  string      $key
     * @return bool
     */
    public function isEnabled(AdminModel $model, string $key)
    {
        return $model->hasFieldParam($key, 'belongsToMany');
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
        $properties = $model->getRelationProperty($key, 'belongsToMany');

        //We are using admin model table for belongs to model relation
        if ( AdminCore::getModelByTable($properties[3]) ){
            return;
        }

        $singularColumn = ($migrateToPivot = $model->getFieldParam($key, 'migrateToPivot')) && is_string($migrateToPivot)
                           ? $migrateToPivot
                           : (trim_end(str_singular($key), '_id').'_id');

        //Get pivot rows from belongsTo column relation, and move this data into belongsToMany relation
        //but only with migrateToPivot parameter
        $pivotRows = $this->getPivotRowsFromSingleRelation($model, $singularColumn, $properties);

        $this->registerAfterAllMigrations($model, function () use ($table, $model, $key, $properties, $pivotRows, $singularColumn) {
            //Register this pivot table
            $this->getCommand()->registerTable($properties[3]);

            //If pivot table does not exists
            if (! $model->getSchema()->hasTable($properties[3])) {
                //Create pivot table
                $model->getSchema()->create($properties[3], function (Blueprint $table) use ($model, $properties, $key) {
                    $this->buildBasicPivotTable($table, $model, $properties, $key);
                });

                $this->getCommand()->line('<comment>Created table:</comment> '.$properties[3]);

                //Sync data from previous belongsTo relation into belongsToMany
                if (count($pivotRows) > 0) {
                    $model->{$key}()->sync($pivotRows);

                    $this->getCommand()->line('<comment>Imported rows ('.count($pivotRows).'):</comment> from <info>'.$singularColumn.'</info> into pivot <info>'.$properties[3].'</info> table');
                }
            } else {
                $this->getCommand()->line('<info>Checked table:</info> '.$properties[3]);

                if (! $model->getSchema()->hasColumn($properties[3], 'id')) {
                    $model->getSchema()->table($properties[3], function (Blueprint $table) use ($model, $properties) {
                        //Increment
                        $table->increments('id')->first();
                    });

                    $this->getCommand()->line('<comment>+ Added column:</comment> id');
                }
            }
        });

        return true;
    }

    private function buildBasicPivotTable($table, $model, $properties, $key)
    {
        //Increment
        $table->increments('id');

        //Add integer reference for owner table
        $table->integer($properties[6])->unsigned();
        $table->foreign($properties[6], $this->getIndexName($properties[3], $properties[6]))->references($model->getKeyName())->on($model->getTable());

        //Add integer reference for belongs to table
        $table->integer($properties[7])->unsigned();
        $table->foreign($properties[7], $this->getIndexName($properties[3], $properties[7]))->references($properties[2])->on($properties[0]);
    }

    /**
     * Return rows from previous existing column of belongsTo relation.
     * @param  AdminModel $model
     * @param  string $singularColumn
     * @param  array $properties
     * @return array
     */
    private function getPivotRowsFromSingleRelation(AdminModel $model, $singularColumn, $properties)
    {
        //If singular column exists in table and has not been deleted yet and pivot table does not exists
        if (
            ! $model->getField($singularColumn)
            && $model->getSchema()->hasColumn($model->getTable(), $singularColumn)
            && ! $model->getSchema()->hasTable($properties[3])
         ) {
            return $model->withoutGlobalScopes()
                         ->select([$model->getKeyName(), $singularColumn])
                         ->whereNotNull($singularColumn)
                         ->get()
                         ->map(function ($item) use ($singularColumn, $properties) {
                             return [
                                $properties[6] => $item->getKey(),
                                $properties[7] => $item->{$singularColumn},
                            ];
                         })->toArray();
        }

        return [];
    }
}
