<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(["users" => User::orderBy("created_at", "desc")->get()]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate(["status" => "required|in:pending,approved,rejected"]);
        $user->update(["status" => $request->status]);
        return response()->json(["message" => "User status updated successfully"]);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $request->validate(["role" => "required|in:admin,staff"]);
        $user->update(["role" => $request->role]);
        return response()->json(["message" => "User role updated successfully"]);
    }
}
