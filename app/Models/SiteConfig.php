<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Scopes\StoreScope;
use App\Modules\Realtime\Traits\BroadcastsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SiteConfig extends Model
{
    use BelongsToStore, BroadcastsChanges, LogsActivity;

    const CREATED_AT = null;

    protected $table = 'site_config';

    const THEME_DEFAULTS = [
        'primary_color' => '#6898ED',
        'secondary_color' => '#4B5979',
        'accent_color' => '#F3F4F6',
        'heading_font' => 'Inter',
        'body_font' => 'Inter',
        'texture' => 'none',
    ];

    protected $fillable = [
        'store_id',
        'theme',
        'about_overlay_color',
        'about_overlay_opacity',
        'contact_overlay_color',
        'contact_overlay_opacity',
        'badge_in_stock_color',
        'contact_email',
        'contact_entries',
        'favorites_enabled',
        'show_stock_quantity',
        'backorder_enabled',
        'backorder_payment_link_expiry_hours',
        'welcome_popup_discount_id',
        'homepage_showcase',
        'homepage_watch_shop',
        'homepage_best_sellers',
        'blog_overlay_color',
        'blog_overlay_opacity',
        'services_overlay_color',
        'services_overlay_opacity',
        'modules_enabled',
        'default_og_image_url',
        'canonical_base_url',
        'loader_animation',
        'currency_code',
    ];

    /**
     * Get the resolved theme with defaults applied.
     */
    public function getResolvedThemeAttribute(): array
    {
        return array_merge(self::THEME_DEFAULTS, $this->theme ?? []);
    }

    /**
     * Read the SiteConfig for the platform default store, bypassing tenant scoping.
     *
     * Used by public storefront paths until per-store storefront routing lands.
     */
    public static function forDefaultStore(): ?self
    {
        return static::queryForDefaultStore()->first();
    }

    /**
     * Builder targeting the default-store SiteConfig, with the StoreScope removed
     * so it works on public/unauthenticated requests.
     */
    public static function queryForDefaultStore(): \Illuminate\Database\Eloquent\Builder
    {
        return static::withoutGlobalScope(StoreScope::class)
            ->whereHas('store', fn ($q) => $q->where('slug', Store::DEFAULT_SLUG));
    }

    /**
     * Get or create the SiteConfig for the default store (used by public storefront
     * fallbacks until storefront tenancy lands).
     */
    public static function firstOrCreateForDefaultStore(): self
    {
        $existing = static::forDefaultStore();

        if ($existing) {
            return $existing;
        }

        $defaultStore = Store::where('slug', Store::DEFAULT_SLUG)->firstOrFail();

        return static::withoutGlobalScope(StoreScope::class)
            ->create(['store_id' => $defaultStore->id]);
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strtolower(trim($value)) : $value,
        );
    }

    public function welcomePopupDiscount()
    {
        return $this->belongsTo(Discount::class, 'welcome_popup_discount_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['store_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('site_config');
    }

    protected $casts = [
        'updated_at' => 'datetime',
        'theme' => 'array',
        'contact_entries' => 'array',
        'favorites_enabled' => 'boolean',
        'show_stock_quantity' => 'boolean',
        'backorder_enabled' => 'boolean',
        'backorder_payment_link_expiry_hours' => 'integer',
        'about_overlay_opacity' => 'integer',
        'contact_overlay_opacity' => 'integer',
        'welcome_popup_discount_id' => 'integer',
        'homepage_showcase' => 'array',
        'homepage_watch_shop' => 'array',
        'homepage_best_sellers' => 'array',
        'blog_overlay_opacity' => 'integer',
        'services_overlay_opacity' => 'integer',
        'modules_enabled' => 'array',
    ];

    public function media()
    {
        return $this->morphMany(Media::class, 'imageable');
    }

    public function storefrontMedia(): MorphMany
    {
        return $this->morphMany(Media::class, 'imageable')
            ->where(function ($query): void {
                $query->whereIn('collection', [
                    'logo',
                    'favicon',
                    'cart_icon',
                    'footer_logo',
                    'loader_logo',
                    'hero',
                    'homepage_statement',
                    'story_image_a',
                    'story_image_b',
                    'about',
                    'contact',
                    'blog',
                    'services',
                    'showcase_video',
                    'showcase_video_poster',
                    'watch_shop_card',
                ])->orWhere('collection', 'like', 'watch_shop_poster:%');
            });
    }

    public function websiteDocument(): HasOne
    {
        return $this->hasOne(SiteConfigDocument::class)
            ->withoutGlobalScope(StoreScope::class);
    }

    public function logoMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'logo');
    }

    public function faviconMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'favicon');
    }

    public function cartIconMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'cart_icon');
    }

    public function footerLogoMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'footer_logo');
    }

    public function loaderLogoMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'loader_logo');
    }

    public function heroMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'hero');
    }

    public function homepageStatementMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'homepage_statement');
    }

    public function storyImageAMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'story_image_a');
    }

    public function storyImageBMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'story_image_b');
    }

    public function aboutMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'about');
    }

    public function contactMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'contact');
    }

    public function blogMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'blog');
    }

    public function servicesMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'services');
    }

    public function showcaseVideoMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'showcase_video');
    }

    public function showcaseVideoPosterMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'showcase_video_poster');
    }
}
