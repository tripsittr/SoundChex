<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Book;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Models\MediaItem;
use App\Models\Person;
use App\Services\Metadata\Contracts\MetadataSource;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Layer 1 for books — Open Library. Author, publisher, year, ISBN, subjects,
 * and cover art.
 *
 * No API key. Lookup prefers ISBN, which is exact, and falls back to a title
 * search.
 */
class OpenLibrary implements MetadataSource
{
    private const BASE = 'https://openlibrary.org';

    private const COVER_BASE = 'https://covers.openlibrary.org/b';

    /**
     * Whether the last accepted candidate matched its title verbatim, rather
     * than only after normalising punctuation, censoring, or a subtitle.
     *
     * The organizer renames files from this metadata, so a fuzzy match is
     * recorded as such and the file is left where it is.
     */
    private bool $lastMatchWasExact = false;

    /**
     * Whether this lookup was resolved from an ISBN the item already had.
     *
     * Only an ISBN that arrived with the item — scanned, typed, or read from
     * the file — proves anything. One written by the match under test does not.
     */
    private bool $matchedByIsbn = false;

    public function name(): string { return 'Open Library'; }
    public function priority(): int { return 1; }
    public function requiredSettings(): array { return []; } // no key needed

    public function supports(MediaItem $item): bool
    {
        if ($item->type !== MediaItemType::Book) {
            return false;
        }

        $meta = $item->bookMetadata;

        return filled($meta?->isbn_13)
            || filled($meta?->isbn_10)
            || filled($meta?->open_library_id)
            || filled($item->title);
    }

    public function enrich(MediaItem $item): void
    {
        $doc = $this->resolveBook($item);

        if ($doc === null) {
            return;
        }

        // Read before fillBlank writes one, for the same reason the ISBN is:
        // afterwards the match's own author would be read back as if it were
        // independent corroboration.
        $knownAuthor = $item->bookMetadata?->author;

        $this->fillBlank($item, [
            'author'          => $doc['author_name'][0] ?? null,
            'publisher'       => $doc['publisher'][0] ?? null,
            'publish_year'    => $doc['first_publish_year'] ?? null,
            'pages'           => $doc['number_of_pages_median'] ?? null,
            'language'        => $this->firstLanguage($doc),
            'isbn_13'         => $this->firstIsbn($doc, 13),
            'isbn_10'         => $this->firstIsbn($doc, 10),
            'open_library_id' => $this->editionKey($doc),
        ]);

        $this->promoteTitle($item, $doc);
        $this->writeCover($item, $doc);
        $this->writeSubjectTags($item, $doc);
        $this->writeAuthor($item, $doc);
        $this->recordConfidence($item, $knownAuthor, $doc['author_name'][0] ?? null);
    }

    /**
     * Records how certain this match was, so the organizer knows whether it
     * may rename and relocate the file.
     *
     * An ISBN lookup is unambiguous by definition. A title search is only
     * trusted when the title matched verbatim; anything reached by loosening
     * punctuation or dropping a subtitle stays put.
     */
    private function recordConfidence(MediaItem $item, ?string $knownAuthor, ?string $matchedAuthor): void
    {
        // Deliberately not "does this item have an ISBN?" — by the time this
        // runs, the match itself has written one, so reading it back would
        // treat every match as self-evidently correct. Only an ISBN the item
        // arrived with (a barcode scan, a manual entry) proves anything, and
        // that's captured in $matchedByIsbn when the lookup begins.
        $confidence = ($this->lastMatchWasExact || $this->matchedByIsbn)
            ? MatchConfidence::Exact
            : MatchConfidence::Fuzzy;

        // A title search alone can't tell two same-titled books apart, and it
        // returns whichever edition ranked first — so "Money Master the Game"
        // came back under a different author entirely. When the file told us
        // who wrote it and the match disagrees, that is exactly the case the
        // organizer must not rename a file over.
        if ($confidence === MatchConfidence::Exact
            && ! $this->matchedByIsbn
            && ! $this->authorsAgree($knownAuthor, $matchedAuthor)) {
            $confidence = MatchConfidence::Fuzzy;
        }

        // Never downgrade: an earlier source may already have identified this
        // item precisely.
        if ($item->match_confidence === MatchConfidence::Exact) {
            return;
        }

        $item->forceFill([
            'match_confidence' => $confidence,
            'matched_by' => $this->name(),
        ])->saveQuietly();
    }

    /**
     * Whether a known author and a matched one describe the same person.
     *
     * No hint means nothing to contradict, so the match stands on the title
     * alone as before — this only ever withdraws confidence, never adds it.
     *
     * Comparison is on surname plus first initial: catalogues and filenames
     * disagree constantly on middle names and initials ("Karen M. McManus" vs
     * "Karen McManus", "Robin S. Sharma" vs "Robin Sharma") while still being
     * the same author, and surnames are what the folder tree is built from.
     */
    private function authorsAgree(?string $known, ?string $matched): bool
    {
        if (blank($known) || blank($matched)) {
            return true;
        }

        $a = $this->authorKey($known);
        $b = $this->authorKey($matched);

        return $a === '' || $b === '' || $a === $b;
    }

    /** Surname + first initial, lowercased and stripped of punctuation. */
    private function authorKey(string $name): string
    {
        // "Wendorf, Patricia" — catalogues invert names; put it back.
        if (str_contains($name, ',')) {
            [$surname, $rest] = array_pad(explode(',', $name, 2), 2, '');
            $name = trim($rest) . ' ' . trim($surname);
        }

        $clean = strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $name) ?? $name);
        $parts = array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: []));

        if ($parts === []) {
            return '';
        }

        $surname = array_pop($parts);
        $initial = $parts === [] ? '' : mb_substr($parts[0], 0, 1);

        return $initial . ':' . $surname;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBook(MediaItem $item): ?array
    {
        $meta = $item->bookMetadata;

        // Reset per lookup, since the same source instance handles many items.
        $this->matchedByIsbn = false;
        $this->lastMatchWasExact = false;

        // ISBN is an exact identifier — always prefer it.
        foreach ([$meta?->isbn_13, $meta?->isbn_10] as $isbn) {
            if (blank($isbn)) {
                continue;
            }

            $doc = $this->search(['q' => 'isbn:' . $this->normalizeIsbn($isbn)]);

            if ($doc !== null) {
                // The item already carried this ISBN, so the match is
                // unambiguous rather than inferred from a title.
                $this->matchedByIsbn = true;
            }

            if ($doc !== null) {
                return $doc;
            }
        }

        if (blank($item->title)) {
            return null;
        }

        // Filenames run a title and its subtitle together, so the full string
        // matches nothing ("The 100 Startup Reinvent the Way You Make a
        // Living..."). Try progressively shorter leading phrases.
        foreach ($this->titleCandidates($item->title) as $candidate) {
            // Free-text search first. The `title=` field demands a near-exact
            // string, which real catalogue records rarely give you — editions
            // censor words ("Not Giving a F*ck"), punctuate differently
            // ("5 a.m." vs "5 AM"), or shorten ("Money" vs "Money Master the
            // Game"). Free text with the author appended tolerates all of that
            // and is far more likely to surface the actual edition.
            $doc = $this->search(
                ['q' => trim($candidate . ' ' . ($meta?->author ?? ''))],
                $candidate,
                strict: false,
            );

            if ($doc !== null) {
                return $doc;
            }

            // Then the stricter field search, which is better when a title is
            // short and common enough that free text drifts.
            $query = ['title' => $candidate];

            if (filled($meta?->author)) {
                $query['author'] = $meta->author;
            }

            $doc = $this->search($query, $candidate);

            if ($doc !== null) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * Progressively shorter forms of a title to try.
     *
     * Ordered longest-first so a precise match wins before a looser one; the
     * shortest form is capped at three words, below which almost anything
     * matches something and the result stops being trustworthy.
     *
     * @return array<int, string>
     */
    private function titleCandidates(string $title): array
    {
        $candidates = [$title];

        // Subtitles are usually introduced by a separator.
        foreach ([' - ', ': ', ' — ', ' – '] as $separator) {
            if (str_contains($title, $separator)) {
                $candidates[] = trim(explode($separator, $title)[0]);
            }
        }

        // Then simply shorten word by word.
        $words = preg_split('/\s+/', $title) ?: [];

        for ($length = count($words) - 1; $length >= 3; $length--) {
            $candidates[] = implode(' ', array_slice($words, 0, $length));
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @param array<string, mixed> $query
     * @param string|null $wantedTitle Title to prefer an exact match on.
     * @return array<string, mixed>|null
     */
    private function search(array $query, ?string $wantedTitle = null, bool $strict = true): ?array
    {
        // `fields` keeps the response small; the default returns far more than
        // the catalog stores.
        $response = $this->request('/search.json', $query + [
            // Several candidates, because relevance order alone puts sequels
            // and companion volumes above the book actually asked for.
            'limit'  => 10,
            'fields' => implode(',', [
                'key', 'title', 'author_name', 'author_key', 'first_publish_year',
                'publisher', 'isbn', 'number_of_pages_median', 'cover_i',
                'subject', 'language', 'edition_key',
            ]),
        ]);

        $docs = $response?->json('docs') ?? [];

        if (empty($docs)) {
            return null;
        }

        if (blank($wantedTitle)) {
            return $docs[0];
        }

        // "Dune" must not resolve to "Children of Dune", so the candidate title
        // still has to match. Comparison is normalised rather than literal:
        // real records censor words ("Not Giving a F*ck"), punctuate
        // differently ("5 a.m." vs "5 AM"), and vary on "&" versus "and".
        $wanted = $this->normalizeTitle($wantedTitle);

        // Reset per search; the last accepted candidate sets this, and the
        // caller reads it to decide whether the file may be renamed.
        $this->lastMatchWasExact = false;

        $exact = collect($docs)
            ->values()
            ->filter(function (array $doc) use ($wanted, $strict): bool {
                $candidate = $this->normalizeTitle($doc['title'] ?? '');

                if ($candidate === $wanted) {
                    $this->lastMatchWasExact = true;

                    return true;
                }

                // A censored spelling ("f*ck") normalises to "fck", which will
                // never equal "fuck". Comparing the two with vowels removed
                // makes them agree without loosening anything else.
                if ($this->skeleton($candidate) === $this->skeleton($wanted)) {
                    $this->lastMatchWasExact = true;

                    return true;
                }

                if ($strict || $wanted === '' || $candidate === '') {
                    return false;
                }

                // Either side can be the shorter one, and both cases are the
                // same book:
                //
                //   catalogue shorter — the record is "Money" while the file
                //   is "Money Master the Game"; publishers routinely index
                //   under a bare title and push the rest into a subtitle.
                //
                //   catalogue longer — the record carries the full subtitle
                //   the filename dropped.
                //
                // A shared opening of at least three words is what keeps this
                // from matching on "the" alone.
                $shorter = mb_strlen($candidate) <= mb_strlen($wanted) ? $candidate : $wanted;
                $longer = $shorter === $candidate ? $wanted : $candidate;

                if (! str_starts_with($longer, $shorter)) {
                    return false;
                }

                if (str_word_count($shorter) < 3) {
                    return false;
                }

                // Cap the gap so a boxed set that merely opens with the right
                // words can't win — adopting its name would rename the file
                // to a 150-character string.
                return mb_strlen($longer) - mb_strlen($shorter) <= 60;
            });

        // Popular non-fiction attracts a shelf of summaries, workbooks, and
        // "conversation starters" that reuse the exact title. Matching one of
        // those doesn't just mislabel the book — the organizer then renames
        // and refiles the actual file under the wrong author.
        $exact = $exact->reject(function (array $doc): bool {
            $author = strtolower($doc['author_name'][0] ?? '');
            $title = strtolower($doc['title'] ?? '');

            foreach (['summary', 'summaries', 'workbook', 'analysis',
                      'conversation starters', 'key takeaways', 'sidekick',
                      'instaread', 'blinkist'] as $marker) {
                if (str_contains($author, $marker) || str_contains($title, $marker)) {
                    return true;
                }
            }

            return false;
        });

        if ($exact->isEmpty()) {
            // No exact match usually means the shelf is summaries, parodies,
            // and translations of the real book. Picking the top of that pile
            // produces confidently wrong metadata, so decline instead — the
            // item stays unenriched and visible in Needs Review.
            return null;
        }

        // Open Library's own ordering is a good relevance signal, so it's kept
        // rather than re-sorted. Year is only a tiebreaker among equally
        // relevant hits, and only when several actually exist — sorting by it
        // outright promotes a 1996 book that happens to share a title over the
        // 2017 one the search was actually about.
        return $exact
            ->sortBy(fn (array $doc, int $index) => [
                // A record with no author is a stub; real editions win.
                blank($doc['author_name'][0] ?? null) ? 1 : 0,
                $index,
            ])
            ->first();
    }

    /**
     * Reduces a title to a form two catalogue records can be compared on.
     *
     * Editions of the same book disagree constantly: one censors a word
     * ("F*ck"), another punctuates differently ("5 a.m." vs "5 AM"), a third
     * spells out "and" where its sibling used "&". Comparing raw strings makes
     * all of those look like different books.
     */
    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower(trim($title));

        // Censor marks replace a letter rather than sit alongside one, so
        // simply deleting them turns "f*ck" into "fck" — which still doesn't
        // equal "fuck". Dropping vowels from both sides instead reduces each
        // title to its consonant skeleton, where the censored and uncensored
        // spellings finally agree.
        $title = str_replace(['*', '#', '@', '!'], '', $title);

        $title = str_replace(['&', '+'], ' and ', $title);

        // Everything else that isn't a letter, number, or space is noise for
        // comparison purposes.
        $title = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title) ?? $title;

        // A leading article is dropped on plenty of records.
        $title = preg_replace('/^(the|a|an)\s+/', '', $title) ?? $title;

        return trim(preg_replace('/\s+/', ' ', $title) ?? $title);
    }

    /**
     * A title reduced to its consonants.
     *
     * Censored and uncensored spellings of the same word differ only in the
     * replaced letter, which is almost always a vowel, so the skeletons match
     * where the full strings don't.
     */
    private function skeleton(string $title): string
    {
        return preg_replace('/[aeiou\s]+/u', '', $title) ?? $title;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $path, array $query): ?Response
    {
        $response = Http::acceptJson()
            ->withHeaders([
                // Open Library asks that clients identify themselves.
                'User-Agent' => sprintf(
                    '%s/1.0 (%s)',
                    config('app.name', 'SoundChex'),
                    config('app.url', 'https://github.com/tripsittr/SoundChex'),
                ),
            ])
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->get(self::BASE . $path, $query);

        return $response->successful() ? $response : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function fillBlank(MediaItem $item, array $values): void
    {
        $meta = $item->bookMetadata;

        if ($meta === null) {
            return;
        }

        $dirty = false;

        foreach ($values as $field => $value) {
            if (blank($meta->{$field}) && filled($value)) {
                $meta->{$field} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $meta->saveQuietly();
        }
    }

    /**
     * Open Library returns ISBN-10 and ISBN-13 mixed in one array.
     *
     * @param array<string, mixed> $doc
     */
    private function firstIsbn(array $doc, int $length): ?string
    {
        foreach ($doc['isbn'] ?? [] as $isbn) {
            $clean = $this->normalizeIsbn($isbn);

            if (strlen($clean) === $length) {
                return $clean;
            }
        }

        return null;
    }

    private function normalizeIsbn(string $isbn): string
    {
        return strtoupper(preg_replace('/[^0-9Xx]/', '', $isbn) ?? '');
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function editionKey(array $doc): ?string
    {
        // "/works/OL45804W" → "OL45804W"
        if (filled($doc['key'] ?? null)) {
            return basename($doc['key']);
        }

        return $doc['edition_key'][0] ?? null;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function firstLanguage(array $doc): ?string
    {
        $language = $doc['language'][0] ?? null;

        return filled($language) ? substr($language, 0, 8) : null;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function promoteTitle(MediaItem $item, array $doc): void
    {
        $canonical = $doc['title'] ?? null;

        if (blank($canonical) || strcasecmp($canonical, $item->title) === 0) {
            return;
        }

        // The organizer renames files from the title, so a bad one here
        // becomes a bad filename on disk. Refuse anything implausibly long —
        // that's a boxed set or omnibus record, not this book's name.
        if (mb_strlen($canonical) > 120) {
            return;
        }

        // An all-lowercase catalogue entry reads as broken next to properly
        // cased titles, and looks worse still as a filename.
        if ($canonical === mb_strtolower($canonical)) {
            $canonical = mb_convert_case($canonical, MB_CASE_TITLE, 'UTF-8');
        }

        $item->title = $canonical;
        $item->saveQuietly();
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function writeCover(MediaItem $item, array $doc): void
    {
        if (filled($item->cover_image_url) || blank($doc['cover_i'] ?? null)) {
            return;
        }

        // -L is the large variant; covers are served straight from their CDN.
        $item->cover_image_url = self::COVER_BASE . '/id/' . $doc['cover_i'] . '-L.jpg';
        $item->saveQuietly();
    }

    /**
     * Subjects are Open Library's closest thing to genres, but the list runs
     * long and gets specific ("Fiction, American -- 20th century"), so only
     * the leading few are kept.
     *
     * @param array<string, mixed> $doc
     */
    private function writeSubjectTags(MediaItem $item, array $doc): void
    {
        $aliases = config('metadata_rules.genre_aliases', []);

        // Subjects arrive both as single values and as comma-joined strings
        // ("Fiction, Science Fiction, General"), so split before deduping —
        // otherwise the same subject lands several times in different groupings.
        $canonicalSubjects = collect($doc['subject'] ?? [])
            ->flatMap(fn (string $subject) => explode(',', $subject))
            ->map(fn (string $subject) => trim($subject))
            ->filter()
            // Skip compound cataloguing entries; they read as noise.
            ->reject(fn (string $subject) => str_contains($subject, '--'))
            // Overly generic buckets add nothing next to a real genre.
            ->reject(fn (string $subject) => in_array(strtolower($subject), [
                'general', 'fiction in english', 'open library staff picks',
                'new york times reviewed', 'accessible book', 'large type books',
            ], true))
            ->map(fn (string $subject) => $aliases[strtolower($subject)]
                ?? str($subject)->title()->toString())
            ->unique(fn (string $subject) => strtolower($subject))
            ->take(5);

        foreach ($canonicalSubjects as $canonical) {
            $exists = $item->tags()
                ->where('type', 'genre')
                ->whereRaw('lower(value) = ?', [strtolower($canonical)])
                ->exists();

            if ($exists) {
                continue;
            }

            $item->tags()->create([
                'type'   => 'genre',
                'value'  => $canonical,
                'source' => MediaTagSource::Api,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function writeAuthor(MediaItem $item, array $doc): void
    {
        $name = $doc['author_name'][0] ?? null;

        if (blank($name) || $item->people()->exists()) {
            return;
        }

        $person = Person::firstOrCreate(['name' => $name]);

        $item->people()->attach($person->id, [
            'role'       => 'author',
            'sort_order' => 0,
        ]);
    }
}
