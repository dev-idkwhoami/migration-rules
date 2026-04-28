<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Services;

use Illuminate\Support\Facades\File;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;

class Inliner
{
    /**
     * Inline all altering migrations into their base migrations and delete the altering files.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @param  array<string, string>  $slots
     * @param  array<string, array<string, mixed>>  $alteredColumns  Map of altering migration => columns to add
     * @return array<string> Files that were deleted
     */
    public function inline(
        array $manifest,
        array $slots,
        bool $force = false
    ): array {
        $deletedFiles = [];

        foreach ($manifest as $tableName => $entry) {
            if (! $entry['is_altering']) {
                continue;
            }

            // Find the base migration this altering migration targets
            $baseTable = $this->findBaseTable($entry, $manifest);
            if ($baseTable === null) {
                continue;
            }

            $baseEntry = $manifest[$baseTable];
            $alteringColumns = $this->extractColumnsFromFile($entry['file']);

            // Check for collisions
            $baseColumns = $this->extractColumnsFromFile($baseEntry['file']);

            $conflicts = $this->detectConflicts($alteringColumns, $baseColumns);

            if (! empty($conflicts) && ! $force) {
                $this->resolveCollisionsInteractive($conflicts, $alteringColumns, $baseColumns);
            }

            // Merge altering columns into base
            $this->mergeIntoBase($baseEntry['file'], $alteringColumns, $conflicts);

            // Delete the altering migration file
            if (File::exists($entry['file'])) {
                File::delete($entry['file']);
                $deletedFiles[] = $entry['file'];
            }
        }

        return $deletedFiles;
    }

    /**
     * @return array<string> Column definitions from the altering migration
     */
    private function extractColumnsFromFile(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        // Extract column definitions from Schema::table block
        // This is a simplified approach - actual implementation would need
        // proper PHP AST parsing for full accuracy
        $columns = [];

        // Match $table->type('column', ...) patterns
        if (preg_match_all('/\$table->(\w+)\s*\(\s*[\'"](\w+)[\'"]\s*(?:,\s*([^\)]+))?\)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $columns[] = [
                    'method' => $match[1],
                    'column' => $match[2],
                    'args' => $match[3] ?? null,
                ];
            }
        }

        return $columns;
    }

    /**
     * @return array<string, array{altering: array, base: array|null}>
     */
    private function detectConflicts(array $alteringColumns, array $baseColumns): array
    {
        $baseColumnMap = [];
        foreach ($baseColumns as $col) {
            $baseColumnMap[$col['column']] = $col;
        }

        $conflicts = [];
        foreach ($alteringColumns as $altCol) {
            if (isset($baseColumnMap[$altCol['column']])) {
                $baseCol = $baseColumnMap[$altCol['column']];
                // Check if types match
                if ($baseCol['method'] !== $altCol['method']) {
                    $conflicts[$altCol['column']] = [
                        'altering' => $altCol,
                        'base' => $baseCol,
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, array{altering: array, base: array|null}>  $conflicts
     */
    private function resolveCollisionsInteractive(
        array $conflicts,
        array &$alteringColumns,
        array &$baseColumns
    ): void {
        foreach ($conflicts as $columnName => $conflict) {
            $choice = new SelectPrompt(
                "Column '{$columnName}' has different definitions in base vs altering migration.",
                [
                    'keep' => "Keep base migration's definition",
                    'take' => "Take altering migration's definition",
                    'rename' => 'Rename column (provide new name)',
                ],
                default: 0
            );

            if ($choice === 'rename') {
                $newName = new TextPrompt("Enter new column name for the altering migration's version:");
                // Update the altering column to a new name
                foreach ($alteringColumns as &$col) {
                    if ($col['column'] === $columnName) {
                        $col['column'] = $newName;
                        break;
                    }
                }
                // Keep base column as-is
            } elseif ($choice === 'keep') {
                // Remove the conflicting column from altering columns
                $alteringColumns = array_values(array_filter(
                    $alteringColumns,
                    fn ($c) => $c['column'] !== $columnName
                ));
            }
            // 'take' means we keep the altering column as-is (it will override)
        }
    }

    private function findBaseTable(array $entry, array $manifest): ?string
    {
        foreach ($entry['alters_columns'] as $altersTable) {
            if (isset($manifest[$altersTable]) && ! $manifest[$altersTable]['is_altering']) {
                return $altersTable;
            }
        }

        return null;
    }

    /**
     * @param  array<array{column: string, method: string, args: string|null}>  $alteringColumns
     * @param  array<string, array{altering: array, base: array|null}>  $conflicts
     */
    private function mergeIntoBase(string $baseFile, array $alteringColumns, array $conflicts): void
    {
        // Filter out columns that had conflicts resolved to "keep base"
        $filteredColumns = array_filter($alteringColumns, function ($col) use ($conflicts) {
            return ! isset($conflicts[$col['column']]);
        });

        if (empty($filteredColumns)) {
            return;
        }

        $content = file_get_contents($baseFile);
        if ($content === false) {
            return;
        }

        // Find the Schema::create closure and append new columns
        // We need to insert column definitions inside the closure
        $newColumnCode = "\n";
        foreach ($filteredColumns as $col) {
            if ($col['args']) {
                $newColumnCode .= "\$table->{$col['method']}('{$col['column']}', {$col['args']});\n";
            } else {
                $newColumnCode .= "\$table->{$col['method']}('{$col['column']}');\n";
            }
        }

        // Find the closing of the Schema::create callback and insert before it
        // Look for the pattern: ...}, function (Blueprint $table) { ... });
        // Insert before the final });
        if (preg_match('/(\$table->\w+\([^)]+\);[\s\n]*)+(\s*\}\)[\s\n]*\);[\s\n]*$)/', $content, $matches)) {
            $insertPos = strrpos($content, '});');
            if ($insertPos !== false) {
                $newContent = substr($content, 0, $insertPos).$newColumnCode.substr($content, $insertPos);
                file_put_contents($baseFile, $newContent);
            }
        }
    }
}
