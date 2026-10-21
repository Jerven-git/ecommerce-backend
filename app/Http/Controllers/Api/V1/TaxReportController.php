<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\TaxReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxReportController extends Controller
{
    public function __construct(private readonly TaxReport $report) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
        ]);

        $data = $this->report->build($validated);

        return response()->json(['data' => $data]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
        ]);

        $data = $this->report->build($validated);

        $filename = 'tax-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Region',
                'Country',
                'State',
                'Orders',
                'Subtotal (Ex-Tax)',
                'Tax Collected',
                'Total',
                'Tax Rate',
                'Tax Name',
            ]);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['region_label'] ?? 'No Tax Region',
                    $row['country'] ?? '',
                    $row['state'] ?? '',
                    $row['order_count'],
                    number_format($row['subtotal'], 2, '.', ''),
                    number_format($row['tax_collected'], 2, '.', ''),
                    number_format($row['total'], 2, '.', ''),
                    $row['tax_rate'] ?? '',
                    $row['tax_name'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
