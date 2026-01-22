<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteConfig extends Model
{
    protected $table = 'site_config';

    protected $fillable = [
        'site_name',
        'primary_color',
        'secondary_color',
        'logo_url',
        'hero_title',
        'hero_subtitle',
        'about_content',
        'contact_email',
        'contact_phone',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];
}