<?php

namespace App\Services\Estimating;

use App\Models\MaterialPrice;

class MaterialPriceMatcher
{
    public function price(array $materials, ?string $zipCode): array
    {
        return array_values(array_map(function (array $material) use ($zipCode) {
            $quantityLow = max(0, (float) ($material['quantity_low'] ?? 0));
            $quantityHigh = max($quantityLow, (float) ($material['quantity_high'] ?? $quantityLow));

            if (array_key_exists('unit_price_low', $material) || array_key_exists('unit_price_high', $material)) {
                $unitLow = max(0, (float) ($material['unit_price_low'] ?? 0));
                $unitHigh = max($unitLow, (float) ($material['unit_price_high'] ?? $unitLow));

                return array_merge($material, [
                    'quantity_low' => round($quantityLow, 2),
                    'quantity_high' => round($quantityHigh, 2),
                    'unit_price_low' => round($unitLow, 2),
                    'unit_price_high' => round($unitHigh, 2),
                    'estimated_cost_low' => round($quantityLow * $unitLow, 2),
                    'estimated_cost_high' => round($quantityHigh * $unitHigh, 2),
                    'price_missing' => $unitHigh <= 0,
                    'price_source' => $material['price_source'] ?? 'Contractor entry',
                ]);
            }

            $match = $this->findMatch((string) ($material['name'] ?? ''), $zipCode);
            if (!$match) {
                return array_merge($material, [
                    'quantity_low' => round($quantityLow, 2),
                    'quantity_high' => round($quantityHigh, 2),
                    'unit_price_low' => 0,
                    'unit_price_high' => 0,
                    'estimated_cost_low' => 0,
                    'estimated_cost_high' => 0,
                    'material_price_id' => null,
                    'price_source' => null,
                    'price_observed_at' => null,
                    'price_missing' => true,
                ]);
            }

            $unitLow = (float) ($match->low_unit_price ?? $match->unit_price);
            $unitHigh = (float) ($match->high_unit_price ?? $match->unit_price);

            return array_merge($material, [
                'unit' => $material['unit'] ?? $match->unit,
                'quantity_low' => round($quantityLow, 2),
                'quantity_high' => round($quantityHigh, 2),
                'unit_price_low' => round($unitLow, 2),
                'unit_price_high' => round(max($unitLow, $unitHigh), 2),
                'estimated_cost_low' => round($quantityLow * $unitLow, 2),
                'estimated_cost_high' => round($quantityHigh * max($unitLow, $unitHigh), 2),
                'material_price_id' => $match->id,
                'price_source' => $match->source_name ?: 'TrustFix material catalog',
                'price_observed_at' => optional($match->observed_at)->toDateString(),
                'price_missing' => false,
            ]);
        }, $materials));
    }

    private function findMatch(string $name, ?string $zipCode): ?MaterialPrice
    {
        $normalized = MaterialPrice::normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        $query = MaterialPrice::where('active', true)
            ->where('normalized_name', $normalized)
            ->where(function ($query) use ($zipCode) {
                if ($zipCode) {
                    $query->where('zip_code', $zipCode)->orWhereNull('zip_code');
                } else {
                    $query->whereNull('zip_code');
                }
            });

        if ($zipCode) {
            $query->orderByRaw('CASE WHEN zip_code = ? THEN 0 ELSE 1 END', [$zipCode]);
        }

        return $query->orderByDesc('observed_at')->orderByDesc('id')->first();
    }
}
