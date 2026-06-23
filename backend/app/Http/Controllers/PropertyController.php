<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\User;
use App\Models\PropertyImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    public function myProperties()
    {
        
        $user = Auth::guard('api')->user();

        if (!$user)
        {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }
    
        $query = Property::query()->where('owner_user_id', $user->id);
        
        return response()->json($query->latest()->get());
    }


    public function show($id)
    {
        $user = Auth::guard('api')->user();

        $property = Property::with('images')
            ->where('owner_user_id', $user->id)
            ->findOrFail($id);

//Old peice, without image support        
//        $properties = Property::query()->findOrFail($id);

// This throws an error, possibly delete or fix

// Currently commented out.
//        $isCustomer = $properties->customer_id == $user->id;
//        $isAssignedHandyman = $properties->handyman_id == $user->id;

// Let's go back and revisit the code above another time.

//        $isAdmin = $user->role == 'admin';

//        if (!$isCustomer && !$isAssignedHandyman && !$isAdmin) {
//            return response()->json(['error' => 'Forbidden'], 403);
//        }

        return response()->json($property);
    }

    public function store(Request $request)
    {
        
        $validated = $request->validate([
            'street_address' => ['nullable', 'string', 'max:500'],
            'address_line_2' => ['nullable', 'string', 'max:500'],
            'apartment' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:500'],
            'zip' => ['nullable', 'string', 'max:20'],
            'county' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500'], 
            'images.*' => ['nullable', 'images', 'mimes:jpg,jpeg,png,webp,gif','max:5120']
        ]);
        
        $property = Property::create([
            'owner_user_id' => Auth::guard('api')->id(),
            'street_address' => $validated['street_address'] ?? '',
            'address_line_2' => $validated['address_line_2'] ?? '',
            'apartment' => $validated['apartment'] ?? '',
            'city' => $validated['city'] ?? '',
            'state' => $validated['state'] ?? '',
            'zip' => $validated['zip'] ?? '',
            'county' => $validated['county'] ?? '',
            'description' => $validated['description'] ?? ''
        ]);


        if($request->hasFile('images')) {
            foreach($request->file('images') as $image) {
                $path = $image->store('properties/' . $property->id, 'public');
                PropertyImage::create([
                    'owner_user_id' => $property->id,
                    'image_path' => $path
                ]);
            }
        }

//        return response()->json($property->load('images'), 201);

        return response()->json([
            'success' => true,
            'data' => $property
        ], 201);
    }
    
    public function uploadImage(Request $request, $id)
    {
        
//*********ERROR LOGGING***********************************
//    \Log::info('Property image upload started', [
//        'property_id' => $id,
//        'user_id' => Auth::guard('api')->id(),
//        'has_file' => $request->hasFile('image')
//    ]);
//*********END LOGGING*************************************
        $property = Property::where(
            'owner_user_id',
            Auth::guard('api')->id()
        )->findOrFail($id);

        $request->validate([
            'images.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:5120'
            ]
        ]);

        if ($request->hasFile('images'))
        {
            foreach ($request->file('images') as $image)
            {
                $path = $image->store(
                    'properties/' . $property->id,
                    'public'
                );

                $property->images()->create([
                    'image_path' => $path
                ]);
            }
        }

        
//*********ERROR LOGGING***********************************
//    \Log::info('File stored', [
//        'path' => $path
//    ]);
//*********END LOGGING*************************************
    
        return response()->json([
            'success' => true,
            'images' => $property->images()->get(),
            'property' => $property->load('images')
        ]);
    
    }

    public function deleteImage(
        Request $request, 
        $propertyId,
        $imageId
    )
    {
        $property = Property::where(
            'owner_user_id',
            Auth::guard('api')->id()
        )->findOrFail($propertyId);

        $image = $property->images()
            ->findOrFail($imageId);

        Storage::disk('public')->delete(
            $image->image_path
        );

        $image->delete();

        return response()->json([
            'success' => true
        ]);
    }

/*
    public function show($id)
    {
        $property =
            Property::where(
                'owner_user_id',
                auth()->id()
            )
            ->findOrFail($id);

        return response()->json(
            $property
        );
    }
*/

    public function update(Request $request, $id)
    {
// Update for security
//        $property = Property::findOrFail($id);

        $property = Property::where(
            'owner_user_id',
            Auth::guard('api')->id()
        )->findOrFail($id);

        $property->update([
            'street_address' => $request->street_address,
            'address_line_2' => $request->address_line_2,
            'apartment' => $request->apartment,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'county' => $request->county,
            'description' => $request->description
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function destroy($id)
    {
        Property::destroy($id);

        return response()->json([
            'success' => true
        ]);
    }
}