<?php

namespace App\Services\Estimating;

use App\Models\EstimatePricingProfile;
use App\Models\Job;
use App\Models\User;

class EstimatePricingResolver
{
    public function forJob(Job $job): array
    {
        if ($job->handyman_id) {
            $contractorProfile = EstimatePricingProfile::where('user_id', $job->handyman_id)
                ->where('active', true)
                ->first();

            if ($contractorProfile) {
                return $this->serialize($contractorProfile, 'contractor');
            }
        }

        $global = EstimatePricingProfile::whereNull('user_id')
            ->where('active', true)
            ->latest('id')
            ->first();

        return $global
            ? $this->serialize($global, 'trustfix')
            : $this->starter();
    }

    public function forUser(User $user): array
    {
        $query = $user->role === 'admin'
            ? EstimatePricingProfile::whereNull('user_id')
            : EstimatePricingProfile::where('user_id', $user->id);

        $profile = $query->latest('id')->first();

        return $profile
            ? $this->serialize($profile, $user->role === 'admin' ? 'trustfix' : 'contractor')
            : $this->starter();
    }

    public function saveForUser(User $user, array $values): EstimatePricingProfile
    {
        $profile = $user->role === 'admin'
            ? EstimatePricingProfile::firstOrNew(['user_id' => null])
            : EstimatePricingProfile::firstOrNew(['user_id' => $user->id]);

        $profile->fill($values);
        $profile->user_id = $user->role === 'admin' ? null : $user->id;
        $profile->active = true;
        $profile->save();

        return $profile;
    }

    private function serialize(EstimatePricingProfile $profile, string $source): array
    {
        return [
            'profile_id' => $profile->id,
            'configured' => true,
            'source' => $source,
            'name' => $profile->name,
            'hourly_wage' => (float) $profile->hourly_wage,
            'payroll_burden_percent' => (float) $profile->payroll_burden_percent,
            'insurance_percent' => (float) $profile->insurance_percent,
            'tools_percent' => (float) $profile->tools_percent,
            'material_markup_percent' => (float) $profile->material_markup_percent,
            'travel_flat_fee' => (float) $profile->travel_flat_fee,
            'overhead_percent' => (float) $profile->overhead_percent,
            'profit_percent' => (float) $profile->profit_percent,
            'uncertainty_percent' => (float) $profile->uncertainty_percent,
        ];
    }

    private function starter(): array
    {
        return array_merge([
            'profile_id' => null,
            'configured' => false,
            'source' => 'starter',
            'name' => 'Starter assumptions — configure before production',
        ], config('trustfix.estimator.starter_pricing', []));
    }
}
