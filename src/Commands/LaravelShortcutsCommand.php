<?php

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\Command;

class LaravelShortcutsCommand extends Command
{
    public $signature = 'laravel-shortcuts';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
