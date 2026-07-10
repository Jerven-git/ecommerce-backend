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
        'domain',
        'domain_verified_at',
        'status',
        'default_currency_id',
    ];

    protected function casts(): array
    {
        return [
            'domain_verified_at' => 'datetime',
        ];
    }

    /**
     * Releasing a soft-deleted store's domain keeps it claimable by another
     * store. The unique index spans trashed rows, so without this a deleted
     * store would hold its domain hostage forever. Re-pointing it at a new
     * store still requires a fresh DNS verification.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $store): void {
            if ($store->isForceDeleting() || $store->domain === null) {
                return;
            }

            $store->newQueryWithoutScopes()
                ->whereKey($store->getKey())
                ->update(['domain' => null, 'domain_verified_at' => null]);

            $store->domain = null;
            $store->domain_verified_at = null;
            $store->syncOriginal();
        });
    }

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

    /**
     * Persist the canonical host form — lowercase, no leading "www." — because
     * HostStoreResolver canonicalises inbound hosts the same way before
     * matching. Storing "www.example.com" would silently never resolve.
     */
    protected function domain(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }

                $value = strtolower(trim($value));

                return str_starts_with($value, 'www.') ? substr($value, 4) : $value;
            },
        );
    }

    public function hasVerifiedDomain(): bool
    {
        return $this->domain !== null && $this->domain_verified_at !== null;
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
            ->logOnly(['name', 'slug', 'domain', 'status', 'default_currency_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('store');
    }
}
