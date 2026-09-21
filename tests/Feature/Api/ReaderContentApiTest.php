<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Jobs\ExtractBookContentJob;
use App\Models\BookAsset;
use App\Models\BookContent;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\Books\BookTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reflowable reader content endpoint (S-295): a book served as ordered text
 * the device renders, extraction kicked off on demand.
 */
class ReaderContentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->owner = Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);
        Sanctum::actingAs($this->user, ['profile:'.$this->owner->id]);
    }

    public function test_a_fast_book_extracts_in_the_request_and_returns_its_text(): void
    {
        // A real EPUB (fast — no OCR) is extracted synchronously and comes back
        // ready in one call, no queue, no polling — the fix for the reader that
        // hung on "preparing this book".
        Queue::fake();
        $book = $this->book(name: 'book.epub');
        Storage::disk('local')->put($book->file_path, $this->minimalEpub());

        $this->getJson(route('api.items.reader.content', $book))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('chapters.0.text', fn ($t): bool => str_contains((string) $t, 'Ishmael'));

        Queue::assertNotPushed(ExtractBookContentJob::class);
    }

    public function test_a_fast_book_with_no_text_settles_as_empty(): void
    {
        // A "fast" book whose bytes aren't a real EPUB extracts to nothing and is
        // reported empty rather than looping — the reader stops asking.
        $book = $this->book(name: 'book.epub'); // placeholder bytes, not a real epub

        $this->getJson(route('api.items.reader.content', $book))
            ->assertOk()
            ->assertJsonPath('status', 'empty');
    }

    public function test_it_returns_the_ordered_chapters_once_extracted(): void
    {
        $book = $this->book();
        BookContent::create(['media_item_id' => $book->id, 'position' => 2, 'title' => 'Two', 'text' => 'Second.']);
        BookContent::create(['media_item_id' => $book->id, 'position' => 1, 'title' => 'One', 'text' => 'First.']);

        $this->getJson(route('api.items.reader.content', $book))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('chapters.0.position', 1)
            ->assertJsonPath('chapters.0.title', 'One')
            ->assertJsonPath('chapters.0.text', 'First.')
            ->assertJsonPath('chapters.1.position', 2);
    }

    public function test_content_carries_a_books_images_keyed_by_page(): void
    {
        $book = $this->book();
        BookContent::create(['media_item_id' => $book->id, 'position' => 1, 'title' => 'Page 1', 'text' => 'Scan.']);

        $assetPath = 'book-assets/'.$book->id.'/img-001.png';
        Storage::disk('local')->put($assetPath, 'PNGDATA');
        $asset = BookAsset::create([
            'media_item_id' => $book->id, 'page' => 1, 'path' => $assetPath,
            'width' => 800, 'height' => 1200, 'format' => 'png', 'is_significant' => true,
        ]);

        $this->getJson(route('api.items.reader.content', $book))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('images.0.page', 1)
            ->assertJsonPath('images.0.url', route('api.items.reader.asset', ['item' => $book, 'asset' => $asset]));

        // And the image itself is served, token-authed.
        $this->get(route('api.items.reader.asset', ['item' => $book, 'asset' => $asset]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_non_book_has_no_content_endpoint(): void
    {
        $movie = MediaItem::create([
            'user_id' => $this->user->id, 'type' => MediaItemType::Movie,
            'title' => 'A Film', 'file_path' => 'library/a.mkv', 'owned' => true,
        ]);

        $this->getJson(route('api.items.reader.content', $movie))->assertNotFound();
    }

    public function test_the_extractor_reads_an_epub_into_chapters(): void
    {
        $book = $this->book(name: 'book.epub');
        Storage::disk('local')->put($book->file_path, $this->minimalEpub());

        $count = app(BookTextExtractor::class)->extract($book->fresh());

        $this->assertSame(2, $count);
        $chapters = $book->bookContents()->orderBy('position')->get();
        $this->assertStringContainsString('Call me Ishmael', $chapters[0]->text);
        $this->assertStringContainsString('Chapter One', (string) $chapters[0]->title);
        $this->assertStringContainsString('the second chapter', $chapters[1]->text);
    }

    private function book(?string $name = 'book.epub'): MediaItem
    {
        $path = "books/{$name}";
        Storage::disk('local')->put($path, 'placeholder');

        $book = MediaItem::create([
            'user_id' => $this->user->id, 'type' => MediaItemType::Book,
            'title' => 'A Novel', 'file_path' => $path, 'owned' => true,
        ]);
        $book->bookMetadata()->create(['author' => 'An Author']);

        return $book;
    }

    /**
     * A minimal but valid EPUB (a zip with container.xml, an OPF spine, and two
     * XHTML chapters), built in memory so the extractor has a real file to parse.
     */
    private function minimalEpub(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epub').'.epub';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE);

        $zip->addFromString('META-INF/container.xml', <<<'XML'
            <?xml version="1.0"?>
            <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
              <rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>
            </container>
            XML);

        $zip->addFromString('OEBPS/content.opf', <<<'XML'
            <?xml version="1.0"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="3.0">
              <manifest>
                <item id="c1" href="ch1.xhtml" media-type="application/xhtml+xml"/>
                <item id="c2" href="ch2.xhtml" media-type="application/xhtml+xml"/>
              </manifest>
              <spine><itemref idref="c1"/><itemref idref="c2"/></spine>
            </package>
            XML);

        $zip->addFromString('OEBPS/ch1.xhtml',
            '<html><body><h1>Chapter One</h1><p>Call me Ishmael.</p></body></html>');
        $zip->addFromString('OEBPS/ch2.xhtml',
            '<html><body><h1>Chapter Two</h1><p>This is the second chapter.</p></body></html>');

        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
