<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'purchases';
};
?>

@include('pages.reports.partials.report-page')
