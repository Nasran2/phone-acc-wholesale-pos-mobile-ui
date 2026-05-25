<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profit & Loss')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'profit-loss';
};
?>

@include('pages.reports.partials.report-page')
