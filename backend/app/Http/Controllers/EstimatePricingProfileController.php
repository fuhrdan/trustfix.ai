<?php

namespace App\Http\Controllers;

use App\Services\Estimating\EstimatePricingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EstimatePricingProfileController extends Controller
{
    public function __construct(private readonly EstimatePricingResolver $resolver)
    {
    }

    public function show()
    {
        $user = Auth::guard('api')->user();
        $this->authorizeRole($user?->role);

        return response()->json($this->resolver->forUser($user));
    }

    public function update(Request $request)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeRole($user?->role);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hourly_wage' => ['required', 'numeric', 'min:0', 'max:10000'],
            'payroll_burden_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'insurance_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'tools_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'material_markup_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'travel_flat_fee' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'overhead_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'profit_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'uncertainty_percent' => ['required', 'numeric', 'min:0', 'max:50'],
        ]);

        $profile = $this->resolver->saveForUser($user, $validated);

        return response()->json([
            'message' => 'Estimate pricing settings saved.',
            'profile' => $profile,
        ]);
    }

    private function authorizeRole(?string $role): void
    {
        if (!in_array($role, ['handyman', 'company', 'admin'], true)) {
            abort(response()->json(['message' => 'Contractor or administrator access is required.'], 403));
        }
    }
}
