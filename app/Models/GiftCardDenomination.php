<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class GiftCardDenomination extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'amount',
        'label',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeEnabled($query): void
    {
        $query->where('is_enabled', true)->orderBy('sort_order')->orderBy('amount');
    }
}
