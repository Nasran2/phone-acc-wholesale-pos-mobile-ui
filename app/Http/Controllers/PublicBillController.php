<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Contracts\View\View;

class PublicBillController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Sale $sale): View
    {
        return view('public.bill', [
            'sale' => $sale->load(['customer', 'items.product', 'payments']),
        ]);
    }
}
