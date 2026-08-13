<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shareable scanning links, so a door can be staffed by someone without an
 * account.
 *
 * The venue scanner at /admin/scanner assumes the person holding the tablet is
 * staff, signed in, and inside the field-office scope of their own user. That
 * assumption breaks at almost every real session: the door is worked by a
 * training aide, a student volunteer or the host agency's own clerk, on their
 * own phone, and issuing them a staff account to tap a camera for two hours is
 * both slow and far too much authority.
 *
 * A scan link is the narrow grant that replaces it. It names exactly one
 * training, it expires, it can be revoked mid-session, and it carries the
 * scoping of whoever issued it rather than of whoever holds it — so a link
 * cannot be used to read a roster its issuer could not read themselves.
 *
 * The token alone is deliberately *not* enough. Links get pasted into group
 * chats and printed on handover sheets, so a short code is required alongside
 * it; the token identifies the link, the code proves the holder was actually
 * given it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();

            // The public half of the credential, in the URL. Unique and
            // indexed because every request on the station resolves through it.
            $table->string('token', 64)->unique();

            // The private half, hashed. Stored hashed for the same reason a
            // password is: this table is dumped into backups and read by more
            // people than the issuer, and a plaintext code beside its own token
            // would make the pair worthless.
            $table->string('code_hash');

            // Attribution. Attendance recorded through this link is written as
            // the issuer's action, so this is a foreign key rather than a name
            // — AttendanceService::checkIn needs a real User.
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();

            // "Front door", "Hall B" — printed on the handover sheet so an
            // office running three doors can tell its links apart.
            $table->string('label')->nullable();

            /*
             * dateTime, not timestamp, and this is load-bearing on MySQL.
             *
             * MySQL attaches `DEFAULT CURRENT_TIMESTAMP ON UPDATE
             * CURRENT_TIMESTAMP` to the first NOT NULL TIMESTAMP column in a
             * table that declares no default of its own. Under that rule every
             * write to this row — and `last_used_at` is written on the very
             * first unlock — silently reset the expiry to now, so a link died
             * the instant somebody used it.
             *
             * SQLite has no such behaviour, so the test suite was perfectly
             * green while the feature was broken against the real database.
             * DATETIME carries no implicit default or auto-update on either
             * engine, which is the only reason this column can be trusted.
             */
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();

            // Diagnostics for the issuer: whether the link was ever picked up,
            // and when it was last actually scanning.
            $table->dateTime('last_used_at')->nullable();

            $table->timestamps();

            // The admin screen lists a training's links newest first.
            $table->index(['training_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_links');
    }
};
