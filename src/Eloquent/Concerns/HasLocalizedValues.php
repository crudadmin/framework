<?php

namespace Admin\Core\Eloquent\Concerns;

trait HasLocalizedValues
{
    /**
     * Returns localized values in ->toArray() response as array keys, or end translation values.
     * Usefull if we throw await in all API responses other languages, and return only specific one
     *
     * @var  bool
     */
    public static $localizedResponseArray = true;

    /**
     * Return end value insetad of localized array. This is usefull to disable arrays only in given model instance
     *
     * @var  bool
     */
    public $localizedResponseLocalArray = false;

    /**
     * Return localized array all the time
     * This property exists for rewriting localizedResponseArray property
     * but only in getValue method
     *
     * @var  bool
     */
    private $forcedLocalizedArray = false;

    /**
     * Turn on localized responses as final locale strings in toArray()
     *
     * @param  bool  $state
     */
    public function setLocalizedResponse($state = true)
    {
        $this->localizedResponseLocalArray = $state;

        return $this;
    }

    /**
     * Returns if localized array is forced
     * because in getValue methods etc, we want return correct array value
     *
     * @return  bool
     */
    public function isForcedLocalizedArray()
    {
        return $this->forcedLocalizedArray;
    }

    /**
     * Should model return end-locale string in toArray response?
     *
     * @return  bool
     */
    public function isSocalizedResponseLocalArray()
    {
        return $this->localizedResponseLocalArray;
    }
}