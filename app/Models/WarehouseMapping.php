<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseMapping extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'csa_code',
        'csa_name',
        'target_sheet',
        'region',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
