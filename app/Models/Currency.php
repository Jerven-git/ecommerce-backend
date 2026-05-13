<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    /** @use HasFactory<\Database\Factories\CurrencyFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'symbol_position',
        'decimal_places',
        'rate',
        'is_base',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'rate' => 'decimal:8',
            'is_base' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strtoupper(trim($value)) : $value,
        );
    }

    public static function base(): ?self
    {
        return self::query()->where('is_base', true)->first();
    }

    /**
     * Convert an amount from the base currency to this currency, rounded
     * to this currency's `decimal_places`.
     */
    public function convertFromBase(float $baseAmount): float
    {
        return round($baseAmount * (float) $this->rate, $this->decimal_places);
    }
}
