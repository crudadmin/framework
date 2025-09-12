<?php

namespace Admin\Core\Eloquent\Concerns;

use AdminCore;
use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Fields\Group;
use Admin\Helpers\Localization\AdminResourcesSyncer;
use Fields;
use Localization;

trait FieldProperties
{
    /**
     * Buffered fields in model.
     *
     * @var null|array
     */
    private static $adminFields = [];

    /**
     * Which options can be loaded in getFields (eg data from db).
     *
     * @var array
     */
    private $withOptions = [];

    /**
     * Save admin parent row into model.
     *
     * @var Admin\Core\Eloquent\AdminModel|null
     */
    protected $parentRow = null;

    /**
     * Skip belongsToMany properties in getAdminModelAttributes.
     *
     * @var bool
     */
    protected $skipBelongsToMany = false;

    /**
     * Fields cache key
     *
     * @return  string
     */
    public function getFieldsCacheModelKey()
    {
        return implode(';', array_merge(
            [
                get_class($this),
            ],
                $this->getModules(),
                AdminCore::getAdminModelNamespaces(),
            )
        );
    }

    /**
     * Return fields converted from string (key:value|otherkey:othervalue) into array format.
     *
     * @param  Admin\Core\Eloquent\AdminModel|null  $param
     * @param  bool  $force
     * @return array
     */
    public function getFields($param = null, $force = false)
    {
        $withOptions = count($this->withOptions) > 0;

        if ($param !== null || $withOptions === true) {
            $force = true;
        }

        $cacheKey = static::class;

        //Field mutations
        if ( !array_key_exists($cacheKey, static::$adminFields) || $force == true) {
            static::$adminFields[$cacheKey] = [
                'fields' => Fields::getFields($this, $param, $force),
                'key' => $this->getFieldsCacheModelKey(),
            ];

            $this->withoutOptions();
        }

        return static::$adminFields[$cacheKey]['fields'];
    }

    /*
     * Refresh fields when modules/or cache key has been changed
     */
    public function refreshFields()
    {
        $cacheKey = static::class;

        if (
            isset(static::$adminFields[$cacheKey])
            && static::$adminFields[$cacheKey]['key'] != $this->getFieldsCacheModelKey()
        ){
            unset(static::$adminFields[$cacheKey]);
        }
    }

    /**
     * Return all model fields with options.
     *
     * @param  Admin\Core\Eloquent\AdminModel  $param
     * @param  bool  $force
     * @return array
     */
    public function getFieldsWithOptions($param = null, $force = false)
    {
        $this->withAllOptions();

        return $this->getFields($param, $force);
    }

    /**
     * Return registered groups for given model.
     *
     * @return array
     */
    public function getFieldsGroups()
    {
        $groups = Fields::getFieldsGroups($this);

        return $this->recursivelyBuildAllGroups($groups ?: []);
    }

    /**
     * Retrieve all buildedgroups
     *
     * @param  array  $groups
     * @return  array
     */
    private function recursivelyBuildAllGroups($groups = [])
    {
        foreach ($groups as $key => $group) {
            if ( !($group instanceof Group) ) {
                continue;
            }

            $group->name = AdminResourcesSyncer::translate($group->name);
            $group->fields = $this->recursivelyBuildAllGroups($group->fields);

            if ( method_exists($group, 'build') ) {
                $groups[$key] = $group->build();
            }
        }

        return $groups;
    }

    /**
     * Returns needed field.
     *
     * @param  string $key
     * @return array|null
     */
    public function getField(string $key)
    {
        $fields = $this->getFields();

        if (array_key_exists($key, $fields)) {
            return $fields[$key];
        }
    }

    /**
     * Returns type of field.
     *
     * @param  string  $key
     * @return string
     */
    public function getFieldType(string $key)
    {
        if ( $field = $this->getField($key) ) {
            return $field['type'];
        }
    }

    /**
     * Check column type.
     *
     * @param  string  $key
     * @param  string|array  $types
     * @return bool
     */
    public function isFieldType(string $key, $types)
    {
        if (is_string($types)) {
            $types = [$types];
        }

        return in_array($this->getFieldType($key), $types);
    }

    /**
     * Returns maximum length of field.
     *
     * @param  string  $key
     * @return int
     */
    public function getFieldLength(string $key)
    {
        $field = $this->getField($key);

        if ($this->isFieldType($key, ['file', 'password'])) {
            return 255;
        }

        //Return maximum defined value
        if (array_key_exists('max', $field)) {
            return $field['max'];
        }

        //Return default maximum value
        return 255;
    }

    /**
     * Returns if field has required.
     *
     * @param  string  $key
     * @param  string|array  $params
     * @param  mixed  $paramValue
     * @return bool
     */
    public function hasFieldParam(string $key, $params, $paramValue = null)
    {
        if (! $field = $this->getField($key)) {
            return false;
        }

        foreach (array_wrap($params) as $paramName) {
            if (array_key_exists($paramName, $field)) {
                if ($paramValue !== null) {
                    if ($field[$paramName] === $paramValue) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns attribute of field.
     *
     * @param  string  $key
     * @param  string  $paramName
     * @return mixed
     */
    public function getFieldParam(string $key, string $paramName)
    {
        if ($this->hasFieldParam($key, $paramName) === false) {
            return;
        }

        $field = $this->getField($key);

        return $field[$paramName];
    }

    /**
     * Field mutator for selects returns all options (also from db, etc...).
     *
     * @return $this
     */
    public function withAllOptions()
    {
        return $this->withOptions(true);
    }

    /**
     * Disable generate select options into fields.
     *
     * @return $this
     */
    public function withoutOptions()
    {
        return $this->withOptions(false);
    }

    /**
     * Allow options.
     *
     * @param  bool|array  $set
     * @return $this
     */
    public function withOptions($set = null)
    {
        //We want all fields options
        if ($set === true) {
            $this->withOptions = [
                '*' => [
                    // Settings
                ]
            ];
        }

        //We want specifics fields options
        elseif (is_array($set) || $set === false) {
            $this->withOptions = $set ?: [];
        }

        return $this;
    }

    /**
     * Returns allowed field options.
     *
     * @return array
     */
    public function getOptionsSettings()
    {
        return $this->withOptions;
    }

    /**
     * Save admin parent row into model.
     *
     * @param  Admin\Core\Eloquent\AdminModel|null  $row
     * @return void
     */
    public function setParentRow($row)
    {
        $this->parentRow = $row;

        return $this;
    }

    /**
     * Get admin parent row.
     *
     * @return Admin\Core\Eloquent\AdminModel|null
     */
    public function getParentRow()
    {
        return $this->parentRow;
    }

    /**
     * Returns value of given key from options.
     *
     * @param  string  $field
     * @param  string|int  $value
     * @return string
     */
    public function getSelectOption(string $field, $value = null)
    {
        $options = $this->getProperty('options');

        if (is_null($value)) {
            $value = $this->{$field};
        }

        if (
            ! array_key_exists($field, (array)$options)
            || ! array_key_exists($value, (array)$options[$field])
        ) {
            return;
        }

        return $options[$field][$value];
    }

    /**
     * alias for getSelectOption
     *
     * @param  string  $field
     * @param  string|int  $value
     * @return string
     */
    public function getOptionValue(string $field, $value)
    {
        return $this->getSelectOption($field, $value);
    }

    /**
     * Get migration column type.
     *
     * @param  string  $key
     * @return bool
     */
    private function getMigrationColumnType($key)
    {
        return Fields::getColumnType($this, $key);
    }

    /**
     * Returns short values of fields for content table of rows in administration.
     *
     * @return array
     */
    public function getColumnNames()
    {
        return Fields::cache('models.'.$this->getTable().'.columns_names', function () {
            $fields = ['id'];

            //If has foreign key, add column name to base fields
            if ($this->getForeignColumn()) {
                $fields = array_merge($fields, array_values($this->getForeignColumn()));
            }

            foreach ($this->getFields() as $key => $field) {
                $columnType = $this->getMigrationColumnType($key);

                //Skip column types without database column representation
                if ($columnType && $columnType->hasColumn()) {
                    $fields[] = $key;
                }
            }

            //Insert skipped columns
            if (is_array($this->skipDropping)) {
                foreach ($this->skipDropping as $key) {
                    $fields[] = $key;
                }
            }

            //Get register static columns from migrations
            //_order, published_at, deleted_at etc...
            $staticColumns = array_map(function ($columnClass) {
                return $columnClass->getColumn();
            }, Fields::getEnabledStaticFields($this));

            //Get enabled static columns
            $fields = array_unique(array_merge($fields, $staticColumns));

            return $fields;
        });
    }

    /**
     * Return decimal length of given field
     *
     * @param  string  $key
     * @return  array
     */
    public function getDecimalLength($key)
    {
        $decimalLength = $this->getFieldParam($key, 'decimal_length') ?: '8,2';
        $decimalLength = str_replace(':', ',', $decimalLength);
        $decimalLength = explode(',', $decimalLength);

        return $decimalLength;
    }
}
