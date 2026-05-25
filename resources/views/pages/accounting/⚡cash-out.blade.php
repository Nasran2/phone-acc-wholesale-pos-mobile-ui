<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash Out')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'cash-out';
};
?>

@include('pages.accounting.partials.report-page')
