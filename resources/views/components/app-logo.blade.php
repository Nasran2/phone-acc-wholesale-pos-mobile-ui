@props([
    'sidebar' => false,
])

@php
    $businessName = \App\Models\Setting::get('business_name', config('app.name', 'Laravel')) ?: config('app.name', 'Laravel');
    $businessLogo = \App\Models\Setting::get('business_logo');
    $businessLogoUrl = $businessLogo ? \Illuminate\Support\Facades\Storage::url($businessLogo) : asset('images/twinsofte-logo.png');
@endphp

@if($sidebar)
    <flux:sidebar.brand name="{{ $businessName }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            @if ($businessLogoUrl)
                <img src="{{ $businessLogoUrl }}" alt="{{ $businessName }}" class="size-8 rounded-md bg-white object-contain" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="{{ $businessName }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            @if ($businessLogoUrl)
                <img src="{{ $businessLogoUrl }}" alt="{{ $businessName }}" class="size-8 rounded-md bg-white object-contain" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:brand>
@endif
