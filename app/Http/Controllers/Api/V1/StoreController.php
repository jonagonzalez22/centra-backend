<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
  /**
   * Display a listing of the resource.
   */
  public function index(Request $request)
  {
    $stores = Store::all();
    return response()->json($stores);
  }

  /**
   * Store a newly created resource in storage.
   */
  public function store(Request $request)
  {
    $store = Store::create($request->all());
    return response()->json($store, 201);
  }

  /**
   * Display the specified resource.
   */
  public function show(string $id)
  {
    $store = Store::findOrFail($id);
    return response()->json($store);
  }

  /**
   * Update the specified resource in storage.
   */
  public function update(Request $request, string $id)
  {
    $store = Store::findOrFail($id);
    $store->update($request->all());
    return response()->json($store);
  }

  /**
   * Remove the specified resource from storage.
   */
  public function destroy(string $id)
  {
    $store = Store::findOrFail($id);
    $store->delete();
    return response()->json(null, 204);
  }
}
