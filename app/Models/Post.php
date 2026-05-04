<?php

namespace App\Models;

use App\Modules\Realtime\Traits\BroadcastsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Post extends Model
{
    use BroadcastsChanges;

    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'cover_image_url',
        'cover_alt_text',
        'author_name',
        'category_id',
        'is_published',
        'is_featured',
        'published_at',
        'seo_title',
        'seo_description',
        'og_image_url',
        'noindex',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'noindex' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function title(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strip_tags(trim($value)),
        );
    }

    protected function excerpt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    public static function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $slug = Str::slug($title);
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'category_id');
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'imageable');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
