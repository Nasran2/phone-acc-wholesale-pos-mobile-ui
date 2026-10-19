@props(['return', 'allowManage' => false])

<flux:modal wire:model="returnDetailOpen" class="w-full md:max-w-2xl">
    <div class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Return Details') }}</flux:heading>
        @if ($return)
            <p class="text-sm text-zinc-500">{{ $return->customer?->name ?? __('Walk-in Customer') }}</p>
            <x-sale-return-details :return="$return" />
            @if ($allowManage)
                <flux:error name="returnItems" />
                <div class="flex flex-wrap gap-2"><x-sale-return-actions :return="$return" :inline="true" /></div>
            @endif
        @endif
    </div>
</flux:modal>
