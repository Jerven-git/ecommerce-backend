<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionRequest extends Model
{
    /** @use HasFactory<\Database\Factories\CommissionRequestFactory> */
    use BelongsToStore, HasFactory;

    public const STATUSES = [
        'pending',
        'reviewing',
        'quoted',
        'accepted',
        'in_progress',
        'delivered',
        'cancelled',
    ];

    protected $fillable = [
        'customer_name',
        'customer_email',
        'customer_phone',
        'title',
        'description',
        'budget_range',
        'preferred_medium',
        'preferred_size',
        'deadline',
        'reference_image_url',
        'status',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
        ];
    }

    protected function customerName(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function customerEmail(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strtolower(trim($value)) : $value,
        );
    }

    protected function customerPhone(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function adminNotes(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }
}
