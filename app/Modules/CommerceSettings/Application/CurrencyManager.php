<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CurrencyManager
{
    public function enabled(): Collection
    {
        return Currency::where('is_enabled', true)->orderBy('sort_order')->orderBy('code')->get();
    }

    public function all(): Collection
    {
        return Currency::orderBy('sort_order')->orderBy('code')->get();
    }

    public function baseCode(): ?string
    {
        return Currency::base()?->code;
    }

    public function create(array $attributes): Currency
    {
        return DB::transaction(function () use ($attributes): Currency {
            if (! empty($attributes['is_base'])) {
                Currency::where('is_base', true)->update(['is_base' => false]);
            }

            return Currency::create($attributes);
        });
    }

    public function update(Currency $currency, array $attributes): Currency
    {
        return DB::transaction(function () use ($currency, $attributes): Currency {
            if (! empty($attributes['is_base'])) {
                Currency::where('is_base', true)->where('id', '!=', $currency->id)->update(['is_base' => false]);
                $attributes['rate'] = 1;
            }

            $currency->update($attributes);

            return $currency->fresh();
        });
    }

    public function delete(Currency $currency): bool
    {
        if ($currency->is_base) {
            return false;
        }

        $currency->delete();

        return true;
    }
}
