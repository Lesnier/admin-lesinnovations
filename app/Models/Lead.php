<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'string',
        'total_estimate' => 'decimal:2',
    ];
}
