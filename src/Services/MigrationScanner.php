<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Services;

use Illuminate\Support\Facades\File;

class MigrationScanner
{
    /**
     * Scan migrations directory and parse each file.
     *
     * @return array<string, array{
     *     file: string,
     *     action: string,
     *     slot: int|null,
     *     creates_tables: array<string>,
     *     alters_columns: array<string>,
     *     has_fks: array<string>,
     *     referenced_by: array<string>,
     *     is_pivot: bool,
     *     is_altering: bool
     * }>
     */
    public function scan(?string $migrationsPath = null): array
    {
        $migrationsPath = $migrationsPath ?? database_path('migrations');
        $files = File::glob($migrationsPath.'/*.php');

        $manifest = [];
        foreach ($files as $file) {
            $parsed = $this->parseFile($file);
            if ($parsed === null) {
                continue;
            }
            $tableName = $parsed['table_name'];
            $manifest[$tableName] = $parsed;
        }

        return $this->detectAlteringMigrations($manifest);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseFile(string $file): ?array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $tableName = $this->extractTableName($content, $file);
        if ($tableName === null) {
            return null;
        }

        $isPivot = $this->detectPivot($content, $tableName);
        $createsTables = $this->extractCreatesTables($content);
        $altersColumns = $this->extractAltersColumns($content);
        $hasFks = $this->extractForeignKeys($content, $createsTables);

        return [
            'file' => $file,
            'action' => $this->extractActionFromFilename($file),
            'table_name' => $tableName,
            'slot' => null,
            'creates_tables' => $createsTables,
            'alters_columns' => $altersColumns,
            'has_fks' => $hasFks,
            'referenced_by' => [],
            'is_pivot' => $isPivot,
            'is_altering' => false,
            'is_external' => false,
        ];
    }

    private function extractTableName(string $content, string $file): ?string
    {
        // Schema::create('table_name', ...)
        if (preg_match('/Schema::create\s*\(\s*[\'"](\w+)[\'"]\s*,/', $content, $matches)) {
            return $matches[1];
        }

        // Schema::table('table_name', ...)
        if (preg_match('/Schema::table\s*\(\s*[\'"](\w+)[\'"]\s*,/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractActionFromFilename(string $file): string
    {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        // Remove any date prefix (e.g., "2024_01_01_123456_create_users_table" -> "create_users_table")
        $parts = explode('_', $basename);
        // Skip date parts (first 4 segments that look like dates)
        $actionParts = [];
        $foundAction = false;
        foreach ($parts as $part) {
            if (! $foundAction && preg_match('/^\d{4}$|^\d{2}$/', $part)) {
                continue;
            }
            $foundAction = true;
            $actionParts[] = $part;
        }

        return implode('_', $actionParts);
    }

    private function detectPivot(string $content, string $tableName): bool
    {
        // Check name pattern
        if (str_contains($tableName, '_pivot') || str_contains($tableName, '_pivot_')) {
            return true;
        }

        // Check for many-to-many setup: two ->foreignId() / ->foreignIdFor() calls
        $foreignIdMatches = preg_match_all('/->foreignId\s*\(|->foreignIdFor\s*\(/', $content);
        if ($foreignIdMatches >= 2) {
            return true;
        }

        // Check morphs
        if (preg_match('/->morphs\s*\(|->nullableMorphs\s*\(|->ulidMorphs\s*\(|->uuidMorphs\s*\(/', $content)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function extractCreatesTables(string $content): array
    {
        $tables = [];
        if (preg_match_all("/Schema::create\s*\(\s*['\"](\w+)['\"]\s*,/", $content, $matches)) {
            $tables = $matches[1];
        }

        return $tables;
    }

    /**
     * @return array<string>
     */
    private function extractAltersColumns(string $content): array
    {
        $columns = [];
        // Schema::table calls (not create)
        if (preg_match_all("/Schema::table\s*\(\s*['\"](\w+)['\"]\s*,/", $content, $matches)) {
            $columns = array_merge($columns, $matches[1]);
        }

        return array_unique($columns);
    }

    /**
     * @return array<string> Table names this migration references via FK
     */
    private function extractForeignKeys(string $content, array $createsTables): array
    {
        $refs = [];

        // ->foreignId('column')->constrained() or ->constrained('table')
        if (preg_match_all('/->constrained\s*\(\s*[\'"]?(\w+)[\'"]?\s*\)/', $content, $matches)) {
            $refs = array_merge($refs, $matches[1]);
        }

        // ->foreignId('column')->references('id')->on('table')
        if (preg_match_all('/->on\s*\(\s*[\'"](\w+)[\'"]\s*\)/', $content, $matches)) {
            $refs = array_merge($refs, $matches[1]);
        }

        // ->foreignIdFor(Model::class)
        if (preg_match_all('/->foreignIdFor\s*\(\s*[\w\\\\]+::class\s*\)/', $content, $matches)) {
            // Can't extract table name from Model class without reflection - skip
        }

        // ->foreignUlid / ->foreignUuid
        if (preg_match_all('/->foreignUlid\s*\(\s*[\'"](\w+)[\'"]\s*\)/', $content, $matches)) {
            // These reference the same column name as table - skip for now
        }

        // Filter out self-references and tables created in this migration
        $refs = array_filter($refs, fn ($r) => ! in_array($r, $createsTables));

        return array_unique($refs);
    }

    /**
     * Detect altering migrations — Schema::table targeting a table created by another migration.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private function detectAlteringMigrations(array $manifest): array
    {
        $createdTables = [];
        foreach ($manifest as $entry) {
            foreach ($entry['creates_tables'] as $table) {
                $createdTables[$table] = $entry['table_name'];
            }
        }

        foreach ($manifest as $tableName => $entry) {
            foreach ($entry['alters_columns'] as $altersTable) {
                if (isset($createdTables[$altersTable]) && $altersTable !== $tableName) {
                    $manifest[$tableName]['is_altering'] = true;
                    break;
                }
            }
        }

        // Build referenced_by (reverse of has_fks)
        foreach ($manifest as $tableName => $entry) {
            foreach ($entry['has_fks'] as $fkTarget) {
                if (isset($manifest[$fkTarget])) {
                    $manifest[$fkTarget]['referenced_by'][] = $tableName;
                }
            }
        }

        return $manifest;
    }

    /**
     * Check if a table name is external (not in our migrations).
     */
    public function isExternalTable(string $tableName, array $manifest): bool
    {
        return ! isset($manifest[$tableName]);
    }
}
