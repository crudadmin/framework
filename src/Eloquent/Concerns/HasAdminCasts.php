<?php

namespace Admin\Core\Eloquent\Concerns;

use Admin\Core\Casts\EditorCast;
use Admin\Core\Casts\DateableCast;
use Admin\Core\Casts\GeometryCast;
use Admin\Core\Casts\AdminFileCast;
use Admin\Core\Casts\GutenbergCast;
use Admin\Core\Casts\AdminMultiCast;
use Admin\Core\Casts\MultipleJsonCast;
use Admin\Core\Casts\LocalizedJsonCast;

trait HasAdminCasts
{
    /**
     * Enable temporary multicast features for regular cast methods
     *
     * @var  bool
     */
    private static $withMultiCast = false;

    public function temporaryCastType($key, $type, $cache, $callback)
    {
        $originalCast = $this->casts[$key] ?? null;

        $this->casts[$key] = $type;

        $value = $callback();

        if ( $cache == false ) {
            unset($this->classCastCache[$key]);
        }

        $this->casts[$key] = $originalCast;

        return $value;
    }

    /**
     * Returns uncached attribute value.
     *
     * @param  mixed $key
     * @return void
     */
    public function castAttributeUncached($key)
    {
        return $this->temporaryCastType($key, $this->casts[$key], false, function() use ($key) {
            return $this->castAttribute($key, $this->attributes[$key]);
        });
    }

    /**
     * Support for multi attribute casting.
     *
     * @param  string  $key
     * @param  string  $value
     * @param  string  $type
     *
     * @return  mixed
     */
    public function getMultyCastAttribute($key, $value, $type, $cache)
    {
        return $this->temporaryCastType($key, $type, $cache, function() use ($key, $value) {
            return $this->castAttribute($key, $value);
        });
    }

    /**
     * Support for multi attribute cast in backward order.
     * This logic is copied from laravel.
     *
     * @param  string  $key
     * @param  string  $value
     * @param  string  $type
     *
     * @return  mixed
     */
    public function setMultyCastAttribute($key, $value, $type)
    {
        return $this->temporaryCastType($key, $type, true, function() use ($key, $value) {
            if ($this->isEnumCastable($key)) {
                $this->setEnumCastableAttribute($key, $value);

                return $this->attributes[$key];
            } else if ($this->isClassCastable($key)) {
                $this->setClassCastableAttribute($key, $value);

                return $this->attributes[$key];
            }

            if (! is_null($value) && $this->isJsonCastable($key)) {
                $value = $this->castAttributeAsJson($key, $value);
            }

            // If this attribute contains a JSON ->, we'll set the proper value in the
            // attribute's underlying array. This takes care of properly nesting an
            // attribute in the array's value in the case of deeply nested items.
            if (str_contains($key, '->')) {
                $this->fillJsonAttribute($key, $value);

                return $this->attributes[$key];
            }

            if (! is_null($value) && $this->isEncryptedCastable($key)) {
                $value = $this->castAttributeAsEncryptedString($key, $value);
            }

            return $value;
        });
    }

    /**
     * Add multy cast attribute
     *
     * @param  string  $key
     * @param  string  $cast
     */
    public function addMultiCast($key, $cast)
    {
        $multiCastClass = AdminMultiCast::class.':';

        $casts = $this->getMultiCasts($key);

        $casts[] = $cast;

        $casts = array_map(function($cast){
            return $cast.(str_ends_with($cast, ':') ? '' : ',');
        }, $casts);

        //Add multicastp prefix only if needed.
        $this->casts[$key] = (count($casts) >= 2 ? $multiCastClass : '').rtrim(implode('', $casts), ',');

        return $this;
    }

    private function getMultiCasts($key)
    {
        $multiCastClass = AdminMultiCast::class.':';

        return array_key_exists($key, $this->casts)
                ? explode(',', str_replace_first($multiCastClass, '', $this->casts[$key]))
                : [];
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param  string  $key
     * @param  array|string|null  $types
     * @return bool
     */
    public function hasCast($key, $types = null)
    {
        if ( self::$withMultiCast ) {
            return $this->hasMutliCast($key, $types);
        }

        return parent::hasCast($key, $types);
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param  string  $key
     * @param  array|string|null  $types
     * @return bool
     */
    public function hasMutliCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            if ( $types ) {
                foreach ($this->getMultiCasts($key) as $castType) {
                    if ( in_array($castType, (array) $types, true) ){
                        return true;
                    }
                }
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Enable temporary multicast features for regular cast methods
     *
     * @var  bool
     */
    public function withMultiCast($callback)
    {
        self::$withMultiCast = true;

        $callback();

        self::$withMultiCast = false;
    }

    /**
     * Set date fields.
     *
     * @return void
     */
    protected function makeDateable()
    {
        $columns = [
            'published_at'
        ];

        //Laravel <=9
        if ( property_exists($this, 'dates') ) {
            $this->dates = array_unique(array_merge($this->dates, $columns));
        }

        //Laravel 10+
        else {
            foreach ($columns as $key) {
                $this->casts[$key] = 'datetime';
            }
        }
    }

    /**
     * Set selectbox field to automatic json format.
     *
     * @return void
     */
    protected function makeCastable()
    {
        foreach ($this->getFields() as $key => $field) {
            if ($this->hasFieldParam($key, 'locale')) {
                $this->addMultiCast($key, LocalizedJsonCast::class.':');
            }


            if ($this->isFieldType($key, 'geometry')) {
                $this->addMultiCast($key, GeometryCast::class);
            }

            if (
                $this->isFieldType($key, ['select', 'file', 'date', 'time'])
                && !$this->hasFieldParam($key, 'belongsToMany')
                && $this->hasFieldParam($key, 'multiple', true)
            ) {
                $this->addMultiCast($key, MultipleJsonCast::class.':');
            }

            //Encryption must be in this exact order, after all multiplies will be performed.
            if ($this->hasFieldParam($key, 'encrypted')) {
                //Custom encryption types fields
                if ( ($encryptedValue = $this->getFieldParam($key, 'encrypted')) && is_string($encryptedValue) ){
                    $this->addMultiCast($key, 'encrypted:'.$encryptedValue);
                }

                //Suport for array/json casts
                else if ( ($this->casts[$key] ?? '') === 'json' ) {
                    $this->addMultiCast($key, 'encrypted:array');
                } else {
                    $this->addMultiCast($key, 'encrypted');
                }
            }

            if ( $this->isFieldType($key, ['file']) ) {
                $this->addMultiCast($key, AdminFileCast::class);
            } else if ( $this->isFieldType($key, ['time', 'date', 'datetime', 'timestamp']) ) {
                $this->addMultiCast($key, DateableCast::class);
            } else if ( $this->isFieldType($key, ['editor', 'longeditor']) ) {
                $this->addMultiCast($key, EditorCast::class);
            }

            //Add cast attribute for fields with multiple select
            if ( $this->isFieldType($key, 'json') ) {
                $this->addMultiCast($key, 'json');
            } elseif ($this->isFieldType($key, 'checkbox')) {
                $this->addMultiCast($key, 'boolean');
            } elseif ($this->isFieldType($key, 'integer') || $this->hasFieldParam($key, 'belongsTo')) {
                $this->addMultiCast($key, 'integer');
            } elseif ($this->isFieldType($key, 'decimal')) {
                $this->addMultiCast($key, 'float');
            } elseif ( $this->isFieldType($key, 'gutenberg') ) {
                $this->addMultiCast($key, GutenbergCast::class);
            }
        }

        //Casts foreign keys
        if (is_array($relations = $this->getForeignColumn())) {
            foreach ($relations as $key) {
                $this->casts[$key] = 'integer';
            }
        }

        //Add cast into localized slug column
        if ( $this->hasLocalizedSlug() ){
            $this->casts['slug'] = LocalizedJsonCast::class;
        }

        //Publishable state
        if ($this->publishableState == true){
            $this->casts['published_state'] = 'json';
        }
    }
}