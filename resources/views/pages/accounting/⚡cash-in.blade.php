<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash In')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'cash-in';
};
?>

@include('pages.accounting.partials.report-page')
