<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Import\Sources;

use App\Services\Import\ImportedTrack;

/**
 * A streaming service SoundChex can pull playlists from (S-312).
 *
 * One implementation per service (Spotify, Apple Music, YouTube Music). Each
 * turns the service's playlists into the same normalised {@see ImportedTrack}s
 * the file parsers produce, so the matcher and the import flow do not care where
 * a playlist came from.
 *
 * A source is *configured* when the operator has set its app credentials, and
 * *connected* when a user has authorised it. Both are separate from whether the
 * class exists, so the UI can show "set this up" vs "connect" vs "ready".
 */
interface PlaylistSource
{
    /** A stable key: 'spotify', 'apple_music', 'youtube_music'. */
    public function key(): string;

    /** A human name for the service. */
    public function name(): string;

    /**
     * Whether the operator has configured this source's app credentials, so a
     * user could connect it at all.
     */
    public function isConfigured(): bool;

    /**
     * Whether the current user has connected (authorised) this source.
     */
    public function isConnected(): bool;

    /**
     * The URL to send the user to, to authorise the connection (the OAuth
     * consent screen). `$redirectUri` is where the service returns them.
     */
    public function authorizationUrl(string $redirectUri, string $state): string;

    /**
     * Complete the connection from the code the service redirected back with,
     * storing whatever token the source needs for later calls. Throws on failure.
     */
    public function connect(string $code, string $redirectUri): void;

    /** Forget the stored authorisation. */
    public function disconnect(): void;

    /**
     * The connected user's playlists — id and name, for them to choose one.
     *
     * @return array<int, array{id: string, name: string, track_count: ?int}>
     */
    public function playlists(): array;

    /**
     * One playlist as its name and ordered tracks.
     *
     * @return array{name: string, tracks: array<int, ImportedTrack>}
     */
    public function fetch(string $playlistId): array;
}
