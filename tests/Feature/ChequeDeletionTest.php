<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingLedgerService;
use App\Services\ChequePaymentService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

function chequeDeletionFixture(string $ownerType, string $status = 'pending'): array
{
    $isInvoice = in_array($ownerType, ['sale', 'purchase'], true);
    $partyClass = in_array($ownerType, ['sale', 'customer'], true) ? Customer::class : Supplier::class;
    $party = $partyClass::query()->create(['name' => 'Cheque Deletion Party', 'opening_balance' => $isInvoice ? 0 : 3000, 'due_balance' => $isInvoice ? 0 : 2000]);
    $owner = $party;
    if ($isInvoice) {
        $invoiceClass = $ownerType === 'sale' ? Sale::class : Purchase::class;
        $owner = $invoiceClass::query()->create([
            $ownerType === 'sale' ? 'customer_id' : 'supplier_id' => $party->id,
            'invoice_no' => 'DELETE-'.Str::uuid(), 'date' => today(),
            $ownerType === 'sale' ? 'subtotal_amount' : 'total_amount' => 1500,
            'grand_total' => 1500, 'paid_amount' => 500, 'due_amount' => 0, 'payment_status' => 'cheque_pending',
        ]);
        $owner->payments()->create(['amount' => 500, 'payment_method' => 'cash', 'date' => today()]);
    }
    $payment = $owner->payments()->create([
        'amount' => 1000, 'payment_method' => 'cheque', 'date' => today(), 'cheque_no' => 'DELETE-CHQ',
        'cheque_status' => 'pending', 'cheque_date' => today()->addDay(), 'cheque_type' => $party instanceof Supplier ? 'own' : null,
    ]);
    $service = app(ChequePaymentService::class);
    if ($status === 'passed') {
        $service->pass($payment);
    } elseif ($status === 'returned') {
        $service->markReturned($payment);
    }

    return [$party->refresh(), $owner->refresh(), $payment->refresh()];
}

test('deleting a standalone cheque reverses its payment once and preserves other payments', function (string $ownerType, string $status) {
    [$party, $owner, $payment] = chequeDeletionFixture($ownerType, $status);
    $service = app(ChequePaymentService::class);
    $service->delete($payment);
    $service->delete($payment);

    $this->assertModelMissing($payment);
    expect((float) $party->refresh()->due_balance)->toBe($owner instanceof Sale || $owner instanceof Purchase ? 1000.0 : 3000.0);
    if ($owner instanceof Sale || $owner instanceof Purchase) {
        expect((float) $owner->refresh()->paid_amount)->toBe(500.0)
            ->and((float) $owner->due_amount)->toBe(1000.0)
            ->and($owner->payment_status)->toBe('partial')
            ->and($owner->payments()->where('payment_method', 'cash')->count())->toBe(1);
    }
    expect(ActivityLog::query()->where('action', 'cheque_deleted')->count())->toBe(1);
    $audit = ActivityLog::query()->where('action', 'cheque_deleted')->sole();
    expect($audit->description)->toContain('DELETE-CHQ', (string) $payment->id, $status);
    expect(Payment::query()->pendingCheque()->count())->toBe(0);
})->with(['sale', 'purchase', 'customer', 'supplier'])->with(['pending', 'passed', 'returned']);

test('deleting an assigned supplier cheque restores its actual deduction and preserves the source customer receipt', function (string $status, string $ownerType) {
    [$customer, $owner, $source] = chequeDeletionFixture($ownerType);
    $supplier = Supplier::query()->create(['name' => 'Assigned Delete Supplier', 'due_balance' => 3000]);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    if ($status === 'passed') {
        $service->pass($issued);
    } elseif ($status === 'returned') {
        $service->markReturned($issued);
    }
    $sourceBefore = $source->refresh()->getAttributes();
    $customerBefore = $customer->refresh()->due_balance;
    $ownerBefore = $owner->refresh()->getAttributes();
    $service->delete($issued);

    $this->assertModelMissing($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0)
        ->and($source->refresh()->getAttributes())->toEqual($sourceBefore)
        ->and($customer->refresh()->due_balance)->toBe($customerBefore)
        ->and($owner->refresh()->getAttributes())->toEqual($ownerBefore)
        ->and($source->issuedPayments()->count())->toBe(0)
        ->and(Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->pending_cheque_hold_amount)->toBe(0.0);
    $ledger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect($ledger->where('account', 'Accounts Payable'))->toHaveCount(0);
})->with(['pending', 'passed', 'returned'])->with(['sale', 'customer']);

test('deleting a forwarded purchase cheque reverses only the purchase payment', function (string $status) {
    [$customer, $sale, $source] = chequeDeletionFixture('sale');
    [$supplier, $purchase, $issued] = chequeDeletionFixture('purchase');
    $issued->update(['source_payment_id' => $source->id, 'cheque_type' => 'party', 'party_customer_id' => $customer->id]);
    $service = app(ChequePaymentService::class);
    if ($status === 'passed') {
        $service->pass($issued);
    } elseif ($status === 'returned') {
        $service->markReturned($issued);
    }
    $sourceBefore = $source->refresh()->getAttributes();
    $saleBefore = $sale->refresh()->getAttributes();
    $customerDue = $customer->refresh()->due_balance;
    $service->delete($issued);

    $this->assertModelMissing($issued);
    expect((float) $purchase->refresh()->paid_amount)->toBe(500.0)
        ->and((float) $purchase->due_amount)->toBe(1000.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(1000.0)
        ->and($source->refresh()->getAttributes())->toEqual($sourceBefore)
        ->and($sale->refresh()->getAttributes())->toEqual($saleBefore)
        ->and($customer->refresh()->due_balance)->toBe($customerDue);
})->with(['pending', 'passed', 'returned']);

test('deleting a customer source cheque with a linked supplier payment is blocked without redirecting to the supplier row', function (string $destination) {
    [$customer, $sale, $source] = chequeDeletionFixture('sale');
    $service = app(ChequePaymentService::class);
    if ($destination === 'supplier') {
        $supplier = Supplier::query()->create(['name' => 'Linked Delete Supplier', 'due_balance' => 3000]);
        $issued = $service->assignSupplier($source, $supplier->id);
    } else {
        [$supplier, $purchase, $issued] = chequeDeletionFixture('purchase');
        $issued->update(['source_payment_id' => $source->id, 'cheque_type' => 'party']);
    }
    $supplierDue = $supplier->refresh()->due_balance;
    expect(fn () => $service->delete($source))->toThrow(ValidationException::class);
    $this->assertModelExists($source);
    $this->assertModelExists($issued);
    expect($supplier->refresh()->due_balance)->toBe($supplierDue);
})->with(['supplier', 'purchase']);

test('deleting one duplicate cheque number reverses only the selected payment', function () {
    [$supplier, $owner, $first] = chequeDeletionFixture('supplier', 'passed');
    $duplicate = $supplier->payments()->create([
        ...$first->only(['amount', 'payment_method', 'date', 'cheque_no', 'cheque_date', 'cheque_status', 'cheque_type']),
    ]);
    $supplier->decrement('due_balance', 1000);
    app(ChequePaymentService::class)->delete($first);
    $this->assertModelMissing($first);
    $this->assertModelExists($duplicate);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0);
});

test('deleting tracked supplier assignments restores zero and partial historical deductions exactly', function (float $deduction) {
    [$supplier, $owner, $payment] = chequeDeletionFixture('supplier');
    $supplier->update(['due_balance' => 3000 - $deduction]);
    $payment->update(['supplier_deduction_amount' => $deduction]);
    app(ChequePaymentService::class)->delete($payment);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0);
})->with([0.0, 600.0]);

test('deleting a passed cheque with capped invoice acceptance preserves the cash payment', function (string $ownerType) {
    [$party, $invoice, $payment] = chequeDeletionFixture($ownerType);
    $invoice->update(['grand_total' => 800]);
    $service = app(ChequePaymentService::class);
    $service->pass($payment);
    expect((float) $invoice->refresh()->paid_amount)->toBe(800.0);
    $service->delete($payment);
    expect((float) $invoice->refresh()->paid_amount)->toBe(500.0)
        ->and((float) $invoice->due_amount)->toBe(300.0)
        ->and((float) $party->refresh()->due_balance)->toBe(300.0);
})->with(['sale', 'purchase']);

test('deleting one pending invoice cheque preserves other cheque holds', function (string $ownerType) {
    [$party, $invoice, $payment] = chequeDeletionFixture($ownerType);
    $invoice->update(['grand_total' => 2000]);
    $other = $invoice->payments()->create(['amount' => 500, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    app(ChequePaymentService::class)->delete($payment);
    expect((float) $invoice->refresh()->paid_amount)->toBe(500.0)
        ->and((float) $invoice->due_amount)->toBe(1000.0)
        ->and($invoice->payment_status)->toBe('cheque_pending');
    $this->assertModelExists($other);
})->with(['sale', 'purchase']);

test('non cheque payments cannot be deleted through the cheque service', function () {
    [$customer, $sale, $payment] = chequeDeletionFixture('sale');
    $cash = $sale->payments()->where('payment_method', 'cash')->sole();
    expect(fn () => app(ChequePaymentService::class)->delete($cash))->toThrow(ValidationException::class);
    $this->assertModelExists($cash);
    expect((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('the report confirms cheque deletion reverses balances and refreshes the list', function (string $status) {
    [$supplier, $owner, $payment] = chequeDeletionFixture('supplier', $status);
    $page = Livewire::test('pages::reports.cheques')->set('search', 'DELETE-CHQ')
        ->assertSee('Delete Cheque')->assertSee('wire:confirm=', false)
        ->call('deleteCheque', $payment->id)->assertHasNoErrors();
    expect($page->instance()->cheques->total())->toBe(0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(3000.0);
    $page->call('deleteCheque', $payment->id)->assertHasNoErrors();
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0);
})->with(['pending', 'passed']);

test('report viewers cannot delete cheque payments', function () {
    [$supplier, $owner, $payment] = chequeDeletionFixture('supplier');
    $this->actingAs(User::factory()->create(['role' => 'cashier']));
    Livewire::test('pages::reports.cheques')->assertDontSee('Delete Cheque')
        ->call('deleteCheque', $payment->id)->assertForbidden();
    $this->assertModelExists($payment);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0);
});

test('deleting invoice cheques updates archived customer and supplier balances for later restoration', function (string $ownerType, string $status) {
    [$party, $invoice, $payment] = chequeDeletionFixture($ownerType, $status);
    $party->delete();
    app(ChequePaymentService::class)->delete($payment);
    expect((float) $party->refresh()->due_balance)->toBe(1000.0)
        ->and((float) $invoice->refresh()->due_amount)->toBe(1000.0);
    $party->restore();
    expect((float) $party->refresh()->due_balance)->toBe(1000.0);
})->with(['sale', 'purchase'])->with(['pending', 'passed', 'returned']);

test('deleting a capped purchase cheque preserves supplier return credit without payment rows', function () {
    [$supplier, $purchase, $payment] = chequeDeletionFixture('purchase');
    $purchase->payments()->where('payment_method', 'cash')->delete();
    $purchase->update(['grand_total' => 800, 'return_credit_amount' => 500]);
    $service = app(ChequePaymentService::class);
    $service->pass($payment);
    expect((float) $purchase->refresh()->paid_amount)->toBe(800.0);
    $service->delete($payment);
    expect((float) $purchase->refresh()->paid_amount)->toBe(500.0)
        ->and((float) $purchase->return_credit_amount)->toBe(500.0)
        ->and((float) $purchase->due_amount)->toBe(300.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(300.0);
});
