<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegionalSetting extends Model
{
    protected $fillable = ['country_code', 'country_name', 'multiplier', 'hourly_rate', 'extra_data'];

    protected $casts = [
        'extra_data' => 'array',
        'multiplier' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
    ];
}
