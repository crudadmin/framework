<?php

namespace Admin\Core\Eloquent\Concerns;

use Route;
use Localization;
use Admin\Models\SluggableHistory;
use Admin\Exceptions\SluggableException;

trait Sluggable
{
    /**
     * IF slug is localized.
     *
     * @var  null|bool
     */
    private $hasLocalizedSlug = null;

    /**
     * Makes from text nice url.
     *
     * @param  string  $url
     * @return  string
     */
    private function toSlug($url)
    {
        $rules = [
            '´'=>'', 'ˇ'=>'', 'ä'=>'a', 'Ä'=>'A', 'á'=>'a', 'Á'=>'A', 'à'=>'a', 'À'=>'A', 'ã'=>'a',
            'Ã'=>'A', 'â'=>'a', 'Â'=>'A', 'č'=>'c', 'Č'=>'C', 'ć'=>'c', 'Ć'=>'C', 'ď'=>'d', 'Ď'=>'D',
            'ě'=>'e', 'Ě'=>'E', 'é'=>'e', 'É'=>'E', 'ë'=>'e', 'è'=>'e', 'È'=>'E', 'ê'=>'e', 'Ê'=>'E',
            'í'=>'i', 'Í'=>'I', 'ï'=>'i', 'Ï'=>'I', 'ì'=>'i', 'Ì'=>'I', 'î'=>'i', 'Î'=>'I', 'ľ'=>'l',
            'Ľ'=>'L', 'ĺ'=>'l', 'Ĺ'=>'L', 'ń'=>'n', 'Ń'=>'N', 'ň'=>'n', 'Ň'=>'N', 'ñ'=>'n', 'Ñ'=>'N',
            'ó'=>'o', 'Ó'=>'O', 'ö'=>'o', 'Ö'=>'O', 'ô'=>'o', 'Ô'=>'O', 'ò'=>'o', 'Ò'=>'O', 'õ'=>'o',
            'Õ'=>'O', 'ő'=>'o', 'Ő'=>'O', 'ř'=>'r', 'Ř'=>'R', 'ŕ'=>'r', 'Ŕ'=>'R', 'š'=>'s', 'Š'=>'S',
            'ś'=>'s', 'Ś'=>'S', 'ť'=>'t', 'Ť'=>'T', 'ú'=>'u', 'Ú'=>'U', 'ů'=>'u', 'Ů'=>'U', 'ü'=>'u',
            'Ü'=>'U', 'ù'=>'u', 'Ù'=>'U', 'ũ'=>'u', 'Ũ'=>'U', 'û'=>'u', 'Û'=>'U', 'ý'=>'y', 'Ý'=>'Y',
            'ž'=>'z', 'Ž'=>'Z', 'ź'=>'z', 'Ź'=>'Z',
        ];

        $url = trim($url);
        $url = strtr($url, $rules);
        $url = mb_strtolower($url, 'utf8');
        $url = preg_replace('/[^\-a-z0-9]+/', '-', $url);
        $url = preg_replace('[^-*|-*$]', '', $url);
        $url = preg_replace('~(-+)~', '-', $url);

        return $url;
    }

    private function isValidSlug($slug)
    {
        return $slug || $slug == 0;
    }

    /**
     * Return which slugs from other row has same slug values with actual editting row.
     *
     * @param  array  $slugs
     * @param  string  $relatedSlug
     * @return array
     */
    private function getLocaleDifferences($slugs, $relatedSlug)
    {
        if ($this->hasLocalizedSlug()) {
            $slugData = is_array($relatedSlug) ? $relatedSlug : (array) json_decode($relatedSlug);

            return array_filter(array_intersect_assoc($slugs, $slugData));
        } else {
            return array_wrap($relatedSlug);
        }
    }

    /**
     * Count, increment existing slugs.
     *
     * @param  array  $slugs
     * @param  string  $key
     * @param  int  $index
     * @param  string  $withoutIndex
     * @return  void
     */
    private function incrementSlug(&$slugs, string $key, $index, $withoutIndex)
    {
        $newSlug = implode('-', $withoutIndex);

        $column = $this->hasLocalizedSlug() ? 'JSON_EXTRACT(slug, "$.'.$key.'")' : 'slug';

        $i = 1;

        //Return original slugs
        do {
            $slugs[$key] = $newSlug.'-'.($index + $i);

            $i++;
        } while ($this->where(function ($query) {
            if ($this->exists) {
                $query->where($this->getTable().'.'.$this->getKeyName(), '!=', $this->getKey());
            }
        })->whereRaw($column.' = ?', $slugs[$key])->withoutGlobalScopes()->count() > 0);
    }

    /**
     * If slug exists in db related in other than actual row, then add index at the end into actual slug.
     *
     * @param  array  $slugs
     * @param  string  $relatedSlug
     * @return array
     */
    private function makeUnique($slugs, $relatedSlug)
    {
        $exists = $this->getLocaleDifferences($slugs, $relatedSlug);

        foreach ($exists as $key => $value) {
            $array = explode('-', $value);

            //Get incement of index
            $index = last($array);

            //If slug has no increment yet
            if (! is_numeric($index) || count($array) == 1) {
                $index = 1;
                $withoutIndex = $array;
            } else {
                $withoutIndex = array_slice($array, 0, -1);
            }

            //Add unique increment into slug
            $this->incrementSlug($slugs, $key, $index, $withoutIndex);
        }

        return array_filter($slugs);
    }

    /**
     * Set empty localization into default language slug.
     *
     * @param  string  $text
     * @return string
     */
    private function normalizeSlugs($text, $wrap = false)
    {
        if ($text && $this->hasLocalizedSlug()) {
            //TODO: this statement is weird.
            if (! $text && $text != 0) {
                return $text;
            }

            $text = (array) json_decode($text);

            if ( is_array($text) ){
                ksort($text);
            }
        } elseif ($wrap === true) {
            $text = array_wrap($this->isValidSlug($text) ? $text : []);
        }

        return $text;
    }

    /**
     * Generate slug from field value.
     *
     * @param  string $text
     * @return string
     */
    public function makeSlug($text, $prevSlug = null)
    {
        $text = $this->normalizeSlugs($text, true);

        //Bind first level of translated slugs (without incrementing at the end, -1 etc..)
        $slugs = [];
        foreach ($text as $key => $value) {
            $slugs[$key] = $this->toSlug($value);
        }

        if ( $this->slugBaseFormIsSame($slugs, $prevSlug) == true ){
            return $prevSlug;
        }

        //Checks if some of localized slugs in database exists in other rows
        $row = $this->getConnection()->table($this->getTable())->where(function ($query) use ($slugs) {
            //Multilanguages slug
            if ($this->hasLocalizedSlug()) {
                $i = 0;
                foreach ($slugs as $key => $value) {
                    if (! $value) {
                        continue;
                    }

                    $query->{ $i == 0 ? 'whereRaw' : 'orWhereRaw' }('JSON_EXTRACT(slug, "$.'.$key.'") = ?', $value);
                    $i++;
                }

            }

            //If is simple string slug
            else {
                $query->where('slug', $slugs[0]);
            }
        })->limit(1);

        //If models exists, then skip slug owner
        if ($this->exists) {
            $row->where($this->getKeyName(), '!=', $this->getKey());
        }

        $row = $row->get(['slug']);

        //If new slugs does not exists, then return new generated slug
        if ($row->count() == 0) {
            return $this->castSlug(array_filter($slugs, function($item){
                return $this->isValidSlug($item);
            }));
        }

        //Generate new unique slug with increment
        $uniqueSlug = $this->makeUnique($slugs, $row->first()->slug);

        //If slug exists, then generate unique slug
        return $this->castSlug($uniqueSlug);
    }

    /**
     * Remove increments from currently saved slugs in database
     * If base form is same, we don't need to check incrementing for given row.
     */
    public function slugBaseFormIsSame($newSlug, $prevSlug)
    {
        $prevSlug = $this->normalizeSlugs($prevSlug, true);

        foreach ($prevSlug as $key => $value) {
            $parts = explode('-', $value);
            $prevSlug[$key] = count($parts) > 1 && is_numeric($parts[count($parts) - 1]) ? implode('-', array_slice($parts, 0,  -1)) : $value;
        }

        //Sort arrays to have same key order (it's not necessary for comparing but better for debug)
        ksort($newSlug);
        ksort($prevSlug);

        return $newSlug == $prevSlug;
    }

    /**
     * Return casted valeu of slug (json or string).
     *
     * @param  mixed  $slugs
     * @return string|null
     */
    private function castSlug($slugs)
    {
        if ($this->hasLocalizedSlug()) {
            if (is_array($slugs)) {
                return json_encode($slugs);
            }

            return;
        }

        return is_array($slugs) ? ($slugs[0] ?? null) : null;
    }

    /**
     * Return if is column localized.
     *
     * @return bool
     */
    public function hasLocalizedSlug()
    {
        if ($this->hasLocalizedSlug !== null) {
            return $this->hasLocalizedSlug;
        }

        if ( !($slugcolumn = $this->getProperty('sluggable'))) {
            return;
        }

        return $this->hasLocalizedSlug = $this->hasFieldParam($slugcolumn, 'locale', true);
    }

    /**
     * Automatically generates slug into model by field.
     *
     * @return void
     */
    public function sluggable()
    {
        $attributes = $this->attributes;

        $slugColumn = $this->getProperty('sluggable');

        $slugFromColumnValue = $attributes[$slugColumn] ?? '';
        $prevSlug = $this->getRawOriginal('slug');

        $prevSlugNormalized = $this->normalizeSlugs($prevSlug);
        $currentSlugNormalized = $this->normalizeSlugs($attributes['slug'] ?? '');

        $canAddToHistory = $this->exists && $this->isAllowedHistorySlugs();

        //If dynamic slugs are turned off
        if ( $this->slug_dynamic === false && $this->slug ) {
            //If does exists row, and if has been changed
            if (
                $canAddToHistory
                && $prevSlugNormalized
                && $currentSlugNormalized != $prevSlugNormalized //If arrays are not the same. Or slug strings are not the same.
            ) {
                $this->slugSnapshot($prevSlugNormalized);
            }
        }

        //If is available original column value from which we are generating slug
        else if ( mb_strlen($slugFromColumnValue, 'UTF-8') > 0 ) {
            $newlyGeneratedSlug = $this->makeSlug($slugFromColumnValue, $prevSlug);
            $newlyGeneratedSlugArray = $this->normalizeSlugs($newlyGeneratedSlug);

            //If slug has been changed, then save previous slug state
            if (
                $canAddToHistory
                && $currentSlugNormalized != $newlyGeneratedSlugArray
            ) {
                $this->slugSnapshot();
            }

            $this->attributes['slug'] = $newlyGeneratedSlug;
        }
    }

    /**
     * Check if history slugs are allowed.
     *
     * @return bool
     */
    public function isAllowedHistorySlugs()
    {
        return config('admin.sluggable_history', false) === true
               && $this->getProperty('sluggable_history') !== false;
    }

    /**
     * Save slug state.
     *
     * @return void
     */
    public function slugSnapshot($value = null)
    {
        SluggableHistory::snapshot($this, $value);
    }

    /**
     * Returns correct url adress with correct slug.
     *
     * @param  string  $slug
     * @param  string  $wrong
     * @param  int  $id
     * @param  string  $key
     * @return Illuminate\Http\Response
     */
    protected static function buildFailedSlugResponse($slug, $wrong, $id, $key, $status = 301)
    {
        $route = Route::current();

        $current_controller = Route::currentRouteAction();

        $parameters = $route->parameters();

        $binding = [];

        //If is avaiable route key binding, and not exists in actual route
        if ($key && ! array_key_exists($key, $parameters)) {
            abort(500, 'Unknown route identifier: '.$key);
        }

        //Rewrite wrong slug to correct from db
        foreach ($parameters as $k => $value) {
            if ($key == $k || (! $key && $value != $id && $value == $wrong)) {
                $binding[] = $slug;
            } else {
                $binding[] = $value;
            }
        }

        $query = request()->all();

        $url = action('\\'.$current_controller, $binding).'?'.http_build_query($query);

        //Returns redirect
        return redirect($url, $status);
    }

    /**
     * Redirect with wrong slug by existing row found by given id.
     *
     * @param  string  $slug
     * @param  int  $id
     * @param  string  $key
     * @param  Admin\Core\Eloquent\AdminModel  $row
     * @return  void
     */
    private function redirectWithWrongSlug($slug, $id, $key, $row)
    {
        //If is definer row where is slug saved
        if (is_numeric($id)) {
            $row = $this->where($this->getKeyName(), $id)->select(['slug'])->first();

            //Compare given slug and slug from db
            if ($row && $row->slug != $slug) {
                throw new SluggableException($this->buildFailedSlugResponse($row->slug, $slug, $id, $key));
            }
        }

        if ($this->isAllowedHistorySlugs()) {
            $this->redirectWithSlugFromHistory($slug, $id, $key);
        }
    }

    /**
     * Redirect if slug has been found in history.
     *
     * @param  string  $slug
     * @param  int  $id
     * @param  string  $key
     * @return  void
     */
    private function redirectWithSlugFromHistory($slug, $id, $key)
    {
        $history_model = new SluggableHistory;
        $history_model->hasLocalizedSlug = $this->hasLocalizedSlug();

        $history_row = $history_model
                        ->where('table', $this->getTable())
                        ->whereSlug($slug, $history_model->getSlugColumnName($this))
                        ->whereExists(function ($query) use ($history_model) {
                            $query->select(['id'])
                                  ->from($this->getTable())
                                  ->whereRaw($history_model->getTable().'.row_id = '.$this->getTable().'.id')
                                  ->when($this->publishable, function ($query) {
                                      $query->where('published_at', '!=', null)->whereRAW('published_at <= NOW()');
                                  })
                                  ->where('deleted_at', null);
                        })
                        ->leftJoin($this->getTable(), $history_model->getTable().'.row_id', '=', $this->getTable().'.id')
                        ->select($this->getTable().'.slug')
                        ->first();

        if (! $history_row) {
            return;
        }

        $history_row->hasLocalizedSlug = $this->hasLocalizedSlug();

        $newSlug = $history_row->getSlug();

        throw new SluggableException($this->buildFailedSlugResponse($newSlug, $slug, $id, $key));
    }

    /**
     * If is inserted also row of id, then will be compared slug from database and slug from url bar, if is different, automatically
     * redirect to correct route with correct and updated route.
     *
     * @param  Closure  $scope
     * @param  string  $slugValue
     * @param  string|null  $column
     * @return void
     */
    public function scopeWhereSlug($scope, $slugValue, $column = null)
    {
        if (! $column) {
            $column = 'slug';
        }

        if (! $this->hasLocalizedSlug()) {
            return $scope->where($this->getTable().'.'.$column, $slugValue);
        }

        $lang = Localization::get();

        $default = Localization::getDefaultLanguage();

        //Find slug from selected language
        $scope->whereRaw('JSON_EXTRACT('.$column.', "$.'.$lang->slug.'") = ?', $slugValue);

        //If selected language is other than default
        if ($lang->getKey() != $default->getKey()) {
            //Then search also values in default language
            $scope->orWhere(function ($query) use ($lang, $default, $slugValue, $column) {
                $query->whereRaw('JSON_EXTRACT('.$column.', "$.'.$lang->slug.'") is NULL')
                      ->whereRaw('JSON_EXTRACT('.$column.', "$.'.$default->slug.'") = ?', $slugValue);
            });
        }

        if ( $previousLocale = $this->getValidPreiousLocale() ) {
            //Then search also values in default language
            $scope->orWhere(function ($query) use ($column, $previousLocale, $slugValue) {
                $query->whereRaw('JSON_EXTRACT('.$column.', "$.'.$previousLocale.'") = ?', $slugValue);
            });
        }
    }

    /**
     * Find row by slug.
     *
     * @param  Closure  $query
     * @param  string  $slug
     * @param  int|null  $id
     * @param  string|null  $key
     * @param  array  $columns
     * @return Admin\Core\Eloquent\AdminModel|null
     */
    public function scopeFindBySlug($query, $slug, $id = null, $key = null, array $columns = ['*'])
    {
        return static::findBySlug($slug, $id, $key, $columns, $query);
    }

    /**
     * Find row by slug or throw 404.
     *
     * @param  Closure  $query
     * @param  string  $slug
     * @param  int|null  $id
     * @param  string|null  $key
     * @param  array  $columns
     * @return Admin\Core\Eloquent\AdminModel
     */
    public function scopeFindBySlugOrFail($query, $slug, $id = null, $key = null, array $columns = ['*'])
    {
        return static::findBySlugOrFail($slug, $id, $key, $columns, $query);
    }

    /**
     * Find a model by slug.
     *
     * @param  string  $slug
     * @param  int|null  $id
     * @param  string|null  $key
     * @param  array  $columns
     * @param  Illuminate\Database\Query\Builder  $query
     * @return  [type]
     */
    public static function findBySlug($slug, $id = null, $key = null, array $columns = ['*'], $query = null)
    {
        $static = (new static);

        if (is_array($id)) {
            $columns = $id;
        } elseif (! is_string($id)) {
            $id = null;
        }

        $row = ($query ?: $static)->whereSlug($slug)->first($columns);

        if (! $row || ($id && $row->getKey() != $id)) {
            $static->redirectWithWrongSlug($slug, $id, $key, $row);
        }

        if ( $static->getValidPreiousLocale() ) {
            $static->redirectToCorrectLocale($slug, $id, $key, $row);
        }

        return $row ?: false;
    }

    /**
     * Find a model by its primary slug or throw an exception.
     *
     * @param  string  $slug
     * @param  int|null  $id
     * @param  string|null  $key
     * @param  array  $columns
     * @param  Illuminate\Database\Query\Builder  $query
     * @return  [type]
     */
    public static function findBySlugOrFail($slug, $id = null, $key = null, array $columns = ['*'], $query = null)
    {
        if (is_array($id)) {
            $columns = $id;
        } elseif (! is_string($id)) {
            $id = null;
        }

        $row = static::findBySlug($slug, $id, $key, $columns, $query);

        if (! $row) {
            abort(404);
        }

        return $row;
    }

    /**
     * Returns slug of item also with localization support.
     *
     * @return string
     */
    public function getSlug()
    {
        if ($this->hasLocalizedSlug()) {
            //Cast model slug to propert type
            $slug = $this->getRawOriginal('slug');

            $slug = is_array($slug) ? $slug : (array) json_decode($slug);

            $lang = Localization::get();

            //Return selected language slug
            if (array_key_exists($lang->slug, $slug) && $slug[$lang->slug]) {
                return $slug[$lang->slug];
            }

            $default = Localization::getFirstLanguage();

            //Return default slug value
            if ($default->getKey() != $lang->getKey() && array_key_exists($default->slug, $slug) && $slug[$default->slug]) {
                return $slug[$default->slug];
            }

            //Return one of set slug from any language
            foreach (Localization::getLanguages() as $lang) {
                if (array_key_exists($lang->slug, $slug) && $slug[$lang->slug]) {
                    return $slug[$lang->slug];
                }
            }

            //If languages has been hidden, and no slug has been defined from any known language
            //we can return any existing
            return array_values($slug)[0] ?? null;
        }

        return $this->slug;
    }

    /**
     * Check if given model has sluggable support
     *
     * @return  bool
     */
    public function hasSluggable()
    {
        return $this->sluggable ?: false;
    }

    private function getValidPreiousLocale()
    {
        if ( !$previousLocale = request('_previous_locale') ){
            return;
        }

        if ( preg_match("/^[a-z]+$/", $previousLocale) == false ){
            return;
        }

        if ( mb_strlen($previousLocale) != 2 ) {
            return;
        }

        return $previousLocale;
    }

    public function redirectToCorrectLocale($slug, $id, $key, $row)
    {
        if ($row && $row->getSlug() != $slug) {
            throw new SluggableException(
                $this->buildFailedSlugResponse($row->getSlug(), $slug, $id, $key, 302)
            );
        }
    }
}
