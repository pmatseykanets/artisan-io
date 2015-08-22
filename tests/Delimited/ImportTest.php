<?php

namespace ArtisanIo\Delimited;

use Mockery as m;
use ArtisanIo\TestCase;

class ImportTest extends TestCase
{
    protected $abstract = CommonBaseImport::class;

    protected $emptyFile = __DIR__.'/empty.file';
    protected $importFile = __DIR__.'/import.csv';
    protected $fieldFile = __DIR__.'/import.fields';
    protected $rulesFile = __DIR__.'/import.rules';

    public function tearDown()
    {
        parent::tearDown();

        m::close();

        // Check for "remnants"
        foreach ([$this->emptyFile, $this->importFile, $this->fieldFile, $this->rulesFile] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    public function testItCanBeInstantiated()
    {
        $import = $this->getInstance();
        $this->assertInstanceOf($this->abstract, $import);
    }

    public function testItSetsImportFile()
    {
        $import = $this->getInstance();

        file_put_contents($this->importFile, 'foo,bar,baz');

        $return = $import->setImportFile($this->importFile);
        $this->assertEquals($this->importFile, $import->getImportFile());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItThrowsExceptionIfImportFileDoesNotExist()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');
        $import->setImportFile('non_existing_file.csv');
    }

    public function testItThrowsExceptionIfImportFileIsEmpty()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');

        file_put_contents($this->emptyFile, '');

        $import->setImportFile($this->emptyFile);
    }

    public function testItSetsDatabaseConnection()
    {
        $config = m::mock('Illuminate\Config\Repository');
        $config->shouldReceive('set')->once()->with('database.default', 'mysql');

        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('connection')->once();

        $import = $this->getInstance(['config' => $config, 'db' => $db]);

        $return = $import->setConnectionName('mysql');
        $this->assertReturnsSelf($import, $return);
    }

    public function testItThrowsExceptionForInvalidConnectionName()
    {
        $config = m::mock('Illuminate\Config\Repository');
        $config->shouldReceive('set')->once()->with('database.default', 'none');

        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('connection')->once()->andThrow('InvalidArgumentException');

        $import = $this->getInstance(['config' => $config, 'db' => $db]);

        $this->setExpectedException('RuntimeException');

        $import->setConnectionName('none');
    }

    public function testItSetsUseTransactionFlag()
    {
        $import = $this->getInstance();

        $return = $import->setUseTransaction(true);
        $this->assertTrue($import->getUseTransaction());
        $this->assertReturnsSelf($import, $return);

        $return = $import->setUseTransaction(false);
        $this->assertFalse($import->getUseTransaction());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItSetsAllSupportedModes()
    {
        $modes = ['insert', 'insert-new', 'update', 'upsert'];

        $import = $this->getInstance();

        foreach ($modes as $mode) {
            $return = $import->setMode($mode);
            $this->assertEquals($mode, $import->getMode());
            $this->assertReturnsSelf($import, $return);
        }
    }

    public function testItThrowsExceptionForInvalidMode()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');

        $import->setMode('invalid');
    }

    public function testItSetsDelimiter()
    {
        $import = $this->getInstance();

        $delimiters = [',', "\t"];

        foreach ($delimiters as $delimiter) {
            $return = $import->setDelimiter($delimiter);
            $this->assertEquals($delimiter, $import->getDelimiter());
            $this->assertReturnsSelf($import, $return);
        }
    }

    public function testDefaultIgnoreLinesIsSetToZero()
    {
        $import = $this->getInstance();

        $this->assertEquals(0, $import->getIgnoreLines());
    }

    public function testItSetsIgnoreLines()
    {
        $import = $this->getInstance();

        $return = $import->setIgnoreLines(1);
        $this->assertEquals(1, $import->getIgnoreLines());
        $this->assertReturnsSelf($import, $return);
    }

    public function testDefaultTakeLinesIsSetToNull()
    {
        $import = $this->getInstance();

        $this->assertNull($import->getTakeLines());
    }

    public function testItIgnoresLines()
    {
        $import = $this->getInstance();

        file_put_contents($this->importFile, 'foo,bar'."\n".'1,bar1'."\n".'2,bar2');

        $import
            ->setTargetName('table')
            ->setFields('foo,bar')
            ->setImportFile($this->importFile)
            ->setMode(BaseImport::MODE_INSERT)
            ->setIgnoreLines(1)
            ->import();

        $this->assertEquals(2, $import->getImportedCount());
    }

    public function testItSetsTakeLines()
    {
        $import = $this->getInstance();

        $return = $import->setTakeLines(1);
        $this->assertEquals(1, $import->getTakeLines());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItTakesLines()
    {
        $import = $this->getInstance();

        file_put_contents($this->importFile, 'foo,bar'."\n".'1,bar1'."\n".'2,bar2');

        $import
            ->setTargetName('table')
            ->setFields('foo,bar')
            ->setImportFile($this->importFile)
            ->setMode(BaseImport::MODE_INSERT)
            ->setIgnoreLines(1)
            ->setTakeLines(1)
            ->import();

        $this->assertEquals(1, $import->getImportedCount());
    }

    public function testDefaultDryRunSetToFalse()
    {
        $import = $this->getInstance();

        $this->assertFalse($import->getDryRun());
    }

    public function testItSetsDryRunFlag()
    {
        $import = $this->getInstance();

        $return = $import->setDryRun(true);
        $this->assertTrue($import->getDryRun());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItThrowsExceptionIfFieldsStringIsEmpty()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');

        $import->setFields('');
    }

    public function testItThrowsExceptionIfFieldsStringConsistsSolelyOfCommas()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');

        $import->setFields(',,');
    }

    public function testItThrowsExceptionIfFieldFileDoesNotExist()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');

        $import->setFieldsFromFile('none.fields');
    }

    public function testItThrowsExceptionIfFieldFileIsEmpty()
    {
        $import = $this->getInstance();

        $this->setExpectedException('RuntimeException');

        $import->setFieldsFromFile($this->emptyFile);
    }

    public function testItThrowsExceptionIfFieldFileJustContainsEmptyLinesOrWhiteSpace()
    {
        $import = $this->getInstance();

        file_put_contents($this->fieldFile, "\n\t\n    \n");

        $this->setExpectedException('RuntimeException');

        $import->setFieldsFromFile($this->fieldFile);
    }

    public function testItSetFieldsWithoutPositionsFromString()
    {
        $import = $this->getInstance();

        $return = $import->setFields('foo,bar');
        $this->assertEquals(['foo' => 0, 'bar' => 1], $import->getFields());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItSetFieldsWithPositionsFromString()
    {
        $import = $this->getInstance();

        $return = $import->setFields('foo:2,bar:0');
        $this->assertEquals(['foo' => 2, 'bar' => 0], $import->getFields());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItSetFieldsWithoutPositionsFromFile()
    {
        $import = $this->getInstance();

        file_put_contents($this->fieldFile, "foo\nbar");

        $return = $import->setFieldsFromFile($this->fieldFile);
        $this->assertEquals(['foo' => 0, 'bar' => 1], $import->getFields());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItSetFieldsWithPositionsFromFile()
    {
        $import = $this->getInstance();

        file_put_contents($this->fieldFile, "foo:2\nbar:0");

        $return = $import->setFieldsFromFile($this->fieldFile);
        $this->assertEquals(['foo' => 2, 'bar' => 0], $import->getFields());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItSetsValidationRules()
    {
        $import = $this->getInstance();

        $rules = ['foo' => 'required|integer', 'bar' => 'date'];

        $return = $import->setValidationRules($rules);
        $this->assertEquals($rules, $import->getValidationRules());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItSetsValidationRulesFromFile()
    {
        $import = $this->getInstance();

        $rules = ['foo' => 'required|integer', 'bar' => 'date'];
        file_put_contents($this->rulesFile, '<?php return '.var_export($rules, true).';');

        $return = $import->setValidationRulesFromFile($this->rulesFile);

        $this->assertEquals($rules, $import->getValidationRules());
        $this->assertReturnsSelf($import, $return);
    }

    public function testItUsesTransaction()
    {
        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('commit')->once();

        $import = $this->getInstance(['db' => $db]);

        file_put_contents($this->importFile, '1,bar1'."\n".'2,bar2');

        $import
            ->setTargetName('table')
            ->setFields('foo,bar')
            ->setImportFile($this->importFile)
            ->setMode(BaseImport::MODE_INSERT)
            ->setUseTransaction(true)
            ->import();
    }

    public function testItDoesNotUseTransaction()
    {
        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('beginTransaction')->never();
        $db->shouldReceive('commit')->never();

        $import = $this->getInstance(['db' => $db]);

        file_put_contents($this->importFile, '1,bar1'."\n".'2,bar2');

        $import
            ->setTargetName('table')
            ->setFields('foo,bar')
            ->setImportFile($this->importFile)
            ->setMode(BaseImport::MODE_INSERT)
            ->import();
    }

    /**
     * @param array $mocks
     *
     * @return EloquentTableImport
     */
    protected function getInstance($mocks = [])
    {
        $container = isset($mocks['container']) ? $mocks['container'] : m::mock('Illuminate\Contracts\Container\Container');
        $db = isset($mocks['db']) ? $mocks['db'] : m::mock('Illuminate\Database\DatabaseManager');
        $config = isset($mocks['config']) ? $mocks['config'] : m::mock('Illuminate\Config\Repository');
        $validator = isset($mocks['validator']) ? $mocks['validator'] : m::mock('Illuminate\Validation\Factory');

        $instance = new $this->abstract($container, $db, $config, $validator);

        return $instance;
    }

    protected function assertReturnsSelf($expect, $actual)
    {
        $this->assertInstanceOf(get_class($expect), $actual);
    }
}

class CommonBaseImport extends BaseImport
{
    protected function upsert($row, $key)
    {
    }
    protected function update($row, $key)
    {
    }
    protected function insertNew($row, $key)
    {
    }
    protected function insert($row)
    {
    }
    protected function validateFields($fieldDefinitions)
    {
        return $fieldDefinitions;
    }
}
