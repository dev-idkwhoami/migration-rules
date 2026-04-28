<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Exceptions;

class ColumnCollisionException extends \Exception
{
    private string $columnName;

    private string $baseMigration;

    private string $alteringMigration;

    public function __construct(
        string $columnName,
        string $baseMigration,
        string $alteringMigration
    ) {
        $this->columnName = $columnName;
        $this->baseMigration = $baseMigration;
        $this->alteringMigration = $alteringMigration;

        parent::__construct(
            "Column '{$columnName}' collision between base migration '{$baseMigration}' and altering migration '{$alteringMigration}'."
        );
    }

    public function getColumnName(): string
    {
        return $this->columnName;
    }

    public function getBaseMigration(): string
    {
        return $this->baseMigration;
    }

    public function getAlteringMigration(): string
    {
        return $this->alteringMigration;
    }
}
