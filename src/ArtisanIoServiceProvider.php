<?php

namespace ArtisanIo;

use Illuminate\Support\ServiceProvider;
use ArtisanIo\Console\ImportDelimitedCommand;

class ArtisanIoServiceProvider extends ServiceProvider
{
    public function register()
    {
        $commands = collect([
            'command.artisan-io.import-delimited' => ImportDelimitedCommand::class,
        ]);

        $commands->each(function ($command, $abstract) {
            $this->app->singleton($abstract, function () use ($command) {
                return new $command();
            });
        });

        $this->commands($commands->keys()->toArray());
    }
}
