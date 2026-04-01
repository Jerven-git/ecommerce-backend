<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
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
