<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Material\BomQuantityCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;

class BatchReportController extends Controller
{
    public function __construct(private readonly BomQuantityCalculator $bomQuantities) {}

    public function show(Batch $batch)
    {
        $data = $this->gatherReportData($batch);

        return Inertia::render('admin/reports/BatchReport', $data);
    }

    public function pdf(Batch $batch)
    {
        $data = $this->gatherReportData($batch);

        $pdf = Pdf::loadView('admin.reports.batch-report-pdf', $data)
            ->setPaper('a4', 'portrait');

        $filename = 'report-'.($batch->lot_number ?? 'batch-'.$batch->id).'.pdf';

        return $pdf->download($filename);
    }

    private function gatherReportData(Batch $batch): array
    {
        $batch->load([
            'workOrder.line',
            'workOrder.productType',
            'workstation',
            'steps.startedBy',
            'steps.completedBy',
            'processConfirmations.confirmedBy',
            'qualityChecks.samples',
            'qualityChecks.checkedBy',
            'packagingChecklist',
            'releasedBy',
        ]);

        $workOrder = $batch->workOrder;
        $snapshot = $workOrder->process_snapshot ?? [];
        $bom = $snapshot['bom'] ?? [];

        $bomWithTotals = array_map(function ($item) use ($batch) {
            $calculated = $this->bomQuantities->calculate($item, (float) $batch->produced_qty);

            return array_merge($item, [
                'total_qty' => $calculated['required_qty'],
            ]);
        }, $bom);

        return [
            'batch' => $batch,
            'workOrder' => $workOrder,
            'bom' => $bomWithTotals,
            'steps' => $batch->steps,
            'confirmations' => $batch->processConfirmations,
            'qualityChecks' => $batch->qualityChecks,
            'checklist' => $batch->packagingChecklist,
        ];
    }
}
