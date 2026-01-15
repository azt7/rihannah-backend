<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    /**
     * List all units.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Unit::query()->ordered();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        $units = $query->get();

        return response()->json(['units' => $units]);
    }

    /**
     * Create a new unit.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'status' => 'in:active,inactive',
            'default_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $unit = Unit::create($validated);

        return response()->json(['unit' => $unit], 201);
    }

    /**
     * Get a single unit.
     */
    public function show(Unit $unit): JsonResponse
    {
        return response()->json(['unit' => $unit]);
    }

    /**
     * Update a unit.
     */
    public function update(Request $request, Unit $unit): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:100',
            'status' => 'in:active,inactive',
            'default_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $unit->update($validated);

        return response()->json(['unit' => $unit]);
    }

    /**
     * Delete a unit (soft-disable by setting inactive).
     */
    public function destroy(Unit $unit): JsonResponse
    {
        // Instead of deleting, set to inactive
        $unit->update(['status' => 'inactive']);

        return response()->json(['message' => 'Unit deactivated successfully']);
    }
}
