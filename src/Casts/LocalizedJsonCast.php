<?php

namespace Admin\Core\Casts;

use AdminCore;
use Admin\Core\Casts\Concerns\MultiCast;
use Admin\Core\Casts\Concerns\UncachableCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Support\Collection;
use Localization;

class LocalizedJsonCast implements Castable
{
    public static function castUsing(array $arguments)
    {
        return new class($arguments) extends MultiCast implements UncachableCast
        {
            public function get($model, $key, $value, $attributes)
            {
                $localeValues = json_decode($value, true);

                //When Localized array response is forced
                if ( $this->isArrayResponse($model) ){
                    return collect($localeValues)->map(function($item) use ($model, $key, $attributes) {
                        return parent::get($model, $key, $item, $attributes);
                    });
                }

                $value = $this->getLocaleValue($localeValues);

                return parent::get($model, $key, $value, $attributes);
            }

            private function isArrayResponse($model)
            {
                return $model::$localizedResponseArray === true && $model->isLocalizedResponseLocalArray() === true;
            }

            /**
             * Return specific value in multi localization field by selected language
             * if translations are missing, returns default, or first available language.
             *
             * @param  mixed  $object
             * @param  string|null  $lang
             * @return mixed
             */
            private function getLocaleValue($object, $lang = null)
            {
                if ( ! $object ) {
                    return;
                }

                else if (! is_array($object)) {
                    return $object;
                }

                //If row has saved actual value
                foreach ($this->getLanguageSlugsByPriority($lang) as $slug) {
                    if (array_key_exists($slug, $object) && (! empty($object[$slug]) || $object[$slug] === 0)) {
                        return $object[$slug];
                    }
                }

                //Return first available translated value in admin
                foreach ($object as $value) {
                    if (!is_null($value)) {
                        return $value;
                    }
                }
            }

            /**
             * Returns selected language slug, or default to try
             *
             * @param  string  $lang
             *
             * @return  array
             */
            private function getLanguageSlugsByPriority($lang)
            {
                return AdminCore::cache('localized.value.'.($lang ?: Localization::getLocale() ?: 'default'), function() use ($lang) {
                    $selectedLanguageSlug = $lang ?: (Localization::get()->slug ?? null);

                    $slugs = [$selectedLanguageSlug, Localization::getDefaultLanguage()->slug];

                    return $slugs;
                });
            }

            public function set($model, $key, $value, $attributes)
            {
                // If passed value is JSON, decode it
                if ( $object = $this->tryDecodeStringAsJSONObject($value) ) {
                    $value = $object;
                }

                if ( is_array($value) || $value instanceof Collection ) {
                    return collect($value)->map(function($item) use ($model, $key, $attributes) {
                        return parent::set($model, $key, $item, $attributes);
                    })->filter(function($value){
                        return is_null($value) == false;
                    })->toJson();
                } else {
                    return parent::set($model, $key, $value, $attributes);
                }
            }

            /**
             * If string is received, try to decode it as JSON object
             *
             * @param  mixed $value
             * @return void
             */
            private function tryDecodeStringAsJSONObject($value){
                if (!is_string($value)) {
                    return false;
                }

                // Check json format
                if ( ($value[0] == '{' && $value[strlen($value) - 1] == '}') === false ) {
                    return false;
                }

                $decoded = json_decode($value, true);

                // If is object, return it
                if ( json_last_error() === JSON_ERROR_NONE && is_array($decoded) ) {
                    return $decoded;
                }
            }
        };

    }
}