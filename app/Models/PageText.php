<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recognised text for one page of a scanned book.
 *
 * Rows are created lazily — a page only gets one once something has needed its
 * text. A row with status `skipped` records that the page already had usable
 * embedded text, which stops it being re-examined on every open.
 */
class PageText extends Model
{
    protected $fillable = [
        'media_item_id',
        'page',
        'text',
        'words',
        'confidence',
        'status',
        'engine',
    ];

    protected $casts = [
        'words' => 'array',
        'page' => 'integer',
        'confidence' => 'integer',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** Recognised successfully and has words to lay over the page. */
    public function isUsable(): bool
    {
        return $this->status === 'complete' && filled($this->words);
    }

    /**
     * Read, but there was nothing on the page.
     *
     * A distinct state from 'failed': a section break or the back of a cover
     * is a legitimate result, and treating it as an error made the reader
     * retry it forever.
     */
    public function isBlank(): bool
    {
        return $this->status === 'blank';
    }

    /** No further attempt will change the answer. */
    public function isSettled(): bool
    {
        return in_array($this->status, ['complete', 'blank', 'skipped'], true);
    }

    /**
     * Confidence low enough to be worth flagging.
     *
     * Tesseract reports high nineties on clean type; anything under about 70
     * usually means a poor scan, and the text will read as garbled.
     */
    public function isLowConfidence(): bool
    {
        return $this->confidence !== null && $this->confidence < 70;
    }
}
