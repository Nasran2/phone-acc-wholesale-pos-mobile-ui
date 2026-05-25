<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Debit Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'debits';
};
?>

@include('pages.reports.partials.report-page')
