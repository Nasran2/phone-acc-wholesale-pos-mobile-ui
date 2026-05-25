<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Expense Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'expenses';
};
?>

@include('pages.reports.partials.report-page')
