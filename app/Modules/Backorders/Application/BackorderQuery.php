<?php

namespace App\Modules\Backorders\Application;

use App\Models\Backorder;
use Illuminate\Database\Eloquent\Builder;

class BackorderQuery
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage): array
    {
        $query = $this->tenantQuery()->with(['order', 'product']);

        foreach (['status', 'product_id', 'order_id'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (filled($filters['search'] ?? null)) {
            $term = $filters['search'];
            $query->whereHas('order', function ($query) use ($term) {
                $query->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_email', 'like', "%{$term}%");
            });
        }

        $statusCounts = $this->tenantQuery()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return array_merge(
            $query->latest()->paginate(min($perPage, 100))->toArray(),
            ['status_counts' => $statusCounts],
        );
    }

    /** @param list<string> $relations */
    public function find(int|string $id, array $relations = []): Backorder
    {
        return $this->tenantQuery()->with($relations)->findOrFail($id);
    }

    public function findByPublicToken(string $token): ?Backorder
    {
        return $this->tenantQuery()
            ->with(['order', 'product'])
            ->where('payment_token', $token)
            ->first();
    }

    public function tenantQuery(): Builder
    {
        return Backorder::query()->whereHas('order');
    }
}
