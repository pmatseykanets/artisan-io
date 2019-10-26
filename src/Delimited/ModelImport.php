<?php

namespace ArtisanIo\Delimited;

use Illuminate\Database\Eloquent\Model;

class ModelImport extends BaseImport
{
    /** @var \Illuminate\Database\Eloquent\Model */
    protected $model;

    /**
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @param mixed $model
     *
     * @return ImportDelimited
     */
    public function setModel($model)
    {
        if (is_string($model)) {
            if (! $this->model = $this->makeModel($model)) {
                throw new \RuntimeException("Model $model doesn't exist.");
            }
        } else {
            if (! $model instanceof Model) {
                throw new \RuntimeException("Model should be of type '".Model::class."'.");
            }
            $this->model = $model;
        }

        $this->table = null;

        return $this;
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
        parent::setTargetName($targetName);

        $this->setModel($this->getTargetName());

        return $this;
    }

    /**
     * Performs UPSERT.
     *
     * @param $row
     * @param $key
     */
    protected function upsert($row, $key)
    {
        $instances = $this->findByKey($key);

        if ($this->dryRun) {
            return;
        }

        if (0 == count($instances)) {
            return $this->insert($row);
        }

        return $this->updateInstances($row, $instances);
    }

    /**
     * Performs UPDATE.
     *
     * @param $row
     * @param $key
     */
    protected function update($row, $key)
    {
        $instances = $this->findByKey($key);

        if ($this->dryRun) {
            return;
        }

        return $this->updateInstances($row, $instances);
    }

    /**
     * Given a key checks whether a record(s) already exists
     * and inserts only new records.
     *
     * @param $row
     * @param $key
     *
     * @return mixed|void
     */
    protected function insertNew($row, $key)
    {
        $instance = $this->model
            ->firstOrNew($key);

        if ($this->dryRun) {
            return;
        }

        if (! $instance->exists) {
            $this->updateInstances($row, [$instance]);
        }
    }

    /**
     * Performs INSERT.
     *
     * @param $row
     */
    protected function insert($row)
    {
        if ($this->dryRun) {
            return;
        }

        $record = $this->model->create($row);
    }

    /**
     * Validates field names.
     *
     * @param $fieldDefinitions
     */
    protected function validateFields($fieldDefinitions)
    {
        // There is no good way to validate fields (attributes) for models
        // At least I haven't found one yet
        return $fieldDefinitions;
    }

    /**
     * Given a class name Instantiates a model checks if it is in fact
     * an instance of Illuminate\Database\Eloquent\Model.
     *
     * @param string $abstract
     *
     * @return null|\Illuminate\Database\Eloquent\Model
     */
    private function makeModel($abstract)
    {
        try {
            $model = $this->container->make($abstract);
        } catch (\Exception $e) {
            return;
        }

        return $model instanceof Model ? $model : null;
    }

    /**
     * Given a collection of models updates them.
     *
     * @param $row
     * @param $instances
     */
    private function updateInstances($row, $instances)
    {
        if (empty($instances)) {
            return;
        }

        foreach ($instances as $instance) {
            $instance->fill($row)->save();
        }
    }

    /**
     * Retrieves model(s) by key.
     *
     * @param array $key
     *
     * @return mixed
     */
    private function findByKey($key)
    {
        return $this->model->where($key)->get();
    }
}
