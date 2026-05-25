<?php

namespace App\Console\Commands;

use App\Services\SmsNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sms:send-cheque-reminders')]
#[Description('Send SMS reminders for pending cheques due in two days')]
class SendChequeReminderSms extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SmsNotificationService $smsNotificationService): void
    {
        $sent = $smsNotificationService->sendPendingChequeReminders();

        $this->info("Cheque reminder SMS sent: {$sent}");
    }
}
