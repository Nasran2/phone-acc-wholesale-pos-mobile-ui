@props(['return', 'inline' => false])

@if (auth()->user()?->hasPermission('process_return'))
    @if ($return->return_type === 'bill_credit')
        @if ($inline)
            <flux:button size="sm" wire:click="editReturn({{ $return->id }})">{{ __('Edit / Delete on Bill') }}</flux:button>
        @else
            <flux:button size="sm" href="{{ route('pos.returns', ['edit_return' => $return->id]) }}" wire:navigate>{{ __('Edit / Delete on Bill') }}</flux:button>
        @endif
        <p class="text-xs text-zinc-500">{{ __('Change or remove the return credit on its checkout bill, then save the bill.') }}</p>
    @elseif ($return->faultyItems->contains('status', 'returned'))
        <p class="text-xs text-zinc-500">{{ __('Already returned to supplier; changes unavailable.') }}</p>
    @else
        @if ($inline)
            <flux:button size="sm" wire:click="editReturn({{ $return->id }})">{{ __('Edit Return') }}</flux:button>
            <flux:button size="sm" variant="danger" wire:click="deleteReturn({{ $return->id }})" wire:loading.attr="disabled" wire:target="deleteReturn" wire:confirm="Delete this return? Its stock movements, cash refund, due adjustment, and replacement expense will be reversed.">{{ __('Delete Return') }}</flux:button>
        @else
            <flux:button size="sm" href="{{ route('pos.returns', ['edit_return' => $return->id]) }}" wire:navigate>{{ __('Edit Return') }}</flux:button>
        @endif
    @endif
@endif
