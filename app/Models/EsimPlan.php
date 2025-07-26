<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EsimPlan extends Model
{
    use HasFactory;

    protected $table = 'esim_plans';

    protected $fillable = [
        'plan_id',
        'package_id',
        'plan_name',
        'period',
        'capacity',
        'capacity_unit',
        'capacity_info',
        'price',
        'currency',
        'prices',
        'price_info',
        'country_code',
        'country',
        'coverages',
        'targets',
        'direct_link',
        'url',
        'embedding', // ← ДОБАВЬ ЭТО
    ];

    protected $casts = [
        'prices'       => 'array',
        'coverages'    => 'array',
        'country_code' => 'array',
        'country'      => 'array',
        'embedding'    => 'array', // ← ДОБАВЬ ЭТО
    ];
}
