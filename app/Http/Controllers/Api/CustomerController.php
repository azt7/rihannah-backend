<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * List customers with search.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()->with('creator');

        if ($search = $request->get('search')) {
            $query->search($search);
        }

        $customers = $query->orderBy('full_name')
            ->paginate($request->get('per_page', 20));

        return response()->json($customers);
    }

    /**
     * Create a new customer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:150',
            'phone_number' => 'required|string|max:20',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();

        $customer = Customer::create($validated);

        return response()->json(['customer' => $customer], 201);
    }

    /**
     * Get a single customer.
     */
    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['bookings' => function ($q) {
            $q->with('unit')->orderByDesc('start_date')->limit(10);
        }]);

        return response()->json(['customer' => $customer]);
    }

    /**
     * Update a customer.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'string|max:150',
            'phone_number' => 'string|max:20',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json(['customer' => $customer]);
    }

    /**
     * Search customers by phone number.
     */
    public function searchByPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:3',
        ]);

        $customers = Customer::where('phone_number', 'like', '%' . $request->phone . '%')
            ->limit(10)
            ->get(['id', 'full_name', 'phone_number']);

        return response()->json(['customers' => $customers]);
    }
}
