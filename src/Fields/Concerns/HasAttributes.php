<?php

namespace Admin\Core\Fields\Concerns;

use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Fields\Mutations;

trait HasAttributes
{
    /*
     * Registred custom admin attributes for fields
     */
    protected $attributes = [
         'name', 'type', 'resize', 'locale', 'default', 'unique_db',
         'index', 'unsigned', 'imaginary', 'migrateToPivot'
    ];

    /*
     * Returns field attributes which are not includes in request rules, and are used for mutations
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /*
     * Add one or multiple attributes
     */
    public function addAttribute($attribute)
    {
        foreach (array_wrap($attribute) as $attr)
        {
            if ( in_array($attr, $this->attributes) )
                continue;

           $this->attributes[] = $attr;
        }
    }
}
?>