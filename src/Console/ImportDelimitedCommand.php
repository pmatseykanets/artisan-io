<?php

namespace ArtisanIo\Console;

use ArtisanIo\Delimited\BaseImport;
use ArtisanIo\Delimited\ModelImport;
use ArtisanIo\Delimited\TableImport;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Str;

class ImportDelimitedCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:delimited
        {from            : The path to an import file i.e. storage/import.csv}
        {to              : The table or Eloquent model class name}
        {--f|fields=     : A comma separated list of field definitions in a form <field>[:position] i.e. "email:0,name,2". Positions are 0 based}
        {--F|field-file= : Path to a file that contains field definitions. One definition per line}
        {--m|mode=upsert : Import mode [insert|insert-new|update|upsert]}
        {--k|key=        : Field names separated by a comma that constitute a key for update, upsert and insert-new modes}
        {--R|rule-file=  : Path to a file that contains field validation rules}
        {--d|delimiter=, : Field delimiter}
        {--i|ignore=     : Ignore first N lines of the file}
        {--t|take=       : Take only M lines}
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
    protected $description = 'Import data from a delimited file using a table or Eloquent model.';

    protected $showProgress = true;
    protected $lastProgressValue = 0;

    /** @var \ArtisanIo\ImportDelimited */
    protected $import;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return;
        }

        try {
            $this->parseArguments();

            $this->import
                ->setBeforeHandler(function () {
                    if ($this->showProgress) {
                        $this->output->progressStart($this->getProgressMaxValue());
                    }
                })
                ->setImportedHandler(function () {
                    if ($this->showProgress) {
                        $currentValue = $this->getProgressCurrentValue();
                        $this->output->progressAdvance($currentValue - $this->lastProgressValue);
                        $this->lastProgressValue = $currentValue;
                    }
                })
                ->setAfterHandler(function () {
                    if ($this->showProgress) {
                        $this->progressRemove();
                    }
                })
                ->import();
        } catch (\Exception $e) {
            $this->abort($e->getMessage());
        }

        $this->reportImported();
    }

    /**
     * Parse input arguments.
     */
    protected function parseArguments()
    {
        $targetName = trim($this->argument('to'));

        // Instanciate a proper importer depending on the import target (table or model)
        // If target name starts with a slash '\' then we consider it a model otherwise a table
        if (Str::startsWith($targetName, '\\')) {
            $this->import = app()->make(ModelImport::class);
        } else {
            $this->import = app()->make(TableImport::class);
        }

        $this->import->setImportFile(trim($this->argument('from')));

        // DB connection
        if ($this->option('database')) {
            $this->import->setConnectionName($this->option('database'));
        }

        // Are we going to use transactions?
        $this->import->setUseTransaction($this->option('transaction') ?: false);

        // After we set up the connection we can finally set the target
        $this->import->setTargetName($targetName);

        // Import mode
        $this->import->setMode($this->option('mode') ?: BaseImport::MODE_UPSERT);

        // Import field definitions
        if (empty($this->option('fields')) && empty($this->option('field-file'))) {
            throw new \RuntimeException("Import fields haven't been specified. Use -f or -F option.");
        }

        // Read validate and assign field definitions
        if ($this->option('fields')) {
            $this->import->setFields($this->option('fields'));
        }

        if ($this->option('field-file')) {
            $this->import->setFieldsFromFile($this->option('field-file'));
        }

        // Key fields
        $this->import->setKeyFields($this->option('key'));

        // Row validation rules file
        if ($this->option('rule-file')) {
            $this->import->setValidationRulesFromFile($this->option('rule-file'));
        }

        $this->import
            ->setDelimiter($this->unescape($this->option('delimiter') ?: ','))
            ->setIgnoreLines($this->option('ignore'))
            ->setDryRun($this->option('dry-run'));

        if ($this->option('take')) {
            $this->import->setTakeLines($this->option('take'));
        }

        // Are we going to display the progress bar?
        $this->showProgress = ! $this->option('no-progress');
    }

    /**
     * Displays the number of imported records.
     */
    protected function reportImported()
    {
        if (! $this->import) {
            return;
        }

        $message = "<info>Processed {$this->import->getImportedCount()} row(s) in ".
            $this->renderElapsedTime($this->import->getExecutionTime()).'.</info>';

        if ($this->import->isDryRun()) {
            $message = '<comment>Dry run: </comment>'.$message;
        }

        $this->output->writeln($message);
    }

    /**
     * Displays the message and exits.
     *
     * @param $message
     * @param int $code
     */
    protected function abort($message, $code = 1)
    {
        if ($this->showProgress) {
            $this->progressRemove();
        }

        if (! is_null($this->import)) {
            $this->reportImported();

            if ($lastLine = $this->import->getCurrentFileLine()) {
                $this->info("Last attempted line: $lastLine.");
            }
        }

        throw new \RuntimeException($message, $code);
    }

    /**
     * Render elapsed time in a more human readable way.
     *
     * @param $milliseconds
     *
     * @return string
     */
    protected function renderElapsedTime($milliseconds)
    {
        if ($milliseconds < 1000) {
            return round($milliseconds, 2).'ms';
        }

        if ($milliseconds >= 1000 && $milliseconds < 60000) {
            return round($milliseconds / 1000, 2).'s';
        }

        return round($milliseconds / 60000, 2).'min';
    }

    /**
     * Interpret escape characters.
     *
     * @param $value
     *
     * @return mixed
     */
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
     * Removes the progress bar.
     */
    protected function progressRemove()
    {
        $this->output->write("\x0D");
        $this->output->write(str_repeat(' ', 60)); // Revisit this
        $this->output->write("\x0D");
    }

    /**
     * Gets the max value for the progress bar off of import object.
     *
     * @return mixed
     */
    private function getProgressMaxValue()
    {
        if ($value = $this->import->getTakeLines()) {
            return $value;
        }

        return $this->import->getImportFileSize();
    }

    /**
     * Gets the current value for the progress bar off of import object.
     *
     * @return mixed
     */
    private function getProgressCurrentValue()
    {
        if ($this->import->getTakeLines()) {
            return $this->import->getImportedCount();
        }

        return $this->import->getBytesRead();
    }
}
