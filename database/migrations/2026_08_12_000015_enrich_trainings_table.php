<?php

use App\Enums\TrainingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings v1's richer training description across.
 *
 * `duration_days` is the load-bearing one: per-day attendance needs to know how
 * many days a training runs, and deriving it from the timestamps every time
 * would silently break the moment a training's dates are edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            // Nullable-unique rather than required: existing rows predate it,
            // and HRD may leave it blank on a draft.
            $table->string('training_code', 50)->nullable()->unique()->after('id');
            $table->string('category', 100)->nullable()->after('description');
            $table->string('mode')->default(TrainingMode::FaceToFace->value)->after('venue');
            $table->unsignedSmallInteger('duration_days')->default(1)->after('ends_at');
            $table->dateTime('registration_opens_at')->nullable()->after('duration_days');
            $table->string('facilitator_name', 128)->nullable()->after('capacity');
            $table->string('facilitator_contact', 32)->nullable()->after('facilitator_name');
            $table->text('objectives')->nullable()->after('facilitator_contact');
            $table->text('prerequisites')->nullable()->after('objectives');
            $table->text('target_participants')->nullable()->after('prerequisites');
            // Phase 5 reads these; declared here so the training form only has
            // to be touched once.
            $table->boolean('payment_required')->default(false)->after('target_participants');
            $table->decimal('payment_amount', 10, 2)->nullable()->after('payment_required');
        });

        $this->backfill();
    }

    /**
     * Give existing rows a sensible duration and code.
     *
     * Done in PHP rather than SQL because the date arithmetic differs between
     * database engines.
     */
    private function backfill(): void
    {
        DB::table('trainings')
            ->select('id', 'starts_at', 'ends_at')
            ->orderBy('id')
            ->chunkById(200, function ($trainings) {
                foreach ($trainings as $training) {
                    $starts = Carbon::parse($training->starts_at)->startOfDay();
                    $ends = Carbon::parse($training->ends_at)->startOfDay();

                    DB::table('trainings')->where('id', $training->id)->update([
                        // Inclusive of both endpoints: a training that starts
                        // and ends on the same day runs for one day, not zero.
                        'duration_days' => max(1, $starts->diffInDays($ends) + 1),
                        'training_code' => sprintf('TRN-%s-%04d', $starts->format('Y'), $training->id),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropUnique(['training_code']);
            $table->dropColumn([
                'training_code', 'category', 'mode', 'duration_days', 'registration_opens_at',
                'facilitator_name', 'facilitator_contact', 'objectives', 'prerequisites',
                'target_participants', 'payment_required', 'payment_amount',
            ]);
        });
    }
};
