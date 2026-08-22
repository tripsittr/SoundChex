<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\MetadataPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A source with no API key reports itself unsupported and is skipped in
 * silence, which is indistinguishable from a source that simply has nothing to
 * say about this media type.
 *
 * That silence is not hypothetical. The TMDB key was lost when the database was
 * rebuilt; every film enriched afterwards completed with no year, no runtime and
 * no rating, and nothing anywhere said why. It surfaced only when the conversion
 * filer refused to file a film it could not name.
 */
class MetadataSourceSkipWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_warns_when_a_registered_source_declines_to_run(): void
    {
        // TMDB is registered for films and gated on a key. With none stored it
        // declines, which is exactly the case that used to pass unnoticed.
        config(['metadata_sources.movie' => [\App\Services\Metadata\Sources\Movie\Tmdb::class]]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'skipped')
                    && in_array('Tmdb', $context['skipped'], true);
            });

        app(MetadataPipeline::class)->sourcesFor($this->movie());
    }

    public function test_it_stays_quiet_when_every_source_runs(): void
    {
        // Nothing registered means nothing declined — no source could have run,
        // so there is nothing to report and the log stays clean.
        config(['metadata_sources.movie' => []]);

        Log::shouldReceive('warning')->never();

        $this->assertSame([], app(MetadataPipeline::class)->sourcesFor($this->movie()));
    }

    private function movie(): MediaItem
    {
        return MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film With No Key',
            'file_path' => '/tmp/nonexistent.mkv',
            'owned' => true,
        ]);
    }
}
