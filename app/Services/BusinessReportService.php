<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BusinessReportService
{
    /**
     * @return array{0: string, 1: string}
     */
    public function periodFor(string $dateRange, ?string $customStartDate = null, ?string $customEndDate = null): array
    {
        return match ($dateRange) {
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
            '7days' => [today()->subDays(7)->toDateString(), today()->toDateString()],
            '30days' => [today()->subDays(30)->toDateString(), today()->toDateString()],
            'custom' => [$customStartDate ?: today()->toDateString(), $customEndDate ?: today()->toDateString()],
            default => [today()->toDateString(), today()->toDateString()],
        };
    }

    /**
     * @return array{name: string, address: string, phone: string, email: string, currency: string}
     */
    public function businessDetails(): array
    {
        return [
            'name' => (string) Setting::get('business_name', 'Imran Phone Accessories'),
            'address' => (string) Setting::get('business_address', 'No. 45, Mobile Plaza, Colombo 11, Sri Lanka'),
            'phone' => (string) Setting::get('business_phone', '+94 77 123 4567'),
            'email' => (string) Setting::get('business_email', 'info@imranaccessories.com'),
            'currency' => (string) Setting::get('currency_symbol', 'Rs'),
        ];
    }

    /**
     * @return array{title: string, eyebrow: string, description: string, empty: string, date_sensitive: bool}
     */
    public function meta(string $reportType): array
    {
        return match ($reportType) {
            'purchases' => [
                'title' => 'Purchase Report',
                'eyebrow' => 'Purchases',
                'description' => 'Supplier restock invoices with paid, due, discount, tax, and status totals.',
                'empty' => 'No purchase records found for the selected filters.',
                'date_sensitive' => true,
            ],
            'profit-loss' => [
                'title' => 'Profit & Loss',
                'eyebrow' => 'Profitability',
                'description' => 'Revenue, cost of goods sold, gross profit, operating expenses, and net profit.',
                'empty' => 'No profit and loss records found for the selected filters.',
                'date_sensitive' => true,
            ],
            'stock' => [
                'title' => 'Stock Report',
                'eyebrow' => 'Inventory',
                'description' => 'Current inventory quantity, alert level, cost value, retail value, and margin.',
                'empty' => 'No stock records found for the selected filters.',
                'date_sensitive' => false,
            ],
            'expenses' => [
                'title' => 'Expense Report',
                'eyebrow' => 'Overheads',
                'description' => 'Operating expenses grouped by category, payment method, reference, and notes.',
                'empty' => 'No expenses found for the selected filters.',
                'date_sensitive' => true,
            ],
            'receives' => [
                'title' => 'Receive Report',
                'eyebrow' => 'Receipts',
                'description' => 'Customer and sales receipts received by cash, card, QR, bank transfer, or cleared cheque.',
                'empty' => 'No received payments found for the selected filters.',
                'date_sensitive' => true,
            ],
            'debits' => [
                'title' => 'Debit Report',
                'eyebrow' => 'Payments Out',
                'description' => 'Supplier, purchase, and expense payments that moved money out of the business.',
                'empty' => 'No debit payments found for the selected filters.',
                'date_sensitive' => true,
            ],
            'due-bills' => [
                'title' => 'Due Bills Report',
                'eyebrow' => 'Open Bills',
                'description' => 'Outstanding customer invoices and supplier bills with remaining balances.',
                'empty' => 'No due bills found for the selected filters.',
                'date_sensitive' => true,
            ],
            'customer-dues' => [
                'title' => 'Customer Due Report',
                'eyebrow' => 'Receivables',
                'description' => 'Customer account balances with opening dues, current dues, and recent sale activity.',
                'empty' => 'No customer due records found for the selected filters.',
                'date_sensitive' => false,
            ],
            default => [
                'title' => 'Sales Report',
                'eyebrow' => 'Sales',
                'description' => 'Customer invoices with revenue, paid, due, status, and profit figures.',
                'empty' => 'No sales records found for the selected filters.',
                'date_sensitive' => true,
            ],
        };
    }

    /**
     * @return array<int, array{route: string, label: string}>
     */
    public function pages(): array
    {
        return [
            ['route' => 'reports.sales', 'label' => 'Sales Report'],
            ['route' => 'reports.purchases', 'label' => 'Purchase Report'],
            ['route' => 'reports.profit-loss', 'label' => 'Profit & Loss'],
            ['route' => 'reports.stock', 'label' => 'Stock Report'],
            ['route' => 'reports.expenses', 'label' => 'Expense Report'],
            ['route' => 'reports.receives', 'label' => 'Receive Report'],
            ['route' => 'reports.debits', 'label' => 'Debit Report'],
            ['route' => 'reports.due-bills', 'label' => 'Due Bills Report'],
            ['route' => 'reports.customer-dues', 'label' => 'Customer Due Report'],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $reportType, string $startDate, string $endDate, string $status = 'all', string $paymentMethod = 'all', ?string $search = null): Collection
    {
        return match ($reportType) {
            'purchases' => $this->purchaseRows($startDate, $endDate, $status, $search),
            'profit-loss' => $this->profitLossRows($startDate, $endDate),
            'stock' => $this->stockRows($status, $search),
            'expenses' => $this->expenseRows($startDate, $endDate, $paymentMethod, $search),
            'receives' => $this->receiveRows($startDate, $endDate, $paymentMethod, $search),
            'debits' => $this->debitRows($startDate, $endDate, $paymentMethod, $search),
            'due-bills' => $this->dueBillRows($startDate, $endDate, $status, $search),
            'customer-dues' => $this->customerDueRows($status, $search),
            default => $this->salesRows($startDate, $endDate, $status, $search),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, value: string, tone: string}>
     */
    public function summary(string $reportType, Collection $rows): array
    {
        $currency = $this->businessDetails()['currency'];

        return match ($reportType) {
            'purchases' => [
                ['label' => 'Bills', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Grand Total', 'value' => $this->money((float) $rows->sum('grand_total'), $currency), 'tone' => 'violet'],
                ['label' => 'Paid', 'value' => $this->money((float) $rows->sum('paid_amount'), $currency), 'tone' => 'emerald'],
                ['label' => 'Due', 'value' => $this->money((float) $rows->sum('due_amount'), $currency), 'tone' => 'rose'],
            ],
            'profit-loss' => [
                ['label' => 'Revenue', 'value' => $this->money((float) ($rows->firstWhere('key', 'revenue')['amount'] ?? 0), $currency), 'tone' => 'emerald'],
                ['label' => 'COGS', 'value' => $this->money((float) ($rows->firstWhere('key', 'cogs')['amount'] ?? 0), $currency), 'tone' => 'rose'],
                ['label' => 'Gross Profit', 'value' => $this->money((float) ($rows->firstWhere('key', 'gross_profit')['amount'] ?? 0), $currency), 'tone' => 'emerald'],
                ['label' => 'Net Profit', 'value' => $this->money((float) ($rows->firstWhere('key', 'net_profit')['amount'] ?? 0), $currency), 'tone' => ((float) ($rows->firstWhere('key', 'net_profit')['amount'] ?? 0)) >= 0 ? 'emerald' : 'rose'],
            ],
            'stock' => [
                ['label' => 'Products', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Units', 'value' => number_format((float) $rows->sum('stock_quantity')), 'tone' => 'violet'],
                ['label' => 'Cost Value', 'value' => $this->money((float) $rows->sum('cost_value'), $currency), 'tone' => 'rose'],
                ['label' => 'Retail Value', 'value' => $this->money((float) $rows->sum('retail_value'), $currency), 'tone' => 'emerald'],
            ],
            'expenses' => [
                ['label' => 'Expenses', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Total Paid', 'value' => $this->money((float) $rows->sum('amount'), $currency), 'tone' => 'rose'],
                ['label' => 'Categories', 'value' => (string) $rows->pluck('category')->unique()->count(), 'tone' => 'violet'],
                ['label' => 'Methods', 'value' => (string) $rows->pluck('method')->unique()->count(), 'tone' => 'zinc'],
            ],
            'receives' => [
                ['label' => 'Receipts', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Received', 'value' => $this->money((float) $rows->sum('amount'), $currency), 'tone' => 'emerald'],
                ['label' => 'Methods', 'value' => (string) $rows->pluck('method')->unique()->count(), 'tone' => 'violet'],
                ['label' => 'Cleared Cheques', 'value' => (string) $rows->where('method', 'cheque')->count(), 'tone' => 'zinc'],
            ],
            'debits' => [
                ['label' => 'Debits', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Paid Out', 'value' => $this->money((float) $rows->sum('amount'), $currency), 'tone' => 'rose'],
                ['label' => 'Methods', 'value' => (string) $rows->pluck('method')->unique()->count(), 'tone' => 'violet'],
                ['label' => 'Expenses', 'value' => $this->money((float) $rows->where('type', 'Expense')->sum('amount'), $currency), 'tone' => 'rose'],
            ],
            'due-bills' => [
                ['label' => 'Due Bills', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Customer Due', 'value' => $this->money((float) $rows->where('party_type', 'Customer')->sum('due_amount'), $currency), 'tone' => 'rose'],
                ['label' => 'Supplier Due', 'value' => $this->money((float) $rows->where('party_type', 'Supplier')->sum('due_amount'), $currency), 'tone' => 'rose'],
                ['label' => 'Grand Total', 'value' => $this->money((float) $rows->sum('grand_total'), $currency), 'tone' => 'violet'],
            ],
            'customer-dues' => [
                ['label' => 'Customers', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Opening Due', 'value' => $this->money((float) $rows->sum('opening_balance'), $currency), 'tone' => 'violet'],
                ['label' => 'Current Due', 'value' => $this->money((float) $rows->sum('due_balance'), $currency), 'tone' => 'rose'],
                ['label' => 'With Due', 'value' => (string) $rows->where('due_balance', '>', 0)->count(), 'tone' => 'rose'],
            ],
            default => [
                ['label' => 'Invoices', 'value' => (string) $rows->count(), 'tone' => 'zinc'],
                ['label' => 'Revenue', 'value' => $this->money((float) $rows->sum('grand_total'), $currency), 'tone' => 'emerald'],
                ['label' => 'Paid', 'value' => $this->money((float) $rows->sum('paid_amount'), $currency), 'tone' => 'emerald'],
                ['label' => 'Due', 'value' => $this->money((float) $rows->sum('due_amount'), $currency), 'tone' => 'rose'],
            ],
        };
    }

    /**
     * @return array<int, array{key: string, label: string, align: string, money?: bool, tone?: string}>
     */
    public function columns(string $reportType): array
    {
        return match ($reportType) {
            'purchases' => [
                ['key' => 'date', 'label' => 'Date', 'align' => 'left'],
                ['key' => 'invoice_no', 'label' => 'Invoice', 'align' => 'left'],
                ['key' => 'party', 'label' => 'Supplier', 'align' => 'left'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'paid_amount', 'label' => 'Paid', 'align' => 'right', 'money' => true, 'tone' => 'emerald'],
                ['key' => 'due_amount', 'label' => 'Due', 'align' => 'right', 'money' => true, 'tone' => 'rose'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left'],
            ],
            'profit-loss' => [
                ['key' => 'label', 'label' => 'Line Item', 'align' => 'left'],
                ['key' => 'description', 'label' => 'Details', 'align' => 'left'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'money' => true],
            ],
            'stock' => [
                ['key' => 'name', 'label' => 'Product', 'align' => 'left'],
                ['key' => 'sku', 'label' => 'SKU', 'align' => 'left'],
                ['key' => 'category', 'label' => 'Category', 'align' => 'left'],
                ['key' => 'stock_quantity', 'label' => 'Stock', 'align' => 'right'],
                ['key' => 'minimum_stock', 'label' => 'Alert', 'align' => 'right'],
                ['key' => 'cost_value', 'label' => 'Cost Value', 'align' => 'right', 'money' => true],
                ['key' => 'retail_value', 'label' => 'Retail Value', 'align' => 'right', 'money' => true],
            ],
            'expenses' => [
                ['key' => 'date', 'label' => 'Date', 'align' => 'left'],
                ['key' => 'category', 'label' => 'Category', 'align' => 'left'],
                ['key' => 'reference', 'label' => 'Reference', 'align' => 'left'],
                ['key' => 'method', 'label' => 'Method', 'align' => 'left'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'money' => true, 'tone' => 'rose'],
            ],
            'receives', 'debits' => [
                ['key' => 'date', 'label' => 'Date', 'align' => 'left'],
                ['key' => 'description', 'label' => 'Transaction / Reference', 'align' => 'left'],
                ['key' => 'type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'method', 'label' => 'Method', 'align' => 'left'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'money' => true],
            ],
            'due-bills' => [
                ['key' => 'date', 'label' => 'Date', 'align' => 'left'],
                ['key' => 'invoice_no', 'label' => 'Bill', 'align' => 'left'],
                ['key' => 'party', 'label' => 'Party', 'align' => 'left'],
                ['key' => 'party_type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'due_amount', 'label' => 'Due', 'align' => 'right', 'money' => true, 'tone' => 'rose'],
            ],
            'customer-dues' => [
                ['key' => 'party', 'label' => 'Customer', 'align' => 'left'],
                ['key' => 'phone', 'label' => 'Phone', 'align' => 'left'],
                ['key' => 'opening_balance', 'label' => 'Opening Due', 'align' => 'right', 'money' => true],
                ['key' => 'due_balance', 'label' => 'Current Due', 'align' => 'right', 'money' => true, 'tone' => 'rose'],
                ['key' => 'sales_count', 'label' => 'Bills', 'align' => 'right'],
                ['key' => 'last_sale_date', 'label' => 'Last Sale', 'align' => 'left'],
            ],
            default => [
                ['key' => 'date', 'label' => 'Date', 'align' => 'left'],
                ['key' => 'invoice_no', 'label' => 'Invoice', 'align' => 'left'],
                ['key' => 'party', 'label' => 'Customer', 'align' => 'left'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'paid_amount', 'label' => 'Paid', 'align' => 'right', 'money' => true, 'tone' => 'emerald'],
                ['key' => 'due_amount', 'label' => 'Due', 'align' => 'right', 'money' => true, 'tone' => 'rose'],
                ['key' => 'profit', 'label' => 'Profit', 'align' => 'right', 'money' => true, 'tone' => 'emerald'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left'],
            ],
        };
    }

    private function salesRows(string $startDate, string $endDate, string $status, ?string $search): Collection
    {
        return Sale::query()
            ->with('customer')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->when($status !== 'all', fn ($query) => $query->where('payment_status', $status))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('invoice_no', 'like', "%{$term}%")
                        ->orWhere('payment_status', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
                });
            })
            ->latest('date')
            ->get()
            ->map(fn (Sale $sale): array => [
                'date' => $this->date($sale->date),
                'invoice_no' => $sale->invoice_no,
                'party' => $sale->customer?->name ?? 'Walk-in Customer',
                'grand_total' => (float) $sale->grand_total,
                'paid_amount' => (float) $sale->paid_amount,
                'due_amount' => (float) $sale->due_amount,
                'profit' => (float) $sale->profit,
                'status' => str((string) $sale->payment_status)->replace('_', ' ')->headline()->toString(),
            ]);
    }

    private function purchaseRows(string $startDate, string $endDate, string $status, ?string $search): Collection
    {
        return Purchase::query()
            ->with('supplier')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->when($status !== 'all', fn ($query) => $query->where('payment_status', $status))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('invoice_no', 'like', "%{$term}%")
                        ->orWhere('payment_status', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('company_name', 'like', "%{$term}%"));
                });
            })
            ->latest('date')
            ->get()
            ->map(fn (Purchase $purchase): array => [
                'date' => $this->date($purchase->date),
                'invoice_no' => $purchase->invoice_no,
                'party' => $purchase->supplier?->name ?? 'Supplier',
                'grand_total' => (float) $purchase->grand_total,
                'paid_amount' => (float) $purchase->paid_amount,
                'due_amount' => (float) $purchase->due_amount,
                'status' => str((string) $purchase->payment_status)->replace('_', ' ')->headline()->toString(),
            ]);
    }

    private function profitLossRows(string $startDate, string $endDate): Collection
    {
        $revenue = (float) Sale::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->sum('grand_total');
        $cogs = (float) SaleItem::query()
            ->whereHas('sale', fn ($query) => $query->whereDate('date', '>=', $startDate)->whereDate('date', '<=', $endDate))
            ->sum(DB::raw('quantity * cost_price'));
        $grossProfit = $revenue - $cogs;
        $expenses = (float) Expense::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->sum('amount');
        $netProfit = $grossProfit - $expenses;

        return collect([
            ['key' => 'revenue', 'label' => 'Total Gross Revenue', 'description' => 'Sum of sales invoice grand totals', 'amount' => $revenue],
            ['key' => 'cogs', 'label' => 'Cost of Goods Sold', 'description' => 'Quantity sold multiplied by product cost', 'amount' => $cogs],
            ['key' => 'gross_profit', 'label' => 'Gross Profit', 'description' => 'Revenue minus COGS', 'amount' => $grossProfit],
            ['key' => 'expenses', 'label' => 'Operating Expenses', 'description' => 'Expenses paid during the selected period', 'amount' => $expenses],
            ['key' => 'net_profit', 'label' => 'Net Profit / Loss', 'description' => 'Gross profit minus operating expenses', 'amount' => $netProfit],
        ]);
    }

    private function stockRows(string $status, ?string $search): Collection
    {
        return Product::query()
            ->with(['category', 'brand', 'unit'])
            ->when($status === 'low_stock', fn ($query) => $query->whereColumn('stock_quantity', '<=', 'minimum_stock'))
            ->when($status === 'in_stock', fn ($query) => $query->where('stock_quantity', '>', 0))
            ->when($status === 'out_of_stock', fn ($query) => $query->where('stock_quantity', '<=', 0))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhereHas('category', fn ($query) => $query->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('brand', fn ($query) => $query->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => [
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name ?? '-',
                'brand' => $product->brand?->name ?? '-',
                'stock_quantity' => (int) $product->stock_quantity,
                'minimum_stock' => (int) $product->minimum_stock,
                'cost_value' => (float) $product->stock_quantity * (float) $product->cost_price,
                'retail_value' => (float) $product->stock_quantity * (float) $product->selling_price,
                'status' => $product->stock_quantity <= 0 ? 'Out of Stock' : ($product->stock_quantity <= $product->minimum_stock ? 'Low Stock' : 'In Stock'),
            ]);
    }

    private function expenseRows(string $startDate, string $endDate, string $paymentMethod, ?string $search): Collection
    {
        return Expense::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->when($paymentMethod !== 'all', fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('category', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%")
                        ->orWhere('notes', 'like', "%{$term}%");
                });
            })
            ->latest('date')
            ->get()
            ->map(fn (Expense $expense): array => [
                'date' => $this->date($expense->date),
                'category' => $expense->category,
                'reference' => $expense->reference ?: '-',
                'method' => $this->method($expense->payment_method),
                'amount' => (float) $expense->amount,
                'notes' => $expense->notes,
            ]);
    }

    private function receiveRows(string $startDate, string $endDate, string $paymentMethod, ?string $search): Collection
    {
        return Payment::query()
            ->with('paymentable')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereHasMorph('paymentable', [Customer::class, Sale::class])
            ->where(function ($query): void {
                $query->where('payment_method', '!=', 'cheque')
                    ->orWhere('cheque_status', 'passed');
            })
            ->when($paymentMethod !== 'all', fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($search, fn ($query, string $term) => $this->paymentSearch($query, $term))
            ->latest('date')
            ->get()
            ->map(fn (Payment $payment): array => $this->paymentRow($payment, 'Receive'));
    }

    private function debitRows(string $startDate, string $endDate, string $paymentMethod, ?string $search): Collection
    {
        $payments = Payment::query()
            ->with('paymentable')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereHasMorph('paymentable', [Supplier::class, Purchase::class])
            ->where(function ($query): void {
                $query->where('payment_method', '!=', 'cheque')
                    ->orWhere('cheque_status', 'passed');
            })
            ->when($paymentMethod !== 'all', fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($search, fn ($query, string $term) => $this->paymentSearch($query, $term))
            ->latest('date')
            ->get()
            ->map(fn (Payment $payment): array => $this->paymentRow($payment, 'Debit'));

        $expenses = $this->expenseRows($startDate, $endDate, $paymentMethod, $search)
            ->map(fn (array $expense): array => [
                'date' => $expense['date'],
                'description' => $expense['notes'] ?: $expense['category'].($expense['reference'] !== '-' ? ' / '.$expense['reference'] : ''),
                'type' => 'Expense',
                'method' => $expense['method'],
                'amount' => $expense['amount'],
                'reference' => $expense['reference'],
            ]);

        return $payments->concat($expenses)->sortByDesc('date')->values();
    }

    private function dueBillRows(string $startDate, string $endDate, string $status, ?string $search): Collection
    {
        $sales = Sale::query()
            ->with('customer')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->where('due_amount', '>', 0)
            ->when($status === 'customer_due', fn ($query) => $query)
            ->when($status === 'supplier_due', fn ($query) => $query->whereRaw('1 = 0'))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('invoice_no', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$term}%"));
                });
            })
            ->get()
            ->map(fn (Sale $sale): array => [
                'date' => $this->date($sale->date),
                'invoice_no' => $sale->invoice_no,
                'party' => $sale->customer?->name ?? 'Walk-in Customer',
                'party_type' => 'Customer',
                'grand_total' => (float) $sale->grand_total,
                'due_amount' => (float) $sale->due_amount,
                'status' => 'Customer Due',
            ]);

        $purchases = Purchase::query()
            ->with('supplier')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->where('due_amount', '>', 0)
            ->when($status === 'supplier_due', fn ($query) => $query)
            ->when($status === 'customer_due', fn ($query) => $query->whereRaw('1 = 0'))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('invoice_no', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', "%{$term}%"));
                });
            })
            ->get()
            ->map(fn (Purchase $purchase): array => [
                'date' => $this->date($purchase->date),
                'invoice_no' => $purchase->invoice_no,
                'party' => $purchase->supplier?->name ?? 'Supplier',
                'party_type' => 'Supplier',
                'grand_total' => (float) $purchase->grand_total,
                'due_amount' => (float) $purchase->due_amount,
                'status' => 'Supplier Due',
            ]);

        return $sales->concat($purchases)->sortByDesc('date')->values();
    }

    private function customerDueRows(string $status, ?string $search): Collection
    {
        return Customer::query()
            ->withCount('sales')
            ->withMax('sales', 'date')
            ->when($status === 'with_due', fn ($query) => $query->where('due_balance', '>', 0))
            ->when($status === 'no_due', fn ($query) => $query->where('due_balance', '<=', 0))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('due_balance')
            ->get()
            ->map(fn (Customer $customer): array => [
                'party' => $customer->name,
                'phone' => $customer->phone,
                'opening_balance' => (float) $customer->opening_balance,
                'due_balance' => (float) $customer->due_balance,
                'sales_count' => (int) $customer->sales_count,
                'last_sale_date' => $customer->sales_max_date ? $this->date(Carbon::parse($customer->sales_max_date)) : '-',
                'status' => $customer->due_balance > 0 ? 'With Due' : 'Settled',
            ]);
    }

    private function paymentRow(Payment $payment, string $fallbackType): array
    {
        return [
            'date' => $this->date($payment->date),
            'description' => $payment->notes ?: ($payment->reference ?: 'Payment transaction'),
            'type' => class_basename((string) $payment->paymentable_type) ?: $fallbackType,
            'method' => $this->method($payment->payment_method),
            'amount' => (float) $payment->amount,
            'reference' => $payment->reference ?: '-',
        ];
    }

    private function paymentSearch($query, string $term): void
    {
        $query->where(function ($query) use ($term): void {
            $query->where('notes', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")
                ->orWhere('payment_method', 'like', "%{$term}%");
        });
    }

    private function method(string $method): string
    {
        return str($method)->replace('_', ' ')->headline()->toString();
    }

    private function date(Carbon|string|null $date): string
    {
        return $date instanceof Carbon ? $date->format('Y-m-d') : (string) $date;
    }

    private function money(float $amount, string $currency): string
    {
        return $currency.' '.number_format($amount, 2);
    }
}
