<?php

use App\Support\DeveloperCommandPreset;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.developer')]
#[Title('Developer Dashboard')] class extends Component
{
    /**
     * @var array<int, array{label: string, command: string, exit_code: int, output: string, successful: bool, ran_at: string}>
     */
    public array $results = [];

    /**
     * @return array<int, array{key: string, label: string, description: string, command: string, dangerous: bool}>
     */
    public function presets(): array
    {
        return collect(DeveloperCommandPreset::all())
            ->map(fn (DeveloperCommandPreset $preset): array => [
                'key' => $preset->key,
                'label' => $preset->label,
                'description' => $preset->description,
                'command' => $preset->displayCommand(),
                'dangerous' => $preset->dangerous,
            ])
            ->values()
            ->all();
    }

    public function runCommand(string $key): void
    {
        $preset = DeveloperCommandPreset::all()[$key] ?? null;

        if (! $preset) {
            $this->recordError(__('Unknown command preset.'), 'php artisan', __('The requested command preset is not allowed.'));

            return;
        }

        $this->recordResult($preset->run()->toArray());
    }

    public function runFullMaintenanceSequence(): void
    {
        foreach (DeveloperCommandPreset::fullMaintenanceSequence() as $preset) {
            $this->recordResult($preset->run()->toArray(), showToast: false);
        }

        $failed = collect($this->results)
            ->take(count(DeveloperCommandPreset::fullMaintenanceSequence()))
            ->contains(fn (array $result): bool => ! $result['successful']);

        Flux::toast(
            variant: $failed ? 'danger' : 'success',
            text: $failed ? __('Maintenance sequence finished with errors.') : __('Maintenance sequence completed successfully.'),
        );
    }

    public function logout(): void
    {
        session()->forget((string) config('developer.session_key'));
        session()->regenerateToken();

        $this->redirect(route('developer.login'), navigate: true);
    }

    public function maintenanceBypassUrl(): string
    {
        return url((string) config('developer.maintenance_secret'));
    }

    /**
     * @param  array{label: string, command: string, exit_code: int, output: string, successful: bool}  $result
     */
    private function recordResult(array $result, bool $showToast = true): void
    {
        array_unshift($this->results, [
            ...$result,
            'ran_at' => now()->format('Y-m-d H:i:s'),
        ]);

        if ($showToast) {
            Flux::toast(
                variant: $result['successful'] ? 'success' : 'danger',
                text: $result['successful']
                    ? __('Command completed successfully.')
                    : __('Command finished with an error.'),
            );
        }
    }

    private function recordError(string $label, string $command, string $output): void
    {
        $this->recordResult([
            'label' => $label,
            'command' => $command,
            'exit_code' => 1,
            'output' => $output,
            'successful' => false,
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <section class="rounded-2xl border border-white/80 bg-white p-5 shadow-[0_18px_50px_rgba(15,23,42,0.08)] sm:p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    {{ __('Developer session active') }}
                </div>
                <h1 class="mt-4 font-display text-2xl font-bold tracking-tight text-zinc-950 sm:text-3xl dark:text-white">
                    {{ __('Developer Dashboard') }}
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                    {{ __('Run approved Laravel maintenance commands on the server and review success or error output below.') }}
                </p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Maintenance bypass URL:') }}
                    <span class="font-mono text-zinc-700 dark:text-zinc-200">{{ $this->maintenanceBypassUrl() }}</span>
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <flux:button wire:click="runFullMaintenanceSequence" variant="primary" icon="bolt">
                    {{ __('Run Full Maintenance Sequence') }}
                </flux:button>
                <flux:button wire:click="logout" variant="ghost" icon="arrow-right-start-on-rectangle">
                    {{ __('Developer Logout') }}
                </flux:button>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-white/80 bg-white p-5 shadow-sm sm:p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-1">
            <h2 class="font-display text-xl font-bold text-zinc-950 dark:text-white">{{ __('Artisan Command Presets') }}</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Run common server operations with one click.') }}</p>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->presets() as $preset)
                <article wire:key="developer-preset-{{ $preset['key'] }}" class="flex min-h-56 flex-col justify-between rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-base font-bold text-zinc-950 dark:text-white">{{ __($preset['label']) }}</h3>
                            @if ($preset['dangerous'])
                                <span class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-600 dark:bg-rose-950/40 dark:text-rose-300">{{ __('Danger') }}</span>
                            @endif
                        </div>
                        <p class="text-sm leading-6 text-zinc-500 dark:text-zinc-400">{{ __($preset['description']) }}</p>
                        <div class="overflow-x-auto rounded-lg bg-zinc-50 px-3 py-2 font-mono text-sm text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">
                            {{ $preset['command'] }}
                        </div>
                    </div>

                    <flux:button wire:click="runCommand('{{ $preset['key'] }}')" class="mt-5 w-fit" variant="{{ $preset['dangerous'] ? 'danger' : 'primary' }}">
                        {{ __('Run Command') }}
                    </flux:button>
                </article>
            @endforeach
        </div>
    </section>

    <section class="rounded-2xl border border-white/80 bg-white p-5 shadow-sm sm:p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-1 border-b border-zinc-200 pb-4 dark:border-zinc-800">
            <h2 class="font-display text-xl font-bold text-zinc-950 dark:text-white">{{ __('Command Output') }}</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Each run appears here with status, exit code, and server output.') }}</p>
        </div>

        <div class="mt-5 flex flex-col gap-4">
            @forelse ($results as $result)
                <article wire:key="developer-result-{{ $loop->index }}-{{ $result['ran_at'] }}" class="overflow-hidden rounded-xl border {{ $result['successful'] ? 'border-emerald-200 dark:border-emerald-900/50' : 'border-rose-200 dark:border-rose-900/50' }}">
                    <div class="flex flex-col gap-3 bg-zinc-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:bg-zinc-950">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $result['successful'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' }}">
                                    {{ $result['successful'] ? __('Success') : __('Error') }}
                                </span>
                                <span class="text-sm font-bold text-zinc-950 dark:text-white">{{ $result['label'] }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $result['ran_at'] }}</span>
                            </div>
                            <p class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $result['command'] }}</p>
                        </div>
                        <span class="w-fit rounded-lg bg-white px-2.5 py-1 font-mono text-xs text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-300 dark:ring-zinc-800">
                            {{ __('Exit') }} {{ $result['exit_code'] }}
                        </span>
                    </div>
                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap bg-zinc-950 p-4 text-xs leading-5 text-zinc-100">{{ $result['output'] }}</pre>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    {{ __('No commands have been run yet.') }}
                </div>
            @endforelse
        </div>
    </section>
</div>
