<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteConfigDocument extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'site_config_id',
        'store_id',
        'schema_version',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'payload' => 'array',
        ];
    }

    public function siteConfig(): BelongsTo
    {
        return $this->belongsTo(SiteConfig::class);
    }
}
