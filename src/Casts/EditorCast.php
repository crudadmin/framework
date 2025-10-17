<?php

namespace Admin\Core\Casts;

use Admin\Core\Casts\Concerns\AdminCast;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class EditorCast implements CastsAttributes, AdminCast
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array
     */
    public function get($model, $key, $value, $attributes)
    {
        if ( \Admin::isFrontend() ) {
            $attributes = '';

            if ( \FrontendEditor::isActive() && admin() && admin()->hasAccess($model, 'update') ) {
                $hash = \FrontendEditor::makeHash($model->getTable(), $key, $model->getKey());

                $attributes = 'data-model="'.$model->getTable().'" data-key="'.$key.'" data-id="'.$model->getKey().'" data-hash="'.$hash.'"';
            }

            return $value ? '<div data-crudadmin-editor'.$attributes.'>'.$value.'</div>' : null;
        }

        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  array  $value
     * @param  array  $attributes
     * @return string
     */
    public function set($model, $key, $value, $attributes)
    {
        return $value;
    }
}