<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteConfig extends Model
{
    const CREATED_AT = null;
    protected $table = 'site_config';

    protected $fillable = [
        'site_name',
        'primary_color',
        'secondary_color',
        'heading_font',
        'body_font',
        'logo_url',
        'hero_title',
        'hero_subtitle',
        'about_content',
        'contact_email',
        'contact_phone',
        'contact_entries',
        'favorites_enabled',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
        'contact_entries' => 'array',
        'favorites_enabled' => 'boolean',
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