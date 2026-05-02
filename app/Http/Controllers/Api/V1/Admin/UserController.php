<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
  public function index(): JsonResponse
  {
    return response()->json(['message' => 'Index - Ticket 19']);
  }

  public function store(Request $request): JsonResponse
  {
    return response()->json(['message' => 'Store - Ticket 18']);
  }

  public function show(string $id): JsonResponse
  {
    return response()->json(['message' => 'Show - Ticket 19']);
  }

  public function update(Request $request, string $id): JsonResponse
  {
    return response()->json(['message' => 'Update - Ticket 20']);
  }

  public function destroy(string $id): JsonResponse
  {
    return response()->json(['message' => 'Destroy - Ticket 21']);
  }
}
