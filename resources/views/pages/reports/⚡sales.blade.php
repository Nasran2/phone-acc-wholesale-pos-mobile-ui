<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'sales';
};
?>

@include('pages.reports.partials.report-page')
