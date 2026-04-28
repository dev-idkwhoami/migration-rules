<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Exceptions;

use Exception;

class MigrationCycleException extends Exception
{
    /** @var array<string> */
    private array $cyclePath;

    /**
     * @param  array<string>  $cyclePath
     */
    public function __construct(string $message, array $cyclePath)
    {
        parent::__construct($message);
        $this->cyclePath = $cyclePath;
    }

    /**
     * @return array<string>
     */
    public function getCyclePath(): array
    {
        return $this->cyclePath;
    }
}
