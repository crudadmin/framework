<?php

namespace Admin\Core\Eloquent\Concerns;

trait HasProperties
{
    /**
     * If properties are being mutated in mutator, we want turn of module mutators inside module mutators
     *
     * @var  bool
     */
    private $mutatingInMutator = false;

    /**
     * This properties can be called also as method
     *
     * @var  array
     */
    static $callableProperties = [
        'name', 'group', 'fields', 'active', 'inMenu', 'single', 'options', 'searches',
        'insertable', 'editable', 'publishable', 'deletable', 'rules',
        'settings', 'buttons', 'exports', 'reserved', 'layouts', 'belongsToModel'
    ];

    /**
     * Returns property.
     *
     * @param  string  $property
     * @param  Admin\Core\Eloquent\AdminModel  $row
     * @return mixed
     */
    public function getProperty(string $property, $row = null)
    {
        //Laravel for translatable properties
        if (
            in_array($property, ['name', 'title'])
            && property_exists($this, $property)
            && $translate = trans($this->{$property})
        ) {
            return $translate;
        }

        //Object / Array
        elseif (in_array($property, self::$callableProperties)) {
            if (method_exists($this, $property)) {
                return $this->mutateAdminModelProperty($property, $this->{$property}($row));
            }

            if (property_exists($this, $property)) {
                return $this->mutateAdminModelProperty($property, $this->{$property});
            }

            return $this->mutateAdminModelProperty($property, null);
        }

        else if (property_exists($this, $property)) {
            return $this->{$property};
        }
    }

    /**
     * Set inside property.
     *
     * @param  string  $property
     * @param  mixed  $value
     *
     * @return $this
     */
    public function setProperty($property, $value)
    {
        $this->{$property} = $value;

        return $this;
    }

    private function mutateAdminModelProperty($property, $value = null)
    {
        $mutatorMethodName = 'set'.$property.'Property';

        //We need disable mutating properties inside module
        if ( $this->mutatingInMutator === true ){
            return $value;
        }

        if ( method_exists($this, $mutatorMethodName) ){
            $value = $this->{$mutatorMethodName}($value);
        }

        $this->mutatingInMutator = true;

        //Mutate property in module
        $this->runAdminModules(function($module) use (&$value, $property, $mutatorMethodName) {
            if ( method_exists($module, $mutatorMethodName) ) {
                $value = $module->{$mutatorMethodName}($value);
            }
        });

        $this->mutatingInMutator = false;

        return $value;
    }

}