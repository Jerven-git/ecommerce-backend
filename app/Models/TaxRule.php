<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class TaxRule extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'region_type',
        'country',
        'state',
        'tax_rate',
        'tax_name',
        'tax_display_mode',
        'enabled',
        'priority',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'enabled' => 'boolean',
        'priority' => 'integer',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Find the best matching tax rule for a given region.
     * Priority: state (2) > country (1) > all (0)
     */
    public static function forRegion(?string $country, ?string $state): ?self
    {
        if (! $country && ! $state) {
            return static::enabled()
                ->where('region_type', 'all')
                ->orderByDesc('priority')
                ->first();
        }

        return static::enabled()
            ->where(function ($query) use ($country, $state) {
                $query->where(function ($q) use ($country, $state) {
                    // State-level match
                    if ($state && $country) {
                        $q->where('region_type', 'state')
                            ->where('country', $country)
                            ->where('state', $state);
                    }
                })
                    ->orWhere(function ($q) use ($country) {
                        // Country-level match
                        if ($country) {
                            $q->where('region_type', 'country')
                                ->where('country', $country);
                        }
                    })
                    ->orWhere('region_type', 'all');
            })
            ->orderByDesc('priority')
            ->first();
    }
}
