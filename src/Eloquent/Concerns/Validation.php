<?php

namespace Admin\Core\Eloquent\Concerns;

use Fields;
use Validator;
use Localization;
use Admin\Exceptions\ValidationException;

trait Validation
{
    /**
     * Makes properties keys and values from array to string format.
     *
     * @param  array  $field
     * @return array
     */
    protected function fieldToString(array $field)
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
        $fields = $this->getFields($row);

        $data = [];

        $default_language = Localization::getDefaultLanguage();

        foreach ($fields as $key => $field) {
            $orig_key = $key;

            $this->removeMultiFields($key, $field);

            //If is available default locale, then set default key name, if
            //language is not available, then apply for all langs...
            if ($has_locale = $this->hasFieldParam($orig_key, 'locale')) {
                if ($default_language) {
                    $key = $orig_key.'.'.$default_language->slug;
                } else {
                    $key = $orig_key.'.*';
                }
            }

            //Add multiple validation support for files
            if (
                $is_multiple = $this->hasFieldParam($orig_key, 'array', true)
                && $this->isFieldType($key, ['file', 'date', 'time'])
            ) {
                $key = $key.'.*';
            }

            //If field is not required
            if (! $this->hasFieldParam($orig_key, 'required')) {
                $field['nullable'] = true;
            }

            //If is existing row is file type and required file already exists
            if ($row
                && ! empty($row[$orig_key])
                && $this->hasFieldParam($orig_key, 'required')
                && $this->isFieldType($orig_key, 'file')
            ) {
                $field['required'] = false;
            }

            //Removes admin properties in field from request
            $data[$key] = $this->removeAdminProperties($field);

            //If field has locales, then clone rules for specific locale
            if ($has_locale) {
                foreach (Localization::getLanguages() as $lang) {
                    if ($lang->getKey() != $default_language->getKey()) {
                        $lang_rules = array_unique(array_merge($data[$key], ['nullable']));

                        //Remove required rule for other languages
                        if (($k = array_search('required', $lang_rules)) !== false) {
                            unset($lang_rules[$k]);
                        }

                        //Apply also for multiple files support
                        $field_key = $is_multiple
                                        ? $orig_key.'.'.$lang->slug.'.*'
                                        : $orig_key.'.'.$lang->slug;

                        $data[$field_key] = $lang_rules;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Returns error response after wrong validation.
     *
     * @param  Illuminate\Validation\Validator  $validator
     * @return Illuminate\Http\Response
     */
    private function buildFailedValidationResponse($validator)
    {
        //If is ajax request
        if (request()->expectsJson()) {
            return response()->json($validator->errors(), 422);
        }

        return redirect(url()->previous())->withErrors($validator)->withInput();
    }

    /**
     * Build request with admin mutators.
     *
     * @param  array  $fields
     * @return array
     */
    protected function muttatorsResponse($fields)
    {
        $request = new \Admin\Requests\DataRequest(request()->all());

        $request->applyMutators($this, $fields);

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

        $validator = Validator::make(request()->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($this->buildFailedValidationResponse($validator));
        }

        //Modify request data with admin mutators
        if ($mutators == true) {
            return $this->muttatorsResponse(count($only) > 0 ? $only : null);
        }
    }
}
