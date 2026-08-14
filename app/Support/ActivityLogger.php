<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail.
 *
 * Called from the services rather than from controllers or model events. The
 * services are already the single choke point every state change passes
 * through — that is what makes the trail complete without a listener on every
 * model, and it means the log entry is written knowing *why* something changed,
 * which a generic `updated` event never does.
 *
 * Logging must never be the reason an operation fails. A write that throws is
 * swallowed and reported to the application log instead: losing an audit row is
 * bad, but rolling back a verified payment because the audit table was
 * unavailable is worse.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
        ?User $causer = null,
    ): ?ActivityLog {
        try {
            $actor = $causer ?? (Auth::check() ? Auth::user() : null);

            return ActivityLog::create([
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'causer_id' => $actor?->getKey(),
                'causer_name' => $actor?->name,
                'action' => $action,
                'description' => $description,
                'properties' => $properties === [] ? null : $properties,
                // Absent when the action came from a queued job or a console
                // command, which have no request behind them.
                'ip_address' => self::hasRequest() ? Request::ip() : null,
                'user_agent' => self::hasRequest() ? Request::userAgent() : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write an activity log entry.', [
                'action' => $action,
                'subject' => $subject ? $subject->getMorphClass().'#'.$subject->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a status change, capturing both sides of it.
     *
     * The commonest shape by far, and worth its own entry point so that every
     * service spells "from/to" the same way — a trail where half the entries
     * say `old`/`new` and the other half say `from`/`to` cannot be filtered.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function recordTransition(
        string $action,
        Model $subject,
        string|\BackedEnum|null $from,
        string|\BackedEnum|null $to,
        ?string $description = null,
        array $properties = [],
        ?User $causer = null,
    ): ?ActivityLog {
        return self::record(
            $action,
            $subject,
            $description,
            [
                'from' => $from instanceof \BackedEnum ? $from->value : $from,
                'to' => $to instanceof \BackedEnum ? $to->value : $to,
                ...$properties,
            ],
            $causer,
        );
    }

    /**
     * Whether there is an HTTP request to read an address off.
     *
     * Queued jobs run with a request bound in some configurations but with
     * meaningless values in it, so this checks for a real client address rather
     * than merely for the binding.
     */
    private static function hasRequest(): bool
    {
        return app()->runningInConsole() === false && Request::ip() !== null;
    }
}
