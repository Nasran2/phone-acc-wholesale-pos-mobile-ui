<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounting Ledger')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'cash-book';
};
?>

@include('pages.accounting.partials.report-page')
