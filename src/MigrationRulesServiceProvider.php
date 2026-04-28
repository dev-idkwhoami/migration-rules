<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules;

use Idkwhoami\MigrationRules\Commands\EnforceRulesCommand;
use Illuminate\Support\ServiceProvider;

class MigrationRulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                EnforceRulesCommand::class,
            ]);
        }
    }
}
