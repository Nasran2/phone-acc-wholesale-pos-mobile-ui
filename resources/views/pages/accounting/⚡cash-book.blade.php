<?php

use App\Livewire\Concerns\InteractsWithAccountingReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash Book')] class extends Component
{
    use InteractsWithAccountingReports;

    public string $reportType = 'cash-book';
};
?>

@include('pages.accounting.partials.report-page')
