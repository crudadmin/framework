<?php

namespace Admin\Core\Migrations\Concerns;

use AdminCore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait SupportRelations
{
    /**
     * Check or add static relationship columns from relation admin models.
     * @param object $table
     * @param object $model
     * @param boolena $updating
     */
    public function addRelationships($table, $model, $updating = false)
    {
        $belongsToModel = $model->getBelongsToRelation();

        //Model without belongsToModel parent
        if (($count = count($belongsToModel)) == 0) {
            return;
        }

        //If update migration, then go in reverse order
        if ($updating === true) {
            $belongsToModel = array_reverse($belongsToModel);
        }

        foreach ($belongsToModel as $parent) {
            $isRecursive = class_basename(get_class($model)) == class_basename($parent);

            //If is recursive model, then do not create new same instance, because of bug when parent model
            //is overidded and has relationship on itself in package with other namespace
            $parent = $isRecursive ? $model : new $parent;

            $foreignColumn = $model->getForeignColumn($parent->getTable());

            $column = $table->integer($foreignColumn)->unsigned();

            $isNullable = count($belongsToModel) > 1
                || $model->getProperty('withoutParent') === true
                || $model->getProperty('nullableRelation') === true
                || $isRecursive;

            //If parent belongs to more models, or just itself
            if ($isNullable) {
                $column->nullable();
            }

            //If foreign key does not exists in table
            if (! $model->getSchema()->hasColumn($model->getTable(), $foreignColumn)) {
                //If column does not exists in already created table, then create it after id
                if ($updating === true) {
                    $column->after('id');

                    //If is one foreign column, this columns is not null
                    //so if some rows exists, we need push values into this row
                    if (count($belongsToModel) == 1 && $model->count() > 0) {
                        $this->checkForReferenceTable($model, $foreignColumn, $parent->getTable(), $isNullable);
                    }

                    $this->getCommand()->line('<comment>+ Added column:</comment> '.$foreignColumn);
                }
            } elseif ($updating === true) {
                $column->change();
                continue;
            }

            if ($parent->getConnection() != $model->getConnection()) {
                $this->getCommand()->line('<comment>+ Skipped foreign relationship:</comment> '.$foreignColumn.' <comment>( different db connections )</comment> ');
                continue;
            }

            $this->registerAfterAllMigrations($model, function ($table) use ($model, $foreignColumn, $parent) {
                $foreignName = $this->getIndexName($model->getTable(), $foreignColumn);

                $table->foreign($foreignColumn, $foreignName)->references('id')->on($parent->getTable());
            });
        }
    }

    /**
     * Checks if table has already inserted rows which won't allow insert foreign key without NULL value.
     * @param  object $model
     * @param  string $key
     * @param  string $referenceTable
     * @param  bool $isNullable
     * @return void
     */
    protected function checkForReferenceTable($model, $key, $referenceTable, $isNullable = false)
    {
        if ( $isNullable === false ) {
            $this->getCommand()->line('<comment>+ Cannot add foreign key for</comment> <error>'.$key.'</error> <comment>column into</comment> <error>'.$model->getTable().'</error> <comment>table with reference on</comment> <error>'.$referenceTable.'</error> <comment>table.</comment>');
            $this->getCommand()->line('<comment>  Because table has already inserted rows. But you can insert value for existing rows for this</comment> <error>'.$key.'</error> <comment>column.</comment>');
        } else {
            $this->getCommand()->line('<comment>+ Would you like to insert some of preddefined values into</comment> <error>'.$key.'</error> <comment>column in</comment> <error>'.$model->getTable().'</error> <comment>table with reference on</comment> <error>'.$referenceTable.'</error> <comment>table?</comment>');
        }

        $referenceModel = AdminCore::getModelByTable($referenceTable);
        $referenceTableIds = $referenceModel->take(10)->select('id')->pluck('id');
        $relationRequiredEvent = 'onRequired'.Str::studly($key).'Relation';
        $requestedId = false;

        if (count($referenceTableIds) > 0) {
            //Define ids for existing rows
            if ( method_exists($model, $relationRequiredEvent) === false ) {
                $this->getCommand()->line('<comment>+ Here are some ids from '.$referenceTable.' table:</comment> N, '.implode(', ', $referenceTableIds->toArray()));

                do {
                    $requestedId = $this->getCommand()->ask('Which id would you like define for existing rows?'.($isNullable ? ' (Press enter for skip)' : ''));

                    if (! is_numeric($requestedId) ) {
                        if ( $isNullable === true || $requestedId == 'N' ){
                            $this->getCommand()->line('Continuing without any preddefined value.');

                            break;
                        }

                        continue;
                    }

                    if ($requestedId != 'N' && $referenceModel->where('id', $requestedId)->count() == 0) {
                        $this->getCommand()->line('<error>Id #'.$requestedId.' does not exists.</error>');
                        $requestedId = false;
                    }
                } while (! is_numeric($requestedId));
            } else {
                $this->getCommand()->line('<comment>+ Column required fill initialized for '.$key.' table:</comment> ');
            }

            //Register event after this migration will be done
            $this->registerAfterMigration($model, function () use ($model, $key, $requestedId, $relationRequiredEvent) {
                //Custom update event
                if ( method_exists($model, $relationRequiredEvent) ) {
                    $this->fireModelEvent($model, $relationRequiredEvent);
                } else if ( is_numeric($requestedId) ) {
                    DB::connection($model->getConnectionName())->table($model->getTable())->update([$key => $requestedId]);
                }
            });
        } else {
            $this->getCommand()->line('<error>+ You have to insert at least one row into '.$referenceTable.' reference table or remove all existing data in actual '.$model->getTable().' table:</error>');
            die;
        }
    }
}
