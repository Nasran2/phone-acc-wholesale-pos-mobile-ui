<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment Method Report')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'payment-method-report';
};
?>

@include('pages.accounting.partials.report-page')
