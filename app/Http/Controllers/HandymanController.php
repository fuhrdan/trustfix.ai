<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Skill;
use App\Models\HandymanSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class HandymanController extends Controller
{
    public function profile()
    {
	$handyman = Auth::user();
	$handyman->skills = $handyman->skills()->with('skill')->get();
	$handyman->documents = $handyman->documents()->get();
	return response()->json($handyman);
    }

    //update profile info
    public function updateProfile(Request $request)
    {
	$user = Auth::user();
	$skills = $request->skills; // array: {[skill_id, proficiency_level}]
	foreach($skills as $s){
	    HandymanSkill::updateOrCreate(
		['handyman_id'=>$user->id, 'skill_id'=>$s['skill_id']],
		['proficiency_level'=>$s['proficiency_level']]
	    );
	}
	return response()->json(['status'=>'ok']);
    }

    // Upload document
    public function uploadDocument(Request $request)
    {
	$request->validate([
	    'file'=>'required|file|mimes:pdf,jpg,png|max:10240',
	    'type'=>'required|string'
	]);

	$user = Auth::user();
	$path = $request->file('file')->store("private/docs/{$user->id}");

	$document = $user->documents()->create([
	    'file_path'=>$path,
	    'type'=>$request->type
	    ]);
	return response()->json($document);
    }

}
