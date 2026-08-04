<?php

namespace App\Services\Estimating;

class EstimateCalculator
{
    public function calculate(array $steps, array $materials, array $pricing): array
    {
        [$hoursLow, $hoursHigh] = $this->sumHours($steps);
        [$rawMaterialsLow, $rawMaterialsHigh] = $this->sumMaterials($materials);

        $wage = max(0, (float) ($pricing['hourly_wage'] ?? 0));
        $burdenRate = $this->rate($pricing, 'payroll_burden_percent');
        $insuranceRate = $this->rate($pricing, 'insurance_percent');
        $toolsRate = $this->rate($pricing, 'tools_percent');
        $materialMarkupRate = $this->rate($pricing, 'material_markup_percent');
        $overheadRate = $this->rate($pricing, 'overhead_percent');
        $profitRate = $this->rate($pricing, 'profit_percent');
        $uncertaintyRate = min(0.5, $this->rate($pricing, 'uncertainty_percent'));
        $travel = max(0, (float) ($pricing['travel_flat_fee'] ?? 0));

        $baseWageLow = $hoursLow * $wage;
        $baseWageHigh = $hoursHigh * $wage;
        $laborLow = $baseWageLow * (1 + $burdenRate);
        $laborHigh = $baseWageHigh * (1 + $burdenRate);
        $materialLow = $rawMaterialsLow * (1 + $materialMarkupRate);
        $materialHigh = $rawMaterialsHigh * (1 + $materialMarkupRate);
        $insuranceLow = ($laborLow + $materialLow) * $insuranceRate;
        $insuranceHigh = ($laborHigh + $materialHigh) * $insuranceRate;
        $toolsLow = $laborLow * $toolsRate;
        $toolsHigh = $laborHigh * $toolsRate;

        $directLow = $laborLow + $materialLow + $travel + $insuranceLow + $toolsLow;
        $directHigh = $laborHigh + $materialHigh + $travel + $insuranceHigh + $toolsHigh;
        $overheadLow = $directLow * $overheadRate;
        $overheadHigh = $directHigh * $overheadRate;
        $profitLow = ($directLow + $overheadLow) * $profitRate;
        $profitHigh = ($directHigh + $overheadHigh) * $profitRate;
        $estimateLow = max(0, ($directLow + $overheadLow + $profitLow) * (1 - $uncertaintyRate));
        $estimateHigh = ($directHigh + $overheadHigh + $profitHigh) * (1 + $uncertaintyRate);

        return [
            'estimated_hours_low' => $this->money($hoursLow),
            'estimated_hours_high' => $this->money($hoursHigh),
            'labor_cost_low' => $this->money($laborLow),
            'labor_cost_high' => $this->money($laborHigh),
            'material_cost_low' => $this->money($materialLow),
            'material_cost_high' => $this->money($materialHigh),
            'travel_cost' => $this->money($travel),
            'insurance_cost_low' => $this->money($insuranceLow),
            'insurance_cost_high' => $this->money($insuranceHigh),
            'tools_cost_low' => $this->money($toolsLow),
            'tools_cost_high' => $this->money($toolsHigh),
            'overhead_cost_low' => $this->money($overheadLow),
            'overhead_cost_high' => $this->money($overheadHigh),
            'profit_low' => $this->money($profitLow),
            'profit_high' => $this->money($profitHigh),
            'estimate_low' => $this->money($estimateLow),
            'estimate_high' => $this->money(max($estimateLow, $estimateHigh)),
            'pricing_snapshot' => $pricing,
        ];
    }

    private function sumHours(array $steps): array
    {
        $low = 0;
        $high = 0;

        foreach ($steps as $step) {
            $stepLow = max(0, (float) ($step['hours_low'] ?? 0));
            $stepHigh = max($stepLow, (float) ($step['hours_high'] ?? $stepLow));
            $low += $stepLow;
            $high += $stepHigh;
        }

        return [$low, $high];
    }

    private function sumMaterials(array $materials): array
    {
        $low = 0;
        $high = 0;

        foreach ($materials as $material) {
            $itemLow = max(0, (float) ($material['estimated_cost_low'] ?? 0));
            $itemHigh = max($itemLow, (float) ($material['estimated_cost_high'] ?? $itemLow));
            $low += $itemLow;
            $high += $itemHigh;
        }

        return [$low, $high];
    }

    private function rate(array $pricing, string $key): float
    {
        return min(5, max(0, (float) ($pricing[$key] ?? 0)) / 100);
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }
}
