<?php

namespace ArtisanIo\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportDelimitedCommand extends Command
{
    use ConfirmableTrait;

    const MODE_INSERT = 'insert';
    const MODE_UPDATE = 'update';
    const MODE_UPSERT = 'upsert';

    const TO_TABLE = 'table';
    const TO_MODEL = 'model';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:delimited
        {from            : The path to an import file i.e. /tmp/import.csv}
        {to              : Table or Eloquent class name}
        {--f|fields=     : A comma separated list of fields in a form <field>[:position] i.e. "email:0,name,2". Positions are 0 based}
        {--F|field-file= : Path to a file that contains field definitions. One definition per line}
        {--m|mode=upsert : Import mode [insert|update|upsert]}
        {--k|key=        : Field names separated by a comma that constitute a key for update and upsert modes}
        {--R|rule-file=  : Path to a file, containing field validation rules}
        {--d|delimiter=, : Field delimiter}
        {--i|ignore=     : Ignore first N lines of the file}
        {--c|database=   : The database connection to use}
        {--x|transaction : Use a transaction}
        {--dry-run       : Dry run mode}
        {--no-progress   : Don\'t show the progress bar}
        {--force         : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import data from a file using table name or Eloquent model.';

    protected $filePath;
    protected $table;
    protected $model;
    protected $connectionName;
    protected $useTransaction;
    protected $mode;
    protected $fields;
//    protected $fieldCasts;
    protected $keyFields;
    protected $delimiter;
    protected $ignore;
    protected $rules = [];
    protected $dryRun = false;

    // Statistics
    protected $startedAt;
    protected $imported = 0;
    protected $fileLine = 0;

    // Other
    protected $showProgress = true;

    /**
     * Create a new command instance.
     *
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            exit(1);
        }

        $this->parseArguments();

        $this->runImport();

    }

    /**
     * Permorm the import
     */
    protected function runImport()
    {
        $this->startedAt = microtime(true);

        $fileIterator = $this->getFileIterator();

        $this->imported = 0;

        $rules = $this->getRowValidationRules();

        if ($this->showProgress) {
            $this->output->progressStart();
        }

        if ($this->useTransaction) {
            DB::beginTransaction();
        }

        foreach ($fileIterator as $values) {
            $this->fileLine = $fileIterator->key() + 1;

            $row = $this->mapValues($values, $this->fields);

            if (! empty($rules)) {
                $this->validateRow($row, $rules, $this->fileLine);
            }

            switch ($this->mode) {
                case self::MODE_INSERT:
                    $this->insert($row);
                    break;
                case self::MODE_UPDATE:
                    $this->update($row);
                    break;
                default: //self::MODE_UPSERT
                    $this->upsert($row);
            }

            $this->imported++;

            if ($this->showProgress) {
                $this->output->progressAdvance();
            }
        }

        if ($this->useTransaction) {
            DB::commit();
        }

        $fileIterator = null;

        if ($this->showProgress) {
            $this->output->progressFinish();
        }

        $this->reportImported();
    }

    /**
     * Turns an array of read values into an associative array where keys are column/attribute names
     *
     * @param array $values
     * @param array $columns
     * @return array
     */
    protected function mapValues($values, $columns)
    {
        $mapped = [];
        foreach ($columns as $column => $position) {
            if ($position > count($values)) {
                $this->abort("Position '$position' is out of scope for field '$column'.");
            }
            $mapped[$column] = trim($values[$position]);
        }

        return $mapped;
    }

    /**
     * Parse input arguments
     */
    protected function parseArguments()
    {
        // Import file
        $this->filePath = $this->validateFile($this->argument('from'));

        // DB connection
        $this->connectionName = $this->option('database');
        $this->setDefaultConnection();

        // Are we going to use transactions?
        $this->useTransaction = $this->option('transaction') ?: false;

        $to = trim($this->argument('to'));
        if (Str::startsWith($to, '\\')) {
            if (! $this->model = $this->modelExists($to)) {
                $this->abort("Model '$to' doesn't exist.");
            }
        } else {
            if (! $this->table = $this->tableExists($to)) {
                $this->abort("Table '$to' doesn't exist.");
            }
        }

        // Import mode
        $this->mode = strtolower($this->option('mode') ?: self::MODE_UPSERT);
        if (! in_array($this->mode, [self::MODE_UPSERT, self::MODE_UPDATE, self::MODE_INSERT])) {
            $this->abort("Invalid mode '{$this->mode}'.");
        }

        // Import fields
        if (empty($this->option('fields')) && empty($this->option('field-file'))) {
            $this->abort("Import fields haven't been specified. Use -f or -F option.");
        }
        if (! $fieldDefinitions = $this->getFieldDefinitions()) {
            $this->abort("Invalid field definition.");
        }
        $this->parseFields($fieldDefinitions);
        $this->validateFields();

        // Row validation rules file
        if ($this->option('rule-file')) {
            $ruleFilePath = $this->validateFile($this->option('rule-file'), 'Rule file');
            // TODO: Validate rule file
            $this->rules = @require $ruleFilePath;
        }

        // Fields delimiter
        $this->delimiter = $this->unescape($this->option('delimiter') ?: ',');

        // Ignore N lines
        if (false === ($this->ignore = filter_var($this->option('ignore'), FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]))) {
            $this->abort("--ignore value should be a positive integer");
        }

        // Key fields
        // TODO: Validate key fields. Take into consideration that key fields can be non fillable
        $this->keyFields = empty($this->option('key')) ?
            $this->fields : array_map('trim', explode(',', $this->option('key')));

        // Are we going to display the progress bar?
        $this->showProgress = ! $this->option('no-progress');

        // Are we in a Dry Run mode?
        $this->dryRun = $this->option('dry-run');
    }

    /**
     * Return file iterator
     *
     * @return \LimitIterator
     */
    protected function getFileIterator()
    {
        $file = new \SplFileObject($this->filePath, 'r');
        $file->setFlags(\SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE | \SplFileObject::READ_CSV);
        $file->setCsvControl($this->delimiter);

        return new \LimitIterator($file, $this->ignore);
    }

    /**
     * Returns array with column names and values that constitues a key (not necessarily a Primary Key)
     *
     * @param $row
     * @return mixed
     */
    protected function getKey($row)
    {
        foreach ($this->keyFields as $index => $column) {
            $result[$column] = $row[$column];
        }

        return $result;
    }

    /**
     * Validates values of row against given set of rules
     *
     * @param $row
     * @param $rules
     * @param $line
     */
    protected function validateRow($row, $rules, $line)
    {
        $validator = Validator::make($row, $rules);

        if ($validator->fails()) {
            $this->abort(implode(' ', $validator->errors()->all()));
        }
    }

    /**
     * Checks whether the table exists
     *
     * @param string $table
     * @return bool
     */
    protected function tableExists($table)
    {
        if (Schema::hasTable($table)) {
            return $table;
        }

        return null;
    }

    /**
     * Checks whether a model class exists and is an instance of Illuminate\Database\Eloquent\Model
     *
     * @param string $abstract
     * @return null|\Illuminate\Database\Eloquent\Model
     */
    protected function modelExists($abstract)
    {
        try {
            $instance = app($abstract);
        } catch (\Exception $e) {
            return null;
        }

        return $instance instanceof Model ? $instance : null;
    }

    /**
     * Are we using a table?
     *
     * @return bool
     */
    protected function isTable()
    {
        return ! empty($this->table);
    }

    /**
     * Are we using an Eloquent model?
     *
     * @return bool
     */
    protected function isModel()
    {
        return ! $this->isTable();
    }

    /**
     * Validates field names
     */
    protected function validateFields()
    {
        foreach (array_keys($this->fields) as $field) {
            if ($this->isTable()) {
                if (! Schema::hasColumn($this->table, $field)) {
                    $this->abort("Column '$field' doesn't exist.");
                }
            } else { // isModel
                if (! in_array($field, $this->model->getFillable()) || in_array($field, $this->model->getGuarded())) {
                    $this->abort("Attribute '$field' doesn't exist or is not fillable.");
                }
            }
        }
    }

    /**
     * Returns row validation rules
     * Takes into account that in UPDATE mode we don't neeed full set of rules
     *
     * @return null|array
     */
    protected function getRowValidationRules()
    {
        if (empty($this->rules)) {
            return;
        }

        if (self::MODE_UPDATE == $this->mode) {
            return array_intersect_key($this->rules, array_flip($this->fields));
        }

        return $this->rules;
    }

    /**
     * Performs UPSERT
     *
     * @param $row
     */
    protected function upsert($row)
    {
        $key = $this->getKey($row);

        if ($this->dryRun) {
            return;
        }

        if ($this->isTable()) {
            $this->tableUpsert($row, $key);
        } else {
            $this->modelUpsert($row);
        }
    }

    /**
     * Performs UPDATE
     *
     * @param $row
     */
    protected function update($row)
    {
        $key = $this->getKey($row);

        if ($this->dryRun) {
            return;
        }

        if ($this->isTable()) {
            $this->tableUpdate($row, $key);
        } else {
            $this->modelUpdate($row, $key);
        }
    }

    /**
     * Performs INSERT
     *
     * @param $row
     */
    protected function insert($row)
    {
        if ($this->dryRun) {
            return;
        }

        if ($this->isTable()) {
            $this->tableInsert($row);
        } else {
            $this->modelInsert($row);
        }
    }

    /**
     * Inserts a row into the table
     *
     * @param $row
     */
    protected function tableInsert($row)
    {
        DB::table($this->table)
            ->insert($row);
    }

    /**
     * Updates row(s) in the table
     *
     * @param $row
     * @param $key
     */
    protected function tableUpdate($row, $key)
    {
        DB::table($this->table)
            ->where($key)
            ->update($row);
    }

    /**
     * Upserts row(s) in the table
     *
     * @param $row
     * @param $key
     */
    protected function tableUpsert($row, $key)
    {
        $record = DB::table($this->table)
            ->select(DB::raw('1'))
            ->where($key)
            ->take(1)
            ->first();

        if (is_null($record)) {
            $this->tableInsert($row);
        } else {
            $this->tableUpdate($row, $key);
        }
    }

    /**
     * Inserts a row using the model
     *
     * @param $row
     */
    protected function modelInsert($row)
    {
        $record = $this->model
            ->create($row);
    }

    /**
     * Updates row(s) using the model
     *
     * @param $row
     * @param $key
     */
    protected function modelUpdate($row, $key)
    {
        $this->model
            ->where($key)
            ->update($row);
    }

    /**
     * Upserts row(s) using the model
     *
     * @param $row
     */
    protected function modelUpsert($row)
    {
        $record = $this->model
            ->updateOrCreate($this->getKey($row), $row);
    }

    /**
     * Returns named DB connection
     */
    private function setDefaultConnection()
    {
        try {
            // Explicitely set the default connection name
            Config::set('database.default', $this->connectionName);
            // Try to connect
            $connection = DB::connection();
            // Close the connection
            $connection = null;
        } catch (\Exception $e) {
            $this->abort($e->getMessage());
        }
    }

    /**
     * Displays the number of imported records
     */
    protected function reportImported()
    {
        $message = "<info>Imported {$this->imported} record(s) in " . $this->getElapsedTime($this->startedAt) . 'ms.</info>';

        if ($this->dryRun) {
            $message = '<comment>Dry run: </comment>' . $message;
        }

        $this->output->writeln($message);
    }

    /**
     * Displays the message and exit
     *
     * @param $message
     * @param int $code
     */
    protected function abort($message, $code = 1)
    {
        $this->comment("Error(L{$this->fileLine}): $message");

        if ($this->imported > 0) {
            $this->reportImported();
        }

        exit($code);
    }

    /**
     * Get the elapsed time since a given starting point.
     *
     * @param  int    $start
     * @return float
     */
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    protected function unescape($value)
    {
        return preg_replace_callback(
            '/\\\\([nrtvf\\\\$"]|[0-7]{1,3}|\x[0-9A-Fa-f]{1,2})/',
            function ($in) {
                return stripcslashes("$in[0]");
            },
            $value
        );
    }

    /**
     * Gets field definitions either from arguments or file
     *
     * @return array
     */
    protected function getFieldDefinitions()
    {
        if ($this->option('fields')) {
            return array_map('trim', explode(',', $this->option('fields')));
        }

        if ($this->option('field-file')) {
            $fieldFilePath = $this->validateFile($this->option('field-file'), 'Field file');
            return array_map('trim', file($fieldFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }

        return null;
    }

    /**
     * Parses field definitions
     *
     * @param $fieldDefinitions
     */
    protected function parseFields($fieldDefinitions)
    {
        $fields = [];
        $positions = [];
        foreach ($fieldDefinitions as $field) {
            if (Str::contains($field, ':')) {
                list($field, $position) = array_map('trim', explode(':', $field));
                if (false === ($position = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]))) {
                    $this->abort("Invalid position for the field '$field'");
                }
                $positions[$field] = $position;
            }
            $fields[] = $field;
        }
        $this->fields = array_merge(array_flip($fields), $positions);
    }

    /**
     * Validates that the file exists is readable and is not empty
     * and returns an absolute path to the file
     *
     * @param $filePath
     * @param string $message
     * @return string
     */
    protected function validateFile($filePath, $message = 'File')
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            $this->abort("$message '{$filePath}' doesn't exist or is not readable.");
        }

        if (0 === filesize($filePath)) {
            $this->abort("$message '{$filePath}' is empty.");
        }

        return realpath($filePath);
    }
}
