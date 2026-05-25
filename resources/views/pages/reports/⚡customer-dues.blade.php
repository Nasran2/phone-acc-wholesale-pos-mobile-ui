<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer Due Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'customer-dues';
};
?>

@include('pages.reports.partials.report-page')
