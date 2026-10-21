<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\Discount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DiscountManager
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Discount::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        return $query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['order'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): Discount
    {
        return Discount::findOrFail($id);
    }

    public function create(array $attributes): Discount
    {
        return Discount::create($attributes);
    }

    public function update(int $id, array $attributes): Discount
    {
        $discount = $this->find($id);
        $discount->update($attributes);

        return $discount;
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
    }

    public function byCode(string $code): ?Discount
    {
        return Discount::where('code', $code)->first();
    }
}
