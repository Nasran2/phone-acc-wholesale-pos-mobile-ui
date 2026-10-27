<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerLedgerService;
use Livewire\Livewire;

function mobilePdfSale(int $items = 1): Sale
{
    $customer = Customer::query()->create(['name' => 'Mobile Customer', 'opening_balance' => 0, 'due_balance' => $items * 100]);
    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-MOBILE-1',
        'date' => today(),
        'subtotal_amount' => $items * 100,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => $items * 100,
        'paid_amount' => 0,
        'due_amount' => $items * 100,
        'payment_status' => 'due',
        'profit' => 0,
    ]);
    $product = Product::factory()->create(['name' => 'Mobile PDF Product']);
    foreach (range(1, $items) as $index) {
        $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'cost_price' => 50, 'selling_price' => 100, 'subtotal' => 100]);
    }

    return $sale;
}

test('invoice and ledger PDFs require authentication', function () {
    $sale = mobilePdfSale();
    $this->get(route('sales.pdf', $sale))->assertRedirect(route('login'));
    $this->get(route('customers.ledger.pdf', $sale->customer))->assertRedirect(route('login'));
});

test('saved invoices produce real paginated PDF attachments without changing sales', function (string $paper) {
    Setting::set('invoice_paper_size', $paper, 'invoice');
    Setting::clearCache();
    $sale = mobilePdfSale(90);
    $this->actingAs(User::factory()->create());
    $response = $this->get(route('sales.pdf', $sale));
    $response->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload('inv-mobile-1.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect(preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()))->toBeGreaterThan(1);
    expect(strlen($response->getContent()))->toBeLessThan(1024 * 1024);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(Sale::query()->count())->toBe(1)
        ->and($sale->fresh()->items()->count())->toBe(90)
        ->and((float) $sale->fresh()->grand_total)->toBe(9000.0);
})->with(['A4', 'thermal_80mm']);

test('long customer ledgers export all transactions across multiple PDF pages', function () {
    $sale = mobilePdfSale();
    foreach (range(1, 75) as $index) {
        $sale->payments()->create(['amount' => 1, 'payment_method' => 'cash', 'date' => today(), 'reference' => 'MOBILE-PAY-'.$index]);
    }
    $this->actingAs(User::factory()->create());
    $response = $this->get(route('customers.ledger.pdf', $sale->customer));
    $response->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload('mobile-customer-ledger.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect(preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()))->toBeGreaterThan(1);
    $rows = app(CustomerLedgerService::class)->rows($sale->customer);
    expect($rows->where('type', 'payment'))->toHaveCount(75);
});

test('checkout saves once and refresh preserves the next cart without automatically preparing a PDF', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $product = Product::factory()->create(['stock_quantity' => 10, 'selling_price' => 100]);
    $customer = Customer::query()->create(['name' => 'Checkout Customer', 'opening_balance' => 0, 'due_balance' => 0]);
    $component = Livewire::actingAs($user)->test('pages::pos.index')
        ->set('customer_id', $customer->id)
        ->call('addToCart', $product->id)
        ->set('paid_amount', 100)
        ->call('submitCheckout')
        ->assertHasNoErrors()
        ->assertSet('successOpen', true);
    $sale = Sale::query()->sole();
    $component->assertSee(route('sales.pdf', $sale), false)
        ->assertDontSee('html2canvas', false)
        ->assertDontSee('setTimeout(() => this.prepareBillPdf()', false)
        ->call('closeSuccess')
        ->call('addToCart', $product->id);
    $cart = $component->get('cart');
    $component->call('$refresh')->assertSet('cart', $cart);
    expect(Sale::query()->count())->toBe(1);
});

test('invoice PDF preserves returns payments discounts and cheque status', function () {
    $sale = mobilePdfSale();
    $sale->items()->first()->update(['quantity' => -1, 'subtotal' => -100]);
    $sale->update(['discount_amount' => 10]);
    $sale->payments()->create(['amount' => 100, 'payment_method' => 'cheque', 'cheque_no' => 'CHQ-MOBILE', 'cheque_status' => 'pending', 'date' => today()]);
    $html = view('pdf.invoice', ['sale' => $sale->load(['customer', 'items.product', 'payments', 'returns'])->loadDueCredits(), 'thermal' => false])->render();
    expect($html)->toContain('Return', '-100.00', 'CHQ-MOBILE', 'pending', 'Discount', 'Mobile PDF Product');
});
