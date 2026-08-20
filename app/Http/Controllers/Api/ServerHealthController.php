<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\NetworkAddresses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * What the host application shows.
 *
 * Answers the question the server app exists to answer — is this working? —
 * which is not something the admin panel can do, because reaching the admin
 * panel already proves half of it.
 *
 * Bound to loopback only. This reports the health of the machine rather than
 * anything about the library, but it is still the server talking about itself,
 * and there is no reason for it to be reachable from the network.
 */
class ServerHealthController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'app' => 'soundchex',

            // A worker that has claimed a job recently is running. There is no
            // way to see the process from here, so this infers it from work
            // done — which is the thing actually being asked about.
            'queue_running' => $this->queueIsMoving(),
            'queue_depth' => $this->tableCount('jobs'),
            'failed_jobs' => $this->tableCount('failed_jobs'),

            // The scheduler writes a cache entry each time it fires.
            'scheduler_running' => $this->schedulerIsRunning(),

            'library' => [
                'items' => MediaItem::count(),
                'pending' => MediaItem::where('processing_status', 'pending')->count(),
            ],

            'addresses' => app(NetworkAddresses::class)->all(),
            'last_backup' => $this->lastBackup(),
        ]);
    }

    /**
     * Whether the queue has moved recently.
     *
     * A depth of zero is ambiguous: it means either a worker keeping up, or no
     * worker and nothing queued. Reserved jobs disambiguate it — something has
     * claimed work — and an empty queue with no failures is treated as healthy
     * rather than alarming.
     */
    private function queueIsMoving(): bool
    {
        try {
            $reserved = DB::table('jobs')->whereNotNull('reserved_at')->exists();
            $waiting = DB::table('jobs')->whereNull('reserved_at')->count();

            return $reserved || $waiting === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function schedulerIsRunning(): bool
    {
        // schedule:work runs every minute, so a heartbeat older than a few of
        // them means it is not running.
        $beat = cache()->get('soundchex.scheduler.heartbeat');

        return is_int($beat) && $beat > now()->subMinutes(3)->timestamp;
    }

    private function tableCount(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function lastBackup(): ?string
    {
        $backups = glob(storage_path('backups/soundchex-*.sqlite.gz')) ?: [];

        if ($backups === []) {
            return null;
        }

        rsort($backups);

        return date('c', filemtime($backups[0]) ?: time());
    }
}
