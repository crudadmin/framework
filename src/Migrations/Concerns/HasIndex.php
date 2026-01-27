<?php

namespace Admin\Core\Migrations\Concerns;

use Admin\Core\Eloquent\AdminModel;
use Str;

trait HasIndex
{
    /**
     * Returns foreign key name.
     * @param  mixed    $modelOrTable
     * @param  string   $key
     * @param  string   $postfix
     * @param  string   $prefix
     *
     * @return string
     */
    protected function getIndexName($modelOrTable, $key, $postfix = null, $prefix = null)
    {
        $table = $modelOrTable instanceof AdminModel ? $modelOrTable->getTable() : $modelOrTable;

        $key = array_wrap($key);

        if ( count($key) >= 2 ){
            $key = array_map(function($key){
                return $this->removeEverySecondCharInMiddle($key);
            }, $key);
        }

        $key = implode('_', $key);
        $prefix = is_null($prefix) ? '' : $prefix;
        $postfix = is_null($postfix) ? 'foreign' : $postfix;

        return $this->makeShortForeignIndex($table, $key, $prefix, $postfix);
    }

    /**
     * Returns if table has index builded from column name and table name.
     * @param  AdminModel $model
     * @param  string|array     $key
     * @param  string     $postfix
     * @param  string     $prefix
     * @return int
     */
    protected function hasIndex(AdminModel $model, $key, $postfix = null, $prefix = null)
    {
        $indexes = $this->getModelIndexes($model);

        $searchIndex = $this->getIndexName($model, $key, $postfix, $prefix);

        return array_key_exists($searchIndex, $indexes);
    }

    /**
     * Return indexes of model
     *
     * @param  AdminModel  $model
     * @return  string
     */
    protected function getModelIndexes(AdminModel $model)
    {
        $indexes = $model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable());

        return collect($indexes)->keyBy('name')->toArray();
    }


    /**
     * Return indexes of model
     *
     * @param  AdminModel  $model
     * @return  string
     */
    protected function getModelForeignKeys(AdminModel $model)
    {
        $keys = $model->getConnection()->getSchemaBuilder()->getForeignKeys($model->getTable());

        return collect($keys)->keyBy('name')->toArray();
    }

    /*
     * Drops foreign key in table
     */
    protected function dropIndex($model, $key, $postfix = null)
    {
        $connection = $model->getConnection();

        $expression = dbRaw(
            'alter table `'.$model->getTable().'` drop '.($postfix ?: 'foreign key').' `'.$this->getIndexName($model, $key, $postfix).'`',
            $connection
        );

        return $connection->select($expression);
    }

    /*
     * Drops foreign key in table
     */
    protected function addIndex($model, $key, $postfix = null)
    {
        $connection = $model->getConnection();

        $expression = dbRaw(
            'alter table `'.$model->getTable().'` add INDEX '.$this->getIndexName($model, $key, $postfix).' (`'.$key.'`)',
            $connection
        );

        return $connection->select($expression);
    }

    /**
     * Remove every second char from given string
     *
     * @param  string  $string
     * @return string
     */
    private function removeEverySecondCharInMiddle($string)
    {
        $string = str_replace('_', '', $string);

        //Split string into array
        $allChars = str_split($string);

        //Skip first and last characted in the string
        $chars = array_slice($allChars, 1, -1);

        //Remove every other characted from the middle string
        foreach ($chars as $key => $char) {
            if ( $key%2 == 0 ) {
                unset($chars[$key]);
            }
        }

        //Does not delete first and last character from the table.
        //Everything odd characted in the middle can be removed
        $newString = $allChars[0].implode('', $chars).$allChars[strlen($string)-1];

        //Return smaller character
        return strlen($newString) < strlen($string)
                ? $newString
                : $string;
    }

    /**
     * Create foreign key index name.
     * @param  string $table
     * @param  string $key
     * @param  string $prefix
     * @param  string $postfix
     *
     * @return string
     */
    protected function makeShortForeignIndex($table, $key, $prefix = '', $postfix = '')
    {
        $fkStringLimit = 64;

        $table = preg_replace('/_+/', '_', $table);
        $prefix = $prefix ? Str::rtrim($prefix, '_').'_' : '';
        $postfix = $postfix ? '_'.Str::trim($postfix, '_') : '';

        //If table name is too long for MySql
        for ( $i = 0; $i < 2; $i++ )
        {
            $totalLength = strlen($prefix) + strlen($table) + 1 + strlen($key) + strlen($postfix);

            if ( strlen($table) > 10 && $totalLength > $fkStringLimit ) {
                $table = $this->removeEverySecondCharInMiddle($table);
            } else {
                break;
            }
        }

        return $prefix.$table.'_'.$key.$postfix;
    }
}
