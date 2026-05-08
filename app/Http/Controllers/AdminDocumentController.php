<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AdminDocumentController extends Controller
{
    public function pending()
    {
        $documents = Document::with('handyman')
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($documents);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', 'string', 'max:100'],
            'handyman_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $query = Document::with(['handyman', 'reviewer']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (!empty($validated['handyman_id'])) {
            $query->where('handyman_id', $validated['handyman_id']);
        }

        return response()->json($query->latest()->get());
    }

    public function show($id)
    {
        $document = Document::with(['handyman', 'reviewer'])->findOrFail($id);

        return response()->json($document);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = Auth::guard('api')->user();
        $document = Document::findOrFail($id);

        $document->update([
            'status' => $validated['status'],
            'verified' => $validated['status'] === 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return response()->json($document->fresh(['handyman', 'reviewer']));
    }
}