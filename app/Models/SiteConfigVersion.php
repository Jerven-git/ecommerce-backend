<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteConfigVersion extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id',
        'payload',
        'published_by',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
