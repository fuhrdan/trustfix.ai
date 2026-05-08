<?php
//*****************************************************************************
//** A job controller stands where the workflows meet,
//** Guiding each task with a steady, silemt beat/
//** It queues up the future, releases the past,
//** A conductor of code, making moments last. -Dan
//*****************************************************************************
namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    // Customer posts a job
    public function postJob(Request $request)
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'initial_description' => ['required', 'string', 'max:5000'],
            'agreed_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $job = Job::create([
            'customer_id' => Auth::guard('api')->id(),
            'address' => $validated['address'],
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'initial_description' => $validated['initial_description'],
            'agreed_price' => $validated['agreed_price'] ?? null,
        ]);

        return response()->json($job, 201);
    }

    // Nearby handymen, simple radius filter
    public function nearbyHandymen(Request $request)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);

        $lat = $validated['lat'];
        $lng = $validated['lng'];
        $radius = $validated['radius'] ?? 10;

        $handymen = User::where('role', 'handyman')
            ->selectRaw(
                "*, (3959 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distance",
                [$lat, $lng, $lat]
            )
            ->having('distance', '<=', $radius)
            ->get();

        return response()->json($handymen);
    }

    // Handyman accepts job
    public function acceptJob($id)
    {
        $user = Auth::guard('api')->user();
        $job = Job::findOrFail($id);

        if ($job->handyman_id) {
            return response()->json(['error' => 'Already assigned'], 409);
        }

        $job->handyman_id = $user->id;
        $job->status = 'accepted';
        $job->save();

        return response()->json($job);
    }

    // Update job status
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'posted',
                    'requested',
                    'accepted',
                    'in_progress',
                    'change_requested',
                    'completed',
                    'cancelled',
                ]),
            ],
        ]);

        $user = Auth::guard('api')->user();
        $job = Job::findOrFail($id);

        $isCustomer = $job->customer_id === $user->id;
        $isAssignedHandyman = $job->handyman_id === $user->id;

        if (!$isCustomer && !$isAssignedHandyman) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $job->status = $validated['status'];
        $job->save();

        return response()->json($job);
    }
}