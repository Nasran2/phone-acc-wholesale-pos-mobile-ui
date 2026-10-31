<?php

use App\Models\ActivityLog;
use App\Models\Customer;
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

function supplierAssignmentCheque(string $status = 'pending', string $ownerType = 'sale'): array
{
    $customer = Customer::query()->create(['name' => 'Assignment Customer', 'due_balance' => 0]);
    $owner = $customer;
    if ($ownerType === 'sale') {
        $owner = Sale::query()->create([
            'customer_id' => $customer->id, 'invoice_no' => 'INV-'.Str::uuid(), 'date' => today(),
            'subtotal_amount' => 1000, 'grand_total' => 1000, 'paid_amount' => 0, 'due_amount' => 0,
            'payment_status' => 'cheque_pending', 'profit' => 0,
        ]);
    }
    $payment = $owner->payments()->create([
        'amount' => 1000, 'payment_method' => 'cheque', 'date' => today(),
        'cheque_no' => 'ASSIGN-'.Str::uuid(), 'reference' => 'Assignment cheque', 'cheque_bank' => 'BOC',
        'cheque_date' => today()->addDay(), 'cheque_status' => 'pending',
    ]);
    $supplier = Supplier::query()->create(['name' => 'First Assignment Supplier', 'due_balance' => 3000]);
    $otherSupplier = Supplier::query()->create(['name' => 'Second Assignment Supplier', 'due_balance' => 5000]);
    $service = app(ChequePaymentService::class);
    if ($status === 'passed') {
        $service->pass($payment);
    } elseif ($status === 'returned') {
        $service->markReturned($payment);
    }

    return [$customer, $owner, $payment->refresh(), $supplier, $otherSupplier];
}

test('assigning a cheque preserves the customer payment and reserves supplier due for pending and passed cheques', function (string $status, string $ownerType) {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque($status, $ownerType);
    $beforeCustomerDue = $customer->refresh()->due_balance;
    $beforeSource = $source->only(['paymentable_type', 'paymentable_id', 'cheque_status', 'cheque_processed_at']);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $deduction = $status === 'returned' ? 0.0 : 1000.0;

    expect($issued->paymentable_type)->toBe(Supplier::class)
        ->and($issued->paymentable_id)->toBe($supplier->id)
        ->and($issued->source_payment_id)->toBe($source->id)
        ->and($issued->party_customer_id)->toBe($customer->id)
        ->and($issued->cheque_type)->toBe('party')
        ->and($issued->cheque_status)->toBe($status)
        ->and((float) $issued->supplier_deduction_amount)->toBe($deduction)
        ->and((float) $supplier->refresh()->due_balance)->toBe(3000.0 - $deduction)
        ->and($source->refresh()->only(array_keys($beforeSource)))->toEqual($beforeSource)
        ->and($customer->refresh()->due_balance)->toBe($beforeCustomerDue)
        ->and($issued->resolveCustomerName())->toBe($customer->name);
    $again = $service->assignSupplier($source, $supplier->id);
    expect($again->id)->toBe($issued->id)
        ->and($source->issuedPayments()->count())->toBe(1)
        ->and((float) $supplier->refresh()->due_balance)->toBe(3000.0 - $deduction)
        ->and(ActivityLog::query()->where('action', 'cheque_supplier_changed')->count())->toBe(1);
})->with(['pending', 'passed', 'returned'])->with(['sale', 'customer']);

test('changing supplier restores the exact old deduction and charges the new supplier once', function (string $status) {
    [$customer, $owner, $source, $supplier, $otherSupplier] = supplierAssignmentCheque($status);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $service->assignSupplier($issued, $otherSupplier->id);
    $service->assignSupplier($issued, $otherSupplier->id);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0)
        ->and((float) $otherSupplier->refresh()->due_balance)->toBe($status === 'returned' ? 5000.0 : 4000.0)
        ->and($issued->refresh()->paymentable_id)->toBe($otherSupplier->id)
        ->and($issued->source_payment_id)->toBe($source->id)
        ->and($source->refresh()->cheque_status)->toBe($status)
        ->and(ActivityLog::query()->where('action', 'cheque_supplier_changed')->count())->toBe(2);
})->with(['pending', 'passed', 'returned']);

test('pending assigned cheques settle and restore balances through every status transition', function (string $ownerType) {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque('pending', $ownerType);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $service->markReturned($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(1000.0);
    $service->pass($issued);
    $service->pass($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and($source->refresh()->cheque_status)->toBe('passed')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
    $service->revertToPending($issued);
    $service->revertToPending($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and((float) $issued->refresh()->supplier_deduction_amount)->toBe(1000.0)
        ->and($source->refresh()->cheque_status)->toBe('pending');
    $service->pass($source);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and($issued->refresh()->cheque_status)->toBe('passed');
    $service->markReturned($source);
    $service->markReturned($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0)
        ->and($source->refresh()->cheque_status)->toBe('returned')
        ->and((float) $customer->refresh()->due_balance)->toBe(1000.0);
    if ($owner instanceof Sale) {
        expect((float) $owner->refresh()->paid_amount)->toBe(0.0)
            ->and((float) $owner->due_amount)->toBe(1000.0);
    }
    $service->revertToPending($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
})->with(['sale', 'customer']);

test('insufficient due rolls back assignment and reassignment without changing either supplier', function (bool $alreadyAssigned, string $status) {
    [$customer, $owner, $source, $supplier, $otherSupplier] = supplierAssignmentCheque($status);
    $service = app(ChequePaymentService::class);
    $payment = $alreadyAssigned ? $service->assignSupplier($source, $supplier->id) : $source;
    $otherSupplier->update(['due_balance' => 999.99]);
    expect(fn () => $service->assignSupplier($payment, $otherSupplier->id))->toThrow(ValidationException::class);
    expect((float) $supplier->refresh()->due_balance)->toBe($alreadyAssigned ? 2000.0 : 3000.0)
        ->and((float) $otherSupplier->refresh()->due_balance)->toBe(999.99)
        ->and($source->issuedPayments()->count())->toBe($alreadyAssigned ? 1 : 0);
    if ($alreadyAssigned) {
        expect($payment->refresh()->paymentable_id)->toBe($supplier->id);
    }
})->with([false, true])->with(['pending', 'passed']);

test('passing an old unreserved pending assignment with insufficient supplier due rolls back both cheque statuses', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $issued->update(['supplier_deduction_amount' => 0]);
    $supplier->update(['due_balance' => 100]);
    expect(fn () => $service->pass($issued))->toThrow(ValidationException::class);
    expect($source->refresh()->cheque_status)->toBe('pending')
        ->and($issued->refresh()->cheque_status)->toBe('pending')
        ->and((float) $issued->supplier_deduction_amount)->toBe(0.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(100.0)
        ->and((float) $owner->refresh()->paid_amount)->toBe(0.0);
});

test('auto passing a reserved customer cheque does not deduct supplier due again', function (bool $sufficientDue) {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $source->update(['cheque_date' => today()->subDays(8)]);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    if (! $sufficientDue) {
        $supplier->update(['due_balance' => 100]);
    }
    expect($service->autoPassOverduePendingCheques())->toBe(1)
        ->and($issued->refresh()->cheque_status)->toBe('passed')
        ->and((float) $supplier->refresh()->due_balance)->toBe($sufficientDue ? 2000.0 : 100.0);
})->with([true, false]);

test('existing supplier account cheques can change supplier without losing legacy deductions', function (string $status) {
    $supplier = Supplier::query()->create(['name' => 'Legacy supplier', 'due_balance' => 2000]);
    $otherSupplier = Supplier::query()->create(['name' => 'New supplier', 'due_balance' => 5000]);
    $payment = $supplier->payments()->create([
        'amount' => 1000, 'payment_method' => 'cheque', 'date' => today(), 'cheque_no' => 'LEGACY',
        'cheque_status' => $status, 'cheque_type' => 'own', 'cheque_date' => today(),
    ]);
    app(ChequePaymentService::class)->assignSupplier($payment, $otherSupplier->id);
    expect((float) $supplier->refresh()->due_balance)->toBe($status === 'returned' ? 2000.0 : 3000.0)
        ->and((float) $otherSupplier->refresh()->due_balance)->toBe($status === 'returned' ? 5000.0 : 4000.0)
        ->and((float) $payment->refresh()->supplier_deduction_amount)->toBe($status === 'returned' ? 0.0 : 1000.0);
})->with(['pending', 'passed', 'returned']);

test('invalid or deleted suppliers and non cheque and invoice payments cannot be assigned', function (string $invalidCase) {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque('passed');
    if ($invalidCase === 'deleted supplier') {
        $supplier->delete();
    } elseif ($invalidCase === 'non cheque') {
        $source->update(['payment_method' => 'cash']);
    } elseif ($invalidCase === 'invoice payment') {
        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id, 'invoice_no' => 'PUR-INVALID', 'date' => today(),
            'total_amount' => 1000, 'grand_total' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'paid',
        ]);
        $source->paymentable()->associate($purchase);
        $source->save();
    }
    expect(fn () => app(ChequePaymentService::class)->assignSupplier($source, $invalidCase === 'missing supplier' ? 999999 : $supplier->id))
        ->toThrow(ValidationException::class);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0)
        ->and($source->issuedPayments()->count())->toBe(0);
})->with(['missing supplier', 'deleted supplier', 'non cheque', 'invoice payment']);

test('the cheque report assigns and changes suppliers and updates filters and customer display', function () {
    [$customer, $owner, $source, $supplier, $otherSupplier] = supplierAssignmentCheque('passed');
    $component = Livewire::test('pages::reports.cheques')->set('supplierId', 'none')
        ->assertSee('Assign supplier')->call('assignChequeSupplier', $source->id)
        ->set('assignedSupplierId', (string) $supplier->id)->call('saveChequeSupplier')->assertHasNoErrors();
    $issued = $source->issuedPayments()->sole();
    expect($component->instance()->cheques->total())->toBe(0);
    $component->set('supplierId', (string) $supplier->id)->assertSee($source->cheque_no)
        ->assertSee($customer->name)->assertSee('Change supplier')
        ->call('assignChequeSupplier', $issued->id)->assertSet('assignedSupplierId', (string) $supplier->id)
        ->set('assignedSupplierId', (string) $otherSupplier->id)->call('saveChequeSupplier')->assertHasNoErrors();
    expect($component->instance()->cheques->total())->toBe(0);
    $component->set('supplierId', (string) $otherSupplier->id)->assertSee($source->cheque_no);
    expect($component->instance()->cheques->pluck('id')->all())->toBe([$issued->id]);
    $component->call('downloadPdf')->assertFileDownloaded('cheque-report.pdf');
    $pdfHtml = view('pdf.cheque-report', [
        'cheques' => $component->instance()->cheques, 'status' => 'all', 'startDate' => null, 'endDate' => null,
        'supplier' => $otherSupplier, 'isNonSupplier' => false,
    ])->render();
    expect($pdfHtml)->toContain($otherSupplier->name)->toContain($source->cheque_no)->toContain($customer->name);
});

test('the report shows assignment validation errors without changing balances', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque('passed');
    $supplier->update(['due_balance' => 10]);
    Livewire::test('pages::reports.cheques')->call('assignChequeSupplier', $source->id)
        ->call('saveChequeSupplier')->assertHasErrors(['assignedSupplierId' => 'required'])
        ->set('assignedSupplierId', (string) $supplier->id)->call('saveChequeSupplier')->assertHasErrors('assignedSupplierId');
    expect($source->issuedPayments()->count())->toBe(0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(10.0);
});

test('report viewers cannot mutate cheque suppliers details or statuses', function (string $action) {
    [$customer, $owner, $source] = supplierAssignmentCheque();
    $this->actingAs(User::factory()->create(['role' => 'cashier']));
    $component = Livewire::test('pages::reports.cheques')->assertDontSee('Assign supplier');
    if (in_array($action, ['saveChequeSupplier', 'updateCheque'], true)) {
        $component->call($action)->assertForbidden();
    } else {
        $component->call($action, $source->id)->assertForbidden();
    }
})->with(['assignChequeSupplier', 'saveChequeSupplier', 'editCheque', 'updateCheque', 'markAsPassed', 'markAsReturned', 'markAsPending']);

test('cheque detail edits preserve financial amounts and update linked cheque details together', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque('passed');
    $issued = app(ChequePaymentService::class)->assignSupplier($source, $supplier->id);
    Livewire::test('pages::reports.cheques')->call('editCheque', $issued->id)
        ->set('editAmount', '1500')->call('updateCheque')->assertHasErrors('editAmount')
        ->set('editAmount', '1000')->set('editChequeNo', 'CORRECTED-CHEQUE')
        ->set('editChequeBank', 'HNB')->call('updateCheque')->assertHasNoErrors();
    expect($source->refresh()->cheque_no)->toBe('CORRECTED-CHEQUE')
        ->and($issued->refresh()->cheque_bank)->toBe('HNB')
        ->and((float) $issued->amount)->toBe(1000.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and((float) $owner->refresh()->paid_amount)->toBe(1000.0);
});

test('editing a legacy partial party cheque preserves the original customer cheque amount', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $issued = $supplier->payments()->create([
        ...$source->only(['payment_method', 'date', 'cheque_no', 'cheque_bank', 'cheque_date', 'cheque_status']),
        'amount' => 600, 'cheque_type' => 'party', 'source_payment_id' => $source->id,
    ]);
    app(ChequePaymentService::class)->updateDetails($issued, [
        'amount' => '600', 'cheque_no' => 'PARTIAL-CORRECTION', 'reference' => 'PARTIAL-CORRECTION',
        'cheque_bank' => 'HNB', 'cheque_date' => today()->toDateString(),
    ]);
    expect((float) $source->refresh()->amount)->toBe(1000.0)
        ->and((float) $issued->refresh()->amount)->toBe(600.0)
        ->and($source->cheque_no)->toBe('PARTIAL-CORRECTION');
});

test('supplier ledgers count only the applied deduction and accounting records a single supplier outflow', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $supplierPage = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id);
    expect((float) collect($supplierPage->instance()->selectedSupplierLedger)->where('type', 'payment')->sum('debit'))->toBe(1000.0);
    $transactionsPage = Livewire::test('pages::parties.supplier-transactions')->call('selectSupplier', $supplier->id);
    expect((float) $transactionsPage->instance()->supplierTransactions->where('type', 'account_payment')->sum('debit'))->toBe(1000.0);
    expect(app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString()))->toHaveCount(0);
    $service->pass($issued);
    $ledger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect((float) $ledger->sum('debit'))->toBe(1000.0)
        ->and((float) $ledger->sum('credit'))->toBe(1000.0)
        ->and($ledger->where('account', 'Accounts Payable'))->toHaveCount(1);
    $supplierPage->call('$refresh');
    $transactionsPage->call('$refresh');
    expect((float) collect($supplierPage->instance()->selectedSupplierLedger)->where('type', 'payment')->sum('debit'))->toBe(1000.0)
        ->and((float) $transactionsPage->instance()->supplierTransactions->where('type', 'account_payment')->sum('debit'))->toBe(1000.0);
    $service->markReturned($issued);
    $ledger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect($ledger)->toHaveCount(0);
});

test('pending supplier assignment moves payable due into hold immediately without charging it again when passed', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $supplier->update(['due_balance' => 4162876]);
    $source->update(['amount' => 40000]);
    $service = app(ChequePaymentService::class);

    $issued = $service->assignSupplier($source, $supplier->id);
    expect((float) $supplier->refresh()->due_balance)->toBe(4122876.0)
        ->and((float) $issued->supplier_deduction_amount)->toBe(40000.0);
    $service->assignSupplier($source, $supplier->id);
    expect((float) $supplier->refresh()->due_balance)->toBe(4122876.0)
        ->and($source->issuedPayments()->count())->toBe(1);
    $service->pass($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(4122876.0);
});

test('saving an existing pending assignment repairs its missing hold deduction only once', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $issued = $supplier->payments()->create([
        ...$source->only(['amount', 'payment_method', 'date', 'cheque_no', 'cheque_date', 'cheque_status']),
        'cheque_type' => 'party', 'source_payment_id' => $source->id,
        'party_customer_id' => $customer->id, 'supplier_deduction_amount' => 0,
    ]);
    $service = app(ChequePaymentService::class);
    $service->assignSupplier($source, $supplier->id);
    $service->assignSupplier($issued, $supplier->id);
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and((float) $issued->refresh()->supplier_deduction_amount)->toBe(1000.0)
        ->and($source->issuedPayments()->count())->toBe(1)
        ->and($source->refresh()->cheque_status)->toBe('pending');
});

test('supplier cards and ledger show assigned and purchase cheque holds once and exclude passed returned and customer source payments', function () {
    [$customer, $owner, $source, $supplier, $otherSupplier] = supplierAssignmentCheque();
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $purchase = $supplier->purchases()->create([
        'invoice_no' => 'PUR-HOLD-TOTAL', 'date' => today(), 'total_amount' => 500,
        'grand_total' => 500, 'paid_amount' => 0, 'due_amount' => 0, 'payment_status' => 'cheque_pending',
    ]);
    $purchase->payments()->create(['amount' => 500, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    foreach (['passed', 'returned'] as $status) {
        $supplier->payments()->create(['amount' => 250, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today()]);
    }
    $supplier->payments()->create(['amount' => 100, 'payment_method' => 'cash', 'date' => today()]);
    $otherSupplier->payments()->create(['amount' => 200, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);

    expect((float) Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->pending_cheque_hold_amount)->toBe(1500.0);
    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->assertSee('Cheque Hold')->assertSee('1,500.00')->assertSee('2,000.00');
    expect((float) $page->instance()->selectedSupplier->pending_cheque_hold_amount)->toBe(1500.0);
    $service->pass($issued);
    $page->call('$refresh');
    expect((float) $page->instance()->selectedSupplier->pending_cheque_hold_amount)->toBe(500.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(2000.0);
    $service->markReturned($issued);
    expect((float) $supplier->refresh()->due_balance)->toBe(3000.0);
});

test('a repaired pending assignment cannot overdraw the supplier and rolls back on failure', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $issued = $supplier->payments()->create([
        ...$source->only(['amount', 'payment_method', 'date', 'cheque_no', 'cheque_date', 'cheque_status']),
        'cheque_type' => 'party', 'source_payment_id' => $source->id, 'supplier_deduction_amount' => 0,
    ]);
    $supplier->update(['due_balance' => 500]);
    Livewire::test('pages::reports.cheques')->call('assignChequeSupplier', $issued->id)
        ->call('saveChequeSupplier')->assertHasErrors('assignedSupplierId');
    expect((float) $supplier->refresh()->due_balance)->toBe(500.0)
        ->and((float) $issued->refresh()->supplier_deduction_amount)->toBe(0.0)
        ->and($source->refresh()->cheque_status)->toBe('pending');
});

test('the cheque report places pending assignment on hold and keeps it visible under its supplier', function () {
    [$customer, $owner, $source, $supplier] = supplierAssignmentCheque();
    $component = Livewire::test('pages::reports.cheques')->set('supplierId', 'none')
        ->call('assignChequeSupplier', $source->id)
        ->assertSee('moves its amount from supplier due to Cheque Hold')
        ->set('assignedSupplierId', (string) $supplier->id)->call('saveChequeSupplier')->assertHasNoErrors();
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0);
    $issued = $source->issuedPayments()->sole();
    $component->set('supplierId', (string) $supplier->id)->assertSee($source->cheque_no)
        ->call('assignChequeSupplier', $issued->id)->call('saveChequeSupplier')->assertHasNoErrors();
    expect((float) $supplier->refresh()->due_balance)->toBe(2000.0)
        ->and((float) Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->pending_cheque_hold_amount)->toBe(1000.0);
});
