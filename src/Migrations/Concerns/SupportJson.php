<?php

namespace Admin\Core\Migrations\Concerns;

use Localization;
use Illuminate\Support\Facades\DB;

trait SupportJson
{
    /*
     * Checks if DB supports mysql columns
     */
    public function checkForCorrectMysqlVersion($model, $type = null)
    {
        //Allow check only for mysql
        if ( env('DB_CONNECTION') != 'mysql' ) {
            return;
        }

        $pdo = $model->getConnection()->getPdo();
        $version = $pdo->query('select version()')->fetchColumn();

        (float) $version = mb_substr($version, 0, 6);

        //Compare of mysql versions
        if (version_compare($version, '5.7.0', '<')) {
            $this->getCommand()->line('<error>Sorry, but JSON columns are not supported in your MySQL '.$version.' database.</error>');
            $this->getCommand()->line('<comment>You need minimum MySQL 5.7.0 for supporting multiple '.($type == 'select' ? 'select columns' : 'upload files').'.<comment>');
            die;
        }
    }

    /**
     * Set json column, also check mysql version.
     * @param object        $table
     * @param string        $key
     * @param AdminModel    $model
     * @param bool       $update
     * @param bool       $localized
     */
    private function setJsonColumn($table, $key, $model, $update = false, $localized = false)
    {
        $this->checkForCorrectMysqlVersion($model, 'file');

        //If is updating column and previous value is not json
        if ($update === true && $model->getSchema()->hasColumn($model->getTable(), $key)) {
            $type = $model->getConnection()->getDoctrineColumn($model->getTable(), $key)->getType()->getName();

            if (! in_array($type, ['json', 'json_array']) && $localized === true) {
                $this->updateToJsonColumn($model, $key, $type);
            }
        }

        return $table->json($key)->platformOptions([]);
    }

    /**
     * Check if column in database under given model has invalid json values.
     * @param  object  $model
     * @param  string  $key
     * @return Collection
     */
    private function hasInvalidValues($model, $key)
    {
        $query = $model->getConnection()
                      ->table($model->getTable())
                      ->select([$model->getKeyName(), $key])
                      ->whereNotNull($key)
                      ->take(5);

        return $query->whereRaw('NOT JSON_VALID(`'.$key.'`) || `'.$key.'` REGEXP "^-?[0-9]+$" = 1')->pluck($key, $model->getKeyName());
    }

    /**
     * Update existing non-json values into json values format for localization support.
     * @param  object $model
     * @param  string $key
     * @param  string $type
     * @return void
     */
    private function updateToJsonColumn($model, $key, $type = null)
    {
        //Check if exists row in table,
        if ($model->getConnection()->table($model->getTable())->count() === 0) {
            return;
        }

        //Check if database has unvalid values for correct json type application
        if (($update = $this->hasInvalidValues($model, $key))->count() == 0) {
            return;
        }

        $languages = Localization::getLanguages();

        $slug = ($lang = $languages->first()) ? $lang->slug : 'en';

        $this->getCommand()->line('<comment>- You are updating</comment> '.$key.' <comment>column from '.($type ?: 'non-json').' type to json type for translates purposes.</comment>');

        foreach ($update as $id => $value) {
            $value = str_limit($value, 30);
            $this->getCommand()->line('<comment>'.$id.':</comment> '.$value.' <comment>=></comment> <info>{"'.$slug.'":"</info>'.$value.'<info>"}</info>');
        }

        if ($update->count() == 5) {
            $this->getCommand()->line('<comment>...</comment>');
        }

        if (! $this->getCommand()->confirm('Would you like to update this '.($type ?: 'non-json').' values in database to translated format of JSON values?', true)) {
            return;
        }

        if ($languages->count() === 0) {
            $this->getCommand()->line('<error>You have no inserted languages to update '.$key.' column.</error>');
        }

        $prefix = $languages->first()->slug;

        $toJson = 'CONCAT("{\"'.$prefix.'\": \"",
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            '.$key.',
                            \'"\', \'\\\\"\'
                        ),
                    \'\r\', \'\'),
                \'\t\', \'\'),
            \'\n\', \'\')
        , "\"}")';

        $model->getConnection()
                ->table( $model->getTable() )
                ->whereRaw('NOT JSON_VALID('.$key.') || `'.$key.'` REGEXP "^-?[0-9]+$" = 1')
                ->whereRaw('JSON_VALID('.$toJson.')')
                ->update([
                    $key => DB::raw($toJson)
                ]);
    }
}
