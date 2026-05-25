<?php

use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\ActivityLogger;
use App\Services\SmsNotificationService;
use App\Services\TextItSmsService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('SMS Gateway Settings')] class extends Component
{
    use WithPagination;

    public bool $sms_enabled = false;
    public string $sms_textit_id = '';
    public string $sms_textit_password = '';
    public string $sms_textit_base_url = 'https://textit.biz/sendmsg';
    public bool $sms_notify_sale_enabled = true;
    public bool $sms_notify_payment_enabled = true;
    public bool $sms_notify_cheque_passed_enabled = true;
    public bool $sms_notify_cheque_reminder_enabled = true;
    public string $sms_template_sale = '';
    public string $sms_template_payment = '';
    public string $sms_template_cheque_passed = '';
    public string $sms_template_cheque_reminder = '';
    public string $testPhone = '';
    public string $testMessage = 'SMS gateway test from {business_name}.';

    public function mount(): void
    {
        if (! auth()->user()->isAdmin() && auth()->user()->role !== 'super_admin') {
            abort(403, 'Unauthorized access.');
        }

        $this->sms_enabled = Setting::get('sms_enabled', '0') === '1';
        $this->sms_textit_id = Setting::get('sms_textit_id', '');
        $this->sms_textit_password = Setting::get('sms_textit_password', '');
        $this->sms_textit_base_url = Setting::get('sms_textit_base_url', 'https://textit.biz/sendmsg');
        $this->sms_notify_sale_enabled = Setting::get('sms_notify_sale_enabled', '1') === '1';
        $this->sms_notify_payment_enabled = Setting::get('sms_notify_payment_enabled', '1') === '1';
        $this->sms_notify_cheque_passed_enabled = Setting::get('sms_notify_cheque_passed_enabled', '1') === '1';
        $this->sms_notify_cheque_reminder_enabled = Setting::get('sms_notify_cheque_reminder_enabled', '1') === '1';
        $this->sms_template_sale = Setting::get('sms_template_sale', SmsNotificationService::DEFAULT_TEMPLATES['sms_template_sale']);
        $this->sms_template_payment = Setting::get('sms_template_payment', SmsNotificationService::DEFAULT_TEMPLATES['sms_template_payment']);
        $this->sms_template_cheque_passed = Setting::get('sms_template_cheque_passed', SmsNotificationService::DEFAULT_TEMPLATES['sms_template_cheque_passed']);
        $this->sms_template_cheque_reminder = Setting::get('sms_template_cheque_reminder', SmsNotificationService::DEFAULT_TEMPLATES['sms_template_cheque_reminder']);
    }

    public function saveSettings(): void
    {
        $this->validate([
            'sms_enabled' => 'boolean',
            'sms_textit_id' => 'required_if:sms_enabled,true|nullable|string|max:30',
            'sms_textit_password' => 'required_if:sms_enabled,true|nullable|string|max:100',
            'sms_textit_base_url' => 'required|string|url|max:255',
            'sms_notify_sale_enabled' => 'boolean',
            'sms_notify_payment_enabled' => 'boolean',
            'sms_notify_cheque_passed_enabled' => 'boolean',
            'sms_notify_cheque_reminder_enabled' => 'boolean',
            'sms_template_sale' => 'required|string|max:480',
            'sms_template_payment' => 'required|string|max:480',
            'sms_template_cheque_passed' => 'required|string|max:480',
            'sms_template_cheque_reminder' => 'required|string|max:480',
        ]);

        Setting::set('sms_enabled', $this->sms_enabled ? '1' : '0', 'sms');
        Setting::set('sms_textit_id', $this->sms_textit_id, 'sms');
        Setting::set('sms_textit_password', $this->sms_textit_password, 'sms');
        Setting::set('sms_textit_base_url', $this->sms_textit_base_url, 'sms');
        Setting::set('sms_notify_sale_enabled', $this->sms_notify_sale_enabled ? '1' : '0', 'sms');
        Setting::set('sms_notify_payment_enabled', $this->sms_notify_payment_enabled ? '1' : '0', 'sms');
        Setting::set('sms_notify_cheque_passed_enabled', $this->sms_notify_cheque_passed_enabled ? '1' : '0', 'sms');
        Setting::set('sms_notify_cheque_reminder_enabled', $this->sms_notify_cheque_reminder_enabled ? '1' : '0', 'sms');
        Setting::set('sms_template_sale', $this->sms_template_sale, 'sms');
        Setting::set('sms_template_payment', $this->sms_template_payment, 'sms');
        Setting::set('sms_template_cheque_passed', $this->sms_template_cheque_passed, 'sms');
        Setting::set('sms_template_cheque_reminder', $this->sms_template_cheque_reminder, 'sms');

        ActivityLogger::log('setting_update', 'Updated SMS gateway, notification toggles, and message templates.');
        Flux::toast(variant: 'success', text: __('SMS gateway settings saved.'));
    }

    public function sendTestSms(TextItSmsService $smsService): void
    {
        $this->validate([
            'testPhone' => 'required|string|max:30',
            'testMessage' => 'required|string|max:480',
            'sms_textit_id' => 'required|string|max:30',
            'sms_textit_password' => 'required|string|max:100',
            'sms_textit_base_url' => 'required|string|url|max:255',
        ]);

        Setting::set('sms_enabled', $this->sms_enabled ? '1' : '0', 'sms');
        Setting::set('sms_textit_id', $this->sms_textit_id, 'sms');
        Setting::set('sms_textit_password', $this->sms_textit_password, 'sms');
        Setting::set('sms_textit_base_url', $this->sms_textit_base_url, 'sms');

        $message = $smsService->parseTemplate($this->testMessage, []);
        $result = $smsService->sendSms($this->testPhone, $message, 'TEST');

        Flux::toast(
            variant: $result['success'] ? 'success' : 'danger',
            text: $result['message'],
        );
    }

    public function restoreTemplates(): void
    {
        $this->sms_template_sale = SmsNotificationService::DEFAULT_TEMPLATES['sms_template_sale'];
        $this->sms_template_payment = SmsNotificationService::DEFAULT_TEMPLATES['sms_template_payment'];
        $this->sms_template_cheque_passed = SmsNotificationService::DEFAULT_TEMPLATES['sms_template_cheque_passed'];
        $this->sms_template_cheque_reminder = SmsNotificationService::DEFAULT_TEMPLATES['sms_template_cheque_reminder'];
    }

    #[Computed]
    public function placeholders()
    {
        return SmsNotificationService::placeholders();
    }

    #[Computed]
    public function smsLogs()
    {
        return SmsLog::query()
            ->latest('id')
            ->simplePaginate(10);
    }
};
?>

<div class="flex flex-col gap-4 sm:gap-6">
    <div>
        <h1 class="flex items-center gap-2 text-lg font-bold text-zinc-900 dark:text-zinc-100 sm:text-xl">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-950/40">
                <flux:icon.chat-bubble-left-right class="size-4 text-emerald-600 dark:text-emerald-400" />
            </span>
            {{ __('SMS Gateway') }}
        </h1>
        <p class="mt-0.5 ml-9 text-xs text-zinc-500 dark:text-zinc-400 sm:text-sm">
            {{ __('Connect TextIt and control every customer SMS notification.') }}
        </p>
    </div>

    <form wire:submit="saveSettings" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="flex flex-col gap-4 sm:gap-6">
            <div class="app-card p-4 sm:p-6">
                <div class="mb-4 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        <flux:icon.signal class="size-4 text-emerald-600" />
                        {{ __('TextIt Gateway') }}
                    </h2>
                    <p class="mt-0.5 ml-6 text-xs text-zinc-400">{{ __('Uses the Basic HTTP API endpoint provided by textit.biz.') }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:field variant="inline">
                            <flux:label>{{ __('Enable SMS gateway') }}</flux:label>
                            <flux:switch wire:model="sms_enabled" />
                            <flux:error name="sms_enabled" />
                        </flux:field>
                    </div>

                    <flux:input wire:model="sms_textit_id" :label="__('TextIt ID')" placeholder="947XXXXXXXX" />
                    <flux:input wire:model="sms_textit_password" :label="__('TextIt Password')" type="password" />

                    <div class="sm:col-span-2">
                        <flux:input wire:model="sms_textit_base_url" :label="__('Gateway URL')" placeholder="https://textit.biz/sendmsg" />
                    </div>
                </div>
            </div>

            <div class="app-card p-4 sm:p-6">
                <div class="mb-4 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        <flux:icon.bell-alert class="size-4 text-sky-600" />
                        {{ __('Notification Switches') }}
                    </h2>
                    <p class="mt-0.5 ml-6 text-xs text-zinc-400">{{ __('Each event can be enabled or disabled without changing the templates.') }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:switch wire:model="sms_notify_sale_enabled" :label="__('Sale completed')" description="Send the bill link as soon as checkout is completed." />
                    <flux:switch wire:model="sms_notify_payment_enabled" :label="__('Payment received')" description="Send after a due payment is recorded." />
                    <flux:switch wire:model="sms_notify_cheque_passed_enabled" :label="__('Cheque passed')" description="Send when a pending cheque is marked as passed." />
                    <flux:switch wire:model="sms_notify_cheque_reminder_enabled" :label="__('Cheque reminder')" description="Send two days before the cheque date." />
                </div>
            </div>

            <div class="app-card p-4 sm:p-6">
                <div class="mb-4 flex flex-col gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            <flux:icon.document-text class="size-4 text-violet-600" />
                            {{ __('Message Templates') }}
                        </h2>
                        <p class="mt-0.5 ml-6 text-xs text-zinc-400">{{ __('Use the placeholders shown below, including {bill_link} for public invoice access.') }}</p>
                    </div>
                    <flux:button type="button" variant="ghost" size="sm" wire:click="restoreTemplates">
                        {{ __('Restore best templates') }}
                    </flux:button>
                </div>

                <div class="grid gap-4">
                    <flux:textarea wire:model="sms_template_sale" :label="__('Sale completed SMS')" rows="3" />
                    <flux:textarea wire:model="sms_template_payment" :label="__('Payment received SMS')" rows="3" />
                    <flux:textarea wire:model="sms_template_cheque_passed" :label="__('Cheque passed SMS')" rows="3" />
                    <flux:textarea wire:model="sms_template_cheque_reminder" :label="__('Cheque reminder SMS')" rows="3" />
                </div>
            </div>
        </div>

        <aside class="flex flex-col gap-4">
            <div class="app-card p-4 sm:p-5">
                <div class="mb-4 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        <flux:icon.bolt class="size-4 text-amber-600" />
                        {{ __('Test SMS') }}
                    </h2>
                </div>

                <div class="flex flex-col gap-3">
                    <flux:input wire:model="testPhone" :label="__('Recipient')" placeholder="0771234567" />
                    <flux:textarea wire:model="testMessage" :label="__('Message')" rows="3" />
                    <flux:button type="button" wire:click="sendTestSms" variant="ghost" class="w-full">
                        {{ __('Send test') }}
                    </flux:button>
                </div>
            </div>

            <div class="app-card p-4 sm:p-5">
                <div class="mb-3 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        <flux:icon.information-circle class="size-4 text-zinc-500" />
                        {{ __('Available Data') }}
                    </h2>
                </div>

                <div class="max-h-[520px] space-y-2 overflow-y-auto pr-1">
                    @foreach ($this->placeholders as $placeholder)
                        <div class="rounded-lg border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-900/60" wire:key="sms-placeholder-{{ $placeholder['token'] }}">
                            <code class="text-xs font-bold text-violet-700 dark:text-violet-300">{{ $placeholder['token'] }}</code>
                            <p class="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $placeholder['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </aside>

        <div class="lg:col-span-2">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" icon="document-arrow-down">
                {{ __('Save SMS Settings') }}
            </flux:button>
        </div>
    </form>

    <section id="sms-log" class="app-card p-4 sm:p-6">
        <div class="mb-4 flex flex-col gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    <flux:icon.clipboard-document-list class="size-4 text-emerald-600" />
                    {{ __('SMS Logs') }}
                </h2>
                <p class="mt-0.5 ml-6 text-xs text-zinc-400">{{ __('Newest gateway attempts, delivery status, and TextIt responses.') }}</p>
            </div>

            <flux:badge size="sm" color="zinc">
                {{ __('Latest 10 per page') }}
            </flux:badge>
        </div>

        @if ($this->smsLogs->isEmpty())
            <div class="rounded-lg border border-dashed border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-800 dark:bg-zinc-900/60">
                <flux:icon.chat-bubble-left-right class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('No SMS logs yet') }}</p>
                <p class="mt-1 text-xs text-zinc-400">{{ __('Sent, failed, and pending SMS attempts will appear here.') }}</p>
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-zinc-100 dark:border-zinc-800">
                <div class="hidden bg-zinc-50 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-zinc-400 dark:bg-zinc-900/70 md:grid md:grid-cols-[130px_110px_minmax(0,1fr)_150px] md:gap-3">
                    <span>{{ __('Recipient') }}</span>
                    <span>{{ __('Status') }}</span>
                    <span>{{ __('Message / Response') }}</span>
                    <span>{{ __('Time') }}</span>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->smsLogs as $log)
                        @php
                            $statusColor = match ($log->status) {
                                'success' => 'emerald',
                                'failed' => 'rose',
                                default => 'amber',
                            };
                        @endphp

                        <article class="grid gap-3 bg-white p-4 dark:bg-zinc-900/40 md:grid-cols-[130px_110px_minmax(0,1fr)_150px]" wire:key="sms-log-{{ $log->id }}">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase text-zinc-400 md:hidden">{{ __('Recipient') }}</p>
                                <p class="truncate text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $log->phone }}</p>
                                @if ($log->ref_no)
                                    <p class="mt-1 truncate text-[11px] text-zinc-400">{{ $log->ref_no }}</p>
                                @endif
                            </div>

                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase text-zinc-400 md:hidden">{{ __('Status') }}</p>
                                <span @class([
                                    'inline-flex rounded-full px-2 py-1 text-[11px] font-bold uppercase',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $statusColor === 'emerald',
                                    'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => $statusColor === 'rose',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' => $statusColor === 'amber',
                                ])>{{ $log->status }}</span>
                            </div>

                            <div class="min-w-0">
                                <p class="text-sm leading-relaxed text-zinc-700 dark:text-zinc-200">{{ $log->message }}</p>
                                <p class="mt-2 break-words rounded-md bg-zinc-50 px-3 py-2 text-xs text-zinc-500 dark:bg-zinc-950/60 dark:text-zinc-400">
                                    {{ $log->response ?: __('Awaiting gateway response') }}
                                </p>
                            </div>

                            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                <p class="text-xs font-semibold uppercase text-zinc-400 md:hidden">{{ __('Time') }}</p>
                                <p>{{ $log->created_at?->format('Y-m-d') }}</p>
                                <p class="text-xs">{{ $log->created_at?->format('h:i A') }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>

            <div class="mt-4">
                <flux:pagination :paginator="$this->smsLogs" />
            </div>
        @endif
    </section>
</div>
