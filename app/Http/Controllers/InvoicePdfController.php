<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InvoicePdfController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Sale $sale): Response
    {
        $sale->load(['customer', 'items.product', 'payments', 'returns.items.product'])->loadDueCredits();
        $thermal = Setting::get('invoice_paper_size', 'thermal_80mm') !== 'A4';

        return Pdf::loadView('pdf.invoice', compact('sale', 'thermal'))
            ->setOption('isRemoteEnabled', false)
            ->setPaper($thermal ? [0, 0, 226.77, 841.89] : 'a4')
            ->download((Str::slug($sale->invoice_no) ?: 'invoice').'.pdf')
            ->header('Cache-Control', 'private, no-store');
    }
}
