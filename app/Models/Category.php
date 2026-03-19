<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
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
    ];

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
