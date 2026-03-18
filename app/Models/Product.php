<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'image_url',
        'stock',
        'allow_backorder',
        'backorder_charge_policy',
        'weight',
        'length_cm',
        'width_cm',
        'height_cm',
        'shipping_calc_type',
        'category',
        'category_id',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'weight' => 'decimal:2',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'is_active' => 'boolean',
        'allow_backorder' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['volume_cbm', 'can_backorder'];

    public function getVolumeCbmAttribute(): float
    {
        $l = (float) $this->length_cm;
        $w = (float) $this->width_cm;
        $h = (float) $this->height_cm;

        if ($l <= 0 || $w <= 0 || $h <= 0) {
            return 0;
        }

        return round(($l * $w * $h) / 1000000, 6);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }
    public function media()
    {
        return $this->morphMany(Media::class, 'imageable');
    }

    public function backorders()
    {
        return $this->hasMany(Backorder::class);
    }

    public function getCanBackorderAttribute(): bool
    {
        return $this->canBackorder();
    }

    public function canBackorder(): bool
    {
        $globalEnabled = SiteConfig::first()?->backorder_enabled ?? false;
        return $globalEnabled && $this->allow_backorder;
    }
}