<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = [
        'email',
        'source',
        'discount_code_sent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
