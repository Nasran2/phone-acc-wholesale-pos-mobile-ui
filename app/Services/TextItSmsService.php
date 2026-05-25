<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

class TextItSmsService
{
    /**
     * Send an SMS via textit.biz API.
     *
     * @return array{success: bool, message: string, ref?: string|null}
     */
    public function sendSms(string $to, string $message, ?string $ref = null): array
    {
        $enabled = Setting::get('sms_enabled', '0') === '1';

        if (! $enabled) {
            return [
                'success' => false,
                'message' => 'SMS gateway is disabled in settings.',
            ];
        }

        $id = Setting::get('sms_textit_id');
        $password = Setting::get('sms_textit_password');
        $baseUrl = Setting::get('sms_textit_base_url', 'https://textit.biz/sendmsg');

        if (empty($id) || empty($password)) {
            return [
                'success' => false,
                'message' => 'SMS API credentials are not configured.',
            ];
        }

        // Standardize recipient number format
        $to = $this->formatPhoneNumber($to);

        // Pre-create log in pending state
        $log = SmsLog::query()->create([
            'phone' => $to,
            'message' => $message,
            'status' => 'pending',
            'ref_no' => $ref,
        ]);

        try {
            $response = Http::connectTimeout(5)->timeout(10)->retry(2, 500)->get($baseUrl, [
                'id' => $id,
                'pw' => $password,
                'to' => $to,
                'text' => $message,
            ]);

            $body = trim($response->body());
            $parts = explode(':', $body);

            if ($response->successful() && isset($parts[0]) && trim($parts[0]) === 'OK') {
                $log->update([
                    'status' => 'success',
                    'response' => $body,
                ]);

                return [
                    'success' => true,
                    'message' => 'SMS sent successfully. ID: '.($parts[1] ?? ''),
                    'ref' => $parts[1] ?? null,
                ];
            } else {
                $log->update([
                    'status' => 'failed',
                    'response' => $body ?: 'Response status: '.$response->status(),
                ]);

                return [
                    'success' => false,
                    'message' => 'SMS sending failed. Error: '.($parts[1] ?? $body),
                ];
            }
        } catch (Throwable $e) {
            Log::error('SMS sending exception: '.$e->getMessage());

            $log->update([
                'status' => 'failed',
                'response' => 'Exception: '.$e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'SMS gateway exception: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Parse template values with sale details.
     *
     * @param  array<string, mixed>  $data
     */
    public function parseTemplate(string $template, array $data): string
    {
        $placeholders = [
            '{cus_name}' => $data['cus_name'] ?? $data['customer_name'] ?? 'Customer',
            '{cus_phone}' => $data['cus_phone'] ?? $data['customer_phone'] ?? '',
            '{cus_address}' => $data['cus_address'] ?? $data['customer_address'] ?? '',
            '{customer_name}' => $data['customer_name'] ?? 'Customer',
            '{customer_phone}' => $data['customer_phone'] ?? '',
            '{customer_address}' => $data['customer_address'] ?? '',
            '{invoice_no}' => $data['invoice_no'] ?? '',
            '{total}' => $data['total'] ?? '0.00',
            '{paid}' => $data['paid'] ?? '0.00',
            '{due}' => $data['due'] ?? '0.00',
            '{payment_amount}' => $data['payment_amount'] ?? $data['paid'] ?? '0.00',
            '{payment_method}' => $data['payment_method'] ?? '',
            '{cheque_bank}' => $data['cheque_bank'] ?? '',
            '{cheque_no}' => $data['cheque_no'] ?? '',
            '{cheque_date}' => $data['cheque_date'] ?? '',
            '{bill_link}' => $data['bill_link'] ?? '',
            '{business_name}' => Setting::get('business_name', 'Shop'),
            '{business_phone}' => Setting::get('business_phone', ''),
            '{business_address}' => Setting::get('business_address', ''),
            '{date}' => $data['date'] ?? date('Y-m-d'),
        ];

        return strtr($template, $placeholders);
    }

    /**
     * @return array<string, string>
     */
    public function saleTemplateData(Sale $sale, array $extra = []): array
    {
        $sale->loadMissing('customer');
        $customer = $sale->customer;

        $extra = array_map(static fn (mixed $value): string => (string) $value, $extra);

        return array_merge([
            'cus_name' => $customer?->name ?? 'Customer',
            'cus_phone' => $customer?->phone ?? '',
            'cus_address' => $customer?->address ?? '',
            'customer_name' => $customer?->name ?? 'Customer',
            'customer_phone' => $customer?->phone ?? '',
            'customer_address' => $customer?->address ?? '',
            'invoice_no' => $sale->invoice_no,
            'total' => $this->formatAmount((float) $sale->grand_total),
            'paid' => $this->formatAmount((float) $sale->paid_amount),
            'due' => $this->formatAmount((float) $sale->due_amount),
            'bill_link' => route('public.bill', ['sale' => $sale->invoice_no]),
            'date' => $sale->date?->format('Y-m-d') ?? now()->toDateString(),
        ], $extra);
    }

    public function formatAmount(float $amount): string
    {
        return Number::format($amount, precision: 2);
    }

    public function canSendTo(?string $phone): bool
    {
        return filled($phone) && $phone !== '0000000000';
    }

    /**
     * Standardize phone number format for Sri Lanka / International sending.
     */
    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Sri Lankan standard conversions
        if (str_starts_with($phone, '07')) {
            $phone = '94'.substr($phone, 1);
        } elseif (str_starts_with($phone, '7')) {
            $phone = '94'.$phone;
        }

        return $phone;
    }
}
