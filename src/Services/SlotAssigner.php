<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Services;

class SlotAssigner
{
    public function __construct(
        private int $baseStep = 50,
        private int $pivotStep = 25
    ) {}

    /**
     * Assign slots to migrations in dependency order.
     *
     * @param  array<string>  $sortedOrder  Migration table names in dependency order
     * @param  array<string, array<string, mixed>>  $manifest
     * @return array<string, string> table_name => slot (zero-padded 6 digits)
     */
    public function assign(array $sortedOrder, array $manifest): array
    {
        $slot = 0;
        $slots = [];

        foreach ($sortedOrder as $tableName) {
            $entry = $manifest[$tableName] ?? null;
            if ($entry === null) {
                continue;
            }

            if ($entry['is_pivot']) {
                $slot += $this->pivotStep;
            } else {
                $slot += $this->baseStep;
            }

            $slots[$tableName] = str_pad((string) $slot, 6, '0', STR_PAD_LEFT);
        }

        return $slots;
    }

    public function getBaseStep(): int
    {
        return $this->baseStep;
    }

    public function getPivotStep(): int
    {
        return $this->pivotStep;
    }
}
