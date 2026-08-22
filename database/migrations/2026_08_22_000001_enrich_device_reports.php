<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enough in a device report to know which device it came from.
 *
 * A report carried a random id, forty characters of user-agent, the served
 * build and the origin. That is enough to group one device's reports together
 * and nothing else: two iPhones are indistinguishable, and "which device was
 * this?" has no answer at all.
 *
 * The shell build matters most of the four. The served layer updates itself
 * within a minute and reports its build; the Tauri shell is compiled into the
 * binary and cannot, so a device can be current on one and months behind on
 * the other — and reporting only the served build hides precisely the case
 * where a reinstall is the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_reports', function (Blueprint $table) {
            // What the user calls it. Asked for rather than derived: a browser
            // cannot read the device name, and guessing from a user agent
            // produces "iPhone" for every iPhone in the house.
            $table->string('name')->nullable()->after('device');

            // phone, tablet, desktop — narrowed from the user agent, which is
            // the one thing it is reliable about.
            $table->string('kind')->nullable()->after('name');

            // Recorded server-side from the request, never sent by the client:
            // a device reporting its own address would be reporting whatever
            // it felt like.
            $table->string('ip', 45)->nullable()->after('kind');

            // The embedded shell, which the served build says nothing about.
            $table->string('shell')->nullable()->after('build');

            // The app's own version, as opposed to the asset build.
            $table->string('app_version')->nullable()->after('shell');

            // Indexed because filtering by device type is the second thing
            // anyone will do with a table of reports. `device` is already
            // indexed from when the table was created.
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('device_reports', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn(['name', 'kind', 'ip', 'shell', 'app_version']);
        });
    }
};
