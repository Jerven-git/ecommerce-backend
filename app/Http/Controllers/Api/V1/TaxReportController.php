<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
        ]);

        $data = $this->buildReport($validated);

        return response()->json(['data' => $data]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
        ]);

        $data = $this->buildReport($validated);

        $filename = 'tax-report-' . now()->format('Y-m-d') . '.csv';

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

    private function buildReport(array $filters): array
    {
        $query = Order::query()
            ->whereNotIn('status', ['cancelled', 'backorder_cancelled']);

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $results = $query
            ->select(
                'country',
                'state',
                'tax_region',
                'tax_rule_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(tax_amount) as tax_collected'),
                DB::raw('SUM(total_amount) as total')
            )
            ->groupBy('country', 'state', 'tax_region', 'tax_rule_id')
            ->orderBy('country')
            ->orderBy('state')
            ->get();

        return $results->map(function ($row) {
            // Look up tax rule for rate/name info
            $taxRule = $row->tax_rule_id
                ? \App\Models\TaxRule::find($row->tax_rule_id)
                : null;

            return [
                'country' => $row->country,
                'state' => $row->state,
                'region_label' => $row->tax_region ?? ($row->state
                    ? "{$row->state}, {$row->country}"
                    : ($row->country ?? 'No Region')),
                'order_count' => (int) $row->order_count,
                'subtotal' => round((float) $row->subtotal, 2),
                'tax_collected' => round((float) $row->tax_collected, 2),
                'total' => round((float) $row->total, 2),
                'tax_rate' => $taxRule?->tax_rate,
                'tax_name' => $taxRule?->tax_name,
                'tax_rule_id' => $row->tax_rule_id,
            ];
        })->values()->toArray();
    }
}
