<?php

namespace App\Models;

use App\Modules\Realtime\Traits\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServiceCategory extends Model
{
    use BroadcastsChanges;

    protected $fillable = [
        'name',
        'slug',
        'gradient_from',
        'gradient_to',
        'image_url',
        'overlay_opacity',
        'sort_order',
    ];

    protected $casts = [
        'overlay_opacity' => 'integer',
        'sort_order' => 'integer',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strip_tags(trim($value)),
        );
    }

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

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'imageable');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
