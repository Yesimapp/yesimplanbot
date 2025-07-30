<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = [
        'external_id',
        'name_en',
        'name_ru',
        'iso',
        'aliases',
    ];

    protected $casts = [
        'aliases' => 'array',
    ];
}
