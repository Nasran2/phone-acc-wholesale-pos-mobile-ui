<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('T Accounts')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 't-accounts';
};
?>

@include('pages.accounting.partials.report-page')
