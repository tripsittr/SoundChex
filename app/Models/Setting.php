<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A server-wide setting, with API keys stored encrypted at rest.
 *
 * Encryption is detected from the stored value rather than from the
 * `is_encrypted` flag. Mass assignment sets attributes in array order, so a
 * flag that arrives after `value` isn't visible while the value is being
 * written — that ordering dependency silently produced rows whose flag and
 * contents disagreed, and whose keys therefore came back as ciphertext.
 *
 * The flag is kept as a record of intent; the value is the source of truth.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    public function getValueAttribute(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Decrypting is attempted whenever the value looks like a Laravel
        // payload, so a mismatched flag can't strand a key as ciphertext.
        // Anything that isn't one is returned as stored.
        if (! $this->looksEncrypted($raw)) {
            return $raw;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            // A value that merely resembles a payload — or one encrypted under
            // a since-rotated APP_KEY — is better returned verbatim than
            // thrown away.
            return $raw;
        }
    }

    public function setValueAttribute(?string $plain): void
    {
        if ($plain === null) {
            $this->attributes['value'] = null;

            return;
        }

        // Re-encrypting an existing payload would double-wrap it.
        if ($this->looksEncrypted($plain)) {
            $this->attributes['value'] = $plain;

            return;
        }

        $this->attributes['value'] = $this->shouldEncrypt()
            ? Crypt::encryptString($plain)
            : $plain;
    }

    /**
     * Whether this setting is meant to be encrypted.
     *
     * Reads the pending attribute directly: during mass assignment the cast
     * accessor may not reflect a flag that hasn't been applied yet.
     */
    private function shouldEncrypt(): bool
    {
        return (bool) ($this->attributes['is_encrypted'] ?? false);
    }

    /**
     * Laravel's encrypter emits base64-encoded JSON carrying iv/value/mac, so
     * a payload always starts with the encoded opening brace.
     */
    private function looksEncrypted(string $value): bool
    {
        if (! str_starts_with($value, 'eyJ')) {
            return false;
        }

        $decoded = json_decode((string) base64_decode($value, true), true);

        return is_array($decoded)
            && isset($decoded['iv'], $decoded['value'], $decoded['mac']);
    }
}
