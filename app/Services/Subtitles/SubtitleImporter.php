<?php

namespace App\Services\Subtitles;

use App\Models\MediaItem;
use App\Models\Subtitle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Finds caption tracks that already belong to a video and makes them playable.
 *
 * Two local sources, neither of which needs an account or a network call:
 * tracks muxed into the file itself, and .srt files sitting beside it. Both
 * are converted to WebVTT once and stored.
 */
class SubtitleImporter
{
    private const PROCESS_TIMEOUT = 300;

    public function __construct(private SubtitleConverter $converter) {}

    public function isAvailable(): bool
    {
        return $this->binaryExists($this->ffmpegPath())
            && $this->binaryExists($this->ffprobePath());
    }

    /**
     * Imports every track this video carries.
     *
     * @return array{embedded: int, sidecar: int}
     */
    public function importAll(MediaItem $item): array
    {
        return [
            'embedded' => count($this->importEmbedded($item)),
            'sidecar' => count($this->importSidecars($item)),
        ];
    }

    /**
     * Lists the subtitle streams inside a video.
     *
     * @return array<int, array<string, mixed>>
     */
    public function probeStreams(string $videoPath): array
    {
        $process = new Process([
            $this->ffprobePath(),
            '-v', 'error',
            '-select_streams', 's',
            '-show_entries', 'stream=index,codec_name:stream_tags=language,title',
            '-of', 'json',
            $videoPath,
        ]);

        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);

        return is_array($data['streams'] ?? null) ? $data['streams'] : [];
    }

    /**
     * Extracts each text-based subtitle stream to WebVTT.
     *
     * @return array<int, Subtitle>
     */
    public function importEmbedded(MediaItem $item): array
    {
        $videoPath = $item->absoluteFilePath();

        if ($videoPath === null || ! $this->isAvailable()) {
            return [];
        }

        $imported = [];

        foreach ($this->probeStreams($videoPath) as $stream) {
            $codec = $stream['codec_name'] ?? '';

            // Bitmap subtitles are pictures of text. Converting them would
            // need OCR per frame; they're skipped rather than half-handled.
            if (! $this->isTextCodec($codec)) {
                continue;
            }

            $index = $stream['index'] ?? null;

            if ($index === null) {
                continue;
            }

            $tags = $stream['tags'] ?? [];
            $language = $this->normalizeLanguage($tags['language'] ?? null);
            $title = $tags['title'] ?? null;

            $relative = $this->storagePathFor($item, 'embedded-' . $index, $language);

            if (! $this->extractStream($videoPath, (int) $index, Storage::path($relative))) {
                continue;
            }

            $vtt = (string) Storage::get($relative);

            $subtitle = Subtitle::updateOrCreate(
                [
                    'media_item_id' => $item->id,
                    'language' => $language,
                    'source' => Subtitle::SOURCE_EMBEDDED,
                    'origin' => (string) $index,
                ],
                [
                    'label' => $this->labelFor($language, $title),
                    'path' => $relative,
                    // Track titles are where releases mark these; ffprobe
                    // exposes disposition flags too, but titles are far more
                    // consistently populated in practice.
                    'forced' => $this->looksForced($title),
                    'sdh' => $this->looksSdh($title),
                    'cue_count' => $this->converter->countCues($vtt),
                ],
            );

            $imported[] = $subtitle;
        }

        $this->assignDefault($item);

        return $imported;
    }

    /**
     * Imports .srt/.vtt/.ass files sitting next to the video.
     *
     * Naming convention is "Movie.en.srt" or "Movie.english.forced.srt", so
     * the language is taken from what follows the video's own basename.
     *
     * @return array<int, Subtitle>
     */
    public function importSidecars(MediaItem $item): array
    {
        $videoPath = $item->absoluteFilePath();

        if ($videoPath === null) {
            return [];
        }

        $directory = dirname($videoPath);
        $base = pathinfo($videoPath, PATHINFO_FILENAME);
        $extensions = (array) config('subtitles.sidecar_extensions', []);

        $imported = [];

        foreach ($this->sidecarCandidates($directory, $base, $extensions) as $file) {
            $content = @file_get_contents($file);

            if ($content === false || trim($content) === '') {
                continue;
            }

            $suffix = $this->sidecarSuffix($file, $base);
            $language = $this->normalizeLanguage($this->languageFromSuffix($suffix));
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            $vtt = $this->toVtt($content, $extension, $file);

            if ($vtt === null) {
                continue;
            }

            $relative = $this->storagePathFor($item, 'sidecar-' . md5(basename($file)), $language);
            Storage::put($relative, $vtt);

            $imported[] = Subtitle::updateOrCreate(
                [
                    'media_item_id' => $item->id,
                    'language' => $language,
                    'source' => Subtitle::SOURCE_SIDECAR,
                    'origin' => basename($file),
                ],
                [
                    'label' => $this->labelFor($language, null),
                    'path' => $relative,
                    'forced' => $this->looksForced($suffix),
                    'sdh' => $this->looksSdh($suffix),
                    'cue_count' => $this->converter->countCues($vtt),
                ],
            );
        }

        $this->assignDefault($item);

        return $imported;
    }

    /**
     * Converts arbitrary subtitle content to WebVTT.
     *
     * ASS/SSA carry styling and positioning that a naive parser would mangle,
     * so ffmpeg handles those; SRT and VTT are done in PHP.
     */
    public function toVtt(string $content, string $extension, ?string $sourcePath = null): ?string
    {
        if ($extension === 'vtt' || $this->converter->isVtt($content)) {
            return $this->converter->ensureVttHeader($content);
        }

        if (in_array($extension, ['ass', 'ssa'], true)) {
            return $sourcePath !== null ? $this->convertWithFfmpeg($sourcePath) : null;
        }

        return $this->converter->srtToVtt($content);
    }

    /**
     * Picks a default track when nothing is marked as one.
     *
     * A forced track is never the default: it only shows foreign dialogue, so
     * defaulting to it would look like most of the film has no subtitles.
     */
    public function assignDefault(MediaItem $item): void
    {
        $tracks = Subtitle::where('media_item_id', $item->id)->get();

        if ($tracks->isEmpty() || $tracks->contains('is_default', true)) {
            return;
        }

        foreach ((array) config('subtitles.preferred_languages', ['en']) as $language) {
            $match = $tracks->first(fn (Subtitle $t): bool => $t->language === $language
                && ! $t->forced
                && ! $t->sdh);

            if ($match !== null) {
                $match->forceFill(['is_default' => true])->saveQuietly();

                return;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function sidecarCandidates(string $directory, string $base, array $extensions): array
    {
        $found = [];

        foreach ($extensions as $extension) {
            // Both "Movie.srt" and "Movie.en.srt" / "Movie.en.forced.srt".
            foreach (glob($directory . '/' . $this->escapeGlob($base) . '*.' . $extension) ?: [] as $file) {
                if (is_file($file)) {
                    $found[] = $file;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Whatever sits between the video's basename and the extension.
     */
    private function sidecarSuffix(string $file, string $base): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        return trim(substr($name, strlen($base)), '. -_');
    }

    private function languageFromSuffix(string $suffix): ?string
    {
        if ($suffix === '') {
            return null;
        }

        // "en.forced" → "en"; "english" → "english".
        $first = preg_split('/[.\-_ ]/', $suffix)[0] ?? '';

        return $first !== '' ? $first : null;
    }

    /**
     * Extracts one stream to WebVTT.
     */
    private function extractStream(string $videoPath, int $index, string $targetPath): bool
    {
        $directory = dirname($targetPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return false;
        }

        $process = new Process([
            $this->ffmpegPath(),
            '-y',
            '-i', $videoPath,
            // Absolute stream index, which is what ffprobe reported.
            '-map', '0:' . $index,
            '-c:s', 'webvtt',
            '-f', 'webvtt',
            $targetPath,
        ]);

        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($targetPath)) {
            Log::warning('Subtitle extraction failed', [
                'index' => $index,
                'error' => substr($process->getErrorOutput(), -400),
            ]);

            return false;
        }

        // An empty track is worse than no track: it appears in the picker and
        // then shows nothing.
        if (filesize($targetPath) < 16) {
            @unlink($targetPath);

            return false;
        }

        return true;
    }

    private function convertWithFfmpeg(string $sourcePath): ?string
    {
        $target = tempnam(sys_get_temp_dir(), 'sub') . '.vtt';

        $process = new Process([
            $this->ffmpegPath(),
            '-y',
            '-i', $sourcePath,
            '-f', 'webvtt',
            $target,
        ]);

        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($target)) {
            return null;
        }

        $content = (string) file_get_contents($target);
        @unlink($target);

        return $content !== '' ? $content : null;
    }

    private function storagePathFor(MediaItem $item, string $key, string $language): string
    {
        return trim((string) config('subtitles.path', 'media/subtitles'), '/')
            . '/' . $item->id
            . '/' . $language . '-' . $key . '.vtt';
    }

    /**
     * Text-based subtitle codecs. Bitmap formats (dvdsub, pgssub) are pictures
     * and can't become WebVTT without OCR.
     */
    private function isTextCodec(string $codec): bool
    {
        return in_array($codec, [
            'subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text', 'eia_608', 'subviewer',
        ], true);
    }

    /**
     * Normalises a language tag to something an HTML track element accepts.
     *
     * ffprobe reports ISO 639-2 ("eng"); browsers want 639-1 ("en").
     */
    private function normalizeLanguage(?string $language): string
    {
        $language = strtolower(trim((string) $language));

        if ($language === '' || $language === 'und') {
            return 'und';
        }

        $map = [
            'eng' => 'en', 'english' => 'en',
            'spa' => 'es', 'esp' => 'es', 'spanish' => 'es',
            'fre' => 'fr', 'fra' => 'fr', 'french' => 'fr',
            'ger' => 'de', 'deu' => 'de', 'german' => 'de',
            'ita' => 'it', 'italian' => 'it',
            'por' => 'pt', 'portuguese' => 'pt',
            'rus' => 'ru', 'russian' => 'ru',
            'jpn' => 'ja', 'japanese' => 'ja',
            'kor' => 'ko', 'korean' => 'ko',
            'chi' => 'zh', 'zho' => 'zh', 'chinese' => 'zh',
            'dut' => 'nl', 'nld' => 'nl', 'dutch' => 'nl',
            'swe' => 'sv', 'swedish' => 'sv',
            'nor' => 'no', 'norwegian' => 'no',
            'dan' => 'da', 'danish' => 'da',
            'fin' => 'fi', 'finnish' => 'fi',
            'pol' => 'pl', 'polish' => 'pl',
            'tur' => 'tr', 'turkish' => 'tr',
            'ara' => 'ar', 'arabic' => 'ar',
            'hin' => 'hi', 'hindi' => 'hi',
        ];

        if (isset($map[$language])) {
            return $map[$language];
        }

        // Regional tags pass through ("pt-br"), as do bare two-letter codes.
        return preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/', $language) === 1
            ? $language
            : 'und';
    }

    private function labelFor(string $language, ?string $title): string
    {
        $names = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian', 'ja' => 'Japanese',
            'ko' => 'Korean', 'zh' => 'Chinese', 'nl' => 'Dutch', 'sv' => 'Swedish',
            'no' => 'Norwegian', 'da' => 'Danish', 'fi' => 'Finnish', 'pl' => 'Polish',
            'tr' => 'Turkish', 'ar' => 'Arabic', 'hi' => 'Hindi', 'und' => 'Unknown',
        ];

        return $names[$language] ?? strtoupper($language);
    }

    private function looksForced(?string $text): bool
    {
        return $text !== null && stripos($text, 'forced') !== false;
    }

    private function looksSdh(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        return stripos($text, 'sdh') !== false
            || stripos($text, 'hearing') !== false
            || preg_match('/\bcc\b/i', $text) === 1;
    }

    /** Bracket characters in a filename would otherwise act as glob ranges. */
    private function escapeGlob(string $value): string
    {
        return preg_replace('/([*?\[\]])/', '[$1]', $value) ?? $value;
    }

    private function binaryExists(string $path): bool
    {
        if (str_contains($path, '/')) {
            return is_executable($path);
        }

        $process = new Process(['which', $path]);
        $process->run();

        return $process->isSuccessful();
    }

    private function ffmpegPath(): string
    {
        return (string) config('subtitles.ffmpeg_path', 'ffmpeg');
    }

    private function ffprobePath(): string
    {
        return (string) config('subtitles.ffprobe_path', 'ffprobe');
    }
}
