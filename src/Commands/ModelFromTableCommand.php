<?php

namespace Laracademy\Generators\Commands;

use DB;
use Schema;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class ModelFromTableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:modelfromtable
                            {--table= : a single table or a list of tables separated by a comma (,)}
                            {--connection= : database connection to use, leave off and it will use the .env connection}
                            {--debug : turns on debugging}
                            {--folder= : by default models are stored in app, but you can change that}
                            {--namespace= : by default the namespace that will be applied to all models is App}
                            {--singular : class name and class file name singular or plural}
                            {--all : run for all tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate models for the given tables based on their columns';

    public $fieldsFillable;
    public $fieldsHidden;
    public $fieldsCast;
    public $fieldsDate;
    public $columns;
    public $timestamps = false;

    public $debug;
    public $options;

    public $databaseConnection;
    public $pkey=[];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->options = [
            'connection' => '',
            'table'      => '',
            'folder'     => app()->path(),
            'debug'      => false,
            'all'        => false,
            'singular'   => '',
        ];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->doComment('Starting Model Generate Command', true);
        $this->getOptions();

        $tables = [];
        $path = $this->options['folder'];
        $modelStub = file_get_contents($this->getStub());

        // can we run?
        if (strlen($this->options['table']) <= 0 && $this->options['all'] == false) {
            $this->error('No --table specified or --all');

            return;
        }

        // figure out if we need to create a folder or not
        if($this->options['folder'] != app()->path()) {
            if(! is_dir($this->options['folder'])) {
                mkdir($this->options['folder']);
            }
        }

        // figure out if it is all tables
        if ($this->options['all']) {
            $tables = $this->getAllTables();
        } else {
            $tables = explode(',', $this->options['table']);
        }

        // cycle through each table
        foreach ($tables as $table) {
            // grab a fresh copy of our stub
            $stub = $modelStub;

            // generate the file name for the model based on the table name
            $filename = Str::studly(strtolower($table));

            if ($this->options['singular']){
                $filename = Str::singular($filename);
            }

            $fullPath = "$path/$filename.php";
            $this->doComment("Generating file: $filename.php");

            // gather information on it
            $model = [
                'table'     => $table,
                'fillable'  => $this->getSchema($table),
                'guardable' => [],
                'hidden'    => [],
                'casts'     => [],
                'pkey'      => $this->pkey
            ];

            // fix these up
            $columns = $this->describeTable($table);

            // use a collection
            $this->columns = collect();

            foreach ($columns as $col) {
                if($col->COLUMN_NAME == $col->PKEY){
                    array_push($this->pkey, $col->PKEY);
                }

                $this->columns->push([
                    'field' => $col->COLUMN_NAME,
                    'type'  => $col->DATA_TYPE,
                ]);
            }

            // reset fields
            $this->resetFields();

            // replace the class name
            $stub = $this->replaceClassName($stub, $table);

            // replace the fillable
            $stub = $this->replaceModuleInformation($stub, $model);

            // figure out the connection
            $stub = $this->replaceConnection($stub, $this->options['connection']);

            $stub = $this->HasCompositePrimaryKey($stub);

            // writing stub out
            $this->doComment('Writing model: '.$fullPath, true);
            file_put_contents($fullPath, $stub);
        }

        $this->info('Complete');
    }

    public function getSchema($tableName)
    {
        $this->doComment('Retrieving table definition for: '.$tableName);

        if (strlen($this->options['connection']) <= 0) {
            return Schema::getColumnListing($tableName);
        } else {
            return Schema::connection($this->options['connection'])->getColumnListing($tableName);
        }
    }

    public function describeTable($tableName)
    {
        $this->doComment('Retrieving column information for : '.$tableName);

        if (strlen($this->options['connection']) <= 0) {
            return DB::select(DB::raw("SELECT
                        A.COLUMN_NAME, A.DATA_TYPE, B. COLUMN_NAME as PKEY, B.CONSTRAINT_NAME
                    FROM
                        INFORMATION_SCHEMA.COLUMNS A
                    LEFT JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE B ON A.COLUMN_NAME = B.COLUMN_NAME AND A.TABLE_NAME=B.TABLE_NAME
                    WHERE
                        A.TABLE_NAME =  '{$tableName}'"));
        } else {
            return DB::connection($this->options['connection'])->select(DB::raw("SELECT
                    A.COLUMN_NAME, A.DATA_TYPE, B. COLUMN_NAME as PKEY, B.CONSTRAINT_NAME
                FROM
                    INFORMATION_SCHEMA.COLUMNS A
                LEFT JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE B ON A.COLUMN_NAME = B.COLUMN_NAME AND A.TABLE_NAME=B.TABLE_NAME
                WHERE
                    A.TABLE_NAME =  '{$tableName}'"));
        }
    }

    /**
     * replaces the class name in the stub.
     *
     * @param string $stub      stub content
     * @param string $tableName the name of the table to make as the class
     *
     * @return string stub content
     */
    public function replaceClassName($stub, $tableName)
    {
        return str_replace('{{class}}', $this->options['singular'] ? Str::singular(Str::studly($tableName)): Str::studly(strtolower($tableName)), $stub);
    }

    /**
     * replaces the module information.
     *
     * @param string $stub             stub content
     * @param array  $modelInformation array (key => value)
     *
     * @return string stub content
     */
    public function replaceModuleInformation($stub, $modelInformation)
    {
        // replace table
        $stub = str_replace('{{table}}', $modelInformation['table'], $stub);

        // replace fillable
        $this->fieldsHidden = '';
        $this->fieldsFillable = '';
        $this->fieldsCast = '';
        foreach ($modelInformation['fillable'] as $field) {
            // fillable and hidden
            if ($field != 'id') {
                $this->fieldsFillable .= (strlen($this->fieldsFillable) > 0 ? ', ' : '')."'$field'";

                $fieldsFiltered = $this->columns->where('field', $field);
                if ($fieldsFiltered) {
                    // check type
                    switch (strtolower($fieldsFiltered->first()['type'])) {
                        case 'timestamp':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '')."'$field'";
                            break;
                        case 'datetime':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '')."'$field'";
                            break;
                        case 'date':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '')."'$field'";
                            break;
                        case 'tinyint(1)':
                            $this->fieldsCast .= (strlen($this->fieldsCast) > 0 ? ', ' : '')."'$field' => 'boolean'";
                            break;
                        case 'char':
                        case 'nchar':
                        case 'varchar':
                            if(in_array($field, $this->pkey)){
                                $this->fieldsCast .= (strlen($this->fieldsCast) > 0 ? ', ' : '')."'$field'=> 'string'";
                            }
                            break;
                    }
                }
            }else if ($field == 'created_at' || $field == 'updated_at') {
                $this->timestamps = true;
            } else {
                if ($field != 'id' && $field != 'created_at' && $field != 'updated_at') {
                    $this->fieldsHidden .= (strlen($this->fieldsHidden) > 0 ? ', ' : '')."'$field'";
                }
            }
        }

        // replace in stub
        $stub = str_replace('{{fillable}}', $this->fieldsFillable, $stub);
        $stub = str_replace('{{hidden}}', $this->fieldsHidden, $stub);
        $stub = str_replace('{{casts}}', $this->fieldsCast, $stub);
        $stub = str_replace('{{dates}}', $this->fieldsDate, $stub);
        $stub = str_replace('{{modelnamespace}}', $this->options['namespace'], $stub);
        $stub = str_replace('{{pkey}}', $this->addPrimaryKey($stub), $stub);
        $stub = str_replace('{{timestamps}}', ($this->timestamps ? '' : 'public $timestamps = false;'), $stub);
        return $stub;
    }

    public function addPrimaryKey($stub){
        // Cek length pkey
        if(sizeof($this->pkey) > 1){
            $temp = array_map(function($item){
                return "'". addcslashes($item, "\0..\037\"\\"). "'";
            }, $this->pkey);
            return '['. implode(',', $temp) . ']';
        }else{
            return "'".$this->pkey[0]."'";
        }
    }

    public function HasCompositePrimaryKey($stub){
        if(sizeof($this->pkey) > 1){
            $stub = str_replace('{{UseHasCompositePrimaryKey}}', 'use HasCompositePrimaryKey;', $stub);
        }else{
            $stub = str_replace('{{UseHasCompositePrimaryKey}}', '', $stub);
        }
        return $stub;
    }

    public function replaceConnection($stub, $database)
    {
        $replacementString = '/**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = \''.$database.'\';';

        if (strlen($database) <= 0) {
            $stub = str_replace('{{connection}}', '', $stub);
        } else {
            $stub = str_replace('{{connection}}', $replacementString, $stub);
        }

        return $stub;
    }

    /**
     * returns the stub to use to generate the class.
     */
    public function getStub()
    {
        $this->doComment('loading model stub');

        return __DIR__.'/../stubs/model.stub';
    }

    /**
     * returns all the options that the user specified.
     */
    public function getOptions()
    {
        // debug
        $this->options['debug'] = ($this->option('debug')) ? true : false;

        // connection
        $this->options['connection'] = ($this->option('connection')) ? $this->option('connection') : '';

        // namespace with possible folder
        // if there is no folder specified and no namespace
        if(! $this->option('folder') && ! $this->option('namespace')) {
            // assume default APP
            $this->options['namespace'] = 'App';
        } else {
            // if we have a namespace, use it first
            if($this->option('namespace')) {
                $this->options['namespace'] = str_replace('/', '\\', $this->option('namespace'));
            } else {
                if($this->option('folder')) {
                    $folder = $this->option('folder');
                    $this->options['namespace'] = str_replace('/', '\\', $folder);
                }
            }
        }

        // finish setting up folder
        $this->options['folder'] = ($this->option('folder')) ? base_path($this->option('folder')) : app()->path();
        // trim trailing slashes
        $this->options['folder'] = rtrim($this->options['folder'], '/');

        // all tables
        $this->options['all'] = ($this->option('all')) ? true : false;

        // single or list of tables
        $this->options['table'] = ($this->option('table')) ? $this->option('table') : '';

        // class name and class file name singular/plural
        $this->options['singular'] = ($this->option('singular')) ? $this->option('singular') : '';
    }

    /**
     * will add a comment to the screen if debug is on, or is over-ridden.
     */
    public function doComment($text, $overrideDebug = false)
    {
        if ($this->options['debug'] || $overrideDebug) {
            $this->comment($text);
        }
    }

    /**
     * will return an array of all table names.
     */
    public function getAllTables()
    {
        $tables = [];

        if (strlen($this->options['connection']) <= 0) {
            $tables = collect(DB::select(DB::raw("show full tables where Table_Type = 'BASE TABLE'")))->flatten();
        } else {
            $tables = collect(DB::connection($this->options['connection'])->select(DB::raw("show full tables where Table_Type = 'BASE TABLE'")))->flatten();
        }

        $tables = $tables->map(function ($value, $key) {
            return collect($value)->flatten()[0];
        })->reject(function ($value, $key) {
            return $value == 'migrations';
        });

        return $tables;
    }

    /**
     * reset all variables to be filled again when using multiple
     */
    public function resetFields()
    {
        $this->fieldsFillable = '';
        $this->fieldsHidden   = '';
        $this->fieldsCast     = '';
        $this->fieldsDate     = '';
    }
}
