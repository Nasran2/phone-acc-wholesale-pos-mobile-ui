<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Support\Collection;

class CustomerLedgerService
{
    public function rows(Customer $customer): Collection
    {
        $openingBalance = collect();
        if ((float) $customer->opening_balance > 0) {
            $openingBalance->push([
                'type' => 'opening',
                'badge' => __('Opening'),
                'id' => 'opening-'.$customer->id,
                'date' => $customer->created_at,
                'bill_date' => $customer->created_at,
                'paid_date' => null,
                'bill_no' => __('Opening Balance'),
                'ref' => __('Opening Balance'),
                'description' => __('Opening customer due balance'),
                'payment_method' => null,
                'payment_status' => __('due'),
                'debit' => (float) $customer->opening_balance,
                'credit' => 0.00,
                'raw_date' => $customer->created_at,
            ]);
        }

        $sales = Sale::query()
            ->with(['payments' => fn ($query) => $query->orderBy('date')->orderBy('id')])
            ->where('customer_id', $customer->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $salesMapped = $sales->map(fn ($sale) => [
            'type' => 'invoice',
            'badge' => __('Bill'),
            'id' => $sale->id,
            'date' => $sale->date,
            'bill_date' => $sale->date,
            'paid_date' => $sale->payments->max('date'),
            'bill_no' => $sale->invoice_no,
            'ref' => $sale->invoice_no,
            'description' => __('Bill created').' - '.strtoupper((string) $sale->payment_status),
            'payment_method' => null,
            'payment_status' => $sale->payment_status,
            'debit' => (float) $sale->grand_total,
            'credit' => 0.00,
            'raw_date' => $sale->created_at,
        ]);

        $salePayments = $sales->flatMap(fn ($sale) => $sale->payments->map(fn ($pm) => [
            'type' => 'payment',
            'badge' => __('Payment'),
            'id' => $pm->id,
            'date' => $pm->date,
            'bill_date' => $sale->date,
            'paid_date' => $pm->date,
            'bill_no' => $sale->invoice_no,
            'ref' => $pm->reference ?: 'PAY-'.$pm->id,
            'description' => __('Payment received for bill').' '.$sale->invoice_no,
            'payment_method' => $pm->payment_method,
            'payment_status' => $pm->cheque_status ?: __('paid'),
            'invoice_status' => $sale->payment_status,
            'invoice_due' => (float) $sale->due_amount,
            'debit' => 0.00,
            'credit' => (float) $pm->amount,
            'raw_date' => $pm->created_at,
        ]));

        $payments = Payment::query()
            ->where('paymentable_type', Customer::class)
            ->where('paymentable_id', $customer->id)
            ->get()
            ->map(fn ($pm) => [
                'type' => 'account_payment',
                'badge' => __('Account Payment'),
                'id' => $pm->id,
                'date' => $pm->date,
                'bill_date' => null,
                'paid_date' => $pm->date,
                'bill_no' => __('Account Ledger'),
                'ref' => $pm->reference ?: 'PAY-'.$pm->id,
                'description' => __('Account payment received'),
                'payment_method' => $pm->payment_method,
                'payment_status' => __('paid'),
                'debit' => 0.00,
                'credit' => (float) $pm->amount,
                'raw_date' => $pm->created_at,
            ]);

        $supplierPartyCheques = Payment::query()
            ->where('paymentable_type', Purchase::class)
            ->where('payment_method', 'cheque')
            ->where('cheque_type', 'party')
            ->where('party_customer_id', $customer->id)
            ->with(['paymentable.supplier', 'sourcePayment.paymentable'])
            ->get()
            ->map(function ($pm) {
                $purchase = $pm->paymentable;
                $sourceSale = $pm->sourcePayment?->paymentable;

                return [
                    'type' => 'payment',
                    'badge' => __('Party Cheque'),
                    'id' => 'supplier-party-'.$pm->id,
                    'date' => $pm->date,
                    'bill_date' => $purchase?->date,
                    'paid_date' => $pm->date,
                    'bill_no' => $sourceSale?->invoice_no ?? __('Customer Cheque'),
                    'ref' => $pm->cheque_no ?: $pm->reference ?: 'CHQ-'.$pm->id,
                    'description' => __('Customer cheque used for supplier purchase').' '.($purchase?->invoice_no ?? ''),
                    'payment_method' => __('party cheque'),
                    'payment_status' => $pm->cheque_status ?: __('pending'),
                    'invoice_status' => $purchase?->payment_status,
                    'invoice_due' => (float) ($purchase?->due_amount ?? 0),
                    'cheque_no' => $pm->cheque_no,
                    'cheque_bank' => $pm->cheque_bank,
                    'cheque_date' => $pm->cheque_date,
                    'supplier_name' => $purchase?->supplier?->name,
                    'purchase_invoice_no' => $purchase?->invoice_no,
                    'debit' => 0.00,
                    'credit' => (float) $pm->amount,
                    'raw_date' => $pm->created_at,
                ];
            });

        $returns = SaleReturn::query()
            ->with(['sale', 'items'])
            ->where('customer_id', $customer->id)
            ->get()
            ->map(fn ($ret) => [
                'type' => 'return',
                'badge' => __('Return'),
                'id' => $ret->id,
                'date' => $ret->date,
                'bill_date' => $ret->date,
                'paid_date' => null,
                'bill_no' => $ret->invoice_no,
                'ref' => $ret->invoice_no,
                'description' => $ret->type_label.' · '.__('Original invoice').': '.($ret->sale?->invoice_no ?? '-'),
                'return_value' => $ret->total_amount,
                'adjustment_label' => $ret->return_type === 'bill_credit' ? __('Bill credit') : __('Due reduced'),
                'refund_amount' => (float) $ret->refund_amount,
                'adjusted_amount' => (float) $ret->adjusted_amount,
                'payment_method' => null,
                'payment_status' => $ret->type_label,
                'debit' => 0.00,
                'credit' => $ret->return_type === 'adjust_due' ? (float) $ret->adjusted_amount : 0.0,
                'raw_date' => $ret->created_at,
            ]);

        return $openingBalance
            ->concat($salesMapped)
            ->concat($salePayments)
            ->concat($payments)
            ->concat($supplierPartyCheques)
            ->concat($returns)
            ->sortBy('raw_date')
            ->values();
    }
}
