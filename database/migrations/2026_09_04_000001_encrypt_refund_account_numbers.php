<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the bank account numbers a refund is paid into.
 *
 * `refund_requests.account_number` is the participant's own account, given so
 * the office can transfer money back to them. It was stored in the clear and
 * masked only on screen, by `User::seesBankDetails()` — a careful
 * least-privilege control at the presentation layer with nothing behind it. Any
 * database dump, and every nightly backup, carried the numbers in full.
 *
 * Two things make this migration more than a cast change.
 *
 * The column has to be widened first. Laravel's encrypter emits a base64
 * envelope of roughly 200-plus characters for even a ten-digit account number,
 * and the column is `varchar(64)`. Adding the cast without this would silently
 * truncate every value written afterwards into ciphertext that can never be
 * decrypted — the failure would not surface until somebody tried to pay a
 * refund.
 *
 * And the rows are rewritten through the query builder rather than the model,
 * deliberately. `RefundRequest` carries the `encrypted` cast as of this commit,
 * so reading a row through Eloquent here would try to decrypt plaintext and
 * throw, and writing one back would encrypt a second time.
 *
 * Note what is *not* encrypted, because the line matters: `account_name` and
 * `bank_name` stay in the clear — a payee's name is already in `users.name` and
 * a bank's name identifies nobody, so encrypting them would add cost and key
 * risk for no protection. Neither is `payment_settings.account_number`, which
 * is the *office's* deposit account: it is printed in approval emails and shown
 * on the payment screen, because participants have to pay into it. Encrypting a
 * value whose whole purpose is publication would be cargo cult.
 *
 * Operational consequence worth knowing: these values are now bound to APP_KEY.
 * Rotating the key, or restoring this table into an application with a
 * different one, makes them unreadable — see docs/deployment.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            // text, not a longer varchar: the envelope's length depends on the
            // cipher and the payload, and a column sized to today's output is a
            // truncation waiting for a framework upgrade.
            $table->text('account_number')->nullable()->change();
        });

        $this->each(function (object $row) {
            // Idempotent. A migration that has already run — or a row inserted
            // by a newer deploy while an older one was still catching up —
            // must not be encrypted twice, which would leave a value only two
            // decrypt passes could read.
            if ($this->isEncrypted($row->account_number)) {
                return;
            }

            DB::table('refund_requests')
                ->where('id', $row->id)
                ->update(['account_number' => Crypt::encryptString($row->account_number)]);
        });
    }

    public function down(): void
    {
        $this->each(function (object $row) {
            if (! $this->isEncrypted($row->account_number)) {
                return;
            }

            DB::table('refund_requests')
                ->where('id', $row->id)
                ->update(['account_number' => Crypt::decryptString($row->account_number)]);
        });

        /*
         * Narrowed back only after the values are plaintext again, and only if
         * they all fit. A row longer than 64 characters here would be silently
         * truncated by MySQL, so the column is left wide rather than losing
         * data to a rollback — a reversal that destroys data is worse than one
         * that leaves a column roomier than it needs to be.
         */
        $longest = (int) DB::table('refund_requests')
            ->selectRaw('COALESCE(MAX(CHAR_LENGTH(account_number)), 0) AS len')
            ->value('len');

        if ($longest <= 64) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->string('account_number', 64)->nullable()->change();
            });
        }
    }

    /**
     * Walk the table in chunks, skipping rows with nothing to convert.
     *
     * @param  callable(object): void  $callback
     */
    private function each(callable $callback): void
    {
        DB::table('refund_requests')
            ->select('id', 'account_number')
            ->whereNotNull('account_number')
            ->where('account_number', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($callback) {
                foreach ($rows as $row) {
                    $callback($row);
                }
            });
    }

    /**
     * Whether a stored value is already ciphertext.
     *
     * Decided by trying to decrypt it, which is the only honest test: a bank
     * account number is digits and an envelope is base64, but "looks like
     * base64" would also match a value somebody typed, and guessing wrong in
     * either direction corrupts the row.
     */
    private function isEncrypted(?string $value): bool
    {
        if (blank($value)) {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
