<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Daily Cash Closing')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'daily-cash-closing';
};
?>

@include('pages.accounting.partials.report-page')
