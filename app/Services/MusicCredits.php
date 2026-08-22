<?php

namespace App\Services;

use App\Models\MediaItem;
use App\Models\Person;

/**
 * Who played on a track, and in what capacity.
 *
 * Credits live in `media_item_person`, the same table that has carried author,
 * actor and director since books and film were built. Music is late to it, not
 * separate from it.
 *
 * Two sources, in order of trust. MusicBrainz returns each artist as its own
 * record with a stable id, which is a fact; a credit string has to be parsed,
 * which is a guess. The guess is only used where there is no fact.
 */
class MusicCredits
{
    /** The artist a track is filed under for browsing. */
    public const PRIMARY = 'primary_artist';

    /** Everyone else named in the credit. */
    public const FEATURED = 'featured_artist';

    public function __construct(private ArtistCredits $credits) {}

    /**
     * Writes credits parsed from the free-text artist string.
     *
     * The fallback path: used where MusicBrainz found no match, which is most
     * of a self-hosted library.
     *
     * @return list<string> the names credited, primary first
     */
    public function fromCreditString(MediaItem $item, ?string $credit, bool $dryRun = false): array
    {
        $names = $this->credits->all($credit);

        if ($names === []) {
            return [];
        }

        if (! $dryRun) {
            $this->write($item, array_map(
                fn (string $name) => ['name' => $name, 'mbid' => null],
                $names,
            ));
        }

        return $names;
    }

    /**
     * Writes credits from MusicBrainz's `artist-credit`.
     *
     * Each entry carries the artist's own name and id rather than a joined
     * string, so nothing is parsed and nothing is guessed. Billing order is
     * the order MusicBrainz returns.
     *
     * @param  array<int, array<string, mixed>>  $artistCredit
     * @return list<string>
     */
    public function fromMusicBrainz(MediaItem $item, array $artistCredit, bool $dryRun = false): array
    {
        $people = [];

        foreach ($artistCredit as $entry) {
            $name = $entry['artist']['name'] ?? $entry['name'] ?? null;

            if (blank($name)) {
                continue;
            }

            $people[] = [
                'name' => $name,
                'mbid' => $entry['artist']['id'] ?? null,
            ];
        }

        if ($people === []) {
            return [];
        }

        if (! $dryRun) {
            $this->write($item, $people);
        }

        return array_map(fn (array $p) => $p['name'], $people);
    }

    /**
     * The name to file this track under, without writing anything.
     */
    public function primaryFor(?string $credit): ?string
    {
        return $this->credits->primary($credit);
    }

    /**
     * @param  list<array{name: string, mbid: ?string}>  $people
     */
    private function write(MediaItem $item, array $people): void
    {
        // Replaced rather than added to. Enrichment re-runs, and a corrected
        // credit should remove the artist it was wrong about rather than leave
        // both attached.
        $item->people()->wherePivotIn('role', [self::PRIMARY, self::FEATURED])->detach();

        foreach ($people as $index => $person) {
            $model = $this->person($person['name'], $person['mbid']);

            $item->people()->attach($model->id, [
                'role' => $index === 0 ? self::PRIMARY : self::FEATURED,
                // Billing order is part of a credit, not decoration: "Pouya,
                // Germ, $uicideboy$" is a different record from the same three
                // names in another order.
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Finds or creates the person, preferring the MusicBrainz id.
     *
     * Matching on id first means two artists sharing a name stay separate, and
     * one artist under a renamed record stays single.
     */
    private function person(string $name, ?string $mbid): Person
    {
        if (filled($mbid)) {
            $existing = Person::where('musicbrainz_artist_id', $mbid)->first();

            if ($existing !== null) {
                // A name change at the source is worth taking.
                if ($existing->name !== $name) {
                    $existing->update(['name' => $name]);
                }

                return $existing;
            }
        }

        // No id matched. Reuse a row with this name only if it has no id of
        // its own — otherwise this is a second artist who happens to share the
        // name, and merging them would put one's work on the other's page.
        $person = Person::where('name', $name)
            ->whereNull('musicbrainz_artist_id')
            ->first();

        if ($person === null) {
            return Person::create(array_filter([
                'name' => $name,
                'musicbrainz_artist_id' => $mbid,
            ]));
        }

        // An id learned later attaches to the row already carrying the name.
        if (filled($mbid)) {
            $person->update(['musicbrainz_artist_id' => $mbid]);
        }

        return $person;
    }
}
