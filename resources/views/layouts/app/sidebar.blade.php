@php
    $isPosTerminal = request()->routeIs('pos.*');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <script>
            if (window.location.hostname === '127.0.0.1') {
                window.location.hostname = 'localhost';
            }
        </script>
        @include('partials.head')
        <script>
            // Load theme and sidebar preferences
            if (localStorage.theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
                if (!localStorage.theme) {
                    localStorage.theme = 'light';
                }
            }
        </script>
        <style>
            .sidebar-transition {
                transition: width 300ms cubic-bezier(0.4, 0, 0.2, 1);
            }
        </style>
    </head>
    <body @class([
        'min-h-screen text-zinc-900 dark:text-zinc-100 antialiased font-sans transition-colors duration-300',
        'bg-white dark:bg-zinc-950' => $isPosTerminal,
        'bg-slate-50 dark:bg-zinc-950' => ! $isPosTerminal,
    ])>
        
        <div x-data="{ 
            mobileMenuOpen: false, 
            sidebarCollapsed: localStorage.sidebarCollapsed === 'true',
            isDark: localStorage.theme === 'dark',
            expandedMenu: localStorage.expandedMenu || '{{ request()->routeIs('pos.*') ? 'pos' : (request()->routeIs('products.*') ? 'products' : (request()->routeIs('parties.suppliers') ? 'suppliers' : (request()->routeIs('parties.customers') ? 'customers' : (request()->routeIs('purchases.*') ? 'purchases' : (request()->routeIs('expenses.*') ? 'expenses' : (request()->routeIs('accounting.*') ? 'accounting' : (request()->routeIs('reports.*') ? 'reports' : (request()->routeIs('settings.*') || request()->routeIs('profile.edit') || request()->routeIs('security.edit') || request()->routeIs('appearance.edit') || request()->routeIs('developer.*') ? 'settings' : 'dashboard')))))))) }}',
            
            toggleSidebar() {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                localStorage.sidebarCollapsed = this.sidebarCollapsed;
            },
            toggleTheme() {
                this.isDark = !this.isDark;
                if (this.isDark) {
                    localStorage.theme = 'dark';
                    document.documentElement.classList.add('dark');
                } else {
                    localStorage.theme = 'light';
                    document.documentElement.classList.remove('dark');
                }
            },
            toggleMenu(menu) {
                if (this.sidebarCollapsed) {
                    this.sidebarCollapsed = false;
                    localStorage.sidebarCollapsed = 'false';
                }
                this.expandedMenu = this.expandedMenu === menu ? null : menu;
                localStorage.expandedMenu = this.expandedMenu;
            }
        }" class="min-h-screen lg:flex">
            
            @unless ($isPosTerminal)
                <!-- Desktop Sidebar (Prism Light Panel with smooth width transition) -->
                <aside
                    class="hidden lg:flex lg:flex-col lg:gap-6 lg:border-r lg:border-zinc-150 lg:bg-white lg:py-6 lg:backdrop-blur-md dark:lg:bg-zinc-900 dark:lg:border-zinc-800 sidebar-transition"
                    :class="sidebarCollapsed ? 'lg:w-20 lg:px-3' : 'lg:w-72 lg:px-6'"
                >
                <!-- Header with Logo / Menu toggle -->
                <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800/80 pb-4" :class="sidebarCollapsed ? 'justify-center' : 'justify-between'">
                    <!-- Logo (Hide text if collapsed) -->
                    <div x-show="!sidebarCollapsed" class="flex items-center">
                        <x-app-logo href="{{ route('dashboard') }}" wire:navigate />
                    </div>
                    <div x-show="sidebarCollapsed" class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-400">
                        <flux:icon.bolt class="size-6" />
                    </div>

                    <!-- Sidebar Toggle Action Button -->
                    <button 
                        type="button" 
                        @click="toggleSidebar()" 
                        class="flex h-8 w-8 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-600 shadow-sm transition hover:bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-900"
                        :class="sidebarCollapsed ? 'mt-4' : ''"
                        title="Collapse or Expand Menu"
                    >
                        <flux:icon.bars-3 class="size-4" />
                    </button>
                </div>

                <!-- Scrollable Menu Section -->
                <div class="flex-1 overflow-y-auto space-y-4 pr-1 scrollbar-none">
                    
                    <div class="space-y-1">
                        <p x-show="!sidebarCollapsed" class="text-[10px] font-black uppercase tracking-wider text-zinc-400 px-3 py-1 dark:text-zinc-500">
                            {{ __('MAIN') }}
                        </p>
                        
                        <nav class="space-y-3">
                            
                            <!-- 1. Dashboard (Single Action Card) -->
                            <a
                                href="{{ route('dashboard') }}"
                                wire:navigate
                                title="{{ __('Dashboard') }}"
                                @class([
                                    'flex items-center rounded-xl transition-all duration-300 w-full',
                                    'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' => request()->routeIs('dashboard'),
                                    'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40' => !request()->routeIs('dashboard'),
                                ])
                                :class="sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300" 
                                      :class="sidebarCollapsed ? '' : '{{ request()->routeIs('dashboard') ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500' }}'">
                                    <flux:icon.home class="size-5 shrink-0" />
                                </span>
                                <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Dashboard') }}</span>
                            </a>

                            <!-- 2. POS Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('pos')"
                                    title="{{ __('POS Operations') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'pos' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'pos' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.shopping-bag class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('POS') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'pos'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'pos'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- POS Sub-Menu Links -->
                                <div 
                                    x-show="expandedMenu === 'pos' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('pos.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.computer-desktop class="size-4 text-zinc-400" />
                                        <span>POS Screen</span>
                                    </a>
                                    <a href="{{ route('sales.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.document-text class="size-4 text-zinc-400" />
                                        <span>Sales List</span>
                                    </a>
                                    <a href="{{ route('pos.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.pause class="size-4 text-zinc-400" />
                                        <span>Hold Orders</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 3. Suppliers Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('suppliers')"
                                    title="{{ __('Suppliers Directory') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'suppliers' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'suppliers' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.truck class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Suppliers') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'suppliers'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'suppliers'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Suppliers Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'suppliers' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('parties.suppliers') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.users class="size-4 text-zinc-400" />
                                        <span>Suppliers List</span>
                                    </a>
                                    <a href="{{ route('purchases.create') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.plus class="size-4 text-zinc-400" />
                                        <span>Add Restock Order</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 4. Customers Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('customers')"
                                    title="{{ __('Customers Directory') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'customers' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'customers' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.users class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Customers') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'customers'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'customers'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Customers Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'customers' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('parties.customers') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.user-group class="size-4 text-zinc-400" />
                                        <span>Customers Directory</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 5. Products Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('products')"
                                    title="{{ __('Products Catalog') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'products' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'products' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.cube class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Products') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'products'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'products'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Products Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'products' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('products.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.list-bullet class="size-4 text-zinc-400" />
                                        <span>Products List</span>
                                    </a>
                                    <a href="{{ route('products.create') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.plus class="size-4 text-zinc-400" />
                                        <span>Add Product</span>
                                    </a>
                                    <a href="{{ route('products.categories') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.tag class="size-4 text-zinc-400" />
                                        <span>Categories</span>
                                    </a>
                                    <a href="{{ route('products.brands') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.bookmark-square class="size-4 text-zinc-400" />
                                        <span>Brands</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 6. Wholesale Purchases Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('purchases')"
                                    title="{{ __('Wholesale Purchases') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'purchases' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'purchases' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.shopping-bag class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Purchases') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'purchases'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'purchases'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Purchases Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'purchases' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('purchases.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.shopping-bag class="size-4 text-zinc-400" />
                                        <span>Purchases</span>
                                    </a>
                                    <a href="{{ route('purchases.create') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.plus-circle class="size-4 text-zinc-400" />
                                        <span>Add Purchase</span>
                                    </a>
                                    <a href="{{ route('purchases.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.arrow-path class="size-4 text-zinc-400" />
                                        <span>Purchase Returns</span>
                                    </a>
                                    <a href="{{ route('reports.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.chart-bar class="size-4 text-zinc-400" />
                                        <span>Purchase Report</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 7. Expenses Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('expenses')"
                                    title="{{ __('Expenses Register') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'expenses' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'expenses' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.credit-card class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Expenses') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'expenses'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'expenses'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Expenses Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'expenses' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('expenses.index') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.credit-card class="size-4 text-zinc-400" />
                                        <span>Expenses</span>
                                    </a>
                                    <a href="{{ route('expenses.create') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.plus-circle class="size-4 text-zinc-400" />
                                        <span>Add Expense</span>
                                    </a>
                                    <a href="{{ route('expenses.categories') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.tag class="size-4 text-zinc-400" />
                                        <span>Expense Categories</span>
                                    </a>
                                    <a href="{{ route('reports.expenses') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400">
                                        <flux:icon.chart-bar class="size-4 text-zinc-400" />
                                        <span>Expense Report</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 8. Cash Book Accounts Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('accounting')"
                                    title="{{ __('Accounts Ledger') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'accounting' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'accounting' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.building-library class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Accounts') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'accounting'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'accounting'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Accounts Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'accounting' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('accounting.cash-book') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.index', 'accounting.cash-book') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.book-open class="size-4 text-zinc-400" />
                                        <span>Cash Book</span>
                                    </a>
                                    <a href="{{ route('accounting.daily-cash-closing') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.daily-cash-closing') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.banknotes class="size-4 text-zinc-400" />
                                        <span>Daily Cash Closing</span>
                                    </a>
                                    <a href="{{ route('accounting.daily-register-closing') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.daily-register-closing') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.document-text class="size-4 text-zinc-400" />
                                        <span>Daily Register Closing</span>
                                    </a>
                                    <a href="{{ route('accounting.cash-in') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.cash-in') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.arrow-down class="size-4 text-zinc-400" />
                                        <span>Cash In</span>
                                    </a>
                                    <a href="{{ route('accounting.cash-out') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.cash-out') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.arrow-up class="size-4 text-zinc-400" />
                                        <span>Cash Out</span>
                                    </a>
                                    <a href="{{ route('accounting.cash-balance') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.cash-balance') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.credit-card class="size-4 text-zinc-400" />
                                        <span>Cash Balance</span>
                                    </a>
                                    <a href="{{ route('accounting.bank-transfers') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.bank-transfers') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.building-library class="size-4 text-zinc-400" />
                                        <span>Bank Transfers</span>
                                    </a>
                                    <a href="{{ route('accounting.payment-method-report') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.payment-method-report') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.credit-card class="size-4 text-zinc-400" />
                                        <span>Payment Method Report</span>
                                    </a>
                                    <a href="{{ route('accounting.t-accounts') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('accounting.t-accounts') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.table-cells class="size-4 text-zinc-400" />
                                        <span>T Accounts</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 9. Reports Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('reports')"
                                    title="{{ __('Reports & Analytics') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'reports' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'reports' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.chart-bar class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Reports') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'reports'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'reports'" class="size-3.5" />
                                    </span>
                                </button>

                                <!-- Reports Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'reports' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                    <a href="{{ route('reports.sales') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.index', 'reports.sales') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.chart-bar class="size-4 text-zinc-400" />
                                        <span>Sales Report</span>
                                    </a>
                                    <a href="{{ route('reports.purchases') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.purchases') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.chart-bar class="size-4 text-zinc-400" />
                                        <span>Purchase Report</span>
                                    </a>
                                    <a href="{{ route('reports.profit-loss') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.profit-loss') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.arrow-trending-up class="size-4 text-zinc-400" />
                                        <span>Profit & Loss</span>
                                    </a>
                                    <a href="{{ route('reports.stock') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.stock') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.cube class="size-4 text-zinc-400" />
                                        <span>Stock Report</span>
                                    </a>
                                    <a href="{{ route('reports.expenses') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.expenses') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.credit-card class="size-4 text-zinc-400" />
                                        <span>Expense Report</span>
                                    </a>
                                    <a href="{{ route('reports.receives') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.receives') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.arrow-down-tray class="size-4 text-zinc-400" />
                                        <span>Receive Report</span>
                                    </a>
                                    <a href="{{ route('reports.debits') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.debits') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.arrow-up-tray class="size-4 text-zinc-400" />
                                        <span>Debit Report</span>
                                    </a>
                                    <a href="{{ route('reports.due-bills') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.due-bills') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.clock class="size-4 text-zinc-400" />
                                        <span>Due Bills Report</span>
                                    </a>
                                    <a href="{{ route('reports.customer-dues') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('reports.customer-dues') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.user-group class="size-4 text-zinc-400" />
                                        <span>Customer Due Report</span>
                                    </a>
                                </div>
                            </div>

                            <!-- 10. Settings Accordion Menu -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    @click="toggleMenu('settings')"
                                    title="{{ __('System Settings') }}"
                                    class="flex items-center rounded-xl transition-all duration-300 w-full"
                                    :class="[
                                        expandedMenu === 'settings' 
                                            ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400 font-bold' 
                                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/40',
                                        sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2.5 text-sm gap-3'
                                    ]"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-300"
                                          :class="expandedMenu === 'settings' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'">
                                        <flux:icon.cog-6-tooth class="size-5 shrink-0" />
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="flex-1 text-left font-medium">{{ __('Settings') }}</span>
                                    <span x-show="!sidebarCollapsed" class="text-zinc-400 shrink-0">
                                        <flux:icon.chevron-down x-show="expandedMenu === 'settings'" class="size-3.5" />
                                        <flux:icon.chevron-right x-show="expandedMenu !== 'settings'" class="size-3.5" />
                                    </span>
                                </button>

                                 <!-- Settings Sub-Menu -->
                                <div 
                                    x-show="expandedMenu === 'settings' && !sidebarCollapsed" 
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="pl-12 pr-2 py-1 space-y-1.5"
                                >
                                     <a href="{{ route('profile.edit') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('profile.edit') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                         <flux:icon.user class="size-4 text-zinc-400" />
                                         <span>Profile Settings</span>
                                     </a>
                                     <a href="{{ route('security.edit') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('security.edit') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                         <flux:icon.shield-check class="size-4 text-zinc-400" />
                                         <span>Security Settings</span>
                                     </a>
                                    <a href="{{ route('settings.business') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('settings.business') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.building-office class="size-4 text-zinc-400" />
                                        <span>Business Info</span>
                                    </a>
                                    <a href="{{ route('settings.general') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('settings.general') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.adjustments-horizontal class="size-4 text-zinc-400" />
                                        <span>General Settings</span>
                                    </a>
                                    <a href="{{ route('settings.invoice') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('settings.invoice') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.document-text class="size-4 text-zinc-400" />
                                        <span>Invoice Settings</span>
                                    </a>
                                    <a href="{{ route('settings.pos-settings') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('settings.pos-settings') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.computer-desktop class="size-4 text-zinc-400" />
                                        <span>POS Settings</span>
                                    </a>
                                    <a href="{{ route('settings.sms') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('settings.sms') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.chat-bubble-left-right class="size-4 text-zinc-400" />
                                        <span>SMS Gateway</span>
                                    </a>
                                    <a href="{{ route('settings.online-platforms') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('settings.online-platforms') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.squares-plus class="size-4 text-zinc-400" />
                                        <span>Online Platforms</span>
                                    </a>
                                    <a href="{{ route('developer.login') }}" wire:navigate class="flex items-center gap-2.5 py-1.5 text-sm font-medium {{ request()->routeIs('developer.*') ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400' }}">
                                        <flux:icon.command-line class="size-4 text-zinc-400" />
                                        <span>Developer</span>
                                    </a>
                                </div>
                            </div>

                        </nav>
                    </div>

                    <!-- Theme Selector Selector Collapsed / Expanded -->
                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-850 flex" :class="sidebarCollapsed ? 'justify-center' : 'justify-between items-center px-3 py-1'">
                        <span x-show="!sidebarCollapsed" class="text-[10px] font-black text-zinc-400 uppercase tracking-wider">Appearance</span>
                        <button 
                            type="button" 
                            @click="toggleTheme()" 
                            class="flex h-8 w-8 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-600 shadow-sm transition hover:bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-900"
                            title="Toggle dark/light theme"
                        >
                            <!-- Sun Icon -->
                            <svg x-show="!isDark" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21M4.93 4.93l1.581 1.58m10.98 10.98l1.58 1.58M3 12h2.25m13.5 0H21M7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.36 5.64l-1.58 1.58m-10.98 10.98l-1.58-1.58" />
                            </svg>
                            <!-- Moon Icon -->
                            <svg x-show="isDark" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="display: none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                            </svg>
                        </button>
                    </div>

                    <!-- Log Out -->
                    <form method="POST" action="{{ route('logout') }}" class="pt-2">
                        @csrf
                        <button 
                            type="submit" 
                            class="flex w-full items-center rounded-xl text-zinc-500 hover:text-rose-600 hover:bg-rose-50 dark:text-zinc-400 dark:hover:bg-rose-950/20 dark:hover:text-rose-400 transition-all duration-300"
                            :class="sidebarCollapsed ? 'justify-center p-3' : 'px-3 py-2 text-sm font-semibold gap-3'"
                            title="{{ __('Log out') }}"
                        >
                            <flux:icon.arrow-right-start-on-rectangle class="size-4 shrink-0" />
                            <span x-show="!sidebarCollapsed" class="truncate">{{ __('Log out') }}</span>
                        </button>
                    </form>
                </div>

                <!-- User profile footer -->
                <div class="mt-auto border-t border-zinc-100 dark:border-zinc-850 pt-4 flex items-center" :class="sidebarCollapsed ? 'justify-center' : 'gap-3'">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-violet-100 font-bold text-violet-700 dark:bg-violet-950/50 dark:text-violet-300">
                        {{ str(auth()->user()->name)->substr(0, 1)->upper() }}
                    </div>
                    <div x-show="!sidebarCollapsed" class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-zinc-950 truncate dark:text-zinc-100 leading-tight">{{ auth()->user()->name }}</p>
                        <p class="text-[10px] text-zinc-400 truncate dark:text-zinc-500">Super Cashier</p>
                    </div>
                </div>
                </aside>
            @endunless

            <!-- Main App Content Container Frame -->
            <div @class([
                'flex min-h-screen min-w-0 flex-1 flex-col transition-colors duration-300',
                'bg-white dark:bg-zinc-950' => $isPosTerminal,
                'bg-slate-50 dark:bg-zinc-950' => ! $isPosTerminal,
            ])>
                
                <!-- Mobile Top Bar -->
                <header @class([
                    'sticky top-0 z-30 items-center gap-3 border-b border-zinc-200/60 bg-white/95 px-4 py-3 backdrop-blur-xl lg:hidden dark:border-zinc-800 dark:bg-zinc-900/95',
                    'hidden' => $isPosTerminal,
                    'flex' => ! $isPosTerminal,
                ])>
                    <button
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-xl border border-zinc-200 bg-white shadow-sm transition active:scale-95 duration-200 dark:border-zinc-800 dark:bg-zinc-900"
                        @click="mobileMenuOpen = true"
                    >
                        <flux:icon.bars-3 class="size-5 text-violet-600 dark:text-violet-400" />
                    </button>
                    
                    <div class="flex-1">
                        <p class="text-[9px] font-black uppercase tracking-wider text-violet-500/80 dark:text-violet-400">IMRAN POS</p>
                        <h1 class="font-display text-base font-bold text-zinc-900 leading-tight dark:text-zinc-50">
                            {{ $title ?? __('Dashboard') }}
                        </h1>
                    </div>

                    <!-- Theme Selector Mobile -->
                    <button 
                        type="button" 
                        @click="toggleTheme()" 
                        class="flex h-10 w-10 items-center justify-center rounded-xl border border-zinc-200 bg-white shadow-sm transition active:scale-95 dark:border-zinc-800 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400"
                    >
                        <!-- Sun Icon -->
                        <svg x-show="!isDark" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21M4.93 4.93l1.581 1.58m10.98 10.98l1.58 1.58M3 12h2.25m13.5 0H21M7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.36 5.64l-1.58 1.58m-10.98 10.98l-1.58-1.58" />
                        </svg>
                        <!-- Moon Icon -->
                        <svg x-show="isDark" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="display: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                        </svg>
                    </button>
                </header>

                <main @class([
                    'flex-1 transition-colors duration-300',
                    'bg-white px-3 pb-6 pt-3 sm:px-5 lg:px-6 lg:py-5 dark:bg-zinc-950' => $isPosTerminal,
                    'bg-slate-50 px-4 pb-28 pt-4 sm:px-6 lg:px-10 lg:pb-10 lg:pt-8 dark:bg-zinc-950' => ! $isPosTerminal,
                ])>
                    <div @class([
                        'mx-auto flex w-full flex-col',
                        'max-w-none gap-4' => $isPosTerminal,
                        'max-w-6xl gap-6' => ! $isPosTerminal,
                    ])>
                        {{ $slot }}
                    </div>
                </main>
            </div>

        @unless ($isPosTerminal)
            <!-- FULL-SCREEN MOBILE OVERLAY NAV DRAWER -->
            <div
            x-show="mobileMenuOpen"
            @keydown.escape.window="mobileMenuOpen = false"
            @click.self="mobileMenuOpen = false"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-x-full"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 -translate-x-full"
            class="fixed inset-0 z-50 flex items-stretch lg:hidden bg-zinc-950/40 backdrop-blur-md"
            style="display: none;"
        >
            <div class="mr-auto flex h-full w-80 max-w-[88vw] flex-col border-r border-zinc-100 bg-white/95 p-5 shadow-2xl backdrop-blur-2xl dark:border-zinc-800 dark:bg-zinc-900/95">
                <div class="flex min-h-0 flex-1 flex-col">
                    <!-- Header -->
                    <div class="flex shrink-0 items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-violet-600 dark:text-violet-400">Retail Core</p>
                            <h3 class="font-display text-lg font-bold text-zinc-950 dark:text-zinc-50">Navigation</h3>
                        </div>
                        <button
                            type="button"
                            @click="mobileMenuOpen = false"
                            class="flex h-8 w-8 items-center justify-center rounded-full border border-zinc-200 text-zinc-400 hover:text-zinc-600 dark:border-zinc-700 dark:text-zinc-300"
                            aria-label="{{ __('Close navigation') }}"
                        >
                            <flux:icon.x-mark class="size-4" />
                        </button>
                    </div>

                    <!-- Scrollable menu inside drawer -->
                    <div class="mt-5 flex-1 overflow-y-auto pb-4 pr-1 no-scrollbar">
                        <p class="mb-3 px-3 text-[10px] font-black uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                            {{ __('MAIN') }}
                        </p>

                        <nav class="space-y-2">
                            <a
                                href="{{ route('dashboard') }}"
                                wire:navigate
                                @click="mobileMenuOpen = false"
                                @class([
                                    'flex min-h-12 items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-all duration-200',
                                    'bg-violet-50 font-bold text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' => request()->routeIs('dashboard'),
                                    'font-semibold text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100' => ! request()->routeIs('dashboard'),
                                ])
                            >
                                <span @class([
                                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                                    'bg-violet-600 text-white shadow-md' => request()->routeIs('dashboard'),
                                    'text-zinc-500' => ! request()->routeIs('dashboard'),
                                ])>
                                    <flux:icon.home class="size-5" />
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ __('Dashboard') }}</span>
                            </a>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('pos')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'pos' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'pos' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.shopping-bag class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('POS') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'pos'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'pos'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'pos'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('pos.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.computer-desktop class="size-4 text-zinc-400" /><span>{{ __('POS Screen') }}</span></a>
                                    <a href="{{ route('sales.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.document-text class="size-4 text-zinc-400" /><span>{{ __('Sales List') }}</span></a>
                                    <a href="{{ route('pos.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.pause class="size-4 text-zinc-400" /><span>{{ __('Hold Orders') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('suppliers')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'suppliers' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'suppliers' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.truck class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Suppliers') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'suppliers'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'suppliers'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'suppliers'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('parties.suppliers') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.users class="size-4 text-zinc-400" /><span>{{ __('Suppliers List') }}</span></a>
                                    <a href="{{ route('purchases.create') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.plus class="size-4 text-zinc-400" /><span>{{ __('Add Restock Order') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('customers')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'customers' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'customers' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.users class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Customers') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'customers'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'customers'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'customers'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('parties.customers') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.user-group class="size-4 text-zinc-400" /><span>{{ __('Customers Directory') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('products')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'products' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'products' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.cube class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Products') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'products'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'products'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'products'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('products.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.list-bullet class="size-4 text-zinc-400" /><span>{{ __('Products List') }}</span></a>
                                    <a href="{{ route('products.create') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.plus class="size-4 text-zinc-400" /><span>{{ __('Add Product') }}</span></a>
                                    <a href="{{ route('products.categories') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.tag class="size-4 text-zinc-400" /><span>{{ __('Categories') }}</span></a>
                                    <a href="{{ route('products.brands') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.bookmark-square class="size-4 text-zinc-400" /><span>{{ __('Brands') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('purchases')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'purchases' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'purchases' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.shopping-bag class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Purchases') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'purchases'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'purchases'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'purchases'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('purchases.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.shopping-bag class="size-4 text-zinc-400" /><span>{{ __('Purchases') }}</span></a>
                                    <a href="{{ route('purchases.create') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.plus-circle class="size-4 text-zinc-400" /><span>{{ __('Add Purchase') }}</span></a>
                                    <a href="{{ route('purchases.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.arrow-path class="size-4 text-zinc-400" /><span>{{ __('Purchase Returns') }}</span></a>
                                    <a href="{{ route('reports.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.chart-bar class="size-4 text-zinc-400" /><span>{{ __('Purchase Report') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('expenses')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'expenses' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'expenses' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.credit-card class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Expenses') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'expenses'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'expenses'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'expenses'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('expenses.index') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.credit-card class="size-4 text-zinc-400" /><span>{{ __('Expenses') }}</span></a>
                                    <a href="{{ route('expenses.create') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.plus-circle class="size-4 text-zinc-400" /><span>{{ __('Add Expense') }}</span></a>
                                    <a href="{{ route('expenses.categories') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.tag class="size-4 text-zinc-400" /><span>{{ __('Expense Categories') }}</span></a>
                                    <a href="{{ route('reports.expenses') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.chart-bar class="size-4 text-zinc-400" /><span>{{ __('Expense Report') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('accounting')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'accounting' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'accounting' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.building-library class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Accounts') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'accounting'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'accounting'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'accounting'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('accounting.cash-book') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.book-open class="size-4 text-zinc-400" /><span>{{ __('Cash Book') }}</span></a>
                                    <a href="{{ route('accounting.daily-cash-closing') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.banknotes class="size-4 text-zinc-400" /><span>{{ __('Daily Cash Closing') }}</span></a>
                                    <a href="{{ route('accounting.daily-register-closing') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.document-text class="size-4 text-zinc-400" /><span>{{ __('Daily Register Closing') }}</span></a>
                                    <a href="{{ route('accounting.cash-in') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.arrow-down class="size-4 text-zinc-400" /><span>{{ __('Cash In') }}</span></a>
                                    <a href="{{ route('accounting.cash-out') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.arrow-up class="size-4 text-zinc-400" /><span>{{ __('Cash Out') }}</span></a>
                                    <a href="{{ route('accounting.cash-balance') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.credit-card class="size-4 text-zinc-400" /><span>{{ __('Cash Balance') }}</span></a>
                                    <a href="{{ route('accounting.bank-transfers') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.building-library class="size-4 text-zinc-400" /><span>{{ __('Bank Transfers') }}</span></a>
                                    <a href="{{ route('accounting.payment-method-report') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.credit-card class="size-4 text-zinc-400" /><span>{{ __('Payment Method Report') }}</span></a>
                                    <a href="{{ route('accounting.t-accounts') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.table-cells class="size-4 text-zinc-400" /><span>{{ __('T Accounts') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('reports')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'reports' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'reports' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.chart-bar class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Reports') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'reports'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'reports'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'reports'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('reports.sales') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.chart-bar class="size-4 text-zinc-400" /><span>{{ __('Sales Report') }}</span></a>
                                    <a href="{{ route('reports.purchases') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.chart-bar class="size-4 text-zinc-400" /><span>{{ __('Purchase Report') }}</span></a>
                                    <a href="{{ route('reports.profit-loss') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.arrow-trending-up class="size-4 text-zinc-400" /><span>{{ __('Profit & Loss') }}</span></a>
                                    <a href="{{ route('reports.stock') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.cube class="size-4 text-zinc-400" /><span>{{ __('Stock Report') }}</span></a>
                                    <a href="{{ route('reports.expenses') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.credit-card class="size-4 text-zinc-400" /><span>{{ __('Expense Report') }}</span></a>
                                    <a href="{{ route('reports.receives') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.arrow-down-tray class="size-4 text-zinc-400" /><span>{{ __('Receive Report') }}</span></a>
                                    <a href="{{ route('reports.debits') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.arrow-up-tray class="size-4 text-zinc-400" /><span>{{ __('Debit Report') }}</span></a>
                                    <a href="{{ route('reports.due-bills') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.clock class="size-4 text-zinc-400" /><span>{{ __('Due Bills Report') }}</span></a>
                                    <a href="{{ route('reports.customer-dues') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.user-group class="size-4 text-zinc-400" /><span>{{ __('Customer Due Report') }}</span></a>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <button type="button" @click="toggleMenu('settings')" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200" :class="expandedMenu === 'settings' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/30 dark:text-violet-400' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="expandedMenu === 'settings' ? 'bg-violet-600 text-white shadow-md' : 'text-zinc-500'"><flux:icon.cog-6-tooth class="size-5" /></span>
                                    <span class="flex-1 text-left">{{ __('Settings') }}</span>
                                    <flux:icon.chevron-down x-show="expandedMenu === 'settings'" class="size-4 text-zinc-400" />
                                    <flux:icon.chevron-right x-show="expandedMenu !== 'settings'" class="size-4 text-zinc-400" />
                                </button>
                                <div x-show="expandedMenu === 'settings'" x-transition class="ml-12 space-y-1 border-l border-zinc-100 pl-3 dark:border-zinc-800">
                                    <a href="{{ route('profile.edit') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.user class="size-4 text-zinc-400" /><span>{{ __('Profile Settings') }}</span></a>
                                    <a href="{{ route('security.edit') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.shield-check class="size-4 text-zinc-400" /><span>{{ __('Security Settings') }}</span></a>
                                    <a href="{{ route('settings.business') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.building-office class="size-4 text-zinc-400" /><span>{{ __('Business Info') }}</span></a>
                                    <a href="{{ route('settings.general') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.adjustments-horizontal class="size-4 text-zinc-400" /><span>{{ __('General Settings') }}</span></a>
                                    <a href="{{ route('settings.invoice') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.document-text class="size-4 text-zinc-400" /><span>{{ __('Invoice Settings') }}</span></a>
                                    <a href="{{ route('settings.pos-settings') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.computer-desktop class="size-4 text-zinc-400" /><span>{{ __('POS Settings') }}</span></a>
                                    <a href="{{ route('settings.sms') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.chat-bubble-left-right class="size-4 text-zinc-400" /><span>{{ __('SMS Gateway') }}</span></a>
                                    <a href="{{ route('settings.online-platforms') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.squares-plus class="size-4 text-zinc-400" /><span>{{ __('Online Platforms') }}</span></a>
                                    <a href="{{ route('developer.login') }}" wire:navigate @click="mobileMenuOpen = false" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 hover:text-violet-600 dark:text-zinc-400 dark:hover:text-violet-400"><flux:icon.command-line class="size-4 text-zinc-400" /><span>{{ __('Developer') }}</span></a>
                                </div>
                            </div>
                        </nav>
                    </div>
                </div>

                <div class="mt-4 shrink-0 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center justify-center gap-3 rounded-xl bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-600 hover:bg-rose-100 transition active:scale-95 duration-150 dark:bg-rose-950/20 dark:text-rose-400">
                            <flux:icon.arrow-right-start-on-rectangle class="size-5" />
                            <span>{{ __('Log out Cashier') }}</span>
                        </button>
                    </form>
                </div>
            </div>
            </div>

            <!-- MOBILE APP BOTTOM CURVED FLOATING TAB NAVIGATION -->
            <div class="fixed bottom-4 left-4 right-4 z-40 lg:hidden">
            <nav class="relative rounded-3xl bg-white/95 border border-zinc-200/80 shadow-[0_12px_30px_rgba(0,0,0,0.06)] px-3 py-1.5 dark:bg-zinc-900/95 dark:border-zinc-800">
                <div class="flex items-center justify-between">
                    
                    <!-- Home Tab -->
                    <a
                        href="{{ route('dashboard') }}"
                        wire:navigate
                        @class([
                            'flex flex-1 flex-col items-center gap-0.5 text-[9px] font-bold transition-all duration-300 relative py-1',
                            'text-violet-600 scale-105 dark:text-violet-400' => request()->routeIs('dashboard'),
                            'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500' => ! request()->routeIs('dashboard'),
                        ])
                    >
                        <flux:icon.home class="size-5" />
                        <span>{{ __('Home') }}</span>
                        @if(request()->routeIs('dashboard'))
                            <span class="absolute bottom-0 w-1.5 h-1.5 rounded-full bg-violet-600 dark:bg-violet-400 shadow-[0_0_8px_rgba(124,58,237,0.5)]"></span>
                        @endif
                    </a>

                    <!-- Products Tab -->
                    <a
                        href="{{ route('products.index') }}"
                        wire:navigate
                        @class([
                            'flex flex-1 flex-col items-center gap-0.5 text-[9px] font-bold transition-all duration-300 relative py-1',
                            'text-violet-600 scale-105 dark:text-violet-400' => request()->routeIs('products.*'),
                            'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500' => ! request()->routeIs('products.*'),
                        ])
                    >
                        <flux:icon.cube class="size-5" />
                        <span>{{ __('Products') }}</span>
                        @if(request()->routeIs('products.*'))
                            <span class="absolute bottom-0 w-1.5 h-1.5 rounded-full bg-violet-600 dark:bg-violet-400 shadow-[0_0_8px_rgba(124,58,237,0.5)]"></span>
                        @endif
                    </a>

                    <!-- CENTRAL ELEVATED VIBRANT GRADIENT ACTION BUTTON -->
                    <div class="flex-1 flex justify-center -mt-6">
                        <a
                            href="{{ route('pos.index') }}"
                            wire:navigate
                            class="relative flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-tr from-violet-500 to-fuchsia-600 text-white shadow-[0_8px_25px_rgba(124,58,237,0.45)] border-4 border-white transition-all active:scale-90 duration-300 hover:shadow-[0_12px_30px_rgba(124,58,237,0.6)] hover:scale-105 dark:border-zinc-900"
                            title="Open POS Terminal"
                        >
                            <flux:icon.shopping-bag class="size-6 text-white" />
                        </a>
                    </div>

                    <!-- Expenses Tab -->
                    <a
                        href="{{ route('expenses.index') }}"
                        wire:navigate
                        @class([
                            'flex flex-1 flex-col items-center gap-0.5 text-[9px] font-bold transition-all duration-300 relative py-1',
                            'text-violet-600 scale-105 dark:text-violet-400' => request()->routeIs('expenses.*'),
                            'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500' => ! request()->routeIs('expenses.*'),
                        ])
                    >
                        <flux:icon.banknotes class="size-5" />
                        <span>{{ __('Expenses') }}</span>
                        @if(request()->routeIs('expenses.*'))
                            <span class="absolute bottom-0 w-1.5 h-1.5 rounded-full bg-violet-600 dark:bg-violet-400 shadow-[0_0_8px_rgba(124,58,237,0.5)]"></span>
                        @endif
                    </a>

                    <!-- Reports Tab -->
                    <a
                        href="{{ route('reports.index') }}"
                        wire:navigate
                        @class([
                            'flex flex-1 flex-col items-center gap-0.5 text-[9px] font-bold transition-all duration-300 relative py-1',
                            'text-violet-600 scale-105 dark:text-violet-400' => request()->routeIs('reports.*'),
                            'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500' => ! request()->routeIs('reports.*'),
                        ])
                    >
                        <flux:icon.chart-bar class="size-5" />
                        <span>{{ __('Reports') }}</span>
                        @if(request()->routeIs('reports.*'))
                            <span class="absolute bottom-0 w-1.5 h-1.5 rounded-full bg-violet-600 dark:bg-violet-400 shadow-[0_0_8px_rgba(124,58,237,0.5)]"></span>
                        @endif
                    </a>

                    <!-- More Tab (Drawer toggle) -->
                    <button
                        type="button"
                        @click="mobileMenuOpen = true"
                        class="flex flex-1 flex-col items-center gap-0.5 text-[9px] font-bold text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 transition-all duration-300 py-1"
                    >
                        <flux:icon.ellipsis-horizontal class="size-5" />
                        <span>{{ __('More') }}</span>
                    </button>
                </div>
            </nav>
            </div>
        @endunless
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
