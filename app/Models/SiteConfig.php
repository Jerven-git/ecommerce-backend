<?php

namespace App\Models;

use App\Modules\Realtime\Traits\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class SiteConfig extends Model
{
    use BroadcastsChanges;
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
        'site_name',
        'theme',
        'hero_title',
        'hero_subtitle',
        'hero_overlay_color',
        'hero_overlay_opacity',
        'hero_full_bleed',
        'hero_focal_x',
        'hero_focal_y',
        'hero_image_url',
        'hero_media_mime',
        'about_content',
        'about_overlay_color',
        'about_overlay_opacity',
        'contact_overlay_color',
        'contact_overlay_opacity',
        'contact_email',
        'contact_phone',
        'contact_entries',
        'favorites_enabled',
        'show_stock_quantity',
        'backorder_enabled',
        'backorder_payment_link_expiry_hours',
        'welcome_popup_enabled',
        'welcome_popup_heading',
        'welcome_popup_body',
        'welcome_popup_discount_id',
        'homepage_steps',
        'homepage_features',
        'homepage_stats',
        'homepage_newsletter',
        'about_highlights',
        'shop_header',
        'shop_promo',
        'contact_page',
    ];

    /**
     * Get the resolved theme with defaults applied.
     */
    public function getResolvedThemeAttribute(): array
    {
        return array_merge(self::THEME_DEFAULTS, $this->theme ?? []);
    }

    protected function siteName(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function heroTitle(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function heroSubtitle(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function aboutContent(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strtolower(trim($value)) : $value,
        );
    }

    protected function contactPhone(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function welcomePopupHeading(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function welcomePopupBody(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    public function welcomePopupDiscount()
    {
        return $this->belongsTo(Discount::class, 'welcome_popup_discount_id');
    }

    protected $casts = [
        'updated_at' => 'datetime',
        'theme' => 'array',
        'contact_entries' => 'array',
        'favorites_enabled' => 'boolean',
        'show_stock_quantity' => 'boolean',
        'backorder_enabled' => 'boolean',
        'backorder_payment_link_expiry_hours' => 'integer',
        'hero_overlay_opacity' => 'integer',
        'about_overlay_opacity' => 'integer',
        'contact_overlay_opacity' => 'integer',
        'hero_full_bleed' => 'boolean',
        'hero_focal_x' => 'integer',
        'hero_focal_y' => 'integer',
        'welcome_popup_enabled' => 'boolean',
        'welcome_popup_discount_id' => 'integer',
        'homepage_steps' => 'array',
        'homepage_features' => 'array',
        'homepage_stats' => 'array',
        'homepage_newsletter' => 'array',
        'about_highlights' => 'array',
        'shop_header' => 'array',
        'shop_promo' => 'array',
        'contact_page' => 'array',
    ];

    public function media()
    {
        return $this->morphMany(Media::class, 'imageable');
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

    public function heroMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'hero');
    }

    public function aboutMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'about');
    }

    public function contactMedia()
    {
        return $this->morphOne(Media::class, 'imageable')->where('collection', 'contact');
    }
}