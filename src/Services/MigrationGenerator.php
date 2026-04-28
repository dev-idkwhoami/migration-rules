<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Services;

use Illuminate\Support\Facades\File;

class MigrationGenerator
{
    public function __construct(
        private string $prefix = '0001_01_01'
    ) {}

    /**
     * Regenerate all migration files based on manifest state.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @param  array<string, string>  $slots  table_name => slot mapping
     * @param  array<string, array{column: string, method: string, args: string|null}>  $extraColumns  Additional columns to include (from inlining)
     */
    public function regenerate(array $manifest, array $slots, array $extraColumns = []): array
    {
        $generated = [];

        foreach ($manifest as $tableName => $entry) {
            if ($entry['is_altering']) {
                continue; // Altering migrations are inlined and deleted
            }

            $slot = $slots[$tableName] ?? '000000';
            $filename = sprintf('%s_%s_%s.php', $this->prefix, $slot, $entry['action']);
            $path = dirname($entry['file']).'/'.$filename;

            // Extract schema from original file content
            $originalContent = file_get_contents($entry['file']);
            $schemaBlock = $this->extractSchemaBlock($originalContent, $entry);

            // Build new migration content
            $content = $this->buildMigrationContent($entry['action'], $schemaBlock, $extraColumns);

            // Write file
            if ($path !== $entry['file']) {
                // New filename, delete old one
                if (File::exists($entry['file'])) {
                    File::delete($entry['file']);
                }
            }

            File::put($path, $content);
            $generated[] = $path;
        }

        return $generated;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function extractSchemaBlock(string $content, array $entry): string
    {
        // Extract the closure body from Schema::create or Schema::table
        if (preg_match('/Schema::(?:create|table)\s*\([^;]+function\s*\([^)]*\)\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s', $content, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function buildMigrationContent(string $action, string $schemaBlock, array $extraColumns): string
    {
        $className = $this->actionToClassName($action);

        $extraCode = '';
        foreach ($extraColumns as $col) {
            if ($col['args']) {
                $extraCode .= "\$table->{$col['method']}('{$col['column']}', {$col['args']});\n        ";
            } else {
                $extraCode .= "\$table->{$col['method']}('{$col['column']}');\n        ";
            }
        }

        return <<<PHP
<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('$this->extractTableName($action)', function (Blueprint \$table) {
            $schemaBlock
        });
    }
};
PHP;
    }

    private function actionToClassName(string $action): string
    {
        $parts = explode('_', $action);
        $parts = array_map(fn ($p) => ucfirst($p), $parts);

        return implode('', $parts);
    }

    private function extractTableName(string $action): string
    {
        // create_users_table -> users
        if (preg_match('/^create_(\w+)_table$/', $action, $matches)) {
            return $matches[1];
        }
        // add_columns_to_users_table -> users
        if (preg_match('/^add_\w+_to_(\w+)_table$/', $action, $matches)) {
            return $matches[1];
        }

        return $action;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }
}
