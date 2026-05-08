<?php

namespace App\Http\Controllers;

use App\Models\HandymanSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HandymanController extends Controller
{
    public function profile()
    {
        $handyman = Auth::guard('api')->user();
        $handyman->skills = $handyman->skills()->with('skill')->get();
        $handyman->documents = $handyman->documents()->get();

        return response()->json($handyman);
    }

    // Update profile info
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user = Auth::guard('api')->user();
        $user->update($validated);

        return response()->json($user);
    }

    // Update handyman skills
    public function updateSkills(Request $request)
    {
        $validated = $request->validate([
            'skills' => ['required', 'array', 'min:1'],
            'skills.*.skill_id' => ['required', 'integer', 'exists:skills,id'],
            'skills.*.proficiency_level' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $user = Auth::guard('api')->user();

        foreach ($validated['skills'] as $skill) {
            HandymanSkill::updateOrCreate(
                [
                    'handyman_id' => $user->id,
                    'skill_id' => $skill['skill_id'],
                ],
                [
                    'proficiency_level' => $skill['proficiency_level'],
                ]
            );
        }

        return response()->json(['status' => 'ok']);
    }

    // Upload document
    public function uploadDocument(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'type' => ['required', 'string', 'max:100'],
        ]);

        $user = Auth::guard('api')->user();
        $path = $request->file('file')->store("private/docs/{$user->id}");

        $document = $user->documents()->create([
            'file_path' => $path,
            'type' => $validated['type'],
            'status' => 'pending',
            'verified' => false,
        ]);

        return response()->json($document, 201);
    }
}