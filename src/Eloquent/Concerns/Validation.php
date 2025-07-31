<?php

namespace Admin\Core\Eloquent\Concerns;

use Admin\Core\Fields\FieldsValidator;
use Admin\Core\Fields\Validation\FileMutator;
use Admin\Exceptions\ValidationException;
use Fields;
use Localization;
use Validator;

trait Validation
{
    /**
     * Makes properties keys and values from array to string format.
     *
     * @param  array  $field
     * @return array
     */
    public function fieldToString(array $field)
    {
        $data = [];

        foreach ($field as $key => $value) {
            if ($value === true) {
                $data[] = $key;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $data[] = $key.':'.$item;
                }
            } elseif (is_object($value)) {
                $data[] = $value;
            } elseif ($value !== false && ($is_string = (is_string($value) || is_numeric($value)))) {
                $data[] = $is_string ? $key.':'.$value : $key;
            }
        }

        return $data;
    }

    /**
     * Removes admin properties in field from request.
     *
     * @param  array  $field
     * @return array
     */
    protected function removeAdminProperties($field)
    {
        //Remove admin columns
        foreach (Fields::getAttributes() as $key) {
            unset($field[$key]);
        }

        return $this->fieldToString($field);
    }

    /**
     * Remove uneccessary parameters from fields.
     *
     * @param  string  $key
     * @param  array  &$field
     * @return void
     */
    private function removeMultiFields(string $key, &$field)
    {
        if ($this->isFieldType($key, 'file') || $this->isFieldType($key, ['date', 'time'])) {
            //If is multiple file uploading
            if ($this->hasFieldParam($key, ['multiple', 'multirows'], true)) {
                foreach (['multiple', 'multirows', 'array'] as $param) {
                    if (array_key_exists($param, $field)) {
                        unset($field[$param]);
                    }
                }
            }
        }
    }

    /**
     * Returns validation rules of model.
     *
     * @param  Admin\Core\Eloquent\AdminModel|null  $row
     * @return array
     */
    public function getValidationRules($row = null)
    {
        $fields = $this->getFields($row, true);

        $data = [];

        // Add foreign keys to validation rules
        foreach ($this->getForeignColumn() ?: [] as $table => $column) {
            if ( ! isset($data[$column]) ) {
                $data[$column] = ['integer', 'nullable'];
            }
        }

        if ( method_exists($this, 'getDefaultLanguage') ) {
            $defaultLanguage = $this->getDefaultLanguage() ?: Localization::getDefaultLanguage();
        } else {
            $defaultLanguage = Localization::getFirstLanguage();
        }

        foreach ($fields as $key => $field) {
            $origKey = $key;

            $this->removeMultiFields($key, $field);

            //If is available default locale, then set default key name, if
            //language is not available, then apply for all langs...
            if ($hasLocale = $this->hasFieldParam($origKey, 'locale', true)) {
                $key = $defaultLanguage
                            ? $origKey.'.'.$defaultLanguage->slug
                            : $origKey.'.*';
            }

            //Add multiple validation support for files
            if (
                ($isMultiple = $this->hasFieldParam($origKey, 'array', true))
                && $this->isFieldType($origKey, ['file', 'date', 'time'])
            ) {
                $key = $key.'.*';
            }

            //Remove max check for multiple array fields.
            if ( $isMultiple && isset($field['max']) ){
                unset($field['max']);
            }

            //If field is not required
            if (! $this->hasFieldParam($origKey, 'required', true)) {
                $field['nullable'] = true;
            }

            //If is existing row is file type and required file already exists
            if ($row
                && $this->hasFieldParam($origKey, 'required', true)
                && $this->isFieldType($origKey, 'file')
                && ! empty($row->getAttribute($origKey))
            ) {
                $field['required'] = false;
            }

            //Removes admin properties in field from request
            $data[$key] = $this->removeAdminProperties($field);

            //If field has locales, then clone rules for specific locale
            if ($hasLocale) {
                foreach (Localization::getLanguages() as $lang) {
                    if ($lang->getKey() != $defaultLanguage->getKey()) {
                        $langRules = array_unique(array_merge($data[$key], ['nullable']));

                        //Remove required rule for other languages
                        $langRules = $this->removeRequiredProperties($langRules);

                        //Apply also for multiple files support
                        $field_key = $isMultiple
                                        ? $origKey.'.'.$lang->slug.'.*'
                                        : $origKey.'.'.$lang->slug;

                        $data[$field_key] = $langRules;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Remove all properties from given array
     *
     * @param  array  $rules
     * @param  array|null  $attributes
     * @return  array
     */
    private function removeRequiredProperties($rules, $attributes = null)
    {
        $attributes = is_null($attributes) ? FileMutator::$requiredAttributes : null;

        foreach ($rules as $k => $key) {
            foreach ($attributes as $attribute) {
                $attributeLength = strlen($attribute);

                if ( $key === $attribute || substr($key, 0, $attributeLength + 1) === $attribute.':' ) {
                    unset($rules[$k]);
                    break;
                }
            }
        }

        return array_values($rules);
    }

    /**
     * Build request with admin mutators.
     *
     * @param  array  $fields
     * @return array
     */
    public function muttatorsResponse($requestData, $fields, $rules = null, $isAdmin = null)
    {
        $request = new \Admin\Requests\DataRequest($requestData);
        $request->setIsAdmin($isAdmin);
        $request->applyMutators($this, $fields, $rules);

        $data = $request->allWithMutators()[0];

        request()->merge($data);

        return $data;
    }

    /**
     * Validate incoming request.
     *
     * @param  AdminModel $row
     * @return bool
     */
    public function scopeValidateRequest($query, array $fields = null, array $except = null, $mutators = true, $row = null)
    {
        //If row exists
        if (! $row && $this->exists) {
            $row = $this;
        }

        $rules = $this->getValidationRules($row);

        $request = request();
        $requestData = $request->all();

        $only = [];
        $replace = [];
        $add = [];

        //Custom properties
        if (is_array($fields)) {
            //Filtrate which fields will be validated
            foreach ($fields as $key => $field) {
                //If key from model are available, then only this fields will be allowed in validation
                if (is_numeric($key) && is_string($field) && $this->getField($field)) {
                    $only[] = $field;
                }

                //If field has also attributes to validation, then exists validation rules will be replaced
                elseif (! is_numeric($key)) {
                    if ($this->getField($key)) {
                        $replace[$key] = $field;
                    } else {
                        $add[$key] = $field;
                    }
                }
            }

            //Allow only existing fields
            if (count($only) > 0) {
                $rules = array_intersect_key($rules, array_flip($only));
            }

            //Add rules
            foreach ($add as $key => $value) {
                $rules[$key] = $value;
            }

            //Replace rules
            foreach ($replace as $key => $value) {
                $rules[$key] = $value;
            }
        }

        //Remove unnecesary fields
        if (is_array($except)) {
            $rules = array_diff_key($rules, array_flip($except));
        }

        $validator = Validator::make($requestData, $rules);

        if ($validator->fails()) {
            throw new ValidationException(
                (new FieldsValidator($this, $request))->buildFailedValidationResponse($validator)
            );
        }

        //Modify request data with admin mutators
        if ($mutators == true) {
            return $this->muttatorsResponse($requestData, count($only) > 0 ? $only : null, $rules);
        }
    }

    public function scopeValidator($query, $request = null)
    {
        $validator = new FieldsValidator($this, $request ?: request());

        return $validator;
    }
}
