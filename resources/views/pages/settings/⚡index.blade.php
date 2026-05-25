<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\TextItSmsService;
use App\Services\ActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System Settings')] class extends Component
{
    // Tabs state
    public string $activeTab = 'business';

    // Business settings
    public string $business_name = '';
    public string $business_phone = '';
    public string $business_email = '';
    public string $business_address = '';
    public string $currency_symbol = 'Rs';
    public string $invoice_footer_note = '';
    public string $invoice_terms = '';
    public string $invoice_paper_size = 'thermal_80mm';

    // POS settings
    public bool $pos_allow_due_sale = true;
    public bool $pos_allow_negative_stock = false;
    public bool $pos_show_product_image = true;
    public bool $pos_enable_hold_order = true;
    public bool $pos_enable_multiple_price = true;

    // SMS settings
    public bool $sms_enabled = true;
    public string $sms_textit_id = '';
    public string $sms_textit_password = '';
    public string $sms_textit_base_url = 'https://textit.biz/sendmsg';
    public string $sms_template_sale = '';
    public string $sms_template_due = '';
    public string $sms_template_payment = '';

    // Test SMS state
    public string $testPhone = '';
    public string $testMessage = 'This is a test message from Imran Accessories POS.';

    // User CRUD state
    public ?int $editingUserId = null;
    public string $userName = '';
    public string $userEmail = '';
    public string $userPassword = '';
    public string $userRole = 'sales_staff';
    public bool $userIsActive = true;

    public function mount(): void
    {
        return $this->redirect(route('settings.business'), navigate: true);
    }

    // Keep remaining mount code below (unreachable, but preserved for reference)
    public function mountFull(): void
    {
        $this->business_name = Setting::get('business_name', '');
        $this->business_phone = Setting::get('business_phone', '');
        $this->business_email = Setting::get('business_email', '');
        $this->business_address = Setting::get('business_address', '');
        $this->currency_symbol = Setting::get('currency_symbol', 'Rs');
        $this->invoice_footer_note = Setting::get('invoice_footer_note', '');
        $this->invoice_terms = Setting::get('invoice_terms', '');
        $this->invoice_paper_size = Setting::get('invoice_paper_size', 'thermal_80mm');

        $this->pos_allow_due_sale = Setting::get('pos_allow_due_sale', '1') === '1';
        $this->pos_allow_negative_stock = Setting::get('pos_allow_negative_stock', '0') === '1';
        $this->pos_show_product_image = Setting::get('pos_show_product_image', '1') === '1';
        $this->pos_enable_hold_order = Setting::get('pos_enable_hold_order', '1') === '1';
        $this->pos_enable_multiple_price = Setting::get('pos_enable_multiple_price', '1') === '1';

        $this->sms_enabled = Setting::get('sms_enabled', '0') === '1';
        $this->sms_textit_id = Setting::get('sms_textit_id', '');
        $this->sms_textit_password = Setting::get('sms_textit_password', '');
        $this->sms_textit_base_url = Setting::get('sms_textit_base_url', 'https://textit.biz/sendmsg');
        $this->sms_template_sale = Setting::get('sms_template_sale', '');
        $this->sms_template_due = Setting::get('sms_template_due', '');
        $this->sms_template_payment = Setting::get('sms_template_payment', '');
    }

    public function saveBusiness(): void
    {
        $this->validate([
            'business_name' => 'required|string|max:120',
            'business_phone' => 'required|string',
            'business_email' => 'required|email',
            'business_address' => 'required|string',
            'currency_symbol' => 'required|string|max:10',
            'invoice_footer_note' => 'nullable|string',
            'invoice_terms' => 'nullable|string',
            'invoice_paper_size' => 'required|string',
        ]);

        Setting::set('business_name', $this->business_name, 'general');
        Setting::set('business_phone', $this->business_phone, 'general');
        Setting::set('business_email', $this->business_email, 'general');
        Setting::set('business_address', $this->business_address, 'general');
        Setting::set('currency_symbol', $this->currency_symbol, 'general');
        Setting::set('invoice_footer_note', $this->invoice_footer_note, 'invoice');
        Setting::set('invoice_terms', $this->invoice_terms, 'invoice');
        Setting::set('invoice_paper_size', $this->invoice_paper_size, 'invoice');

        ActivityLogger::log('setting_update', 'Updated Business Information and Invoice Templates.');
        Flux::toast(variant: 'success', text: __('Business information updated.'));
    }

    public function savePOS(): void
    {
        Setting::set('pos_allow_due_sale', $this->pos_allow_due_sale ? '1' : '0', 'pos');
        Setting::set('pos_allow_negative_stock', $this->pos_allow_negative_stock ? '1' : '0', 'pos');
        Setting::set('pos_show_product_image', $this->pos_show_product_image ? '1' : '0', 'pos');
        Setting::set('pos_enable_hold_order', $this->pos_enable_hold_order ? '1' : '0', 'pos');
        Setting::set('pos_enable_multiple_price', $this->pos_enable_multiple_price ? '1' : '0', 'pos');

        ActivityLogger::log('setting_update', 'Updated POS Defaults.');
        Flux::toast(variant: 'success', text: __('POS settings updated.'));
    }

    public function saveSMS(): void
    {
        Setting::set('sms_enabled', $this->sms_enabled ? '1' : '0', 'sms');
        Setting::set('sms_textit_id', $this->sms_textit_id, 'sms');
        Setting::set('sms_textit_password', $this->sms_textit_password, 'sms');
        Setting::set('sms_textit_base_url', $this->sms_textit_base_url, 'sms');
        Setting::set('sms_template_sale', $this->sms_template_sale, 'sms');
        Setting::set('sms_template_due', $this->sms_template_due, 'sms');
        Setting::set('sms_template_payment', $this->sms_template_payment, 'sms');

        ActivityLogger::log('setting_update', 'Updated SMS templates and gateways.');
        Flux::toast(variant: 'success', text: __('SMS settings and templates saved.'));
    }

    public function sendTestSms(TextItSmsService $smsService): void
    {
        $this->validate([
            'testPhone' => 'required|string',
            'testMessage' => 'required|string',
        ]);

        // Save SMS Settings first
        Setting::set('sms_enabled', '1'); // Force enable for test
        Setting::set('sms_textit_id', $this->sms_textit_id, 'sms');
        Setting::set('sms_textit_password', $this->sms_textit_password, 'sms');
        Setting::set('sms_textit_base_url', $this->sms_textit_base_url, 'sms');

        $result = $smsService->sendSms($this->testPhone, $this->testMessage, 'TEST');

        if ($result['success']) {
            Flux::toast(variant: 'success', text: $result['message']);
        } else {
            Flux::toast(variant: 'danger', text: $result['message']);
        }
    }

    // User Management logic
    public function saveUser(): void
    {
        $rules = [
            'userName' => 'required|string|max:100',
            'userEmail' => [
                'required',
                'email',
                Rule::unique(User::class, 'email')->ignore($this->editingUserId),
            ],
            'userRole' => 'required|string',
            'userIsActive' => 'boolean',
        ];

        if (! $this->editingUserId) {
            $rules['userPassword'] = 'required|string|min:6';
        } else {
            $rules['userPassword'] = 'nullable|string|min:6';
        }

        $this->validate($rules);

        if ($this->editingUserId) {
            $user = User::query()->findOrFail($this->editingUserId);
            $user->name = $userName = $this->userName;
            $user->email = $this->userEmail;
            $user->role = $this->userRole;
            $user->is_active = $this->userIsActive;

            if ($this->userPassword) {
                $user->password = Hash::make($this->userPassword);
            }

            $user->save();
            ActivityLogger::log('user_update', "Updated User details for {$userName}");
            Flux::toast(variant: 'success', text: __('User details updated.'));
        } else {
            User::query()->create([
                'name' => $this->userName,
                'email' => $this->userEmail,
                'password' => Hash::make($this->userPassword),
                'role' => $this->userRole,
                'is_active' => $this->userIsActive,
            ]);
            ActivityLogger::log('user_create', "Registered new user: {$this->userName} [{$this->userRole}]");
            Flux::toast(variant: 'success', text: __('New user registered successfully.'));
        }

        $this->resetUserForm();
    }

    public function editUser(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->editingUserId = $user->id;
        $this->userName = $user->name;
        $this->userEmail = $user->email;
        $this->userRole = $user->role;
        $this->userIsActive = (bool) $user->is_active;
        $this->userPassword = '';
    }

    public function resetUserForm(): void
    {
        $this->reset('editingUserId', 'userName', 'userEmail', 'userPassword', 'userRole', 'userIsActive');
    }

    public function deleteUser(int $userId): void
    {
        if ($userId === auth()->id()) {
            Flux::toast(variant: 'danger', text: __('Cannot remove your own user account.'));
            return;
        }

        $user = User::query()->findOrFail($userId);
        ActivityLogger::log('user_delete', "Deleted user account: {$user->name}");
        $user->delete();

        Flux::toast(variant: 'success', text: __('User deleted successfully.'));
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get();
    }

    // Backups / Caching operations
    public function clearCache(string $type): void
    {
        try {
            if ($type === 'route') {
                Artisan::call('route:clear');
                Flux::toast(variant: 'success', text: __('Route cache cleared successfully.'));
            } elseif ($type === 'config') {
                Artisan::call('config:clear');
                Flux::toast(variant: 'success', text: __('Config cache cleared successfully.'));
            } elseif ($type === 'view') {
                Artisan::call('view:clear');
                Flux::toast(variant: 'success', text: __('Compiled view cache cleared successfully.'));
            } elseif ($type === 'app') {
                Artisan::call('cache:clear');
                Flux::toast(variant: 'success', text: __('Application data cache cleared.'));
            }

            ActivityLogger::log('maintenance', "Cleared {$type} system cache.");
        } catch (\Exception $e) {
            Flux::toast(variant: 'danger', text: __('Error: ') . $e->getMessage());
        }
    }

    public function linkStorage(): void
    {
        try {
            Artisan::call('storage:link');
            Flux::toast(variant: 'success', text: __('Storage symbolic link created.'));
            ActivityLogger::log('maintenance', 'Created public storage symbolic link.');
        } catch (\Exception $e) {
            Flux::toast(variant: 'danger', text: __('Error: ') . $e->getMessage());
        }
    }
}; ?>

<div class="flex flex-col gap-6" x-data="{ currentTab: @entangle('activeTab') }">
    <!-- Mobile Tabs Carousel (Horizontal Scroll chips) -->
    <div class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4 pb-2 lg:-mx-10 lg:px-10">
        <button
            type="button"
            class="flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-xs font-semibold shadow-sm transition"
            :class="currentTab === 'business' ? 'bg-orange-600 text-white shadow-orange-200' : 'bg-white text-zinc-600 border border-zinc-200'"
            @click="currentTab = 'business'; $wire.activeTab = 'business'"
        >
            <flux:icon.home class="size-4" />
            {{ __('Business details') }}
        </button>
        <button
            type="button"
            class="flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-xs font-semibold shadow-sm transition"
            :class="currentTab === 'pos' ? 'bg-orange-600 text-white shadow-orange-200' : 'bg-white text-zinc-600 border border-zinc-200'"
            @click="currentTab = 'pos'; $wire.activeTab = 'pos'"
        >
            <flux:icon.shopping-bag class="size-4" />
            {{ __('POS Default Settings') }}
        </button>
        <button
            type="button"
            class="flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-xs font-semibold shadow-sm transition"
            :class="currentTab === 'sms' ? 'bg-orange-600 text-white shadow-orange-200' : 'bg-white text-zinc-600 border border-zinc-200'"
            @click="currentTab = 'sms'; $wire.activeTab = 'sms'"
        >
            <flux:icon.chat-bubble-left-right class="size-4" />
            {{ __('SMS Alerts Gateway') }}
        </button>
        <button
            type="button"
            class="flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-xs font-semibold shadow-sm transition"
            :class="currentTab === 'users' ? 'bg-orange-600 text-white shadow-orange-200' : 'bg-white text-zinc-600 border border-zinc-200'"
            @click="currentTab = 'users'; $wire.activeTab = 'users'"
        >
            <flux:icon.users class="size-4" />
            {{ __('Staff & Cashiers') }}
        </button>
        <button
            type="button"
            class="flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-xs font-semibold shadow-sm transition"
            :class="currentTab === 'backup' ? 'bg-orange-600 text-white shadow-orange-200' : 'bg-white text-zinc-600 border border-zinc-200'"
            @click="currentTab = 'backup'; $wire.activeTab = 'backup'"
        >
            <flux:icon.cog-6-tooth class="size-4" />
            {{ __('Maintenance Tools') }}
        </button>
    </div>

    <!-- 1. BUSINESS DETAILS TAB -->
    <div x-cloak x-show="currentTab === 'business'" class="flex flex-col gap-6">
        <form wire:submit="saveBusiness" class="app-card p-5">
            <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                <h3 class="font-display text-base font-semibold text-zinc-900">{{ __('Shop Settings') }}</h3>
                <p class="text-xs text-zinc-500">{{ __('Configure invoice receipts headings, footers, and currency defaults.') }}</p>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_name" :label="__('Business Name')" required />
                <flux:input wire:model="business_phone" :label="__('Phone Number')" required />
                <flux:input wire:model="business_email" :label="__('Email Address')" type="email" required />
                <flux:input wire:model="currency_symbol" :label="__('Currency Symbol (e.g. Rs / $)')" required />
                
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="business_address" :label="__('Shop Address')" rows="2" required />
                </div>

                <div class="sm:col-span-2">
                    <flux:select wire:model="invoice_paper_size" :label="__('Receipt Paper Dimensions')">
                        <option value="thermal_80mm">Thermal Printer (80mm Width)</option>
                        <option value="thermal_58mm">Thermal Printer (58mm Width)</option>
                        <option value="A4">Standard A4 Sheet Size</option>
                    </flux:select>
                </div>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="invoice_footer_note" :label="__('Invoice Receipt Footer Note')" rows="2" />
                </div>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="invoice_terms" :label="__('Warranty Terms & Conditions')" rows="2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                    {{ __('Save Details') }}
                </flux:button>
            </div>
        </form>
    </div>

    <!-- 2. POS DEFAULT SETTINGS TAB -->
    <div x-cloak x-show="currentTab === 'pos'" class="flex flex-col gap-6">
        <form wire:submit="savePOS" class="app-card p-5">
            <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                <h3 class="font-display text-base font-semibold text-zinc-900">{{ __('POS Preferences') }}</h3>
                <p class="text-xs text-zinc-500">{{ __('Control the cashier terminal interface and sale calculations defaults.') }}</p>
            </div>

            <div class="mt-4 flex flex-col gap-5">
                <flux:checkbox wire:model="pos_allow_due_sale" :label="__('Enable Partial / Due Sales')" description="Allow checkout with due amount remaining on customer account." />
                <flux:checkbox wire:model="pos_allow_negative_stock" :label="__('Allow Negative Stock Checkout')" description="Permit cashiers to complete sales even if digital inventory is 0." />
                <flux:checkbox wire:model="pos_show_product_image" :label="__('Show Thumbnail Images in POS Grid')" description="Highly recommended for fast item identification." />
                <flux:checkbox wire:model="pos_enable_hold_order" :label="__('Enable Temporary Cart Hold')" description="Allows holding customer cart order to attend next in queue." />
                <flux:checkbox wire:model="pos_enable_multiple_price" :label="__('Support Multiple Prices (Retail / Wholesale)')" description="Prompts cashier to choose price level if product has custom prices." />
            </div>

            <div class="mt-8 flex justify-end">
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                    {{ __('Save POS Defaults') }}
                </flux:button>
            </div>
        </form>
    </div>

    <!-- 3. SMS ALERTS GATEWAY TAB -->
    <div x-cloak x-show="currentTab === 'sms'" class="flex flex-col gap-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Gateway configurations -->
            <form wire:submit="saveSMS" class="app-card p-5">
                <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                    <h3 class="font-display text-base font-semibold text-zinc-900">{{ __('textit.biz API Settings') }}</h3>
                    <p class="text-xs text-zinc-500">{{ __('Integrate bulk SMS notifications for transaction confirmations.') }}</p>
                </div>

                <div class="mt-4 flex flex-col gap-4">
                    <flux:switch wire:model="sms_enabled" :label="__('Enable SMS Dispatching Service')" />
                    
                    <flux:input wire:model="sms_textit_id" :label="__('TextIt User ID (Sender Phone Number)')" placeholder="e.g. 94758822269" required />
                    <flux:input wire:model="sms_textit_password" :label="__('TextIt Password')" type="password" required />
                    <flux:input wire:model="sms_textit_base_url" :label="__('Gateway Dispatch Base URL')" required />

                    <div class="border-t border-zinc-100 pt-4">
                        <flux:textarea wire:model="sms_template_sale" :label="__('Sale Invoice SMS Template')" rows="3" description="Variables: {customer_name}, {invoice_no}, {total}, {paid}, {due}, {business_name}" />
                    </div>

                    <div>
                        <flux:textarea wire:model="sms_template_due" :label="__('Due Payment Reminder Template')" rows="3" description="Variables: {customer_name}, {invoice_no}, {due}, {business_name}" />
                    </div>

                    <div>
                        <flux:textarea wire:model="sms_template_payment" :label="__('Payment Received Alert Template')" rows="3" description="Variables: {customer_name}, {paid}, {due}, {business_name}" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                        {{ __('Save SMS Options') }}
                    </flux:button>
                </div>
            </form>

            <!-- Test SMS Console -->
            <div class="flex flex-col gap-6">
                <div class="app-card p-5">
                    <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                        <h3 class="font-display text-base font-semibold text-zinc-900">{{ __('SMS Sandbox Test') }}</h3>
                        <p class="text-xs text-zinc-500">{{ __('Verify TextIt gateway response using an instant dispatch test.') }}</p>
                    </div>

                    <div class="mt-4 flex flex-col gap-4">
                        <flux:input wire:model="testPhone" :label="__('Recipient Phone Number')" placeholder="e.g. 0771234567" />
                        <flux:textarea wire:model="testMessage" :label="__('Test Message Contents')" rows="3" />

                        <flux:button type="button" wire:click="sendTestSms" variant="ghost" class="mt-2 w-full border-dashed">
                            <flux:icon.bolt class="size-4 mr-1 text-orange-500" />
                            {{ __('Execute Dispatch Test') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. STAFF & CASHIERS TAB -->
    <div x-cloak x-show="currentTab === 'users'" class="flex flex-col gap-6">
        <div class="grid gap-6 lg:grid-cols-[1fr_2fr]">
            <!-- Registration / Edit Form -->
            <form wire:submit="saveUser" class="app-card p-5 h-fit">
                <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                    <h3 class="font-display text-base font-semibold text-zinc-900">
                        {{ $editingUserId ? __('Modify Staff Account') : __('Register New Staff') }}
                    </h3>
                    <p class="text-xs text-zinc-500">{{ __('Create specific cashiers, inventory managers, or accountant credentials.') }}</p>
                </div>

                <div class="mt-4 flex flex-col gap-4">
                    <flux:input wire:model="userName" :label="__('Full Name')" required />
                    <flux:input wire:model="userEmail" :label="__('Email Address')" type="email" required />
                    
                    <flux:input wire:model="userPassword" :label="__('Account Password')" type="password" :placeholder="$editingUserId ? __('Leave empty to keep current') : ''" />

                    <flux:select wire:model="userRole" :label="__('System Authorization Role')">
                        <option value="sales_staff">Sales Staff (POS Terminal checkout only)</option>
                        <option value="cashier">Cashier (POS Terminal checkout, returns, and customers)</option>
                        <option value="inventory_manager">Inventory Manager (Product catalog & Purchase management)</option>
                        <option value="accountant">Accountant (Expenses, reports, and payments auditing)</option>
                        <option value="admin">System Admin (Full control except database settings)</option>
                        <option value="super_admin">Super Admin (Root authority over database & backups)</option>
                    </flux:select>

                    <flux:switch wire:model="userIsActive" :label="__('Account is Active / Enabled')" />
                </div>

                <div class="mt-6 flex gap-2">
                    <flux:button type="submit" variant="primary" class="flex-1">
                        {{ $editingUserId ? __('Update Account') : __('Save Account') }}
                    </flux:button>
                    @if ($editingUserId)
                        <flux:button type="button" wire:click="resetUserForm" variant="ghost">
                            {{ __('Cancel') }}
                        </flux:button>
                    @endif
                </div>
            </form>

            <!-- Active lists -->
            <div class="app-card p-4">
                <h4 class="font-display text-sm font-semibold text-zinc-950 mb-3">{{ __('Registered Accounts') }}</h4>
                
                <div class="grid gap-3">
                    @foreach ($this->users as $u)
                        <div class="flex items-center justify-between rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4" wire:key="user-card-{{ $u->id }}">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-orange-100 text-sm font-semibold text-orange-700">
                                    {{ $u->initials() }}
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-zinc-950">{{ $u->name }}</p>
                                    <p class="text-xs text-zinc-500">{{ $u->email }}</p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <flux:badge size="sm" color="zinc">{{ str_replace('_', ' ', strtoupper($u->role)) }}</flux:badge>
                                        @if ($u->is_active)
                                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                                        @else
                                            <span class="inline-block h-2 w-2 rounded-full bg-rose-400"></span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <flux:button variant="ghost" size="sm" wire:click="editUser({{ $u->id }})">
                                    {{ __('Edit') }}
                                </flux:button>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-rose-500 hover:underline px-2 py-1"
                                    x-on:click.prevent="if (confirm('Permanently remove this staff member?')) { $wire.deleteUser({{ $u->id }}) }"
                                >
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- 5. MAINTENANCE TOOLS TAB -->
    <div x-cloak x-show="currentTab === 'backup'" class="flex flex-col gap-6">
        <div class="app-card p-5">
            <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                <h3 class="font-display text-base font-semibold text-zinc-900">{{ __('Maintenance & Performance Controls') }}</h3>
                <p class="text-xs text-zinc-500">{{ __('Execute clean-ups or link configurations to ensure maximum application speed.') }}</p>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4">
                    <h4 class="text-sm font-semibold text-zinc-900">{{ __('Clean App Caches') }}</h4>
                    <p class="text-xs text-zinc-500 mt-1 mb-3">{{ __('Flushes general application data cache storage.') }}</p>
                    <flux:button type="button" size="sm" wire:click="clearCache('app')" class="w-full">
                        {{ __('Run cache:clear') }}
                    </flux:button>
                </div>

                <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4">
                    <h4 class="text-sm font-semibold text-zinc-900">{{ __('View Compile Cache') }}</h4>
                    <p class="text-xs text-zinc-500 mt-1 mb-3">{{ __('Forces blade engine to recompile UI templates.') }}</p>
                    <flux:button type="button" size="sm" wire:click="clearCache('view')" class="w-full">
                        {{ __('Run view:clear') }}
                    </flux:button>
                </div>

                <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4">
                    <h4 class="text-sm font-semibold text-zinc-900">{{ __('Config Cache') }}</h4>
                    <p class="text-xs text-zinc-500 mt-1 mb-3">{{ __('Clears cached configuration files for system refresh.') }}</p>
                    <flux:button type="button" size="sm" wire:click="clearCache('config')" class="w-full">
                        {{ __('Run config:clear') }}
                    </flux:button>
                </div>

                <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4">
                    <h4 class="text-sm font-semibold text-zinc-900">{{ __('Routes Register') }}</h4>
                    <p class="text-xs text-zinc-500 mt-1 mb-3">{{ __('Regenerates routes register for clean url access.') }}</p>
                    <flux:button type="button" size="sm" wire:click="clearCache('route')" class="w-full">
                        {{ __('Run route:clear') }}
                    </flux:button>
                </div>

                <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4">
                    <h4 class="text-sm font-semibold text-zinc-900">{{ __('Public Assets Symlink') }}</h4>
                    <p class="text-xs text-zinc-500 mt-1 mb-3">{{ __('Creates the symbolic link connecting public folder to private files.') }}</p>
                    <flux:button type="button" size="sm" wire:click="linkStorage" class="w-full">
                        {{ __('Run storage:link') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </div>
</div>
