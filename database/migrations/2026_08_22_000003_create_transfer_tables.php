<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving a server to another machine.
 *
 * Three tables, and the middle one is the point: `transfer_items` is both the
 * work list and the bookmark. 8,315 files over a home connection is hours, and
 * hours is long enough that the transfer will be interrupted — so resuming has
 * to be the same question as starting, asked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What a receiving server asked for, and whether anyone said yes.
        //
        // Lives on the *source*. Nothing can be read from a server until a
        // person there approves the request, which is what makes this safer
        // than a token copied between machines: a copied token exists whether
        // anyone is watching or not.
        Schema::create('transfer_requests', function (Blueprint $table) {
            $table->id();

            // Where it came from, recorded server-side from the request rather
            // than claimed in the body.
            $table->string('ip', 45);

            // What the requester says it is. Untrusted, and shown as such —
            // it is a hint for the person approving, not an identity.
            $table->string('device_name')->nullable();
            $table->string('platform')->nullable();

            // What was asked for: metadata, files, profiles, settings.
            $table->json('wants');

            // Shown on both screens. Matching it is how someone knows the
            // request in front of them is the one they just started, rather
            // than another arriving at the same moment.
            $table->string('code', 8);

            $table->string('state')->default('pending');
            $table->string('denied_reason')->nullable();

            // An unapproved request left forever is a way in.
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();

            // The token minted on approval, so revoking the request revokes it.
            $table->foreignId('token_id')->nullable();

            $table->timestamps();

            $table->index('state');
        });

        // One transfer, from the receiving side's point of view.
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();

            // Where the data is coming from, and how we are allowed to ask.
            $table->string('source_url');
            $table->unsignedBigInteger('remote_request_id')->nullable();
            $table->text('token')->nullable();

            $table->json('wants');

            $table->string('state')->default('requested');
            $table->string('last_error')->nullable();

            // Totals from the manifest, so progress means something before the
            // first file has finished.
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });

        // One file. Both the work list and the bookmark: anything not complete
        // is what is left to do.
        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();

            // The id on the *source*, which is what the file endpoint is asked
            // for. The local id is different and may not exist yet.
            $table->unsignedBigInteger('remote_id');

            $table->string('path');
            $table->string('expected_hash')->nullable();
            $table->unsignedBigInteger('expected_bytes')->default(0);

            // pending, transferring, complete, failed, skipped
            $table->string('state')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            // How far a partial download got, so a resume continues at the byte
            // rather than starting the file again.
            $table->unsignedBigInteger('bytes_received')->default(0);

            $table->timestamps();

            // The question a resume asks: what is left, for this transfer.
            $table->index(['transfer_id', 'state']);

            // A file is only fetched once per transfer.
            $table->unique(['transfer_id', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('transfer_requests');
    }
};
