<?php

namespace Admin\Core\Fields;

use Admin\Core\Eloquent\AdminModel;
use Admin\Core\Fields\Mutations\FieldToArray;
use Admin\Core\Fields\Validation\FileMutator;
use Admin\Core\Requests\AdminModelRequest;
use Admin\Exceptions\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException as BaseValidationExpetion;
use Validator;

class FieldsValidator
{
    /**
     * Only this fields will be validated from given AdminModel
     *
     * @var  array
     */
    protected $useOnly = [];

    /**
     * Merge given fields into rules
     *
     * @var  array
     */
    protected $merge = [];

    /**
     * Which rules should be replaced
     *
     * @var  array
     */
    protected $replace = [];

    /**
     * HTTP request
     *
     * @var  Illuminate\Http\Request
     */
    protected $request;

    /**
     * AdminModel
     *
     * @var  Admin\Core\Eloquent\AdminModel
     */
    protected $model;

    /**
     * Whitelist additional fields than in existing request
     *
     * @var  array
     */
    protected $whitelistedKeys = [];

    protected $mutators = [
        FileMutator::class,
    ];

    /**
     * Constructor
     *
     * @param  AdminModel  $model
     * @param  Request|null  $request
     */
    public function __construct(AdminModel $model, Request $request = null)
    {
        $this->model = $model;

        $this->request = $request ?: request();
    }

    /**
     * Returns AdminModel
     *
     * @return  Admin\Core\Eloquent\AdminModel
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Returns Request
     *
     * @return  Illuminate\Http\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Validate only given fields
     * ->only(['firstname', 'lastname', 'password'])
     * ->only(MyRequest::class)
     *
     * @param  array|string  $onlyFields
     * @return  this
     */
    public function only($onlyFields)
    {
        //Allow only given fields from array set
        if ( is_array($onlyFields) ) {
            $useOnly = [];
            $merge = [];

            foreach ($onlyFields as $key => $value) {
                if ( is_numeric($key) ){
                    $useOnly[] = $value;
                } else {
                    $useOnly[] = $key;

                    $merge[$key] = $value;
                }
            }

            $this->useOnly = array_merge($this->useOnly, $useOnly);

            if ( count($merge) > 0 ) {
                $this->merge($merge);
            }
        }


        //If Request has been received
        else if ( is_string($onlyFields) ) {
            $this->use($onlyFields);
        }

        return $this;
    }

    /**
     * Merge given fields into actual fields
     * ->merge(['firstname' => 'required|max:10', 'lastname' => 'required'])
     * ->merge(MyRequest::class)
     *
     * @param  array  $mergeFields
     * @return  this
     */
    public function merge($mergeFields)
    {
        $this->merge = $this->mergeRules($this->merge, $mergeFields);

        return $this;
    }

    /**
     * Replace rules for another ones
     *
     * @param  array  $mergeFields
     * @return  array
     */
    public function replace($replaceFields)
    {
        $this->replace = $this->mergeRules($this->replace, $replaceFields);

        return $this;
    }

    /**
     * If array is given, return array
     * If laravel Request with custom rules is given, return his rules as array
     *
     * @return  array
     */
    private function mergeRules($rules, $newRules) : array
    {
        //Receive rules from given Request
        if ( is_string($newRules) ) {
            return $this->use($newRules);
        }

        foreach ($newRules as $key => $fieldRules) {
            $toMerge = (new FieldToArray)->update($fieldRules);

            $rules[$key] = array_unique(array_filter(array_merge(
                ($rules[$key] ?? null) ?: [],
                $this->getModel()->fieldToString($toMerge)
            )));
        }

        return $rules;
    }

    /**
     * Boot fields from request class
     *
     * @param  string  $classname
     *
     * @return  this
     */
    public function use($classname)
    {
        $class = is_object($classname) ? $classname : new $classname;

        //Check request authorization
        if ( method_exists($class, 'authorize') && $class->authorize() !== true ){
            abort(401);
        }

        $isAdminRequest = $class instanceof AdminModelRequest;

        //Use only fields
        if ( method_exists($class, 'only') && $isAdminRequest ){
            $this->only($class->only());
        }

        //Merge additional fields
        if ( method_exists($class, 'merge') && $isAdminRequest ){
            $this->merge($class->merge());
        }

        //Merge additional fields
        if ( method_exists($class, 'replace') && $isAdminRequest ){
            $this->replace($class->replace());
        }

        //Use only validation fields + merge additional
        if ( method_exists($class, 'rules') ){
            //Receive rules from given Request
            $requestRules = $class->rules();

            //Allow only data from given request
            $this->only(array_keys($requestRules));

            //Push and merge given fields with admin fields
            $this->merge($requestRules);
        }

        return $this;
    }

    /**
     * Returns error response after wrong validation.
     *
     * @param  Illuminate\Validation\Validator  $validator
     *
     * @return Illuminate\Http\Response
     */
    public function buildFailedValidationResponse($validator)
    {
        //If is ajax request
        if ($this->request->expectsJson()) {
            $error = BaseValidationExpetion::withMessages($validator->errors()->getMessages());

            throw $error;
        }

        return redirect(url()->previous())->withErrors($validator)->withInput();
    }

    private function whitelistUseOnlyKeys($rules)
    {
        $useOnly = array_values($this->useOnly);

        foreach ($rules as $key => $rule) {
            $keyList = explode('.', $key);
            $keyList = array_slice($keyList, 0, -1);
            $keyList = implode('.', $keyList);

            //Skip non allowed keys
            if ( !(in_array($key, $useOnly) || $keyList && in_array($keyList, $useOnly)) ) {
                unset($rules[$key]);
            }
        }

        return $rules;
    }

    /**
     * Build rules validator
     *
     * @return  void
     */
    public function getRules()
    {
        $model = $this->getModel();

        $rules = $model->getValidationRules($model);

        //Apply only given fields
        if ( count($this->useOnly) > 0 ) {
            $rules = $this->whitelistUseOnlyKeys($rules);
        }

        //Merge given fields into request
        $rules = $this->mergeRules($rules, $this->merge);

        //Replace rules fields
        $rules = array_merge($rules, $this->replace);

        //Additional mutation process
        $rules = $this->mutateRules($rules);

        return $rules;
    }

    public function mutateRules($rules, $mutators = null)
    {
        foreach ($mutators ?: $this->mutators as $mutator) {
            $mutator = new $mutator(
                $this->getModel(),
                $this->request,
            );

            foreach ($rules as $key => $attributes) {
                $rules[$key] = $mutator->mutateField(
                    $key,
                    $attributes,
                    $key
                );

                $this->whitelistedKeys = array_merge($this->whitelistedKeys, $mutator->whitelistKeys($key) ?: []);
            }
        }

        return $rules;
    }

    /**
     * Validate incoming request
     *
     * @return  this
     */
    public function validate()
    {
        $validator = Validator::make($this->request->all(), $this->getRules());

        if ($validator->fails()) {
            throw new ValidationException(
                $this->buildFailedValidationResponse($validator)
            );
        }

        return $this;
    }

    /**
     * Returns request data mutated with admin mutators
     *
     * @param  null|array  $whitelistedKeys
     *
     * @return  array
     */
    public function getData(array $whitelistedKeys = [])
    {
        $rules = $this->getRules();

        $dataKeys = array_unique(
            array_merge(
                array_keys($rules),
                $this->useOnly
            )
        );

        $requestKeys = $this->onlyFirstLevelKeys(
            array_unique(array_merge($dataKeys, $this->whitelistedKeys))
        );

        //TODO: this will upload multiple files times if ->get('xy') is used more than once
        $response = $this->getModel()->muttatorsResponse(
            $this->request->only($requestKeys), //we need pass only allowed data set
            $dataKeys,
            $rules,
            false
        );

        return array_intersect_key(
            $response,
            $this->onlyFirstLevelKeys(array_flip($whitelistedKeys ?: $dataKeys))
        );
    }

    /**
     * Returns request value
     *
     * @param  string  $key
     * @param  mixed  $default
     *
     * @return  mixed
     */
    public function get($key, $default = null)
    {
        $value = ($this->getData([$key])[$key] ?? null);

        return is_null($value) ? $default : $value;
    }

    /**
     * Takes all validated data and fills them into the the model
     *
     * @param  mixed $data
     * @return void
     */
    public function fill($data = [])
    {
        $data = array_merge($this->getData(), $data);

        $this->model->fill($data);

        return $this->model;
    }

    /**
     * Returns first level query parameters keys
     * from variant.xy.tralalla makes variant
     *
     * @param  array  $keys
     *
     * @return  array
     */
    private function onlyFirstLevelKeys($keys)
    {
        return array_map(function($key){
            return array_slice(explode('.', $key), 0, 1)[0];
        }, $keys);
    }

    /**
     * Pushes missing default values into incoming request
     */
    public function addDefaultValues()
    {
        foreach ($this->model->getFields() as $key => $field) {
            if (
                is_null(($field['default'] ?? null)) == false
                && $this->request->has($key) === false
            ) {
                $this->request->merge([
                    $key => $field['default'],
                ]);
            }
        }

        return $this;
    }
}
