<?php

namespace Admin\Core\Helpers;

use Admin\Core\Contracts\AdminEvents;
use Admin\Core\Contracts\DataStore;
use Admin\Core\Contracts\HasConfig;
use Admin\Core\Contracts\HasModules;
use Admin\Core\Eloquent\AdminModel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Storage;
use Exception;

class AdminCore
{
    use DataStore,
        AdminEvents,
        HasModules,
        HasConfig;

    /*
     * Filesystem provider
     */
    protected $files;

    public function __construct()
    {
        $this->files = new Filesystem;
    }

    public function getStorage()
    {
        return $this->getDiskByName('crudadmin');
    }

    public function getUploadsStorageName($private = false)
    {
        return $private === true
            ? 'crudadmin.uploads_private'
            : 'crudadmin.uploads';
    }

    public function getUploadsStorage($private = false, $disk = null)
    {
        return $this->getDiskByName(
            $this->getUploadsStorageName($private)
        );
    }

    public function getDiskByName($name)
    {
        return $this->cache('admin.storage.'.$name, function() use ($name) {
            try {
                return Storage::disk($name);
            } catch (Exception $e){
                //.. storage is not working, eg. mount failed.
            }
        });
    }

    /*
     * Returns if is admin models are loaded
     */
    public function isLoaded()
    {
        return $this->get('booted', false);
    }

    /*
     * Returns if is admin models are loaded
     */
    public function isMigrating()
    {
        return $this->get('migrating', false);
    }

    /**
     * Returns all admin models classes in registration order.
     * @return array
     */
    public function getAdminModels()
    {
        if ($this->isLoaded() === false) {
            $this->boot();
        }

        return $this->get('models', []);
    }

    /**
     * Returns all booted models list.
     * @return array
     */
    public function getAdminModelNamespaces()
    {
        if ($this->isLoaded() === false) {
            $this->boot();
        }

        return $this->get('namespaces', []);
    }

    /**
     * Return model by table name.
     * @param  string $tableName
     * @return AdminModel
     */
    public function getModelByTable($tableName)
    {
        $models = $this->getAdminModels();

        //Search specific order
        foreach ($models as $model) {
            //Return cloned booted class instance
            if ($model->getAdminTable() == $tableName) {
                return $model->newInstance();
            }
        }
    }

    /**
     * Returns model object by model class name.
     * @param  string $model
     * @return object/null
     */
    public function getModel($model, $namespace = false)
    {
        $namespaces = $this->getAdminModelNamespaces();

        $model = strtolower($model);

        foreach ($namespaces as $path) {
            $model_name = $this->toModelBaseName($path);

            if ($model_name == $model) {
                if ( $namespace === true ){
                    return $path;
                }

                return new $path;
            }
        }
    }

    /**
     * Boot admin interface.
     * @return array
     */
    public function boot()
    {
        //Register all models from namespaces
        foreach ($this->getNamespacesList('models') as $basepath => $namespace) {
            $this->registerAdminModels($basepath, $namespace);
        }

        //All admin models has been properly loaded
        $this->set('booted', true);

        //Set config cache
        $this->cacheConfig();

        //Returns namespaces list
        return $this->get('namespaces', []);
    }

    /**
     * Register all admin models from given path.
     * @param  string $basepath
     * @param  string $namespace
     * @return void
     */
    public function registerAdminModels($basepath, $namespace)
    {
        //If namespace has been already loaded
        if (in_array($namespace, $this->get('booted_namespaces', []))) {
            return;
        }

        $files = $this->getNamespaceFiles($basepath);

        foreach ($files as $key => $file) {
            $model = $this->fromFilePathToNamespace((string) $file, $basepath, $namespace);

            //If is not same class with filename
            if (! class_exists($model)) {
                continue;
            }

            $this->registerModel($model, false);
        }

        //Set actual namespace as booted
        $this->push('booted_namespaces', $namespace);

        $this->sortModels();
    }

    /**
     * Returns all files of namespace path.
     * @param  string $path
     *
     * @return array
     */
    public function getNamespaceFiles($basepath)
    {
        //Get all files from folder recursive
        if (substr($basepath, -1) == '*') {
            $basepath = trim_end(trim_end($basepath, '*'), '/');

            //Check if model path exists
            if (file_exists($basepath)) {
                $files = array_map(function ($item) {
                    return $item->getPathName();
                }, $this->files->allFiles($basepath));
            } else {
                $files = [];
            }
        }

        //Get files from actual folder
        else {
            $files = file_exists($basepath) ? $this->files->files($basepath) : [];
        }

        return array_unique($files);
    }

    /**
     * Returns all available model namespaces.
     * @return array
     */
    public function getNamespacesList($configKey)
    {
        //Add default app namespace
        $paths = [];

        //Register paths from config
        foreach (config('admin.'.$configKey, []) as $namespace => $path) {
            //If is not set namespace, then use default namespace generated by path
            if (! is_string($namespace)) {
                $namespace = $this->getNamespaceByPath($path);
            }

            $path = $this->getModelsPath($path);

            if ( $path != app_path() )
                $path = $this->makeRecursivePath($path);

            //Register path if does not exists
            if (! in_array($path, $paths)) {
                $paths[$path] = $namespace;
            }
        }

        //Merge default paths, paths from config, and path from 3rd extension in correct order for overiding.
        return $paths;
    }

    /**
     * Return absulute basename path to directory with admin models.
     * @param string $path
     * @return string
     */
    private function getModelsPath($path)
    {
        $path = trim_end($path, '/');

        //If is not absolute app basepath
        if (
            substr($path, 0, strlen(base_path())) !== base_path()
            && file_exists(base_path(dirname($path)))
        ) {
            $path = base_path($path);
        }

        return $path;
    }

    /*
     * Make from path recursive path
     */
    private function makeRecursivePath($path)
    {
        $path = trim_end($path, '*');
        $path = trim_end($path, '/');

        return $path.'/*';
    }

    /**
     * Raplaces file path to file namespace.
     * @param  string $path
     * @param  string $source
     * @param  string $namespace
     * @return string
     */
    public function fromFilePathToNamespace($path, $basepath, $namespace)
    {
        $basepath = trim_end($basepath, '*');
        $basepath = str_replace('/', '\\', $basepath);

        $path = str_replace('/', '\\', $path);
        $path = str_replace_first($basepath, '', $path);
        $path = str_replace('.php', '', $path);
        $path = trim($path, '\\');

        return $namespace.'\\'.$path;
    }

    /*
     * Return root namespace by path name
     */
    private function getNamespaceByPath($path)
    {
        $path = trim_end($path, '*');
        $path = str_replace('/', '\\', $path);
        $path = array_filter(explode('\\', $path));
        $path = array_map(function ($item) {
            return ucfirst($item);
        }, $path);

        return implode('\\', $path);
    }

    /*
     * Sorting models by migration date
     */
    private function sortModels()
    {
        $namespaces = $this->get('namespaces', []);
        $models = $this->get('models', []);

        //Sorting according to migration date
        ksort($namespaces);
        ksort($models);

        $this->set('namespaces', $namespaces);
        $this->set('models', $models);
    }

    /**
     * Register and cache admin model.
     * @param  string  $namespace
     * @param  bool $sort
     * @return void
     */
    public function registerModel($namespaces, $sort = true)
    {
        foreach (array_wrap($namespaces) as $namespace) {
            //Check if model with same class name already exists, if yes, skip it...
            if ( $this->hasAdminModel(class_basename($namespace)) ) {
                continue;
            }

            //Checks if is admin model without initializing of class
            if (! is_a($namespace, AdminModel::class, true)) {
                continue;
            }

            $model = new $namespace;

            //Check if is valid admin model with correct migration date
            if (! $this->isAdminModel($model)) {
                continue;
            }

            //If model with migration date already exists
            if (array_key_exists($model->getMigrationDate(), $this->get('namespaces', []))) {
                //If duplicite model which is actual loaded is extented parent of loaded child, then just skip adding this model
                if ($this->get('models', [])[$model->getMigrationDate()] instanceof $model) {
                    continue;
                }

                $error = 'In '.__CLASS__.' line '.__LINE__.': Model name '.$model->getAdminTable().' has migration date '.$model->getMigrationDate().' wich already exists in other model '.$this->get('models', [])[$model->getMigrationDate()]->getAdminTable().'.';

                Log::error($error);
                abort(500, $error);
            }

            //Save model namespace into array
            $this->push('namespaces', $namespace, $model->getMigrationDate());

            //Save model into array
            $this->push('models', $model, $model->getMigrationDate());

            //Save modelname
            $this->push('modelnames', $model, $this->toModelBaseName($namespace));
        }

        //Sorting models by migration date
        if ($sort == true) {
            $this->sortModels();
        }
    }

    /**
     * Checks if is correct type of admin model instance.
     * @param  AdminModel  $model
     * @return bool
     */
    public function isAdminModel($model)
    {
        return $model instanceof AdminModel && $model->getMigrationDate();
    }

    /**
     * Checks if model exists in admin models list by class name.
     * @param  string  $model
     * @return bool
     */
    public function hasAdminModel($model)
    {
        $model = strtolower($model);

        $modelnames = $this->get('modelnames', []);

        return array_key_exists($model, $modelnames);
    }

    /**
     * Returns lowercase model class name.
     * @param  string $path
     * @return string
     */
    public function toModelBaseName($path)
    {
        return strtolower(class_basename($path));
    }
}
