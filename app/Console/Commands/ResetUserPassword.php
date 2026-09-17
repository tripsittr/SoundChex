<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Resets an account password from the server console.
 *
 * A self-hosted install has no password-reset email configured by default, so
 * being locked out otherwise means editing the database by hand. This is the
 * supported way back in — it requires shell access to the machine, which is
 * already full control of the library.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'user:password
        {email? : The account to reset. Omit to be prompted.}
        {--password= : Use this password instead of generating one.}';

    protected $description = 'Reset an account password';

    /** Readable words — a passphrase someone can retype from a screen. */
    private const WORDS = [
        'harbor', 'trellis', 'lantern', 'meadow', 'quarry',
        'cinder', 'willow', 'anchor', 'cobalt', 'summit',
    ];

    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->askForEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->generate();

        $user->forceFill(['password' => Hash::make($password)])->save();

        // Verified rather than assumed: a silent failure here leaves someone
        // locked out believing they aren't.
        $works = Auth::validate(['email' => $user->email, 'password' => $password]);

        if (! $works) {
            $this->error('The password was written but does not authenticate. Check for a custom auth provider.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Password reset for ' . $user->email);
        $this->newLine();
        $this->line('  <fg=black;bg=yellow> ' . $password . ' </>');
        $this->newLine();
        $this->comment('Shown once. Sign in and change it under your account.');

        return self::SUCCESS;
    }

    private function askForEmail(): ?string
    {
        $accounts = User::orderBy('id')->get(['email', 'name']);

        if ($accounts->isEmpty()) {
            $this->error('There are no accounts on this server.');

            return null;
        }

        return $this->choice(
            'Which account?',
            $accounts->pluck('email')->all(),
            0,
        );
    }

    /**
     * Four elements, mixed case and digits — long enough to be strong, plain
     * enough to read off a screen and type on a phone.
     */
    private function generate(): string
    {
        return self::WORDS[random_int(0, 9)]
            . '-' . strtoupper(self::WORDS[random_int(0, 9)])
            . '-' . random_int(10, 99)
            . '-' . self::WORDS[random_int(0, 9)];
    }
}
