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

class JobController extends Controller
{
    // Customer posts a jorb
    public function postJob(Request $request)
    {
	$request->validate([
	    'address'=>'required|string',
	    'lat'=>'required|numeric',
	    'lng'=>'required|numeric',
	    'initial_description'=>'required|string'
	    ]);

	$job = Job::create([
            'customer_id'=>Auth::id(),
            'address'=>$request->address,
            'lat'=>$request->lat,
            'lng'=>$request->lng,
            'initial_description'=>$request->initial_description
        ]);

        return response()->json($job);
    }

    // Nearby handymen (simple radius filter)
    public function nearbyHandymen(Request $request)
    {
        $lat = $request->lat;
        $lng = $request->lng;
        $radius = $request->radius ?? 10; // miles

        // Haversine formula
        $handymen = User::where('role','handyman')
            ->selectRaw("*, ( 3959 * acos( cos( radians(?) ) * cos( radians( lat ) ) * cos( radians( lng ) - radians(?) ) + sin( radians(?) ) * sin( radians( lat ) ) ) ) AS distance", [$lat, $lng, $lat])
            ->having('distance','<=',$radius)
            ->get();

        return response()->json($handymen);
    }

    // Handyman accepts job
    public function acceptJob($id)
    {
        $job = Job::findOrFail($id);
        if($job->handyman_id) return response()->json(['error'=>'Already assigned'],409);

        $job->handyman_id = Auth::id();
        $job->status = 'accepted';
        $job->save();

        return response()->json($job);
    }

    // Update job status (in progress, completed, change requested)
    public function updateStatus(Request $request,$id)
    {
        $job = Job::findOrFail($id);
        $job->status = $request->status;
        $job->save();
        return response()->json($job);
    }
}
