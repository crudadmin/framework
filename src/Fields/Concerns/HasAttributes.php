<?php

namespace Admin\Core\Fields\Concerns;

use Admin\Core\Fields\Mutations;

trait HasAttributes
{
    /**
     * Registred custom admin attributes for fields.
     *
     * @var array
     */
    protected $attributes = [
         'name', 'type', 'resize', 'locale', 'default', 'decimal_length',
         'index', 'unsigned', 'unique_index', 'null', 'imaginary', 'migrateToPivot', 'fulltext',
    ];

    /**
     * Returns field attributes which are not includes in request rules, and are used for mutations.
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Add one or multiple attributes.
     *
     * @param  array|string  $attribute
     * @return void
     */
    public function addAttribute($attribute)
    {
        foreach (array_wrap($attribute) as $attr) {
            if (in_array($attr, $this->attributes)) {
                continue;
            }

            $this->attributes[] = $attr;
        }
    }
}
