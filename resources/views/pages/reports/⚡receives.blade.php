<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Receive Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'receives';
};
?>

@include('pages.reports.partials.report-page')
