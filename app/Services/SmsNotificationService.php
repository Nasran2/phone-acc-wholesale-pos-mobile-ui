<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\SmsLog;
use Illuminate\Support\Collection;

class SmsNotificationService
{
    public const DEFAULT_TEMPLATES = [
        'sms_template_sale' => 'Hi {cus_name}, thank you for shopping at {business_name}. Bill {invoice_no}: Rs {total}, paid Rs {paid}, due Rs {due}. View: {bill_link}',
        'sms_template_payment' => 'Hi {cus_name}, payment received for bill {invoice_no}. Paid now: Rs {payment_amount}. Remaining due: Rs {due}. View: {bill_link} - {business_name}',
        'sms_template_cheque_passed' => 'Hi {cus_name}, your cheque payment for bill {invoice_no} has passed. Amount: Rs {payment_amount}. Remaining due: Rs {due}. View: {bill_link}',
        'sms_template_cheque_reminder' => 'Hi {cus_name}, reminder: cheque {cheque_no} for bill {invoice_no} is due on {cheque_date}. Amount: Rs {payment_amount}. Contact {business_phone} if needed.',
    ];

    public const PLACEHOLDERS = [
        '{cus_name}' => 'Customer name from the customer profile.',
        '{cus_phone}' => 'Customer phone number from the customer profile.',
        '{cus_address}' => 'Customer address from the customer profile.',
        '{customer_name}' => 'Same as {cus_name}, kept for older templates.',
        '{customer_phone}' => 'Same as {cus_phone}, kept for older templates.',
        '{customer_address}' => 'Same as {cus_address}, kept for older templates.',
        '{invoice_no}' => 'Sale invoice number.',
        '{total}' => 'Invoice grand total.',
        '{paid}' => 'Total paid amount on the invoice.',
        '{due}' => 'Remaining invoice due amount.',
        '{payment_amount}' => 'Amount paid in the latest payment or cheque.',
        '{payment_method}' => 'Payment method such as cash, card, bank transfer, or cheque.',
        '{cheque_bank}' => 'Cheque bank name from the payment.',
        '{cheque_no}' => 'Cheque number/reference from the payment.',
        '{cheque_date}' => 'Cheque due date.',
        '{bill_link}' => 'Public bill link that opens without login.',
        '{business_name}' => 'Business name from Business Info settings.',
        '{business_phone}' => 'Business phone from Business Info settings.',
        '{business_address}' => 'Business address from Business Info settings.',
        '{date}' => 'Sale or notification date.',
    ];

    public function __construct(private TextItSmsService $smsService) {}

    public function notifySaleCreated(Sale $sale): void
    {
        $this->sendForSaleEvent(
            settingKey: 'sms_notify_sale_enabled',
            templateKey: 'sms_template_sale',
            sale: $sale,
            ref: 'SALE-'.$sale->id,
        );
    }

    public function notifyPaymentReceived(Sale $sale, Payment $payment): void
    {
        $this->sendForSaleEvent(
            settingKey: 'sms_notify_payment_enabled',
            templateKey: 'sms_template_payment',
            sale: $sale,
            ref: 'PAY-'.$payment->id,
            extra: $this->paymentTemplateData($payment),
        );
    }

    public function notifyChequePassed(Payment $payment): void
    {
        $sale = $payment->paymentable;

        if (! $sale instanceof Sale) {
            return;
        }

        $this->sendForSaleEvent(
            settingKey: 'sms_notify_cheque_passed_enabled',
            templateKey: 'sms_template_cheque_passed',
            sale: $sale,
            ref: 'CHQ-PASS-'.$payment->id,
            extra: $this->paymentTemplateData($payment),
        );
    }

    public function notifyChequeReminder(Payment $payment): bool
    {
        $sale = $payment->paymentable;

        if (! $sale instanceof Sale || blank($payment->cheque_date)) {
            return false;
        }

        $ref = 'CHQ-REM-'.$payment->id.'-'.$payment->cheque_date->format('Ymd');

        if (SmsLog::query()->where('ref_no', $ref)->exists()) {
            return false;
        }

        return $this->sendForSaleEvent(
            settingKey: 'sms_notify_cheque_reminder_enabled',
            templateKey: 'sms_template_cheque_reminder',
            sale: $sale,
            ref: $ref,
            extra: $this->paymentTemplateData($payment),
        );
    }

    public function sendPendingChequeReminders(): int
    {
        $sent = 0;

        Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->whereDate('cheque_date', today()->addDays(2)->toDateString())
            ->with('paymentable.customer')
            ->orderBy('cheque_date')
            ->orderBy('id')
            ->each(function (Payment $payment) use (&$sent): void {
                if ($this->notifyChequeReminder($payment)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * @return Collection<int, array{token: string, description: string}>
     */
    public static function placeholders(): Collection
    {
        return collect(self::PLACEHOLDERS)
            ->map(fn (string $description, string $token): array => [
                'token' => $token,
                'description' => $description,
            ])
            ->values();
    }

    private function sendForSaleEvent(string $settingKey, string $templateKey, Sale $sale, string $ref, array $extra = []): bool
    {
        if (! $this->enabled($settingKey)) {
            return false;
        }

        $sale->loadMissing('customer');

        if (! $this->smsService->canSendTo($sale->customer?->phone)) {
            return false;
        }

        $template = Setting::get($templateKey, self::DEFAULT_TEMPLATES[$templateKey] ?? '');

        if (blank($template)) {
            return false;
        }

        $message = $this->smsService->parseTemplate(
            $template,
            $this->smsService->saleTemplateData($sale, $extra),
        );

        $result = $this->smsService->sendSms($sale->customer->phone, $message, $ref);

        return (bool) ($result['success'] ?? false);
    }

    private function enabled(string $settingKey): bool
    {
        return Setting::get('sms_enabled', '0') === '1'
            && Setting::get($settingKey, '1') === '1';
    }

    /**
     * @return array<string, string>
     */
    private function paymentTemplateData(Payment $payment): array
    {
        return [
            'payment_amount' => $this->smsService->formatAmount((float) $payment->amount),
            'payment_method' => str_replace('_', ' ', $payment->payment_method),
            'cheque_bank' => (string) $payment->cheque_bank,
            'cheque_no' => (string) ($payment->cheque_no ?: $payment->reference),
            'cheque_date' => $payment->cheque_date?->format('Y-m-d') ?? '',
        ];
    }
}
