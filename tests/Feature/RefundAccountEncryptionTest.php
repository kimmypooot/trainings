<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A refund payee's bank account number is encrypted at rest.
 *
 * It was stored in the clear and masked only on screen, by
 * `User::seesBankDetails()` — a careful least-privilege control at the
 * presentation layer with nothing behind it. Every database dump and every
 * nightly backup carried the numbers in full, which is the opposite of what the
 * masking was for.
 */
class RefundAccountEncryptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Built directly rather than through RefundService::request(), which would
     * need a verified payment behind it — this is a test about how one column
     * is stored, not about the claim workflow that PaymentTest already covers.
     */
    private function refund(?string $accountNumber = '5606837069'): RefundRequest
    {
        return RefundRequest::create([
            'payment_id' => Payment::factory()->verified()->create()->getKey(),
            'amount' => 1500,
            'reason' => 'The training was cancelled.',
            'account_name' => 'Juan Dela Cruz',
            'bank_name' => 'Land Bank of the Philippines',
            'account_number' => $accountNumber,
        ]);
    }

    public function test_the_number_is_ciphertext_in_the_database(): void
    {
        $refund = $this->refund('9988776655');

        $stored = DB::table('refund_requests')->where('id', $refund->id)->value('account_number');

        $this->assertNotSame('9988776655', $stored, 'The account number is sitting in the clear.');
        $this->assertStringNotContainsString('9988776655', (string) $stored);
        $this->assertSame('9988776655', Crypt::decryptString((string) $stored));
    }

    public function test_the_model_reads_and_masks_it_normally(): void
    {
        $refund = $this->refund('5606837069');

        $this->assertSame('5606837069', $refund->fresh()->account_number);
        $this->assertSame('••••••7069', $refund->fresh()->maskedAccountNumber());
    }

    /**
     * The column had to be widened before the cast could be added at all.
     * Laravel's envelope is ~200 characters for a ten-digit number, and the
     * column was varchar(64) — adding the cast alone would have silently
     * truncated every value into ciphertext nothing could ever decrypt, and the
     * failure would not have surfaced until somebody tried to pay a refund.
     */
    public function test_the_column_is_wide_enough_for_the_envelope(): void
    {
        $refund = $this->refund('12345678901234567890');

        $stored = (string) DB::table('refund_requests')->where('id', $refund->id)->value('account_number');

        $this->assertGreaterThan(64, strlen($stored), 'The envelope is short enough that this test proves nothing.');
        $this->assertSame('12345678901234567890', Crypt::decryptString($stored));
        $this->assertSame('text', Schema::getColumnType('refund_requests', 'account_number'));
    }

    /**
     * The line between "the participant's account" and "the office's account"
     * is the whole point. `payment_settings` holds the account participants are
     * told to deposit into — it is printed in approval emails and shown on the
     * payment screen. Encrypting a value whose purpose is publication would be
     * cargo cult, and would break the emails.
     */
    public function test_the_offices_own_deposit_account_stays_readable(): void
    {
        $setting = PaymentSetting::current();

        $setting->forceFill([
            'bank_name' => 'Land Bank of the Philippines',
            'account_name' => 'CSC Regional Office VIII',
            'account_number' => '1234-5678-90',
        ])->save();

        $stored = DB::table('payment_settings')->where('id', $setting->id)->value('account_number');

        $this->assertSame('1234-5678-90', $stored);
    }

    /**
     * Encrypting an empty payee twice would leave a value only two decrypt
     * passes could read. The migration guards it; so does this.
     */
    public function test_a_refund_without_an_account_number_is_left_alone(): void
    {
        $refund = $this->refund(null);

        $this->assertNull($refund->fresh()->account_number);
        $this->assertNull($refund->fresh()->maskedAccountNumber());
    }
}
