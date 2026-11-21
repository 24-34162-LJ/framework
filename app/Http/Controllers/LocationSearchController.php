<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/LocationSearchController.php
 * Feature: Delivery address lookup
 * Purpose: Returns PH place suggestions (barangay, city/town, province) for the Delivery Address field.
 *          Answers from the local psgc_locations table; no outside maps service is called.
 */

namespace App\Http\Controllers;

use App\Models\PsgcLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $items = PsgcLocation::search($query)->map(fn (PsgcLocation $place): array => [
            'code' => $place->code,
            'type' => $place->type,
            'label' => $place->label,
        ])->values();

        return response()->json(['items' => $items]);
    }
}
