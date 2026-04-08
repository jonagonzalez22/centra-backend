<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
  public function index()
  {
    return response()->json(Store::with('businessType')->get());
  }

  public function store(Request $request)
  {
    $store = Store::create($request->all());
    $store->load('businessType');

    return response()->json($store, 201);
  }

  public function show(string $id)
  {
    $store = Store::with('businessType')->findOrFail($id);
    return response()->json($store);
  }

  public function update(Request $request, string $id)
  {
    $store = Store::findOrFail($id);
    $store->update($request->all());
    $store->load('businessType');

    return response()->json($store);
  }

  public function destroy(string $id)
  {
    $store = Store::findOrFail($id);
    $store->delete();

    return response()->json(null, 204);
  }
}
