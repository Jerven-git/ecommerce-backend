<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Modules\Realtime\Traits\BroadcastsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToStore, BroadcastsChanges, HasFactory;

    public static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $count = 1;

        while (static::where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    protected $fillable = [
        'name',
        'slug',
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
        'hover_zoom_enabled',
        'seo_title',
        'seo_description',
        'og_image_url',
        'noindex',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strip_tags(trim($value)),
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'weight' => 'decimal:2',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'is_active' => 'boolean',
        'hover_zoom_enabled' => 'boolean',
        'allow_backorder' => 'boolean',
        'noindex' => 'boolean',
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

    public function categories()
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
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

    public function options()
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants()
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true);
    }

    public function getCanBackorderAttribute(): bool
    {
        return $this->canBackorder();
    }

    public function canBackorder(): bool
    {
        $globalEnabled = SiteConfig::forDefaultStore()?->backorder_enabled ?? false;

        return $globalEnabled && $this->allow_backorder;
    }
}
