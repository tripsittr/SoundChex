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
            'name' => ['nullable', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:120'],
            'build' => ['nullable', 'string', 'max:40'],
            // The embedded Tauri shell, which the served build says nothing
            // about: it cannot update itself, so a device can be current on
            // one and months behind on the other.
            'shell' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'origin' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'max:40'],
            'events.*.kind' => ['required', 'string', 'max:40'],
            'events.*.at' => ['required', 'integer'],
            'events.*.path' => ['nullable', 'string', 'max:255'],
            'events.*.detail' => ['nullable', 'array'],
        ]);

        DeviceReport::create([
            ...$data,
            // Never taken from the request body. A device reporting its own
            // address would be reporting whatever it felt like, and the point
            // of recording it is to tell one device from another.
            'ip' => $request->ip(),
            // Narrowed from the user agent, which is the one thing it is
            // reliable about.
            'kind' => $this->kindOf($data['platform'] ?? ''),
        ]);

        return response()->json(['stored' => true], 201);
    }

    /**
     * What sort of device this is, from its user agent.
     *
     * Deliberately coarse. A user agent will not tell you which iPhone, and a
     * guess dressed up as a fact is worse than "phone" — the name field is for
     * saying which one, and it is asked for rather than inferred.
     */
    private function kindOf(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPad') => 'tablet',
            str_contains($agent, 'iPhone'), str_contains($agent, 'Android') => 'phone',
            str_contains($agent, 'Macintosh'), str_contains($agent, 'Windows'),
            str_contains($agent, 'Linux') => 'desktop',
            default => 'unknown',
        };
    }
}
