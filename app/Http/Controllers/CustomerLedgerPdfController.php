<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CustomerLedgerPdfController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Customer $customer, CustomerLedgerService $ledger): Response
    {
        return Pdf::loadView('pdf.customer-ledger', [
            'customer' => $customer,
            'rows' => $ledger->rows($customer),
        ])->setOption('isRemoteEnabled', false)
            ->setPaper('a4')
            ->download((Str::slug($customer->name) ?: 'customer').'-ledger.pdf')
            ->header('Cache-Control', 'private, no-store');
    }
}
