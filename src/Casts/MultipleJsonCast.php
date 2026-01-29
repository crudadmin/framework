<?php

namespace Admin\Core\Casts;

use Admin\Core\Casts\Concerns\MultiCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Support\Str;

class MultipleJsonCast implements Castable
{
    public static function castUsing(array $arguments)
    {
        return new class($arguments) extends MultiCast
        {
            public function get($model, $key, $value, $attributes)
            {
                $array = collect(json_decode($value, true))->map(function($item) use ($model, $key, $attributes) {
                    return parent::get($model, $key, $item, $attributes);
                });

                if ( $model->hasGetMutator($key) ) {
                    return $model->{'get'.Str::studly($key).'Attribute'}($array);
                }

                return $array;
            }

            //1. We need create clone of received array. We cannot override $value keys.
            //Otherwise there will be heavy bug with AdminFiles.
            //Because if we receive collection, this object is shared out of this function.
            //Cloning is solved by map. Function.
            //2. We can receive raw array without collection. When we are setting data eg. from request.
            public function set($model, $key, $value, $attributes)
            {
                return collect($value)->map(function($item) use ($model, $key, $attributes) {
                    return parent::set($model, $key, $item, $attributes);
                })->filter(function($value){
                    return is_null($value) == false;
                })->toJson();
            }
        };
    }
}