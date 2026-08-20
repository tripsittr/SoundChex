<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where devices send their diagnostics.
 *
 * Unauthenticated on purpose: the failures worth reporting include the ones
 * that stop a device signing in, and a report that requires a working session
 * cannot describe a broken one.
 *
 * That makes it writable by anything that can reach the server, so it is
 * rate-limited and every field is bounded. It stores what happened rather than
 * what was being watched — a crash report should not become a record of
 * someone's viewing.
 */
class DeviceReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device' => ['required', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:40'],
            'build' => ['nullable', 'string', 'max:40'],
            'origin' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'max:40'],
            'events.*.kind' => ['required', 'string', 'max:40'],
            'events.*.at' => ['required', 'integer'],
            'events.*.path' => ['nullable', 'string', 'max:255'],
            'events.*.detail' => ['nullable', 'array'],
        ]);

        DeviceReport::create($data);

        return response()->json(['stored' => true], 201);
    }
}
