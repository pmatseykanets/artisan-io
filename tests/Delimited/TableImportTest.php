<?php

namespace ArtisanIo\Delimited;

use Mockery as m;

class TableImportTest extends BaseTest
{
    protected $abstract = TableImport::class;

    public function testItSetsTableName()
    {
        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('connection->getSchemaBuilder->hasTable')->once()->with('table')->andReturn(true);

        $import = $this->getInstance(['db' => $db]);

        $import->setTargetName('table');
        $this->assertEquals('table', $import->getTargetName());
    }

    public function testItThrowsExceptionIfTableDoesNotExist()
    {
        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('connection->getSchemaBuilder->hasTable')->once()->with('none')->andReturn(false);

        $import = $this->getInstance(['db' => $db]);

        $this->expectException('RuntimeException');

        $import->setTargetName('none');
    }

    public function testItThrowsExceptionIfFieldDoesNotExistFromString()
    {
        $db = $this->getMockDb('table', ['foo', 'bar']);
        $db->shouldReceive('connection->getSchemaBuilder->hasColumn')->once()->with('table', 'baz')->andReturn(false);
        $import = $this->getInstance(['db' => $db]);
        $import->setTargetName('table');

        $this->expectException('RuntimeException');

        $return = $import->setFields('foo,bar,baz');
    }

    /**
     * @depends testItSetsTableName
     */
    public function testItThrowsExceptionIfFildsDoesNotExistFromFile()
    {
        $db = $this->getMockDb('table', ['foo', 'bar']);
        $db->shouldReceive('connection->getSchemaBuilder->hasColumn')->once()->with('table', 'baz')->andReturn(false);
        $import = $this->getInstance(['db' => $db]);
        $import->setTargetName('table');

        file_put_contents($this->fieldFile, "foo\nbar\nbaz");

        $this->expectException('RuntimeException');

        $return = $import->setFieldsFromFile($this->fieldFile);
    }

    /**
     * @depends testItSetsTableName
     */
    public function testItInserts()
    {
        $db = $this->getMockDb('table', ['foo', 'bar']);
        $db->shouldReceive('table->insert')->once()->with(['foo' => 1, 'bar' => 'bar 1']);
        $db->shouldReceive('table->insert')->once()->with(['foo' => 2, 'bar' => 'bar2']);

        $this->runImport($db, BaseImport::MODE_INSERT);
    }

    /**
     * @depends testItSetsTableName
     */
    public function testItInsertsNew()
    {
        $db = $this->getMockDb('table', ['foo', 'bar']);
        $db->shouldReceive('raw');

        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('select')->andReturnSelf()->getMock()
            ->shouldReceive('where')->with(['foo' => 1])->andReturnSelf()->getMock()
            ->shouldReceive('take')->with(1)->andReturnSelf()->getMock()
            ->shouldReceive('first')->andReturn([['1']]);

        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('select')->andReturnSelf()->getMock()
            ->shouldReceive('where')->with(['foo' => 2])->andReturnSelf()->getMock()
            ->shouldReceive('take')->with(1)->andReturnSelf()->getMock()
            ->shouldReceive('first')->andReturnNull();

        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('insert')->with(['foo' => 2, 'bar' => 'bar2']);

        $db->shouldNotReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldNotReceive('insert')->with(['foo' => 1, 'bar' => 'bar 1']);

        $this->runImport($db, BaseImport::MODE_INSERT_NEW, 'foo');
    }

    /**
     * @depends testItSetsTableName
     */
    public function testItUpdates()
    {
        $db = $this->getMockDb('table', ['foo', 'bar']);

        // update() should be called for each record
        // SQL will take care of non matching key
        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('where')->with(['foo'  => 1])->andReturnSelf()->getMock()
            ->shouldReceive('update')->with(['foo' => 1, 'bar' => 'bar 1']);

        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('where')->with(['foo'  => 2])->andReturnSelf()->getMock()
            ->shouldReceive('update')->with(['foo' => 2, 'bar' => 'bar2']);

        $this->runImport($db, BaseImport::MODE_UPDATE, 'foo');
    }

    /**
     * @depends testItSetsTableName
     */
    public function testItUpserts()
    {
        $db = $this->getMockDb('table', ['foo', 'bar']);
        $db->shouldReceive('raw');

        $db->shouldReceive('table')->once()->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('select')->once()->andReturnSelf()->getMock()
            ->shouldReceive('where')->once()->with(['foo' => 1])->andReturnSelf()->getMock()
            ->shouldReceive('take')->once()->with(1)->andReturnSelf()->getMock()
            ->shouldReceive('first')->once()->andReturn([['1']]);

        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('select')->andReturnSelf()->getMock()
            ->shouldReceive('where')->with(['foo' => 2])->andReturnSelf()->getMock()
            ->shouldReceive('take')->with(1)->andReturnSelf()->getMock()
            ->shouldReceive('first')->andReturnNull();

        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('where')->with(['foo'  => 1])->andReturnSelf()->getMock()
            ->shouldReceive('update')->with(['foo' => 1, 'bar' => 'bar 1']);

        //
        $db->shouldReceive('table')->with('table')->andReturnSelf()->getMock()
            ->shouldReceive('insert')->with(['foo' => 2, 'bar' => 'bar2']);

        $this->runImport($db, BaseImport::MODE_UPSERT, 'foo');
    }

    /**
     * He[per method.
     *
     * @param $table
     * @param string|array $fields
     *
     * @return m\MockInterface|\Yay_MockObject
     */
    private function getMockDb($table, $fields)
    {
        $db = m::mock('Illuminate\Database\DatabaseManager');
        $db->shouldReceive('connection->getSchemaBuilder->hasTable')->with($table)->andReturn(true);

        foreach (is_array($fields) ? $fields : [$fields] as $field) {
            $db->shouldReceive('connection->getSchemaBuilder->hasColumn')->with($table, $field)->andReturn(true);
        }

        return $db;
    }

    /**
     * Helper method.
     *
     * @param $db
     * @param $mode
     * @param null $keyFields
     */
    protected function runImport($db, $mode, $keyFields = null)
    {
        $import = $this->getInstance(['db' => $db]);

        file_put_contents($this->importFile, '1,"bar 1"'."\n".'2,bar2');

        $import
            ->setTable('table')
            ->setFields('foo,bar');

        if (!is_null($keyFields)) {
            $import->setKeyFields($keyFields);
        }

        $import
            ->setImportFile($this->importFile)
            ->setMode($mode)
            ->import();
    }
}
