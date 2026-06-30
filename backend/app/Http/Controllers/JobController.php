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
use App\Models\JobActivity;
use App\Models\JobMessage;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobController extends Controller
{

    private function logJobEvent(Job $job, ?User $user, string $type, string $description): void
    {
        JobActivity::create([
            'job_id' => $job->id,
            'user_id' => $user ? $user->id : null,
            'activity_type' => $type,
            'description' => $description,
        ]);

        JobMessage::create([
            'job_id' => $job->id,
            'sender_user_id' => null,
            'message' => $description,
            'message_type' => 'system',
        ]);
    }

    public function availableJobs(Request $request)
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'budget' => ['nullable', 'string', 'in:any,under_100,100_250,250_500,500_plus'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,highest_budget,lowest_budget'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Job::with(['customer', 'property', 'images', 'messages', 'activities'])
            ->whereNull('handyman_id')
            ->whereIn('status', ['posted', 'requested']);

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($query) use ($search) {
                $query->where('initial_description', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('onsite_contact_name', 'like', '%' . $search . '%');
            });
        }

        if (!empty($validated['category'])) {
            $category = $validated['category'];

            $query->where('skills', 'like', '%' . $category . '%');
        }

        switch ($validated['budget'] ?? 'any') {
            case 'under_100':
                $query->whereNotNull('agreed_price')
                    ->where('agreed_price', '<', 100);
                break;

            case '100_250':
                $query->whereBetween('agreed_price', [100, 250]);
                break;

            case '250_500':
                $query->whereBetween('agreed_price', [250, 500]);
                break;

            case '500_plus':
                $query->where('agreed_price', '>=', 500);
                break;
        }

        switch ($validated['sort'] ?? 'newest') {
            case 'oldest':
                $query->oldest();
                break;

            case 'highest_budget':
                $query->orderByRaw('agreed_price IS NULL ASC')
                    ->orderByDesc('agreed_price');
                break;

            case 'lowest_budget':
                $query->orderByRaw('agreed_price IS NULL ASC')
                    ->orderBy('agreed_price');
                break;

            case 'newest':
            default:
                $query->latest();
                break;
        }

        return response()->json(
            $query->paginate($validated['per_page'] ?? 20)
        );
    }

    public function myJobs()
    {
        $user = Auth::guard('api')->user();

        $query = Job::with(['customer', 'handyman', 'property', 'changeOrders', 'disputes', 'images', 'messages', 'activities']);

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

        $job = Job::with(['customer', 'handyman', 'property', 'changeOrders', 'disputes', 'reports', 'images', 'messages.sender', 'activities.user'])->findOrFail($id);

        $isCustomer = $job->customer_id == $user->id;
        $isAssignedHandyman = $job->handyman_id == $user->id;
        $isAvailableForHandyman = $user->role == 'handyman'
            && !$job->handyman_id
            && in_array($job->status, ['posted', 'requested'], true);
        $isAdmin = $user->role == 'admin';

        if (!$isCustomer && !$isAssignedHandyman && !$isAvailableForHandyman && !$isAdmin) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($job);
    }

    private function getAccessibleProperty($propertyId)
    {
        if (!$propertyId) {
            return null;
        }

        $user = Auth::guard('api')->user();

        return Property::where('id', $propertyId)
            ->where(function ($query) use ($user) {
                $query->where('owner_user_id', $user->id)
                    ->orWhereHas('users', function ($query) use ($user) {
                        $query->where('users.id', $user->id);
                    });
            })
            ->firstOrFail();
    }

    private function formatPropertyAddress(Property $property)
    {
        $line1 = trim($property->street_address ?? '');
        $line2 = trim($property->address_line_2 ?? '');
        $apartment = trim($property->apartment ?? '');

        $cityStateZip = trim(
            trim($property->city ?? '') . ', ' .
            trim($property->state ?? '') . ' ' .
            trim($property->zip ?? '')
        );

        $parts = array_filter([
            $line1,
            $line2,
            $apartment ? 'Apt/Unit ' . $apartment : '',
            $cityStateZip
        ]);

        return implode(', ', $parts);
    }

    // Customer posts a job
    public function postJob(Request $request)
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'property_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'initial_description' => ['required', 'string', 'max:5000'],
            'agreed_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'onsite_contact_name' => ['nullable', 'string', 'max:255'],
            'onsite_contact_phone' => ['nullable', 'string', 'max:50'],
            'skills' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif','max:5120']
        ]);

        $property = $this->getAccessibleProperty($validated['property_id'] ?? null);

        $address = $property
            ? $this->formatPropertyAddress($property)
            : trim($validated['address'] ?? '');

        if ($address === '') {
            return response()->json([
                'message' => 'An address or property_id is required.'
            ], 422);
        }

        $createData = [
            'customer_id' => $user->id,
            'status' => 'posted',
            'address' => $address,
            'lat' => $validated['lat'] ?? 0,
            'lng' => $validated['lng'] ?? 0,
            'initial_description' => $validated['initial_description'],
            'agreed_price' => $validated['agreed_price'] ?? null,
            'onsite_contact_name' => $validated['onsite_contact_name'] ?? null,
            'onsite_contact_phone'=> $validated['onsite_contact_phone'] ?? null,
            'skills' => $validated['skills'] ?? []
        ];

        if ($property && Schema::hasColumn('jobs', 'property_id')) {
            $createData['property_id'] = $property->id;
        }

        $job = Job::create($createData);
        
        if($request->hasFile('images')) {
            foreach($request->file('images') as $image) {
                $path = $image->store('jobs/' . $job->id, 'public');
                JobImage::create([
                    'job_id' => $job->id,
                    'image_path' => $path
                ]);
            }
        }

        $this->logJobEvent($job, $user, 'job_posted', 'Job was posted by ' . $user->name . '.');

        return response()->json($job->load(['images', 'property', 'messages', 'activities']), 201);
    }

    public function uploadImages(Request $request, $id)
    {
        $job = Job::findOrFail($id);

        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($job->customer_id != $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'images.*' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120'
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('jobs/' . $job->id, 'public');

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

public function deleteImage($jobId, $imageId)
{
    $user = Auth::guard('api')->user();

    // Find job and ensure ownership/permission
    $job = Job::findOrFail($jobId);

    // SECURITY CHECK (adjust to your logic)
    if (!$user || $job->customer_id != $user->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 403);
    }

    // Find image belonging to this job
    $image = JobImage::where('id', $imageId)
        ->where('job_id', $jobId)
        ->first();

    if (!$image) {
        return response()->json([
            'success' => false,
            'message' => 'Image not found'
        ], 404);
    }

    // Delete file from storage
    $filePath = storage_path('app/public/' . $image->image_path);

    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete DB record
    $image->delete();

    return response()->json([
        'success' => true
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

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $updated = DB::transaction(function () use ($id, $user) {
            $job = Job::where('id', $id)
                ->whereNull('handyman_id')
                ->whereIn('status', ['posted', 'requested'])
                ->lockForUpdate()
                ->first();

            if (!$job) {
                return null;
            }

            $job->handyman_id = $user->id;
            $job->status = 'accepted';
            $job->save();

            $this->logJobEvent($job, $user, 'job_accepted', $user->name . ' accepted this job.');

            return $job->load(['customer', 'handyman', 'property', 'images', 'messages', 'activities']);
        });

        if (!$updated) {
            return response()->json([
                'error' => 'Job is no longer available'
            ], 409);
        }

        return response()->json($updated);
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

        $this->logJobEvent($job, $user, 'job_started', $user->name . ' started this job.');

        return response()->json($job->load(['messages', 'activities']));
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

        $this->logJobEvent($job, $user, 'job_completed', $user->name . ' marked this job complete.');

        return response()->json($job->load(['messages', 'activities']));
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

        $this->logJobEvent($job, $user, 'job_cancelled', $user->name . ' cancelled this job.');

        return response()->json($job->load(['messages', 'activities']));
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
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $job = Job::findOrFail($id);

        if ($job->customer_id != $user->id) {

            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'property_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'initial_description' => ['required', 'string', 'max:5000'],
            'agreed_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'onsite_contact_name' => ['nullable', 'string', 'max:255'],
            'onsite_contact_phone' => ['nullable', 'string', 'max:50'],
            'skills' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif','max:5120']
        ]);

        $property = $this->getAccessibleProperty($validated['property_id'] ?? null);

        $address = $property
            ? $this->formatPropertyAddress($property)
            : trim($validated['address'] ?? '');

        if ($address === '') {
            return response()->json([
                'message' => 'An address or property_id is required.'
            ], 422);
        }

        $updateData = [
            'address' => $address,
            'lat' => $validated['lat'] ?? 0,
            'lng' => $validated['lng'] ?? 0,
            'initial_description' => $validated['initial_description'],
            'agreed_price' => $validated['agreed_price'] ?? null,
            'onsite_contact_name' => $validated['onsite_contact_name'] ?? null,
            'onsite_contact_phone' => $validated['onsite_contact_phone'] ?? null,
            'skills' => $validated['skills'] ?? []
        ];

        if (Schema::hasColumn('jobs', 'property_id')) {
            $updateData['property_id'] = $property ? $property->id : null;
        }

        $job->update($updateData);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('jobs/' . $job->id, 'public');

                JobImage::create([
                    'job_id' => $job->id,
                    'image_path' => $path
                ]);
            }
        }

        return response()->json($job->load(['images', 'property']));
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