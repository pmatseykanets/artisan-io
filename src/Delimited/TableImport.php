<?php

namespace ArtisanIo\Delimited;

class TableImport extends BaseImport
{
    protected $table;

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

        $this->setTable($targetName);
    }

    /**
     * @param mixed $table
     *
     * @return ImportDelimited
     */
    public function setTable($table)
    {
        if (! $this->db->connection()->getSchemaBuilder()->hasTable($table)) {
            throw new \RuntimeException("Table $table doesn't exist.");
        }

        $this->table = $table;

        $this->model = null;

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
        $record = $this->db
            ->table($this->table)
            ->select($this->db->raw('1'))
            ->where($key)
            ->take(1)
            ->first();

        if (is_null($record)) {
            $this->insert($row);
        } else {
            $this->update($row, $key);
        }
    }

    /**
     * Performs UPDATE.
     *
     * @param $row
     * @param $key
     */
    protected function update($row, $key)
    {
        if ($this->dryRun) {
            return;
        }

        $this->db
            ->table($this->table)
            ->where($key)
            ->update($row);
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
        $record = $this->db
            ->table($this->table)
            ->select($this->db->raw('1'))
            ->where($key)
            ->take(1)
            ->first();

        if (is_null($record)) {
            $this->insert($row);
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

        $this->db
            ->table($this->table)
            ->insert($row);
    }

    /**
     * Validates field names.
     *
     * @param $fieldDefinitions
     */
    protected function validateFields($fieldDefinitions)
    {
        foreach ($fieldDefinitions as $field => $position) {
            if (! $this->db->connection()->getSchemaBuilder()->hasColumn($this->table, $field)) {
                throw new \RuntimeException("Column '$field' doesn't exist.");
            }
        }

        return $fieldDefinitions;
    }
}
