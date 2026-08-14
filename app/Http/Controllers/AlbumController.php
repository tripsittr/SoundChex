<?php

namespace App\Http\Controllers;

use App\Services\AlbumBrowser;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Albums as a browsable thing in their own right.
 *
 * An album is derived from track tags rather than stored, so it is addressed
 * by artist and title in the query string instead of by id. Names contain
 * slashes, question marks and colons often enough that a path segment would
 * mean escaping every one of them.
 */
class AlbumController extends Controller
{
    public function __construct(private AlbumBrowser $albums) {}

    public function index(): View
    {
        return view('media.albums', [
            'albums' => $this->albums->paginate(),
        ]);
    }

    public function show(Request $request): View
    {
        $data = $request->validate([
            'artist' => ['required', 'string', 'max:500'],
            'album' => ['required', 'string', 'max:500'],
        ]);

        $tracks = $this->albums->tracks($data['artist'], $data['album']);

        // An album whose every track is hidden by the rating cap must read as
        // absent, not as an empty album — the difference tells a capped
        // profile exactly what it is being kept from.
        abort_if($tracks->isEmpty(), 404);

        return view('media.album', [
            'artist' => $data['artist'],
            'album' => $data['album'],
            'tracks' => $tracks,
            'multiDisc' => $this->albums->isMultiDisc($tracks),
            'duration' => $this->albums->duration($tracks),
        ]);
    }
}
