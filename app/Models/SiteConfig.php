<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class SiteConfig extends Model
{
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
        'about_content',
        'contact_email',
        'contact_phone',
        'contact_entries',
        'favorites_enabled',
        'show_stock_quantity',
        'backorder_enabled',
        'backorder_payment_link_expiry_hours',
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

    protected $casts = [
        'updated_at' => 'datetime',
        'theme' => 'array',
        'contact_entries' => 'array',
        'favorites_enabled' => 'boolean',
        'show_stock_quantity' => 'boolean',
        'backorder_enabled' => 'boolean',
        'backorder_payment_link_expiry_hours' => 'integer',
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