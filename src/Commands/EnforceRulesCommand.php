<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Commands;

use Idkwhoami\MigrationRules\Exceptions\MigrationCycleException;
use Idkwhoami\MigrationRules\Exceptions\MissingDependencyException;
use Idkwhoami\MigrationRules\MigrationManifest;
use Idkwhoami\MigrationRules\Services\DependencyResolver;
use Idkwhoami\MigrationRules\Services\Inliner;
use Idkwhoami\MigrationRules\Services\MigrationGenerator;
use Idkwhoami\MigrationRules\Services\MigrationScanner;
use Idkwhoami\MigrationRules\Services\SlotAssigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EnforceRulesCommand extends Command
{
    public $signature = 'migrate:enforce
        {--dry-run : Show what would change without writing files}
        {--pattern=0001_01_01 : Date prefix for migration filenames}
        {--base-step=50 : Slot step for base migrations}
        {--pivot-step=25 : Slot step for pivot migrations}
        {--force : Auto-resolve collisions by keeping base migration version}
        {--status : Show current manifest and migration state}';

    public $description = 'Scan, sort, inline, and regenerate Laravel migrations to enforce consistent naming and ordering';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $pattern = $this->option('pattern');
        $baseStep = (int) $this->option('base-step');
        $pivotStep = (int) $this->option('pivot-step');

        $this->info('Scanning migrations...');

        $scanner = new MigrationScanner;
        $manifest = $scanner->scan();
        $this->info('Found '.count($manifest).' migration(s).');

        if (empty($manifest)) {
            $this->info('No migrations found.');

            return self::SUCCESS;
        }

        // Phase 2: Dependency resolution
        $this->info('Resolving dependencies...');
        $resolver = new DependencyResolver;

        try {
            $sortedOrder = $resolver->resolve($manifest);
        } catch (MigrationCycleException $e) {
            $this->error('Cycle detected: '.$e->getMessage());

            return self::FAILURE;
        } catch (MissingDependencyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Dependencies resolved. '.count($sortedOrder).' migration(s) in order.');

        // Phase 3: Slot assignment
        $slotAssigner = new SlotAssigner($baseStep, $pivotStep);
        $slots = $slotAssigner->assign($sortedOrder, $manifest);

        // Phase 4: Inline altering migrations
        $this->info('Inlining altering migrations...');
        $inliner = new Inliner;
        $deletedFiles = $inliner->inline($manifest, $slots, $force);

        if (! empty($deletedFiles)) {
            $this->info('Inlined and deleted: '.count($deletedFiles).' altering migration(s).');
        }

        // Phase 5: Regenerate migrations (unless dry-run)
        if ($dryRun) {
            $this->info('[DRY-RUN] Would regenerate migrations:');
            foreach ($manifest as $tableName => $entry) {
                if ($entry['is_altering']) {
                    continue;
                }
                $slot = $slots[$tableName] ?? '000000';
                $filename = sprintf('%s_%s_%s.php', $pattern, $slot, $entry['action']);
                $this->info("  - {$filename}");
            }

            $this->info('[DRY-RUN] Would delete altering migration files:');
            foreach ($deletedFiles as $file) {
                $this->info('  - '.basename($file));
            }
        } else {
            $this->info('Regenerating migrations...');
            $generator = new MigrationGenerator($pattern);
            $generated = $generator->regenerate($manifest, $slots);

            $this->info('Generated '.count($generated).' migration file(s).');

            // Phase 6: Write manifest and update .gitignore
            $manifestData = new MigrationManifest(
                prefix: $pattern,
                slots: $slots,
                inlined: array_map(fn ($f) => basename($f), $deletedFiles),
                baseStep: $baseStep,
                pivotStep: $pivotStep
            );

            $manifestPath = base_path('.migration.rules');
            $manifestData->save($manifestPath);
            $this->info('Manifest saved to .migration.rules');

            // Update .gitignore
            $gitignorePath = base_path('.gitignore');
            if (File::exists($gitignorePath)) {
                $gitignoreContent = file_get_contents($gitignorePath);
                if (! str_contains($gitignoreContent, '.migration.rules')) {
                    file_put_contents($gitignorePath, "\n# Migration Enforcer\n.migration.rules\n", FILE_APPEND);
                    $this->info('.migration.rules added to .gitignore');
                }
            }
        }

        $this->info('Done!');

        return self::SUCCESS;
    }

    private function showStatus(): int
    {
        $manifestPath = base_path('.migration.rules');

        if (! file_exists($manifestPath)) {
            $this->info('No manifest found. Run migrations:enforce first.');

            return self::SUCCESS;
        }

        $manifest = MigrationManifest::load($manifestPath);

        $this->info('Migration Enforcer Status');
        $this->info('========================');
        $this->info('Version: '.$manifest->version);
        $this->info('Prefix: '.$manifest->prefix);
        $this->info('Base step: '.$manifest->baseStep);
        $this->info('Pivot step: '.$manifest->pivotStep);
        $this->info('');
        $this->info('Slots:');
        foreach ($manifest->slots as $table => $slot) {
            $this->info("  {$table} => {$slot}");
        }

        if (! empty($manifest->inlined)) {
            $this->info('');
            $this->info('Inlined migrations:');
            foreach ($manifest->inlined as $file) {
                $this->info("  - {$file}");
            }
        }

        return self::SUCCESS;
    }
}
