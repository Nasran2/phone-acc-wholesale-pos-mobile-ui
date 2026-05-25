<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank Transfers')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'bank-transfers';
};
?>

@include('pages.accounting.partials.report-page')
