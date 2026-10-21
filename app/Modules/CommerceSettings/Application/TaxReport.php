<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\Order;
use App\Models\TaxRule;
use Illuminate\Support\Facades\DB;

class TaxReport
{
    public function build(array $filters): array
    {
        $query = Order::query()->whereNotIn('status', ['cancelled', 'backorder_cancelled']);

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->select(
                'country',
                'state',
                'tax_region',
                'tax_rule_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(tax_amount) as tax_collected'),
                DB::raw('SUM(total_amount) as total'),
            )
            ->groupBy('country', 'state', 'tax_region', 'tax_rule_id')
            ->orderBy('country')
            ->orderBy('state')
            ->get()
            ->map(function ($row): array {
                $taxRule = $row->tax_rule_id ? TaxRule::find($row->tax_rule_id) : null;

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
            })
            ->values()
            ->all();
    }
}
