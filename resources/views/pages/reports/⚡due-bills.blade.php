<?php

use App\Livewire\Concerns\InteractsWithBusinessReports;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Due Bills Report')] class extends Component
{
    use InteractsWithBusinessReports;

    public string $reportType = 'due-bills';
};
?>

@include('pages.reports.partials.report-page')
