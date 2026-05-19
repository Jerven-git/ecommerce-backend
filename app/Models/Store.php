<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Store extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const DEFAULT_SLUG = 'default';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'default_currency_id',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => trim($value),
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtolower(trim($value)),
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function siteConfig(): HasOne
    {
        return $this->hasOne(SiteConfig::class);
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'status', 'default_currency_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('store');
    }
}
