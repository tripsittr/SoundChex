<?php
namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TmpResolveProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe(): void
    {
        $user = User::factory()->create();
        $owner = Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);
        Sanctum::actingAs($user, ['profile:'.$owner->id]);
        app(CurrentProfile::class)->switchTo($owner->id);

        $mk = function (string $title, string $artist) use ($user) {
            $item = MediaItem::create(['user_id' => $user->id, 'type' => MediaItemType::Music, 'title' => $title, 'relative_path' => strtolower($title).'.mp3']);
            $item->musicMetadata()->create(['artist' => $artist, 'primary_artist' => $artist]);
            return $item;
        };
        $mk('Matched', 'Artist');
        $chosen = $mk('The Real One', 'Artist');

        $m3u = "#EXTM3U\n#EXTINF:100,Artist - Matched\n/a.mp3\n#EXTINF:100,Ghost - Not Found\n/b.mp3\n";
        $import = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('p.m3u', $m3u),
        ])->json();
        fwrite(STDERR, "IMPORT: ".json_encode($import)."\n");
        fwrite(STDERR, "CHOSEN ID: ".$chosen->id."\n");

        $resp = $this->postJson(route('api.playlists.imports.resolve', $import['id']), ['index' => 0, 'item_id' => $chosen->id]);
        fwrite(STDERR, "RESOLVE STATUS: ".$resp->status()."\n");
        fwrite(STDERR, "RESOLVE BODY: ".$resp->getContent()."\n");
        $this->assertTrue(true);
    }
}
