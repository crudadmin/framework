<?php

namespace Admin\Core\Migrations;

use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Migrations\Concerns\SupportFulltext;
use Fields;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Schema;

class MigrationBuilder extends Command
{
    use Concerns\MigrationEvents,
        Concerns\MigrationSupport,
        Concerns\MigrationOutOfDate,
        Concerns\SupportColumnsPosition,
        Concerns\SupportRelations,
        Concerns\SupportColumn,
        Concerns\SupportJson,
        Concerns\SupportFulltext,
        Concerns\HasIndex;

    /*
     * Files
     */
    protected $files;

    /*
     * All migrated tables
     */
    protected $initializedTables = [
        'migrations', 'jobs', 'failed_jobs', 'password_resets',
        'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients', 'oauth_personal_access_clients', 'oauth_refresh_tokens',
        'personal_access_tokens',
    ];

    public function __construct()
    {
        $this->files = new Filesystem;

        $this->registerMigrationSupport();

        Fields::setCommand($this);

        parent::__construct();
    }

    /*
     * Returns this instace for supporting
     * columns traits
     */
    public function getCommand()
    {
        return $this;
    }

    /**
     * Generate CrudAdmin migrations.
     * @return void
     */
    protected function migrate($models)
    {
        $migrated = 0;

        foreach ($models as $model) {
            $migration = function () use ($model) {
                $this->generateMigration($model);
            };

            //Check if migration is out of date from cache
            if ($this->isOutOfDate($model, $migration)) {
                continue;
            }

            $migrated++;
        }

        if ($migrated === 0) {
            return $this->line('<info>Nothing to migrate.</info>');
        }

        /*
         * Run events migrations from buffer
         */
        foreach ($models as $model) {
            $this->fireMigrationEvents($model, 'fire_after_all');
        }

        //Check unknown tables
        if ( $this->option('unknown') == true ) {
            $this->checkUneccesaryTables();
        }
    }

    /*
     * Add table into tables list
     */
    public function registerTable($table)
    {
        $this->initializedTables[] = $table;
    }

    /**
     * Generate laravel migratons.
     * @return void
     */
    protected function generateMigration($model)
    {
        $this->registerTable($model->getTable());

        $this->fireModelEvent($model, 'beforeMigrate');

        if ($model->getSchema()->hasTable($model->getTable())) {
            $this->updateTable($model);
        } else {
            $this->createTable($model);

            $this->fireModelEvent($model, 'onTableCreate');
        }

        $this->setTableFullText($model);

        $this->fireModelEvent($model, 'afterMigrate');

        //Checks if model has some extre migrations on create
        $this->registerAfterAllMigrations($model, function ($table) use ($model) {
            $this->fireModelEvent($model, 'onMigrateEnd');
        });

        //Run migrations from cache which have to be runned after actual migration
        $this->fireMigrationEvents($model, 'fire_after_migration');
    }

    /**
     * Skip creating of static columns.
     * @param  string     $key
     * @param  AdminModel $model
     * @return bool
     */
    private function skipField(string $key, AdminModel $model)
    {
        $staticColumns = array_map(function ($columnClass) {
            return $columnClass->getColumn();
        }, Fields::getEnabledStaticFields($model));

        return in_array($key, $staticColumns);
    }

    /**
     * Create table from model.
     * @return void
     */
    protected function createTable($model)
    {
        $model->getSchema()->create($model->getTable(), function (Blueprint $table) use ($model) {

            //Increment
            $table->increments('id');

            //Add relationships with other models
            $this->addRelationships($table, $model);

            foreach ($model->getFields() as $key => $value) {
                if ($this->skipField($key, $model)) {
                    continue;
                }

                $this->registerColumn($table, $model, $key);
            }

            //Register static columns
            $this->registerStaticColumns($table, $model);
        });

        $this->line('<comment>Created table:</comment> '.$model->getTable());
    }

    /**
     * Update existing table.
     * @return void
     */
    protected function updateTable($model)
    {
        $this->line('<info>Updated table:</info> '.$model->getTable());

        $model->getSchema()->table($model->getTable(), function (Blueprint $table) use ($model) {
            //Add relationships with other models
            $this->addRelationships($table, $model, true);

            //Which columns will be added in reversed order
            $addColumns = [];

            //Which columns are creating. Next columns can't be added after this new columns,
            //because this columns does not exists in database yet
            $exceptDoesntExistinging = [];

            foreach ($model->getFields() as $key => $value) {
                if ($this->skipField($key, $model)) {
                    continue;
                }

                //Checks if table has column and update it if can...
                if ($model->getSchema()->hasColumn($model->getTable(), $key)) {
                    if ($column = $this->registerColumn($table, $model, $key, true)) {
                        $column->change();
                    }
                } else {
                    //This key does not exists in db
                    $exceptDoesntExistinging[] = $key;

                    $addColumns[] = [
                        'key' => $key,
                        'callback' => function ($exceptDoesntExistinging) use ($table, $model, $key, $value) {
                            if ($column = $this->registerColumn($table, $model, $key)) {
                                $previous_column = $this->getPreviousColumn($model, $key, $exceptDoesntExistinging);

                                //Add creating column after previous existing field in fields position
                                if ($model->getSchema()->hasColumn($model->getTable(), $previous_column)) {
                                    $column->after($previous_column);
                                }

                                //If column does not exists, then add before deleted_at column
                                elseif ($model->getSchema()->hasColumn($model->getTable(), 'deleted_at')) {
                                    $column->after('id');
                                }
                            }

                            return $column;
                        },
                    ];
                }
            }

            //Add columns in reversed order
            for ($i = count($addColumns) - 1; $i >= 0; $i--) {
                //if no column has been added, then remove column from array for messages
                if (! ($column = call_user_func_array($addColumns[$i]['callback'], [$exceptDoesntExistinging]))) {
                    unset($addColumns[$i]);
                }
            }

            //Which columns has been successfully added
            foreach ($addColumns as $row) {
                $this->line('<comment>+ Added column:</comment> '.$row['key']);
            }

            //Register static columns
            $this->registerStaticColumns($table, $model, true);

            $this->dropUnnecessaryColumns($table, $model);
        });
    }

    /**
     * Automatic columns dropping.
     * @param  Blueprint  $table
     * @param  AdminModel $model
     * @return void
     */
    private function dropUnnecessaryColumns(Blueprint $table, AdminModel $model)
    {
        $columnNames = $model->getColumnNames();

        //Removes unnecessary columns
        foreach ($model->getSchema()->getColumnListing($model->getTable()) as $column) {
            if (! in_array($column, $columnNames) && ! in_array($column, (array) $model->getProperty('skipDropping'))) {
                $this->line('<comment>+ Unknown column:</comment> '.$column);

                $auto_drop = $this->option('auto-drop', false);

                if ($auto_drop === true || $this->confirm('Do you want drop this column? [y|N]')) {
                    //Drop foreign indexes for given column
                    foreach ($this->getModelForeignKeys($model) as $item) {
                        if ( in_array($column, $item->getColumns()) ){
                            $table->dropForeign($item->getName());
                        }
                    }

                    $table->dropColumn($column);

                    $this->line('<comment>+ Dropped column:</comment> '.$column);
                }
            }
        }
    }

    /**
     * Returns field before given field, if is given field first, returns last field.
     * @param  AdminModel $model
     * @param  string     $findKey
     * @param  array      $exceptDoesntExistinging
     * @return string
     */
    public function getPreviousColumn(AdminModel $model, string $findKey, array $exceptDoesntExistinging = [])
    {
        $last = 'id';
        $i = 0;

        foreach ($model->getFields() as $key => $item) {
            if ($key == $findKey) {
                return $i == 0 ? 'id' : $last;
            }

            $i++;

            //Check if given field type is represented with existing field from db
            //and also check if previous position of field does exists in database
            if (
                ($columnClass = Fields::getColumnType($model, $key)) && $columnClass->hasColumn()
                && ! in_array($key, $exceptDoesntExistinging)
            ) {
                $last = $key;
            }
        }

        return $last;
    }

    //Returns schema with correct connection
    protected function getSchema($model)
    {
        return Schema::connection($model->getProperty('connection'));
    }

    /*
     * Display all uneccessary tables
     */
    public function checkUneccesaryTables()
    {
        //We need have turned on force flag. because we need check all tables for receiving their table names.
        if ( $this->option('force') == false ){
            $this->error('Check for uneccessary tables is possible only with -f/--force flag.');
            return;
        }

        $tables = array_map(function($item){
            return array_values((array)$item)[0];
        }, DB::select('SHOW TABLES'));

        foreach (array_diff($tables, $this->initializedTables) as $table) {
            $this->info('Unknown table: <comment>'.$table.'</comment>');

            if ( $this->confirm('Would you like to drop <comment>'.$table.'</comment> table with <comment>'.DB::table($table)->count().' rows</comment>? [y|N]') ) {
                Schema::drop($table);
                $this->line('<comment>+ Dropped table:</comment> '.$table);
            } else {
                $this->line('<info>+ Skipped table:</info> '.$table);
            }
        }
    }
}
