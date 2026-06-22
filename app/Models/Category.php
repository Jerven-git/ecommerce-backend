<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Modules\Realtime\Traits\BroadcastsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use BelongsToStore, BroadcastsChanges;

    protected $fillable = [
        'name',
        'slug',
        'image_url',
        'overlay_opacity',
        'parent_id',
        'sort_order',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strip_tags(trim($value)),
        );
    }

    protected $casts = [
        'sort_order' => 'integer',
        'overlay_opacity' => 'integer',
    ];

    /**
     * Build a store-unique slug from a name. The BelongsToStore global scope
     * keeps the uniqueness check scoped to the current store.
     */
    public static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $count = 1;

        while (static::where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'imageable');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    /**
     * Get all descendant IDs (requires children to be loaded).
     */
    public function allDescendantIds(): array
    {
        $children = $this->relationLoaded('childrenRecursive')
            ? $this->childrenRecursive
            : $this->children;

        $ids = [];
        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->allDescendantIds());
        }

        return $ids;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Flatten childrenRecursive into children key for JSON output.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (isset($array['children_recursive'])) {
            $array['children'] = $array['children_recursive'];
            unset($array['children_recursive']);
        }

        return $array;
    }
}
