<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\TaxRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TaxRuleManager
{
    public function all(): Collection
    {
        return TaxRule::orderBy('country')->orderBy('state')->orderByDesc('priority')->get();
    }

    public function create(array $attributes): TaxRule
    {
        return TaxRule::create($this->normalize($attributes));
    }

    public function update(int $id, array $attributes): TaxRule
    {
        $rule = TaxRule::findOrFail($id);
        $rule->update($this->normalize($attributes, $rule));

        return $rule;
    }

    public function delete(int $id): void
    {
        TaxRule::findOrFail($id)->delete();
    }

    /** @return list<TaxRule> */
    public function sync(array $rules): array
    {
        return DB::transaction(function () use ($rules): array {
            $incomingIds = collect($rules)->pluck('id')->filter()->all();
            TaxRule::whereNotIn('id', $incomingIds)->delete();

            return collect($rules)->map(function (array $attributes): TaxRule {
                $id = $attributes['id'] ?? null;
                unset($attributes['id']);

                if ($id !== null) {
                    return $this->update((int) $id, $attributes);
                }

                return $this->create($attributes);
            })->all();
        });
    }

    private function normalize(array $attributes, ?TaxRule $existing = null): array
    {
        $regionType = $attributes['region_type'] ?? $existing?->region_type;

        if (array_key_exists('region_type', $attributes)) {
            $attributes['priority'] = match ($regionType) {
                'state' => 2,
                'country' => 1,
                default => 0,
            };
        }

        if ($regionType === 'all') {
            $attributes['country'] = null;
            $attributes['state'] = null;
        } elseif ($regionType === 'country') {
            $attributes['state'] = null;
        }

        return $attributes;
    }
}
