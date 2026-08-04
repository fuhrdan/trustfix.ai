<?php

namespace App\Http\Controllers;

use App\Models\MaterialPrice;
use Illuminate\Http\Request;

class MaterialPriceController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MaterialPrice::query()->orderBy('name')->orderByDesc('observed_at');

        if (!empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }
        if (array_key_exists('zip_code', $validated)) {
            $validated['zip_code'] === ''
                ? $query->whereNull('zip_code')
                : $query->where('zip_code', $validated['zip_code']);
        }

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }

    public function store(Request $request)
    {
        return response()->json(
            MaterialPrice::create($this->validated($request)),
            201
        );
    }

    public function update(Request $request, $id)
    {
        $price = MaterialPrice::findOrFail($id);
        $price->update($this->validated($request));

        return response()->json($price->fresh());
    }

    public function destroy($id)
    {
        MaterialPrice::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'unit' => ['required', 'string', 'max:40'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'low_unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'high_unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'observed_at' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
