<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The background processes a host needs running.
 *
 * Three of them, and all three have stopped silently at some point: the web
 * server, which presents on a phone as a white screen with no explanation; the
 * queue worker, whose absence is subtler because the library appears to work
 * while quietly never finishing anything; and the scheduler, without which
 * nothing is scanned and no backup is ever taken.
 *
 * Managed through launchd on macOS. The agents restart themselves and survive a
 * reboot, which is the point — a process started by hand in a terminal does
 * neither.
 */
class HostServices
{
    /** Label, plist file, and what it is for. */
    public const SERVICES = [
        'serve' => [
            'label' => 'com.soundchex.serve',
            'name' => 'Web server',
            'hint' => 'Serves the library to every device.',
        ],
        'queue' => [
            'label' => 'com.soundchex.queue',
            'name' => 'Queue worker',
            'hint' => 'Identifies new media, imports subtitles, transcodes.',
        ],
        'scheduler' => [
            'label' => 'com.soundchex.scheduler',
            'name' => 'Scheduler',
            'hint' => 'Scans watched folders and backs up nightly.',
        ],
    ];

    /**
     * @return array<string, array{name: string, hint: string, installed: bool, running: bool, detail: string}>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::SERVICES as $key => $service) {
            $path = $this->agentPath($service['label']);
            $installed = $path !== null && is_file($path);
            $running = $installed && $this->isLoaded($service['label']);

            $out[$key] = [
                'name' => $service['name'],
                'hint' => $service['hint'],
                'installed' => $installed,
                'running' => $running,
                'detail' => $this->detail($key, $installed, $running),
            ];
        }

        return $out;
    }

    /**
     * Copies the agent into place and loads it.
     *
     * Idempotent: installing twice must not produce two agents, so an existing
     * one is unloaded first.
     */
    /**
     * Why the last install, start or stop failed.
     *
     * These return a bare boolean, which left the screen able to say only
     * "check that the plist exists and launchctl is available" — a message that
     * names two things and diagnoses neither. The reason is kept here so the
     * page can report what actually went wrong.
     */
    public ?string $lastError = null;

    public function install(string $key): bool
    {
        $this->lastError = null;

        $service = self::SERVICES[$key] ?? null;

        if ($service === null) {
            $this->lastError = "Unknown service: {$key}";

            return false;
        }

        if (! $this->supported()) {
            $this->lastError = 'launchd is macOS only.';

            return false;
        }

        $source = base_path('Documentation & Planning/' . $service['label'] . '.plist');
        $target = $this->agentPath($service['label']);

        if ($target === null) {
            $this->lastError = 'Could not find your home directory, so there is '
                . 'nowhere safe to install the service. Start the server from a '
                . 'normal login session rather than a bare launchd context.';

            return false;
        }

        if (! is_file($source)) {
            $this->lastError = "No plist at {$source}";

            return false;
        }

        @mkdir(dirname($target), 0755, true);

        // Unloaded before overwriting, or launchd keeps running the old
        // definition and the new file is ignored until a reboot.
        $this->run(['launchctl', 'unload', $target]);

        if (! @copy($source, $target)) {
            $this->lastError = "Could not write {$target}";

            return false;
        }

        $result = $this->runWithError(['launchctl', 'load', $target]);

        if (! $this->isLoaded($service['label'])) {
            // launchctl exits zero for a job it accepted and then dropped, so
            // the error it printed is the only account of why.
            $this->lastError = $result !== '' ? $result : 'launchctl loaded it but it is not running.';

            return false;
        }

        return true;
    }

    public function start(string $key): bool
    {
        $service = self::SERVICES[$key] ?? null;

        if ($service === null) {
            return false;
        }

        $target = $this->agentPath($service['label']);

        if ($target === null || ! is_file($target)) {
            return $this->install($key);
        }

        $this->run(['launchctl', 'load', $target]);
        $this->run(['launchctl', 'start', $service['label']]);

        return $this->isLoaded($service['label']);
    }

    /**
     * Stops a service until it is started again.
     *
     * Unloaded rather than killed: KeepAlive would restart a killed process
     * immediately, so stopping means telling launchd to stop wanting it.
     */
    public function stop(string $key): bool
    {
        $service = self::SERVICES[$key] ?? null;

        if ($service === null) {
            return false;
        }

        $target = $this->agentPath($service['label']);

        if ($target !== null) {
            $this->run(['launchctl', 'unload', $target]);
        }

        return ! $this->isLoaded($service['label']);
    }

    /**
     * The last few lines this service wrote.
     *
     * A service that will not start says why here, and without a way to read it
     * the only recourse is a terminal — which is what this screen exists to
     * avoid. Tailed rather than read whole: these grow to megabytes and only
     * the end is ever interesting.
     */
    public function log(string $key, int $lines = 40): string
    {
        $service = self::SERVICES[$key] ?? null;

        if ($service === null) {
            return '';
        }

        // ~/Library/Logs, not the repository. macOS gates ~/Documents behind
        // TCC and a launchd agent has no UI to prompt with, so a job told to
        // redirect output into a folder there is dropped with EX_CONFIG before
        // its program ever runs — which is exactly what happened, silently.
        //
        // The same HOME resolution as the agent path: an empty HOME here would
        // read from `/Library/Logs`, the root path, and always find nothing.
        $home = $this->homeDir();

        if ($home === null) {
            return 'Nothing logged yet.';
        }

        $path = $home . '/Library/Logs/SoundChex/' . $key . '.log';

        if (! is_file($path)) {
            return 'Nothing logged yet.';
        }

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return 'Could not read the log.';
        }

        // Read from the end, so a large file costs the same as a small one.
        $buffer = [];

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        $chunk = '';

        while ($position > 0 && count($buffer) <= $lines) {
            $read = min(4096, $position);
            $position -= $read;

            fseek($handle, $position);
            $chunk = fread($handle, $read) . $chunk;
            $buffer = explode("\n", $chunk);
        }

        fclose($handle);

        return trim(implode("\n", array_slice($buffer, -$lines))) ?: 'Nothing logged yet.';
    }

    /** Whether service management is available on this platform. */
    public function supported(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * The user's LaunchAgents path for a service, or null if HOME is unknown.
     *
     * LaunchAgents belong under the user's home. When this runs from a context
     * that carries no `HOME` — launchd itself, the desktop shell's service
     * command, cron — `getenv('HOME')` is empty, and the old
     * `$home . '/Library/LaunchAgents/...'` collapsed to the *root*
     * `/Library/LaunchAgents`, which no non-root process may write. The copy
     * then failed with a message naming a path the user never chose. So HOME is
     * resolved from every source it might live in, and a genuinely empty result
     * returns null rather than a path pointing at the system directory.
     *
     * `??` alone was not enough: `$_SERVER['HOME']` can be the empty string
     * rather than unset, which `??` passes straight through.
     */
    private function agentPath(string $label): ?string
    {
        $home = $this->homeDir();

        if ($home === null) {
            return null;
        }

        return $home . '/Library/LaunchAgents/' . $label . '.plist';
    }

    /** The user's home directory, from whichever source actually holds it. */
    private function homeDir(): ?string
    {
        foreach ([$_SERVER['HOME'] ?? null, getenv('HOME') ?: null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return rtrim($candidate, '/');
            }
        }

        // Last resort: the passwd entry, which is set even when the environment
        // is not. Present on the macOS this feature is limited to.
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $entry = posix_getpwuid(posix_getuid());

            if (is_array($entry) && ! empty($entry['dir'])) {
                return rtrim($entry['dir'], '/');
            }
        }

        return null;
    }

    private function isLoaded(string $label): bool
    {
        $output = $this->run(['launchctl', 'list', $label]);

        return $output !== null;
    }

    /**
     * Something more useful than "running" or "not".
     *
     * The web server can be asked directly; the other two are inferred from the
     * work they do, since there is no way to see the process from here.
     */
    private function detail(string $key, bool $installed, bool $running): string
    {
        if (! $installed) {
            return 'Not installed. It will not survive a reboot.';
        }

        if (! $running) {
            return 'Stopped.';
        }

        return match ($key) {
            'queue' => $this->queueDetail(),
            'scheduler' => $this->schedulerDetail(),
            default => 'Running.',
        };
    }

    private function queueDetail(): string
    {
        try {
            $waiting = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 'Running.';
        }

        if ($failed > 0) {
            return sprintf('Running. %d waiting, %d failed.', $waiting, $failed);
        }

        return $waiting > 0
            ? sprintf('Running. %d job%s waiting.', $waiting, $waiting === 1 ? '' : 's')
            : 'Running. Nothing waiting.';
    }

    private function schedulerDetail(): string
    {
        $beat = cache()->get('soundchex.scheduler.heartbeat');

        if (! is_int($beat)) {
            return 'Running, but it has not reported yet.';
        }

        $age = now()->timestamp - $beat;

        return $age < 180
            ? 'Running. Last checked less than a minute ago.'
            : sprintf('Loaded, but it last reported %d minutes ago.', (int) round($age / 60));
    }

    /**
     * Runs a command, returning its output or null when it failed.
     *
     * launchctl is noisy about things that are not errors — unloading an agent
     * that is not loaded, for one — so the exit code decides rather than the
     * output.
     */
    /**
     * Runs a command and returns whatever it complained about.
     *
     * launchctl reports real failures on stderr while still exiting zero, so
     * the exit code alone cannot distinguish "loaded" from "accepted and then
     * dropped".
     */
    private function runWithError(array $command): string
    {
        if (! $this->supported()) {
            return 'Not macOS.';
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return 'Could not run launchctl.';
        }

        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $err !== '' ? $err : $out;
    }

    private function run(array $command): ?string
    {
        if (! $this->supported()) {
            return null;
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? ($output ?: '') : null;
    }
}
