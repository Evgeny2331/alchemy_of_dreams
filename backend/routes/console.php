<?php

use App\Modules\Economy\Console\ProcessOutboxCommand;
use Illuminate\Console\Application as ArtisanConsole;
use Illuminate\Support\Facades\Artisan;

ArtisanConsole::starting(function (ArtisanConsole $artisan): void {
    $artisan->resolveCommands([
        ProcessOutboxCommand::class,
    ]);
});

Artisan::command('outbox:run {--limit=50} {--max-attempts=5}', function () {
    return $this->call(ProcessOutboxCommand::class, [
        '--limit' => $this->option('limit'),
        '--max-attempts' => $this->option('max-attempts'),
    ]);
})->purpose('Run outbox event processor');
