<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteConfigDraft extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
        'payload',
        'base_updated_at',
        'base_hash',
        'lock_version',
        'created_by',
        'updated_by',
        'review_status',
        'review_requested_by',
        'review_requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'scheduled_publish_at',
        'scheduled_by',
        'scheduled_release_note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'base_updated_at' => 'datetime',
            'lock_version' => 'integer',
            'review_requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'scheduled_publish_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function reviewRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }
}
