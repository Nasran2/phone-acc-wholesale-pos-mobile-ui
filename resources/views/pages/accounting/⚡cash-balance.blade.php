<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash Balance')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'cash-balance';
};
?>

@include('pages.accounting.partials.report-page')
