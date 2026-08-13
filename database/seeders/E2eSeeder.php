<?php

namespace Database\Seeders;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a small, entirely synthetic library for the browser tests.
 *
 * Every byte here is generated. The browser tests must never see the real
 * library — they run against a separate database and a separate storage root
 * (see .env.e2e), and this seeder is what fills that root.
 *
 * The audio and video are real, playable files produced by ffmpeg: the tests
 * assert that playback survives navigation, which needs a decoder to actually
 * succeed. Silence and a black frame are enough for that.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Household',
            'email' => 'household@example.test',
            'password' => bcrypt('password'),
        ]);

        $owner = Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $kid = Profile::create([
            'user_id' => $user->id,
            'name' => 'Kid',
            'is_owner' => false,
            'max_rating' => 'PG',
        ]);

        $this->command?->info("owner profile: {$owner->id}, kid profile: {$kid->id}");

        $this->track($user, 'Test Tone One', 'Synthetic Artist');
        $this->track($user, 'Test Tone Two', 'Synthetic Artist');

        $this->film($user, 'Family Film', 'G');
        $this->film($user, 'Grown Up Film', 'R');
    }

    private function track(User $user, string $title, string $artist): MediaItem
    {
        $relative = 'media/library/Music/' . $artist . '/Singles/' . $title . '.mp3';

        $this->generate($relative, [
            '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '30', '-q:a', '9',
        ]);

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => $relative,
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'duration_ms' => 30_000,
        ]);

        return $item;
    }

    private function film(User $user, string $title, string $rating): MediaItem
    {
        $relative = 'media/library/Movies/' . $title . ' (2026)/' . $title . ' (2026).mp4';

        $this->generate($relative, [
            '-f', 'lavfi', '-i', 'color=c=black:s=320x240:r=15', '-t', '10',
            '-pix_fmt', 'yuv420p',
        ]);

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => $relative,
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);

        $item->movieMetadata()->create([
            'release_year' => 2026,
            'mpaa_rating' => $rating,
        ]);

        return $item;
    }

    /**
     * Writes a generated media file, falling back to a stub without ffmpeg.
     *
     * A stub is enough for the navigation and permission tests; only the
     * playback assertions need a decodable file, and those skip themselves
     * when the media is not playable.
     */
    private function generate(string $relative, array $arguments): void
    {
        $absolute = Storage::disk('local')->path($relative);

        @mkdir(dirname($absolute), 0755, true);

        $ffmpeg = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));

        if ($ffmpeg === '') {
            Storage::disk('local')->put($relative, 'stub');
            $this->command?->warn('ffmpeg missing — wrote a stub for ' . $relative);

            return;
        }

        $command = array_merge(
            [$ffmpeg, '-y', '-loglevel', 'error'],
            $arguments,
            [$absolute],
        );

        @shell_exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1');
    }
}
