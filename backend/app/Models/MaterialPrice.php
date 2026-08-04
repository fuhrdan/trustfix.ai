<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaterialPrice extends Model
{
    protected $fillable = [
        'name',
        'normalized_name',
        'category',
        'zip_code',
        'unit',
        'unit_price',
        'low_unit_price',
        'high_unit_price',
        'source_name',
        'source_url',
        'observed_at',
        'active',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'low_unit_price' => 'decimal:2',
        'high_unit_price' => 'decimal:2',
        'observed_at' => 'date',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (MaterialPrice $price) {
            $price->normalized_name = self::normalizeName($price->name);
            $price->zip_code = $price->zip_code !== null
                ? trim((string) $price->zip_code)
                : null;
        });
    }

    public static function normalizeName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->limit(191, '')
            ->value();
    }
}
