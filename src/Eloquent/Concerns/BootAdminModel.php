<?php

namespace Admin\Core\Eloquent\Concerns;

trait BootAdminModel
{
    /*
     * Closures with properties setters
     */
    private $adminCachable = [];

    /*
     * Which admin properties should be cachced through all admin models
     */
    protected $cacheProperties = ['fillable', 'dates', 'casts', 'hidden'];

    /*
     * Cache booted static models
     */
    private static $cacheModelProperties = [];

    /**
     * Save cachable fields properties which will be booted on model boot state
     *
     * @param  closure  $closure
     * @return void
     */
    public function cachableFieldsProperties(\Closure $closure)
    {
        $this->adminCachable[] = $closure;
    }

    /*
     * Boot cachable properties first time.
     * Generate all required laravel properties as hidden, fillable, visible etc... then cache this values
     * for given moodel, and next time when model will be initialized again, this properties will be received from cache
     */
    public function bootCachableProperties()
    {
        $cacheKey = $this->getFieldsCacheModelKey();

        $cachedModels = static::$cacheModelProperties;

        //Check if model has been cached into admin cache
        if ( !array_key_exists($cacheKey, $cachedModels) ) {
            $cachedProperties = [];

            //Boot all saved callbacks
            foreach ($this->adminCachable as $callback) {
                $callback();
            }

            //Save booted properties
            foreach ($this->cacheProperties as $key) {
                if ( property_exists($this, $key) ){
                    $cachedProperties[$key] = $this->{$key};
                }
            }

            static::$cacheModelProperties[$cacheKey] = $cachedProperties;
        }

        //Set all admin model properties from cache
        foreach (static::$cacheModelProperties[$cacheKey] as $key => $property) {
            $this->{$key} = $property;
        }
    }
}
