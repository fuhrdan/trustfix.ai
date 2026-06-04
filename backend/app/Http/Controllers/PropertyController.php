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

        $properties = Property::query()->findOrFail($id);

        $isCustomer = $properties->customer_id == $user->id;
        $isAssignedHandyman = $properties->handyman_id == $user->id;
        $isAdmin = $user->role == 'admin';

        if (!$isCustomer && !$isAssignedHandyman && !$isAdmin) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($properties);
    }

    public function store(Request $request)
    {
        
        $validated = $request->validate([
            'street_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:500'],
            'zip' => ['nullable', 'string', 'max:5'],
            'county' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500'], 
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif','max:5120']
        ]);
        
        $property = Property::create([
            'owner_user_id' => Auth::guard('api')->id(),
            'street_address' => $validated['street_address'] ?? '',
            'city' => $validated['city'] ?? '',
            'state' => $validated['state'] ?? '',
            'zip' => $validated['zip'] ?? '',
            'county' => $validated['county'] ?? '',
            'description' => $validated['description'] ?? ''
        ]);

/*
        if($request->hasFile('images')) {
            foreach($request->file('images') as $image) {
                $path = $image->store('properties/' . $property->id, 'public');
                PropertyImage::create([
                    'owner_user_id' => $property->id,
                    'image_path' => $path
                ]);
            }
        }
*/
//        return response()->json($property->load('images'), 201);

        return response()->json([
            'success' => true,
            'data' => $property
        ], 201);
    }
    
    public function uploadImage(Request $request, $id)
    {
        $property = Property::where('owner_user_id', auth()->id())
            ->findOrFail($id);

        $path = $request->file('image')->store('properties', 'public');

        $property->images()->create([
            'image_path' => $path
        ]);

        return response()->json([
            'success' => true,
            'path' => $path
        ]);
    }

    public function deleteImage(Request $request, $id)
    {
        $property = Property::where('owner_user_id', auth()->id())
            ->findOrFail($id);

        $path = $request->file('image')->store('properties', 'public');

        $property->images()->create([
            'image_path' => $path
        ]);

        return response()->json([
            'success' => true,
            'path' => $path
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
        $property = Property::findOrFail($id);

        $property->update([
            'street_address' => $request->street_address,
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