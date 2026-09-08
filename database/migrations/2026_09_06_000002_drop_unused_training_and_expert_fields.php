<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three fields the office decided it does not want asked for.
 *
 * `trainings.level` was a ported v1 field with its own enum, offered on the
 * create form and printed on the public catalogue, the training page and the
 * participant's own registration — none of which HRD ever used to decide
 * anything, so it was one more optional select between typing a title and
 * publishing a run. `subject_matter_experts.bio` and `.remarks` were the same
 * kind of thing on the expert form: free text nothing read back except the
 * detail page it was typed on.
 *
 * The columns go rather than being left behind, because a nullable column that
 * nothing writes is indistinguishable from one that is simply empty for these
 * rows, and the next person to find it has no way to tell which. Note what that
 * costs: `down()` restores the columns but not the values, which is the honest
 * shape here — nothing else in the schema can reconstruct them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('subject_matter_experts', function (Blueprint $table) {
            $table->dropColumn(['bio', 'remarks']);
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('level', 32)->nullable()->after('category');
        });

        Schema::table('subject_matter_experts', function (Blueprint $table) {
            $table->text('bio')->nullable();
            $table->text('remarks')->nullable();
        });
    }
};
