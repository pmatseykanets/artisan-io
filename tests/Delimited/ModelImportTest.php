<?php

namespace ArtisanIo\Import\Delimited;

use Mockery as m;
use Illuminate\Database\Eloquent\Model;

class ModelImportTest extends BaseTest
{
    protected $abstract = ModelImport::class;

    public function testItSetsModelName()
    {
        $modelClass = TestModel::class;
        $model = m::mock($modelClass);

        $container = m::mock('Illuminate\Contracts\Container\Container');
        $container->shouldReceive('make')->once()
            ->with('\\'.$modelClass)->andReturn($model);

        $import = $this->getInstance(compact('container'));

        $return = $import->setTargetName('\\'.$modelClass);
        $this->assertInstanceOf($modelClass, $import->getModel());
        $this->assertInstanceOf(Model::class, $import->getModel());

        $this->assertReturnsSelf($import, $return);
    }

    public function testItThrowsExceptionIfModelDoesNotExist()
    {
        $modelClass = '\Bar';
        $container = m::mock('Illuminate\Contracts\Container\Container');
        $container->shouldReceive('make')->once()->with($modelClass)->andThrow('\Exception');

        $import = $this->getInstance(compact('container'));

        $this->setExpectedException('RuntimeException');

        $import->setTargetName($modelClass);
    }

    /**
     * @depends testItSetsModelName
     */
    public function testItInserts()
    {
        $model = m::mock(TestModel::class);

        $model->shouldReceive('create')->once()
            ->with(['foo' => 1, 'bar' => 'bar 1']);
        $model->shouldReceive('create')->once()
            ->with(['foo' => 2, 'bar' => 'bar2']);

        $this->runImport($model, Import::MODE_INSERT);
    }

    public function testItInsertsNew()
    {
        $model = m::mock(TestModel::class);
        $model->shouldReceive('create');

        $foo1 = m::mock(FooModel::class);
        $foo1->exists = true;
        $foo1->shouldNotReceive('fill')
            ->with(['foo' => 1, 'bar' => 'bar 1'])
            ->andReturnSelf()->getMock()
            ->shouldNotReceive('save');

        $foo2 = m::mock(FooModel::class);
        $foo2->exists = false;
        $foo2->shouldReceive('fill')->once()
            ->with(['foo' => 2, 'bar' => 'bar2'])
            ->andReturnSelf()->getMock()
            ->shouldReceive('save');

        $model->shouldReceive('firstOrNew')->once()
            ->with(['foo' => 1])
            ->andReturn($foo1);
        $model->shouldReceive('firstOrNew')->once()
            ->with(['foo' => 2])
            ->andReturn($foo2);

        $this->runImport($model, Import::MODE_INSERT_NEW);
    }

    public function testItUpdates()
    {
        $foo1 = m::mock(FooModel::class);
        $foo1->shouldReceive('fill')->once()
            ->with(['foo' => '1', 'bar' => 'bar 1'])
            ->andReturnSelf()->getMock()
            ->shouldReceive('save');

        $model = m::mock(TestModel::class);

        $model->shouldReceive('where')->twice()
            ->andReturnSelf()->getMock()
            ->shouldReceive('get')
            ->andReturn([$foo1], []);

        $this->runImport($model, Import::MODE_UPDATE);
    }

    public function testItUpserts()
    {
        $foo1 = m::mock(FooModel::class);
        $foo1->shouldReceive('fill')->once()
            ->with(['foo' => '1', 'bar' => 'bar 1'])
            ->andReturnSelf()->getMock()
            ->shouldReceive('save');
        $foo1->shouldReceive('create');

        $model = m::mock(TestModel::class);
        $model->shouldReceive('where')->twice()
            ->andReturnSelf()->getMock()
            ->shouldReceive('get')
            ->andReturn([$foo1], []);

        $model->shouldReceive('create')->once()
            ->with(['foo' => 2, 'bar' => 'bar2']);

        $this->runImport($model, Import::MODE_UPSERT);
    }

    /**
     * @param $model
     * @param $mode
     */
    private function runImport($model, $mode)
    {
        file_put_contents($this->importFile, '1,"bar 1"'."\n".'2,bar2');

        $import = $this->getInstance();

        $import
            ->setModel($model)
            ->setFields('foo,bar')
            ->setKeyFields('foo')
            ->setImportFile($this->importFile)
            ->setMode($mode)
            ->import();
    }
}

class TestModel extends Model
{
}

class FooModel extends Model
{
}
