<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Throwable;

class DeveloperCommandPreset
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $signature,
        public array $parameters = [],
        public bool $dangerous = false,
    ) {}

    public function displayCommand(): string
    {
        $parts = ['php artisan '.$this->signature];

        foreach ($this->parameters as $name => $value) {
            if (str_starts_with((string) $name, '--')) {
                if ($value === true) {
                    $parts[] = $name;
                } elseif ($value !== false && $value !== null) {
                    $parts[] = $name.'='.$value;
                }

                continue;
            }

            $parts[] = (string) $value;
        }

        return implode(' ', $parts);
    }

    public function run(): DeveloperCommandResult
    {
        try {
            $exitCode = Artisan::call($this->signature, $this->parameters);
            $output = Artisan::output();

            return new DeveloperCommandResult(
                label: $this->label,
                command: $this->displayCommand(),
                exitCode: $exitCode,
                output: $output,
                successful: $exitCode === 0,
            );
        } catch (Throwable $exception) {
            return new DeveloperCommandResult(
                label: $this->label,
                command: $this->displayCommand(),
                exitCode: 1,
                output: $exception->getMessage(),
                successful: false,
            );
        }
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        return collect([
            new self('optimize-clear', 'Optimize Clear', 'Clear config, route, view, event, and compiled caches.', 'optimize:clear'),
            new self('cache-clear', 'Cache Clear', 'Clear the application cache store.', 'cache:clear'),
            new self('config-clear', 'Config Clear', 'Remove the cached configuration file.', 'config:clear'),
            new self('config-cache', 'Config Cache', 'Rebuild the cached configuration file.', 'config:cache'),
            new self('route-clear', 'Route Clear', 'Remove the cached routes file.', 'route:clear'),
            new self('route-cache', 'Route Cache', 'Rebuild route cache for faster bootstrap.', 'route:cache'),
            new self('view-clear', 'View Clear', 'Clear compiled Blade and Livewire views.', 'view:clear'),
            new self('view-cache', 'View Cache', 'Compile and cache all Blade templates.', 'view:cache'),
            new self('event-cache', 'Event Cache', 'Cache discovered events and listeners.', 'event:cache'),
            new self('storage-link', 'Storage Link', 'Create the public storage symlink on the server.', 'storage:link'),
            new self('migrate', 'Run Migrations', 'Apply pending database migrations.', 'migrate', ['--force' => true], true),
            new self('migrate-status', 'Migration Status', 'Show the migration status table.', 'migrate:status'),
            new self('queue-restart', 'Queue Restart', 'Gracefully restart queue workers.', 'queue:restart'),
            new self('schedule-run', 'Schedule Run', 'Run due scheduled tasks once.', 'schedule:run'),
            new self('about', 'About', 'Show framework and environment details.', 'about'),
            new self('maintenance-down', 'Maintenance Mode On', 'Put the application into maintenance mode with a bypass secret.', 'down', ['--secret' => config('developer.maintenance_secret')], true),
            new self('maintenance-up', 'Maintenance Mode Off', 'Bring the application out of maintenance mode.', 'up'),
        ])->keyBy('key')->all();
    }

    /**
     * @return array<int, self>
     */
    public static function fullMaintenanceSequence(): array
    {
        $presets = self::all();

        return [
            $presets['optimize-clear'],
            $presets['cache-clear'],
            $presets['config-cache'],
            $presets['route-cache'],
            $presets['event-cache'],
            $presets['view-cache'],
            $presets['storage-link'],
            $presets['queue-restart'],
        ];
    }
}
