<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the query that runs on every single page load.
 *
 * `HandleInertiaRequests::share()` counts unread notifications for the signed-in
 * user on every request — it is the number on the bell — and Laravel's stock
 * notifications table indexes only `(notifiable_type, notifiable_id)`. The
 * `read_at IS NULL` half of the predicate is therefore not covered: MySQL finds
 * the user's rows by index and then filters them one by one. That is free while
 * a participant has six notifications and steadily less so as they accumulate,
 * and the cost lands on every page in the application rather than on one screen.
 *
 * Adding `read_at` to the tail makes it a covering index for the count. The
 * existing two-column index is left in place: it is a prefix of this one and so
 * strictly redundant for lookups, but it is the index Laravel's own
 * `DatabaseNotification` relations are written against, and dropping a
 * framework index to save a few kilobytes is a trade in the wrong direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_notifiable_unread_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_unread_index');
        });
    }
};
