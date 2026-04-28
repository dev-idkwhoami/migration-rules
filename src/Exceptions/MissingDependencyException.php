<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Exceptions;

class MissingDependencyException extends \Exception
{
    private string $migrationName;

    private string $missingTable;

    public function __construct(string $migrationName, string $missingTable)
    {
        $this->migrationName = $migrationName;
        $this->missingTable = $missingTable;
        parent::__construct("Migration '{$migrationName}' references table '{$missingTable}' which does not exist in migrations.");
    }

    public function getMigrationName(): string
    {
        return $this->migrationName;
    }

    public function getMissingTable(): string
    {
        return $this->missingTable;
    }
}
