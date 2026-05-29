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
use App\Models\JobImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function myJobs()
    {
        $user = Auth::guard('api')->user();

        $query = Job::with(['customer', 'handyman', 'changeOrders', 'disputes']);

        if ($user->role == 'handyman') {
            $query->where('handyman_id', $user->id);
        } else {
            $query->where('customer_id', $user->id);
        }

        return response()->json($query->latest()->get());
    }

    public function show($id)
    {
        $user = Auth::guard('api')->user();

        $job = Job::with(['customer', 'handyman', 'changeOrders', 'disputes', 'reports', 'images'])->findOrFail($id);

        $isCustomer = $job->customer_id == $user->id;
        $isAssignedHandyman = $job->handyman_id == $user->id;
        $isAdmin = $user->role == 'admin';

        if (!$isCustomer && !$isAssignedHandyman && !$isAdmin) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($job);
    }

    // Customer posts a job
    public function postJob(Request $request)
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'initial_description' => ['required', 'string', 'max:5000'],
            'agreed_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'onsite_contact_name' => ['nullable', 'string', 'max:255'],
            'onsite_contact_phone' => ['nullable', 'string', 'max:50'],
            'skills' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif','max:5120']
        ]);

        $job = Job::create([
            'customer_id' => Auth::guard('api')->id(),
            'status' => 'posted',
            'address' => $validated['address'],
            'lat' => $validated['lat'] ?? 0,
            'lng' => $validated['lng'] ?? 0,
            'initial_description' => $validated['initial_description'],
            'agreed_price' => $validated['agreed_price'] ?? null,
            'onsite_contact_name' => $validated['onsite_contact_name'] ?? null,
            'onsite_contact_phone'=> $validated['onsite_contact_phone'] ?? null,
            'skills' => $validated['skills'] ?? []
        ]);
        
        if($request->hasFile('images')) {
            foreach($request->file('images') as $image) {
                $path = $image->store('jobs', 'public');
                JobImage::create([
                    'job_id' => $job->id,
                    'image_path' => $path
                ]);
            }
        }

        return response()->json($job->load('images'), 201);
    }

    public function uploadImages(Request $request, $id)
    {
        $job = Job::findOrFail($id);

        $user = Auth::guard('api')->user();
    
    
        if ($job->customer_id != $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'images.*' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120'
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('jobs', 'public');

                JobImage::create([
                    'job_id' => $job->id,
                    'image_path' => $path
                ]);
            }
        }

        $job->refresh();
        
        return response()->json([
            'success' => true,
            'images' => $job->images()->get(),
            'message' => 'Images uploaded successfully',
            'job' => $job->load('images')
        ]);
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

        if (!in_array($job->status, ['posted', 'requested'], true)) {
            return response()->json(['error' => 'Job is not available'], 409);
        }

        $job->handyman_id = $user->id;
        $job->status = 'accepted';
        $job->save();

        return response()->json($job);
    }

    public function startJob($id)
    {
        $user = Auth::guard('api')->user();
        $job = Job::findOrFail($id);

        if ($job->handyman_id != $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($job->status != 'accepted') {
            return response()->json(['error' => 'Job must be accepted before it can start'], 409);
        }

        $job->update([
            'status' => 'in_progress',
        ]);

        return response()->json($job);
    }

    public function completeJob($id)
    {
        $user = Auth::guard('api')->user();
        $job = Job::findOrFail($id);

        if ($job->handyman_id != $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($job->status != 'in_progress') {
            return response()->json(['error' => 'Job must be in progress before completion'], 409);
        }

        $job->update([
            'status' => 'completed',
        ]);

        return response()->json($job);
    }

    public function cancelJob($id)
    {
        $user = Auth::guard('api')->user();
        $job = Job::findOrFail($id);

//Temporary fix to allow anyone to delete jobs.
// Remove for production

//    dd([
//        'job_customer_id' => $job->customer_id,
//             'auth_id' => auth()->id(),
//            'job' => $job
//        ]);
    
// WARNING remove the above before production.
// Or dogs and cats will live together and anarchy will reign.
// AND production will break.

        if ($job->customer_id != $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (in_array($job->status, ['completed', 'cancelled'], true)) {
            return response()->json(['error' => 'Job cannot be cancelled'], 409);
        }

        $job->update([
            'status' => 'cancelled',
        ]);

        return response()->json($job);
    }

// I "fixed" this section while tired.
// And probably made some serious mistakes.
    // Update job status
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::guard('api')->user();

        $job = Job::findOrFail($id);

        if ($job->customer_id != $user->id) {

            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'address' => 'required|string|max:255',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'initial_description' => 'required|string',
            'agreed_price' => 'nullable|numeric'
        ]);
 
        $job->update($validated);

        return response()->json($job);
    }

    public function update(Request $request, $id)
    {
        $job = Job::findOrFail($id);

        if ($job->customer_id != Auth::guard('api')->id()) {

            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'initial_description' => ['required', 'string', 'max:5000'],
            'agreed_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'onsite_contact_name' => ['nullable', 'string', 'max:255'],
            'onsite_contact_phone' => ['nullable', 'string', 'max:50'],
            'skills' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif','max:5120']
        ]);

        $job->update($validated);

        if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {

                $path = $image->store('jobs', 'public');

                JobImage::create([
                    'job_id' => $job->id,
                    'image_path' => $path
                ]);
            }
        }

        return response()->json($job);
    }

// I also updated this while tired.    
    public function destroy($id)
    {
        $user = Auth::guard('api')->user();

        $job = Job::findOrFail($id);

        if ($job->customer_id != $user->id) {

            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $job->delete();

        return response()->json([
            'message' => 'Job deleted successfully'
        ]);
    }
}