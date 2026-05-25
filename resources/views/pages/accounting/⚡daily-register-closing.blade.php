<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Daily Register Closing')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'daily-register-closing';
};
?>

@include('pages.accounting.partials.report-page')
