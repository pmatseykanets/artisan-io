<?php

namespace ArtisanIo\Delimited;

use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Factory as Validator;

abstract class BaseImport
{
    const MODE_INSERT = 'insert';
    const MODE_INSERT_NEW = 'insert-new';
    const MODE_UPDATE = 'update';
    const MODE_UPSERT = 'upsert';

    protected $importFile;
    protected $fileIterator;
    protected $targetName;
    protected $connectionName;
    protected $useTransaction;

    protected $mode = self::MODE_UPSERT;
    protected $fields;
    protected $keyFields;
    protected $delimiter = ',';
    protected $ignoreLines = 0;
    protected $takeLines;
    protected $rules = [];
    protected $dryRun = false;

    // Statistics
    protected $startedAt;
    protected $executionTime = 0; //???
    protected $imported = 0;

    // Event handlers
    protected $beforeHandler;
    protected $afterHandler;
    protected $importedHandler;

    /** @var \Illuminate\Database\DatabaseManager */
    protected $db;
    /** @var \Illuminate\Config\Repository */
    protected $config;
    /** @var \Illuminate\Validation\Factory */
    protected $validator;
    /** @var \Illuminate\Contracts\Container\Container */
    protected $container;

    public function __construct(Container $container, DB $db, Config $config, Validator $validator)
    {
        $this->container = $container;
        $this->db = $db;
        $this->config = $config;
        $this->validator = $validator;
    }

    /**
     * Performs the import.
     */
    public function import()
    {
        $this->startedAt = microtime(true);

        $this->callEventHandler($this->beforeHandler);

        $this->fileIterator = $this->openFile($this->getImportFile(), $this->getDelimiter(), $this->getIgnoreLines(), $this->getTakeLines());

        $this->imported = 0;

        $rules = $this->getRowValidationRules();

        if ($this->useTransaction) {
            $this->db->beginTransaction();
        }

        foreach ($this->fileIterator as $values) {
            $row = $this->mapValues($values, $this->getFields());
            $key = $this->mapValues($values, $this->getKeyFields());

            if (! empty($rules)) {
                $this->validateRow($row, $rules);
            }

            switch ($this->mode) {
                case self::MODE_INSERT:
                    $this->insert($row);
                    break;
                case self::MODE_INSERT_NEW:
                    $this->insertNew($row, $key);
                    break;
                case self::MODE_UPDATE:
                    $this->update($row, $key);
                    break;
                default: //self::MODE_UPSERT
                    $this->upsert($row, $key);
            }

            $this->imported++;

            $this->callEventHandler($this->importedHandler);
        }

        if ($this->useTransaction) {
            $this->db->commit();
        }

        $this->callEventHandler($this->afterHandler);

        $this->fileIterator = null;

        $this->executionTime = $this->getElapsedTime($this->startedAt);
    }

    /**
     * Open the file and returns an iterator.
     *
     * @param $filePath
     * @param $delimiter
     * @param $skip
     * @param $take
     *
     * @return \LimitIterator
     */
    protected function openFile($filePath, $delimiter, $skip, $take)
    {
        $file = new \SplFileObject($filePath, 'r');
        $file->setFlags(\SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE | \SplFileObject::READ_CSV);
        $file->setCsvControl($delimiter);

        return new \LimitIterator($file, $skip, $take ?: -1);
    }

    /**
     * Turns an array of values into an associative array where keys are column/attribute names.
     *
     * @param array $values
     * @param array $columns
     *
     * @return array
     */
    protected function mapValues($values, $columns)
    {
        $mapped = [];

        foreach ($columns as $column => $position) {
            if ($position > count($values) - 1) {
                throw new \RuntimeException("Position '$position' is out of scope for field '$column'. Make sure you use a proper delimiter.");
            }

            $mapped[$column] = trim($values[$position]);
        }

        return $mapped;
    }

    /**
     * Returns row validation rules
     * Takes into account that in UPDATE mode we don't neeed full set of rules.
     *
     * @return null|array
     */
    protected function getRowValidationRules()
    {
        if (empty($rules = $this->getValidationRules())) {
            return;
        }

        // Trim validation rules to only fields that we intend to update
        if (self::MODE_UPDATE == $this->mode) {
            return array_intersect_key($rules, array_flip($this->fields));
        }

        return $rules;
    }

    /**
     * Performs UPSERT.
     *
     * @param $row
     * @param $key
     */
    abstract protected function upsert($row, $key);

    /**
     * Performs UPDATE.
     *
     * @param $row
     * @param $key
     */
    abstract protected function update($row, $key);

    /**
     * Inserts a record only if it doesn't exist.
     *
     * @param $row
     * @param $key
     *
     * @return mixed
     */
    abstract protected function insertNew($row, $key);

    /**
     * Performs INSERT.
     *
     * @param $row
     */
    abstract protected function insert($row);

    /**
     * Changes the default connection name and tries to establish a connection.
     *
     * @param $database
     */
    protected function setDefaultConnection($database)
    {
        try {
            // Explicitely set the default connection name
            $this->config->set('database.default', $database);
            // Try to connect
            $connection = $this->db->connection();
            // Close the connection
            $connection = null;
        } catch (\Exception $e) {
            throw new \RuntimeException("Can't establish a db connection '".$e->getMessage()."'.");
        }
    }

    /**
     * Returns an array of column names and values that constitues a key (not necessarily a Primary Key).
     *
     * @param $row
     *
     * @return mixed
     *
     * @deprecated
     */
    protected function getKey($row)
    {
        return array_intersect_key($row, array_flip($this->keyFields));
    }

    /**
     * Validates values of row against given set of rules.
     *
     * @param $row
     * @param $rules
     */
    protected function validateRow($row, $rules)
    {
        $validator = $this->validator->make($row, $rules);

        if ($validator->fails()) {
            throw new \RuntimeException(implode(' ', $validator->errors()->all()));
        }
    }

    /**
     * Validates field names.
     *
     * @param $fieldDefinitions
     */
    abstract protected function validateFields($fieldDefinitions);

    /**
     * Parses field definitions.
     *
     * @param $fieldDefinitions
     *
     * @return array
     */
    protected function parseFields($fieldDefinitions)
    {
        $fields = [];
        $positions = [];

        foreach ($fieldDefinitions as $field) {
            if (Str::contains($field, ':')) {
                [$field, $position] = array_map('trim', explode(':', $field));

                if (false === ($position = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]))) {
                    throw new \RuntimeException("Invalid position for the field '$field'.");
                }

                $positions[$field] = $position;
            }
            $fields[] = $field;
        }

        return array_merge(array_flip($fields), $positions);
    }

    /**
     * Parses key field definitions.
     *
     * @param $fieldDefinitions
     *
     * @return array
     */
    protected function parseKeyFields($fieldDefinitions)
    {
        $positions = [];

        foreach ($fieldDefinitions as $field) {
            // If an explicit position is given
            if (Str::contains($field, ':')) {
                [$field, $position] = array_map('trim', explode(':', $field));

                if (false === ($position = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]))) {
                    throw new \RuntimeException("Invalid position for the field '$field'.");
                }

                $positions[$field] = $position;
                continue;
            }
            // If not try to get from the corresponding import field
            if (! isset($this->fields[$field])) {
                throw new \RuntimeException("Invalid definition for key field '$field'.");
            }
            $positions[$field] = $this->fields[$field];
        }

        return $positions;
    }

    /**
     * Returns the name of the import target (table or model name).
     *
     * @return mixed
     */
    public function getTargetName()
    {
        return $this->targetName;
    }

    /**
     * Set's the target for the import (table or model name).
     *
     * @param $targetName
     *
     * @return mixed
     */
    public function setTargetName($targetName)
    {
        $this->targetName = $targetName;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getDelimiter()
    {
        return $this->delimiter;
    }

    /**
     * @param mixed $delimiter
     *
     * @return $this
     */
    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getIgnoreLines()
    {
        return $this->ignoreLines;
    }

    /**
     * @param mixed $ignore
     *
     * @return ImportDelimited
     */
    public function setIgnoreLines($ignore)
    {
        if (false === ($this->ignoreLines = filter_var($ignore, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]))) {
            throw new \RuntimeException('Ignore value should be a positive integer.');
        }

        return $this;
    }

    public function getTakeLines()
    {
        return $this->takeLines;
    }

    public function setTakeLines($value)
    {
        if (false === ($this->takeLines = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]))) {
            throw new \RuntimeException('Take value should be a positive integer greater or equal to 1.');
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function isDryRun()
    {
        return $this->dryRun;
    }

    public function getDryRun()
    {
        return $this->dryRun;
    }

    /**
     * @param bool $dryRun
     *
     * @return ImportDelimited
     */
    public function setDryRun($dryRun)
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * @return array
     */
    public function getValidationRules()
    {
        return $this->rules;
    }

    /**
     * @param array $rules
     *
     * @return ImportDelimited
     */
    public function setValidationRules(array $rules)
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * Read and set row validation rules from file.
     *
     * @param $path
     *
     * @return $this
     */
    public function setValidationRulesFromFile($path)
    {
        $filePath = $this->validateFile($path, 'Rules file');

        $rules = @require $filePath;

        if (empty($rules) || ! is_array($rules)) {
            throw new \RuntimeException('Invalid rules file.');
        }

        $this->setValidationRules($rules);

        return $this;
    }

    /**
     * @return mixed
     */
    public function getKeyFields()
    {
        if (! $this->keyFields) {
            $this->keyFields = $this->fields;
        }

        return $this->keyFields;
    }

    /**
     * @param string|array $value
     *
     * @return ImportDelimited
     */
    public function setKeyFields($value)
    {
        if (! is_array($value)) {
            $fields = explode(',', $value);
        }

        $fields = array_filter(array_map('trim', $fields));

        if (empty($fields) && ! empty($value)) {
            throw new \RuntimeException('Invalid key field definition');
        }

//        empty($this->option('key')) ?
//            array_keys($this->import->getFields()) :
//            array_map('trim', explode(',', $this->option('key')))

        $this->keyFields = $this->validateFields($this->parseKeyFields($fields));

        return $this;
    }

    /**
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @param string|array $fields
     *
     * @return ImportDelimited
     */
    public function setFields($fields)
    {
        if (! is_array($fields)) {
            $fields = explode(',', $fields);
        }

        $fields = array_filter(array_map('trim', $fields));

        if (empty($fields)) {
            throw new \RuntimeException('Invalid field definition');
        }

        $this->fields = $this->validateFields($this->parseFields($fields));

        return $this;
    }

    public function setFieldsFromFile($path)
    {
        $filePath = $this->validateFile($path, 'Field file');

        $fields = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->setFields($fields);

        return $this;
    }

    /**
     * @return mixed
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @param mixed $mode
     *
     * @return ImportDelimited
     */
    public function setMode($mode)
    {
        $mode = strtolower(trim($mode));

        if (! in_array($mode, [
            self::MODE_UPSERT,
            self::MODE_UPDATE,
            self::MODE_INSERT,
            self::MODE_INSERT_NEW,
        ])) {
            throw new \RuntimeException("Invalid mode '{$mode}'.");
        }

        $this->mode = $mode;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getUseTransaction()
    {
        return $this->useTransaction;
    }

    /**
     * @param mixed $useTransaction
     *
     * @return ImportDelimited
     */
    public function setUseTransaction($useTransaction)
    {
        $this->useTransaction = $useTransaction;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getConnectionName()
    {
        return $this->connectionName;
    }

    /**
     * @param mixed $connectionName
     *
     * @return ImportDelimited
     */
    public function setConnectionName($connectionName)
    {
        $this->connectionName = $connectionName;

        $this->setDefaultConnection($this->connectionName);

        return $this;
    }

    /**
     * @return mixed
     */
    public function getImportFile()
    {
        return $this->importFile;
    }

    /**
     * @param mixed $path
     *
     * @return ImportDelimited
     */
    public function setImportFile($path)
    {
        $this->importFile = $this->validateFile($path, 'BaseImport file');

        return $this;
    }

    /**
     * @return mixed
     */
    private function getFileIterator()
    {
        return $this->fileIterator;
    }

    /**
     * Returns a number of imported records.
     *
     * @return int
     */
    public function getImportedCount()
    {
        return $this->imported;
    }

    /**
     * Returns the execution time in miliseconds.
     *
     * @return float
     */
    public function getExecutionTime()
    {
        return $this->executionTime;
    }

    /**
     * Returns the number of the last file line being processed.
     *
     * @return int
     */
    public function getCurrentFileLine()
    {
        if (! $this->getFileIterator()) {
            return 0;
        }

        return $this->getFileIterator()
            ->getInnerIterator()
            ->key() + 1;
    }

    /**
     * Returns the file pointer.
     *
     * @return int
     */
    public function getBytesRead()
    {
        if (! $this->getFileIterator()) {
            return 0;
        }

        return $this->getFileIterator()
            ->getInnerIterator()
            ->ftell();
    }

    /**
     * Returns the size of the import file.
     *
     * @return int
     */
    public function getImportFileSize()
    {
        return filesize($this->importFile);
    }

    /**
     * @param mixed $callback
     *
     * @return ImportDelimited
     */
    public function setBeforeHandler($callback)
    {
        if (! is_null($callback) && ! is_callable($callback)) {
            throw new \InvalidArgumentException('Before event handler must be a valid callable (callback or object with an __invoke method), '.var_export($callback, true).' given');
        }

        $this->beforeHandler = $callback;

        return $this;
    }

    /**
     * @param mixed $callback
     *
     * @return ImportDelimited
     */
    public function setAfterHandler($callback)
    {
        if (! is_null($callback) && ! is_callable($callback)) {
            throw new \InvalidArgumentException('After event handler must be a valid callable (callback or object with an __invoke method), '.var_export($callback, true).' given');
        }

        $this->afterHandler = $callback;

        return $this;
    }

    /**
     * @param mixed $callback
     *
     * @return ImportDelimited
     */
    public function setImportedHandler($callback)
    {
        if (! is_null($callback) && ! is_callable($callback)) {
            throw new \InvalidArgumentException('Imported event handler must be a valid callable (callback or object with an __invoke method), '.var_export($callback, true).' given');
        }

        $this->importedHandler = $callback;

        return $this;
    }

    /**
     * Validates the file exists, is readable and is not empty
     * and returns an absolute path to the file.
     *
     * @param $filePath
     * @param string $message
     *
     * @return string
     */
    protected function validateFile($filePath, $message = 'File')
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            throw new \RuntimeException("$message '{$filePath}' doesn't exist or is not readable.");
        }

        if (0 === filesize($filePath)) {
            throw new \RuntimeException("$message '{$filePath}' is empty.");
        }

        return realpath($filePath);
    }

    /**
     * Calls an event's handler.
     *
     * @param $callback
     */
    private function callEventHandler($callback)
    {
        if (! is_null($callback)) {
            call_user_func($callback);
        }
    }

    /**
     * Get the elapsed time since a given starting point.
     *
     * @param int $start
     *
     * @return float
     */
    private function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
