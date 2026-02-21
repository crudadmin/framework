<?php

namespace Admin\Core\Eloquent\Concerns;

use AdminCore;
use Admin\Core\Eloquent\AdminPivot as FrameworkAdminPivot;
use Cache;
use Str;

trait RelationsMapBuilder
{
    private static $bootingRelations = [];

    private static $relationFieldsTree = [
        'key' => null,
        'models' => [],
    ];

    /*
     * Relations cache turned off for now
     */
    protected function hasRelationsCache()
    {
        return false;
    }

    protected function bootRelationships()
    {
        // Support masking relations from original model
        if ( $this instanceof \Admin\Eloquent\AdminView ){
            $tree = AdminCore::getModelByTable($this->getTable())->getCachedRelationsTree();
        } else {
            $tree = $this->getCachedRelationsTree();
        }

        foreach ($tree as $key => $callback) {
            $this->resolveRelationUsing($key, function($model) use ($callback) {
                //Get cached array version, or unpack closure
                $run = is_array($callback)
                        ? $callback
                        : $callback($model);

                //Parse methods
                foreach ($run as $method => $params) {
                    foreach ($params as $k => $param) {
                        //Boot model class
                        if ( is_string($param) && $param[0] == '$' ){
                            $params[$k] = AdminCore::getModelByTable(substr($param, 1));
                        }
                    }

                    $model = $model->{$method}(...$params);
                }

                return $model;
            });
        }
    }

    /**
     * @overriden
     * Create a new model instance for a related model.
     *
     * @param  string  $class
     * @return mixed
     */
    protected function newRelatedInstance($class)
    {
        if ( !is_object($class) ) {
            $class = new $class;
        }

        return tap($class, function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->connection);
            }
        });
    }

    public function getCachedRelationsTree()
    {
        //TODO cache in the future:
        if ( $this->hasRelationsCache() ) {
            $cacheKey = 'relations.'.$this->getFieldsCacheModelKey();

            //TODO: split cache into one single TREE, not each model to have own cache file.
            //This may slow down cache process. But cache is turned off for now.
            return Cache::rememberForever($cacheKey, function(){
                return $this->getRelationsTree();
            });
        }

        //APP Runtime relations
        else {
            return $this->getRelationsTree();
        }
    }

    public function getRelationsTree()
    {
        //Fix infinite loop
        if ( (static::$bootingRelations[static::class] ?? false) === true ) {
            return [];
        }

        static::$bootingRelations[static::class] = true;

        $tree = [];

        $adminFieldsTree = $this->getAdminFieldsTree();

        foreach ([
            $this->getChildrenModelsRelations($adminFieldsTree),
            $this->getBelongsToFieldRelations($adminFieldsTree),
            $this->getBelongsManyToFieldRelations($adminFieldsTree),
        ] as $modelTree) {
            $modelTree = $this->serializeRelationsForCache($modelTree);

            $tree = array_merge(
                $tree,
                $this->prepareCasesVariants($modelTree)
            );

            $tree = array_merge(
                $tree,
                $this->prepareUpperLowerCasesVariants($tree)
            );
        }

        ksort($tree);

        static::$bootingRelations[static::class] = false;

        return $tree;
    }

    /**
     * Build fields tree for all admin models
     * Rebuilds it when model list changes
     * This prevents infinite loop when model is other models which boots previous model
     *
     * @return void
     */
    private function getAdminFieldsTree()
    {
        $models = AdminCore::getAdminModels();
        $cacheKey = implode(';', array_keys($models));

        // Return cached tree if it exists
        if ( static::$relationFieldsTree['key'] === $cacheKey ) {
            return static::$relationFieldsTree['models'];
        }

        static::$relationFieldsTree['key'] = $cacheKey;

        foreach ($models as $model) {
            static::$relationFieldsTree['models'][$model->getTable()] = [
                'model' => $model,
                'fields' => $model->getFields(),
            ];
        }

        return static::$relationFieldsTree['models'];
    }

    private function serializeRelationsForCache($tree)
    {
        //Serialize relationship from closure into array
        if ( $this->hasRelationsCache() ) {
            foreach ($tree as $key => $callback) {
                $tree[$key] = $callback($this);
            }
        }

        return $tree;
    }

    /**
     * Create all variants of forms
     * eg: ->parentRelation, ->parent_relation
     *
     * @param  array  $tree
     *
     * @return  array
     */
    private function prepareCasesVariants($tree)
    {
        $variants = [];

        foreach ($tree as $key => $relation) {
            //Original forms
            $variants[$key] = $relation;

            //Snake: payment_method
            $variants[Str::snake($key)] = $relation;

            //Studly case: PaymentMethod
            $variants[Str::studly($key)] = $relation;
        }

        return $variants;
    }

    /**
     * Create all variants of uppercase/lowercase forms
     *
     * @param  array  $tree
     *
     * @return  array
     */
    private function prepareUpperLowerCasesVariants($tree)
    {
        $variants = [];

        foreach ($tree as $key => $relation) {
            //Full lower case
            $variants[Str::lower($key)] = $relation;

            //First letter upper
            $variants[Str::ucfirst($key)] = $relation;

            //First letter lower
            $variants[Str::lcfirst($key)] = $relation;
        }

        return $variants;
    }

    //Todo if category call category belongsToModel
    private function getChildrenModelsRelations($adminFieldsTree)
    {
        $tree = [];

        $classBaseName = class_basename($this);
        $currentBelongsToModel = $this->getBelongsToRelation(true);

        foreach ($adminFieldsTree as $item) {
            $relationModel = $item['model'];
            $relationBaseName = class_basename($relationModel);
            $belongsToModel = $relationModel->getBelongsToRelation(true);

            if ( in_array($classBaseName, $belongsToModel) ) {
                $forms = $relationModel->getRelationForms(
                    $this,
                    function($model) use ($relationModel) {
                        //Returns single child support
                        if ( $relationModel->maximum == 1 ){
                            return [
                                'hasOne' => [
                                    $relationModel::class,
                                    $model->getForeignColumn($relationModel->getTable())
                                ]
                            ];
                        }

                        //Support for recursive BelongsToModel in oposite direction
                        //When child is calling parent in singular mode.
                        if ( $model::class == $relationModel::class ){
                            return [
                                'belongsTo' => [
                                    $relationModel::class,
                                    $model->getForeignColumn($relationModel->getTable())
                                ]
                            ];
                        }

                        return [
                            'hasMany' => [
                                $relationModel::class,
                                $relationModel->getForeignColumn($model->getTable())
                            ]
                        ];
                    },
                    function($model) use ($relationModel) {
                        return [
                            'hasMany' => [
                                $relationModel::class,
                                $relationModel->getForeignColumn($model->getTable()),
                            ]
                        ];
                    }
                );

                $tree = array_merge($tree, $forms);
            }

            // Reverse call for belongsToModel relation. Call parent from child.
            if ( in_array($relationBaseName, $currentBelongsToModel) ) {
                $forms = $relationModel->getRelationForms(
                    $this,
                    function($model) use ($relationModel) {
                        return [
                            'belongsTo' => [
                                $relationModel::class,
                                $model->getForeignColumn($relationModel->getTable())
                            ]
                        ];
                    }
                );

                $tree = array_merge($tree, $forms);
            }
        }

        return $tree;
    }

    private function getBelongsToFieldRelations($adminFieldsTree)
    {
        $tree = [];

        foreach ($this->getFields() as $fieldKey => $field) {
            if ( isset($field['belongsTo']) ){
                $properties = $this->getRelationProperty($fieldKey, 'belongsTo');


                $relation = function($model) use ($properties, $field) {
                    if ( ($field['hasOne'] ?? false) === true ) {
                        return [
                            'hasOne' => [
                                '$'.$properties[0], //Table relation class
                                $properties[2],
                                $properties[4],
                            ]
                        ];
                    }

                    return [
                        'belongsTo' => [
                            '$'.$properties[0], //Table relation class
                            $properties[4]
                        ]
                    ];
                };

                $relationName = Str::replaceLast('_id', '', $fieldKey);

                $tree[$relationName] = $relation;
            }
        }

        //Reverse belongsTo field relation
        foreach ($adminFieldsTree as $item) {
            $relationModel = $item['model'];
            $fields = $item['fields'];

            foreach ($fields as $fieldKey => $field) {
                if ( isset($field['belongsTo']) ) {
                    $properties = $relationModel->getRelationPropertyData($field, $fieldKey, 'belongsTo');

                    if ( $properties[0] == $this->getTable() ) {
                        $relation = function($model) use ($relationModel, $fieldKey) {
                            return [
                                'hasMany' => [
                                    $relationModel::class,
                                    $fieldKey,
                                    $relationModel->getKeyName(),
                                ]
                            ];
                        };

                        $pluralBasename = Str::plural(class_basename($relationModel));

                        //Full model name, eg: ->productsGallery
                        $tree[$pluralBasename] = $relation;

                        //Final model name, eg: ->gallery
                        $tree[implode('_', array_slice(explode('_', Str::snake($pluralBasename)), -1))] = $relation;
                    }
                }
            }
        }

        return $tree;
    }

    private function getBelongsManyToFieldRelations($adminFieldsTree)
    {
        $tree = [];

        //Reverse belongsToMany field relation
        foreach ($adminFieldsTree as $item) {
            $relationModel = $item['model'];
            $fields = $item['fields'];

            foreach ($fields as $fieldKey => $field) {
                if ( isset($field['belongsToMany']) ) {
                    $properties = $relationModel->getRelationProperty($fieldKey, 'belongsToMany');

                    if ( $properties[0] == $this->getTable() ) {
                        $relation = function($model) use ($relationModel, $properties) {
                            return [
                                'belongsToMany' => [
                                    $relationModel::class,
                                    $properties[3],
                                    $properties[7],
                                    $properties[6],
                                ]
                            ];
                        };

                        $pluralBasename = Str::plural(class_basename($relationModel));

                        $tree[$pluralBasename] = $relation;
                        $tree[Str::studly(implode('_', array_slice(explode('_', Str::snake($pluralBasename)), -1)))] = $relation;
                    }
                }
            }
        }

        //Own fields has priority between parent
        foreach ($this->getFields() as $fieldKey => $field) {
            if ( isset($field['belongsToMany']) ){
                $properties = $this->getRelationProperty($fieldKey, 'belongsToMany');

                $fieldRelationModel = AdminCore::getModelByTable($properties[0]);

                $relation = function($model) use ($fieldRelationModel, $properties) {
                    return [
                        'belongsToMany' => [
                            $fieldRelationModel::class,
                            $properties[3],
                            $properties[6],
                            $properties[7]
                        ],
                        'orderBy' => [
                            $properties[3].'.id', 'asc'
                        ],
                    ];
                };

                $pivotRelation = function($model) use ($fieldRelationModel, $properties) {
                    return [
                        'hasMany' => [
                            $this->getAdminPivotClass($properties),
                            $properties[6],
                            $properties[2],
                            $properties[7]
                        ],
                        'orderBy' => [
                            $properties[3].'.id', 'asc'
                        ],
                    ];
                };

                $tree[$fieldKey] = $relation;

                //Pivot support added
                $tree[$fieldKey.'Pivot'] = $pivotRelation;
            }
        }

        return $tree;
    }

    private function getAdminPivotClass($properties)
    {
        $localAdminPivot = \Admin\Eloquent\AdminPivot::class;
        $basePivotClass = class_exists($localAdminPivot) ? $localAdminPivot : FrameworkAdminPivot::class;
        $pivotClass = AdminCore::getModelByTable($properties[3]) ?: new $basePivotClass;

        $pivotClass->table = $properties[3];

        //Refresh fields for belongsTo cache relations fix
        if ( $pivotClass instanceof $localAdminPivot ) {
            $pivotClass->getFields(true);
            $pivotClass->bootRelationships();
        }

        return $pivotClass;
    }

    private function getRelationForms($parentModel, $relationSingular, $relationPlural = null)
    {
        $forms = [];

        $basename = class_basename($this);
        $basenameSnake = Str::snake(class_basename($this));

        $forms = [
            Str::plural($basename) => $relationPlural,
            Str::singular($basename) => $relationSingular,
            array_slice(explode('_', Str::snake($basename)), -1)[0] => $relationSingular,
        ];

        if ( $parentModel ) {
            $parentModelBasename = class_basename($parentModel);
            $parentModelBasenameSnake = Str::snake($parentModelBasename);

            $snakeForms = [
                Str::snake(Str::plural($parentModelBasename)).'_',
                Str::snake(Str::singular($parentModelBasename)).'_',
            ];

            //Support for recursive relationships
            if ( $this->getTable() == $parentModel->getTable() ){
                $snakeForms = array_map(function($item){
                    return implode('_', array_slice(explode('_', $item), 0, -2)).'_';
                }, $snakeForms);
            }

            foreach ($snakeForms as $name) {
                if ( Str::startsWith($basenameSnake, $name) ){
                    $snakeForm = Str::replaceFirst($name, '', $basenameSnake);

                    $forms[Str::singular($snakeForm)] = $relationSingular;
                    $forms[Str::plural($snakeForm)] = $relationPlural;
                }
            }
        }

        return array_filter($forms);
    }
}
