<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JobWorkspaceController extends Controller
{
    private function getAccessibleJob($id)
    {
        $user = Auth::guard('api')->user();
        $job = Job::with([
            'customer',
            'handyman',
            'property',
            'images',
            'changeOrders',
            'disputes',
            'messages.sender',
            'activities.user',
            'payments',
        ])->findOrFail($id);

        $isCustomer = $job->customer_id == $user->id;
        $isAssignedHandyman = $job->handyman_id == $user->id;
        $isAdmin = $user->role == 'admin';

        if (!$isCustomer && !$isAssignedHandyman && !$isAdmin) {
            abort(response()->json(['error' => 'Forbidden'], 403));
        }

        return $job;
    }

    public function show($id)
    {
        $job = $this->getAccessibleJob($id);

        $job->messages = $job->messages()
            ->with('sender')
            ->oldest()
            ->get();

        $job->activities = $job->activities()
            ->with('user')
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($job);
    }

    public function messages($id)
    {
        $job = $this->getAccessibleJob($id);

        return response()->json(
            $job->messages()
                ->with('sender')
                ->oldest()
                ->get()
        );
    }

    public function storeMessage(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $job = $this->getAccessibleJob($id);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $message = JobMessage::create([
            'job_id' => $job->id,
            'sender_user_id' => $user->id,
            'message' => trim($validated['message']),
            'message_type' => 'user',
        ]);

        JobActivity::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'activity_type' => 'message_sent',
            'description' => $user->name . ' sent a message.',
        ]);

        return response()->json(
            $message->load('sender'),
            201
        );
    }

    public function activities($id)
    {
        $job = $this->getAccessibleJob($id);

        return response()->json(
            $job->activities()
                ->with('user')
                ->latest()
                ->limit(50)
                ->get()
        );
    }
}
