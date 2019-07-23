<?php

namespace Admin\Core\Commands;

use AdminCore;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class AdminModelCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'admin:model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Admin Model into crudadmin';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Admin model';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new Filesystem);
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        $path = __DIR__.'/../Stubs/AdminModel.stub';

        AdminCore::fire('admin.command.model.create.stub_path', [&$path, $this]);

        return $path;
    }

    /**
     * Get the desired class name from the input.
     *
     * @return string
     */
    public function getNameInput()
    {
        return trim($this->argument('name'));
    }

    /**
     * Returns different namespaces for CrudAdmin model and Core models.
     *
     * @return string
     */
    protected function getNamespacesList()
    {
        $namespaces[] = 'use Admin\Core\Eloquent\AdminModel';

        //We can mutate namespaces via $namespaces reference
        AdminCore::fire('admin.command.model.create.namespaces', [&$namespaces, $this]);

        return implode(";\n", $namespaces);
    }

    /**
     * Replace the namespace for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     * @return $this
     */
    protected function replaceNamespace(&$stub, $name)
    {
        //We can mutate generating stub via $stub reference
        AdminCore::fire('admin.command.model.create.stub', [&$stub, $this]);

        $stub = str_replace('DummyNamespace', $this->getNamespace($name), $stub);

        $stub = str_replace('DummyUseNamespaces', $this->getNamespacesList(), $stub);

        $stub = str_replace('DummyRootNamespace', $this->laravel->getNamespace(), $stub);

        $stub = str_replace('CREATED_DATETIME', Carbon::now(), $stub);

        //Automatically bind model parent
        $stub = str_replace('DummyParameters', $this->getStubParameters(), $stub);

        $stub = str_replace('DummyFields', $this->getStubFields(), $stub);

        return $this;
    }

    /**
     * Get owner model of actual class.
     *
     * @return string
     */
    protected function getParentModelName()
    {
        $camel = snake_case(basename($this->getNameInput()));

        $array = array_slice(explode('_', $camel), 0, -1);

        $parent = studly_case(str_singular(implode('_', $array)));

        return $parent;
    }

    /**
     * Returns name of parent model.
     *
     * @return string
     */
    protected function getBelongsTo()
    {
        $parent = $this->getParentModelName();

        //If creating model has not parent and is not belonging to any model
        if (! AdminCore::hasAdminModel($parent)) {
            return 'null';
        }

        return $parent.'::class';
    }

    /**
     * Returns parameters generated into model stub.
     *
     * @return string
     */
    protected function getStubParameters()
    {
        $parameters = [];

        //We can modify generated parameters wia $parameters reference
        AdminCore::fire('admin.command.model.create.parameters', [&$parameters, $this]);

        $parameters[] = '
        /*
         * Model Parent
         * Eg. Article::class
         */
        protected $belongsToModel = '.$this->getBelongsTo().';'."\n";

        return $this->fixParameterSpacing(implode("\n", $parameters), '    ');
    }

    /**
     * Returns fields generated into model stub.
     *
     * @return string
     */
    protected function getStubFields()
    {
        $fields = [];

        $locale = config('admin.locale', 'en');

        $fields['name'] = 'name:'.trans('admin.core::fields.name', [], $locale).'|required|max:90';
        $fields['content'] = 'name:'.trans('admin.core::fields.content', [], $locale).'|type:editor|required';
        $fields['image'] = 'name:'.trans('admin.core::fields.image', [], $locale).'|type:file|image|required';

        //We can modify generated parameters wia $parameters reference
        AdminCore::fire('admin.command.model.create.fields', [&$fields, $this]);

        //Format lines with field keys
        $lines = [];
        foreach ($fields as $key => $field) {
            $lines[] = "'$key' => '$field',";
        }

        return $this->fixParameterSpacing(implode("\n", $lines), '        ');
    }

    /**
     * Receive stub with random tabulators spaces, and fix length of spaces for each parameter.
     *
     * @param  string $stub
     * @param  string $spaces
     * @return string
     */
    private function fixParameterSpacing($stub, $spaces = '')
    {
        $lines = explode("\n", $stub);

        foreach ($lines as $key => $line) {
            $line = trim($line, ' ');

            //Change tabulator instead of space
            $line = str_replace("\t", $spaces, $line);

            if ( substr($line, 0, 2) == "/*" )
                $lines[$key] = ($line ? substr($spaces, 0, -1) : '').$line;
            else
                $lines[$key] = ($line ? $spaces : '').$line;
        }

        return implode("\n", $lines);
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        $options = [
            ['name', '', InputOption::VALUE_OPTIONAL, 'Model name in administration'],
        ];

        /*
         * We can mutate model options with $options reference
         */
        AdminCore::fire('admin.command.model.create.options', [&$options, $this]);

        return $options;
    }
}
