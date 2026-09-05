<?php

namespace Tests\Feature;

use App\Models\PaymentSetting;
use App\Models\PhysicalOrSetting;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The three settings singletons agree with themselves.
 *
 * Each is one row created lazily on first read, and nothing in the database
 * enforces that there is only ever one: two requests arriving together can both
 * find none and both create one, and a restore or a hand-run insert can do the
 * same. Each read the row back with a bare `first()`, so which of two rows won
 * was whatever the storage engine returned — stable in development right up
 * until the day it is not.
 *
 * Ordering does not prevent a duplicate being written. It makes every reader
 * agree on which row is the real one, which is the half that matters: the older
 * row is the one the application has been using.
 *
 * Worth guarding on all three rather than only the one the audit named. They
 * are the same six lines copied, and the consequences differ: a disagreeing
 * SiteSetting flickers the maintenance page on and off between requests, while
 * a disagreeing PaymentSetting publishes a different deposit account number to
 * the participant who happens to load the page next.
 */
class SingletonSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_maintenance_switch_reads_the_same_row_every_time(): void
    {
        $first = SiteSetting::create(['maintenance_mode' => true, 'maintenance_message' => 'The first row']);
        $second = SiteSetting::create(['maintenance_mode' => false, 'maintenance_message' => 'A duplicate']);

        $this->assertNotSame($first->id, $second->id);

        // Read repeatedly: an unordered read can return either row, so a single
        // call could pass by luck.
        foreach (range(1, 5) as $ignored) {
            $this->assertSame($first->id, SiteSetting::current()->id);
        }

        $this->assertTrue(SiteSetting::isInMaintenance());
    }

    public function test_the_payment_settings_read_the_same_row_every_time(): void
    {
        // Through current(), so the row carries whatever defaults the model
        // owns; the duplicate is a copy of it, which is what a racing second
        // create or a clumsy restore would actually leave behind.
        $first = PaymentSetting::current();
        $second = $first->replicate();
        $second->save();

        $this->assertNotSame($first->id, $second->id);

        foreach (range(1, 5) as $ignored) {
            $this->assertSame($first->id, PaymentSetting::current()->id);
        }
    }

    public function test_the_physical_or_settings_read_the_same_row_every_time(): void
    {
        // Through current(), so the row carries whatever defaults the model
        // owns; the duplicate is a copy of it, which is what a racing second
        // create or a clumsy restore would actually leave behind.
        $first = PhysicalOrSetting::current();
        $second = $first->replicate();
        $second->save();

        $this->assertNotSame($first->id, $second->id);

        foreach (range(1, 5) as $ignored) {
            $this->assertSame($first->id, PhysicalOrSetting::current()->id);
        }
    }

    /**
     * The read is ordered, asserted against the SQL rather than the outcome.
     *
     * This one is deliberately white-box, and the reason is worth writing down
     * because the obvious black-box version of it is a test that cannot fail.
     * On InnoDB an unordered `first()` scans the primary key and therefore
     * *does* return the lowest id, so the broken and the fixed forms agree on
     * every run here — a behavioural assertion passes with the fix reverted,
     * which was checked before this test was written this way.
     *
     * The guarantee is not something MySQL owes us today; it is something the
     * query has to state. A secondary index, a scope added later, a replica or
     * a different engine can each make an unordered read return the other row,
     * and by then nobody is looking. So the assertion is on the mechanism: the
     * settings read carries an `order by`.
     */
    public function test_each_singleton_asks_the_database_for_a_defined_order(): void
    {
        foreach ([SiteSetting::class, PaymentSetting::class, PhysicalOrSetting::class] as $model) {
            $model::current();

            DB::flushQueryLog();
            DB::enableQueryLog();

            $model::current();

            $selects = array_filter(
                array_column(DB::getQueryLog(), 'query'),
                fn (string $query) => str_starts_with($query, 'select')
            );
            DB::disableQueryLog();

            $this->assertNotEmpty($selects, $model.' reads its row');

            foreach ($selects as $query) {
                $this->assertStringContainsString(
                    'order by',
                    $query,
                    $model.'::current() must not depend on the storage engine returning rows in id order'
                );
            }
        }
    }

    /**
     * The ordinary path still works: no row at all yields one with defaults,
     * and a second read returns that same row rather than making another.
     */
    public function test_the_first_read_creates_exactly_one_row(): void
    {
        $this->assertSame(0, SiteSetting::count());

        $created = SiteSetting::current();

        $this->assertSame($created->id, SiteSetting::current()->id);
        $this->assertSame(1, SiteSetting::count());
        $this->assertFalse($created->maintenance_mode);
    }
}
