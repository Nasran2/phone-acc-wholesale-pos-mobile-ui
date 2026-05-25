<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-6 space-y-4">
    <div class="p-4 rounded-xl border border-rose-100 dark:border-rose-950/30 bg-rose-50/30 dark:bg-rose-950/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="space-y-1">
            <h3 class="text-sm font-bold text-rose-800 dark:text-rose-400 flex items-center gap-1.5">
                <flux:icon.exclamation-circle class="size-4 shrink-0 text-rose-600 dark:text-rose-450" />
                {{ __('Danger Zone') }}
            </h3>
            <p class="text-xs text-rose-700/80 dark:text-rose-450/70">
                {{ __('Once your account is deleted, all of its resources and data will be permanently wiped.') }}
            </p>
        </div>

        <flux:modal.trigger name="confirm-user-deletion">
            <flux:button variant="danger" size="sm" icon="trash" data-test="delete-user-button" class="shadow-sm">
                {{ __('Delete Account') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <livewire:pages::settings.delete-user-modal />
</section>

