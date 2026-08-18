<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Support\PaymentService;
use App\Support\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The collecting officer's queues, ported from v1's
 * `admin/hrd/pending-payments.php` and `refund-mgmt.php`.
 */
class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $method = $request->string('method')->toString();
        $search = $request->string('search')->toString();

        $officeId = $request->user()->scopedFieldOfficeId();

        /*
         * A field office's collecting officer sees its own money and nobody
         * else's.
         *
         * This screen is reached through EnsureUserCollectsPayments rather than
         * a role list precisely because a field office's officer is a
         * field-office user — the middleware's own comment says they "keep
         * their office scoping while taking payments" — and record() beside
         * this has always re-resolved its registration that way. The list did
         * not, which made it the one payment surface that showed the region.
         *
         * Applied as closures because it has to reach six queries: the rows,
         * the status chips, the three money tallies and both refund queries. A
         * scoped list above unscoped counts would be worse than either, since
         * the totals would then describe work the officer cannot open.
         */
        $scopePayments = fn ($query) => $query->when($officeId !== null, fn ($inner) => $inner->whereHas(
            'user.profile',
            fn ($profile) => $profile->where('field_office_id', $officeId)
        ));

        $scopeRefunds = fn ($query) => $query->when($officeId !== null, fn ($inner) => $inner->whereHas(
            'payment.user.profile',
            fn ($profile) => $profile->where('field_office_id', $officeId)
        ));

        $payments = Payment::with(['user', 'training', 'verifier', 'collectingOfficer', 'registration'])
            ->where($scopePayments)
            // The verification queue defaults to the work in front of the
            // officer; every other status has to be asked for.
            ->when($status ?: PaymentStatus::Pending->value, fn ($query, $s) => $query->where('status', $s))
            ->when($method, fn ($query, $m) => $query->where('payment_method', $m))
            ->when($search, fn ($query, $s) => $query->where(function ($inner) use ($s) {
                $inner->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$s}%"))
                    ->orWhere('or_number', 'like', "%{$s}%")
                    ->orWhere('reference_number', 'like', "%{$s}%")
                    ->orWhereHas('training', fn ($training) => $training->where('title', 'like', "%{$s}%"));
            }))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        // The chips and the summary keep counting the whole queue while the
        // rows below narrow — a chip whose count shrank as the officer typed
        // would read as "work disappeared".
        $paymentCounts = Payment::query()
            ->where($scopePayments)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $summary = [
            'pending' => $this->tally(Payment::where($scopePayments)->where('status', PaymentStatus::Pending)),
            'verified' => $this->tally(Payment::where($scopePayments)->where('status', PaymentStatus::Verified)),
            'rejected' => $this->tally(Payment::where($scopePayments)->where('status', PaymentStatus::Rejected)),
            // Everything still moving, not just the untouched ones — a claim
            // parked at MSD is as much outstanding money as one nobody has
            // looked at yet.
            'open_refunds' => $this->tally(RefundRequest::where($scopeRefunds)->whereNotIn('status', [
                RefundStatus::Refunded->value, RefundStatus::Rejected->value,
            ])),
        ];

        $refundStatus = $request->string('refund_status')->toString();

        $refundCounts = RefundRequest::query()
            ->where($scopeRefunds)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // Open claims first: a refund sitting mid-pipeline is work, a settled
        // one is history, and mixing them buries the former under the latter.
        $refunds = RefundRequest::with([
            'payment.user', 'payment.training', 'reviewer', 'statusLogs.actor',
        ])
            ->where($scopeRefunds)
            ->when($refundStatus, fn ($query, $s) => $query->where('status', $s))
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [
                RefundStatus::Refunded->value, RefundStatus::Rejected->value,
            ])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'participant' => $payment->user->name,
                'training' => $payment->training->title,
                'amount' => $payment->amount,
                // The discount block. `gross` is derived from the stored pair,
                // never re-read off the training, so a later fee change cannot
                // move a figure on a closed payment.
                'prime_hrm_discount' => $payment->prime_hrm_discount,
                'discount_amount' => $payment->discount_amount,
                'gross_amount' => $payment->grossAmount(),
                'method' => $payment->payment_method->label(),
                // The machine-readable half of the line above. A batch may only
                // clear promissory notes — see bulk() — and the page has to know
                // which rows those are without matching on a display label.
                'is_promissory' => $payment->payment_method === PaymentMethod::Promissory,
                'reference_number' => $payment->reference_number,
                'payment_date' => $payment->payment_date->format('d M Y'),
                'payment_date_ts' => $payment->payment_date->timestamp,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'rejection_reason' => $payment->rejection_reason,
                // The officer's own notes, recorded at verification or the
                // counter — and the trail of a refund riding on this payment.
                'remarks' => $payment->remarks,
                'verified_by' => $payment->verifier?->name,
                'or_number' => $payment->or_number,
                'or_date' => $payment->or_date?->format('d M Y'),
                'collecting_officer' => $payment->collectingOfficer?->name,
                // What the fee is billed to, carried from the registration —
                // an agency charge is receipted to the agency, not the person.
                'charge_to' => $payment->registration?->charge_to?->label(),
                'proof_url' => $payment->proof_path ? route('payments.proof', $payment) : null,
            ]),
            'paymentCounts' => $paymentCounts,
            'summary' => $summary,
            'filters' => [
                'status' => $status,
                'method' => $method,
                'search' => $search,
                'refund_status' => $refundStatus,
            ],
            'statuses' => PaymentStatus::options(),
            'methods' => PaymentMethod::options(),
            'refunds' => $refunds->through(fn (RefundRequest $refund) => [
                'id' => $refund->id,
                'request_code' => $refund->request_code,
                'participant' => $refund->payment->user->name,
                'training' => $refund->payment->training->title,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'status' => $refund->status->value,
                'status_label' => $refund->status->label(),
                // The payee block. Officers below collecting-officer never see
                // the full account number — see accountNumberFor().
                'account_name' => $refund->account_name,
                'bank_name' => $refund->bank_name,
                'account_number' => $this->accountNumberFor($refund, $request),
                'proof_url' => $refund->proof_path
                    ? route('payments.refund-proof', $refund)
                    : null,
                'rejection_reason' => $refund->rejection_reason,
                'reviewed_by' => $refund->reviewer?->name,
                'submitted_at' => $refund->created_at->format('d M Y'),
                // Exactly one forward move is ever offered, so the screen
                // cannot put the pipeline out of order.
                'next_stage' => $refund->status->next() === null ? null : [
                    'value' => $refund->status->next()->value,
                    'label' => $refund->status->next()->label(),
                ],
                'can_act' => $refund->status->isOpen(),
                'trail' => $refund->statusLogs->map(fn ($log) => [
                    'to' => $log->to_status->label(),
                    'notes' => $log->notes,
                    'actor' => $log->actor?->name ?? 'Participant',
                    'at' => $log->changed_at->format('d M Y, g:i A'),
                ])->all(),
            ]),
            'refundCounts' => $refundCounts,
            'refundStatuses' => RefundStatus::options(),
            // The ordered stages, so the screen can draw the pipeline without
            // hardcoding a second copy of it.
            'refundPipeline' => collect(RefundStatus::pipeline())
                ->map(fn (RefundStatus $stage) => ['value' => $stage->value, 'label' => $stage->label()])
                ->all(),
            // The bank account participants are told to deposit training fees
            // into. Editing this row updates every approval notification and
            // every payment prompt at once.
            'paymentSettings' => PaymentSetting::current()->only([
                'bank_name', 'account_name', 'account_number', 'instructions',
            ]),
        ]);
    }

    /**
     * Count and value of a payment or refund bucket, for the stats strip.
     *
     * @return array{count: int, amount: int|float}
     */
    /**
     * Re-resolve a route-bound payment inside the officer's own field office.
     *
     * Route model binding resolves by id alone, so without this a scoped
     * officer could act on another office's payment simply by posting its id —
     * the screen would never offer it, which is exactly why the guard cannot
     * live on the screen. record() has always done this for its registration;
     * the review endpoints had not.
     *
     * Not-found rather than forbidden, so the answer does not confirm that a
     * payment with that id exists.
     */
    private function scopedPayment(Request $request, Payment $payment): Payment
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        return Payment::whereKey($payment->getKey())
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->firstOr(fn () => abort(404));
    }

    private function tally($query): array
    {
        return [
            'count' => (clone $query)->count(),
            'amount' => (float) (clone $query)->sum('amount'),
        ];
    }

    public function review(Request $request, Payment $payment): RedirectResponse
    {
        $payment = $this->scopedPayment($request, $payment);

        $validated = $request->validate([
            'decision' => ['required', Rule::in([PaymentStatus::Verified->value, PaymentStatus::Rejected->value])],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Unique across payments: the same OR number on two rows is a
            // transcription slip or a duplicate, and both are worth catching
            // while the officer still has the receipt in hand.
            'or_number' => [
                'nullable', 'string', 'max:32',
                Rule::unique('payments', 'or_number')->ignore($payment),
            ],
            'or_date' => ['nullable', 'date', 'before_or_equal:today'],
            // Officer-only, by design: the discount is an entitlement CSC
            // verifies, so it is never something the participant ticks on the
            // way in.
            'prime_hrm_discount' => ['boolean'],
        ]);

        if ($validated['decision'] === PaymentStatus::Verified->value) {
            PaymentService::verify(
                $payment,
                $request->user(),
                $validated['remarks'] ?? null,
                [
                    'or_number' => $validated['or_number'] ?? null,
                    'or_date' => $validated['or_date'] ?? null,
                ],
                (bool) ($validated['prime_hrm_discount'] ?? false),
            );
        } else {
            PaymentService::reject($payment, $request->user(), (string) ($validated['remarks'] ?? ''));
        }

        return back()->with('success', 'Payment reviewed.');
    }

    /**
     * Verify a batch of promissory notes in one action.
     *
     * A thousand-seat event admits walk-ins on notes all morning, and clearing
     * them one dialog at a time is the queue this exists to remove.
     *
     * Notes only, and that is the whole design rather than a first cut.
     * Verifying real money issues an official receipt, `or_number` is unique
     * across payments, and finance reconciles on it — so a batch could only
     * verify cash by leaving every receipt blank, which is precisely the
     * control the OR exists to be. A promissory note is the one payment
     * verified *without* a receipt, because no money has arrived yet; there is
     * nothing for a batch to skip over. Anything else in the selection is
     * counted and reported rather than silently passed by.
     *
     * No undo, deliberately. The roster's bulk actions offer one because a
     * review is an opinion; a verified note has approved a registration and
     * mailed the participant about it. Taking money decisions back is a
     * reversal with its own trail, not a thirty-second window.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $officeId = $request->user()->scopedFieldOfficeId();

        /*
         * Re-resolved under the office scope rather than taken on trust, the
         * same way the counter payment re-resolves its registration: a list of
         * ids posted from a page must never become a way to act on a payment
         * the officer cannot see.
         */
        $payments = Payment::with(['registration.training', 'user'])
            ->whereIn('id', $validated['ids'])
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->get();

        $verified = 0;
        $skipped = 0;

        foreach ($payments as $payment) {
            if ($payment->status !== PaymentStatus::Pending
                || $payment->payment_method !== PaymentMethod::Promissory) {
                $skipped++;

                continue;
            }

            PaymentService::verify($payment, $request->user(), $validated['remarks'] ?? null);
            $verified++;
        }

        // Ids that resolved to nothing are counted too. Silence there would
        // hide an office-scope mismatch behind a cheerful total.
        $skipped += count($validated['ids']) - $payments->count();

        $message = "{$verified} promissory note(s) verified.";

        return back()->with('success', $skipped === 0
            ? $message
            : "{$message} {$skipped} skipped (not a pending promissory note — verify those individually).");
    }

    /**
     * Record money taken at the counter, ported from v1's `payment-actions.php`.

     *
     * The participant paid cash at the desk and left with the receipt, so there
     * is no upload to review — the officer enters what is on the OR stub. The
     * registration is re-resolved against the field-office scope first, exactly
     * as the roster is, so a scoped officer cannot record a payment against
     * another office's participant by posting its id.
     */
    public function record(Request $request, Registration $registration): RedirectResponse
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $registration = Registration::whereKey($registration->getKey())
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->firstOr(fn () => abort(404));

        $registration->loadMissing('training');

        $method = PaymentMethod::tryFrom((string) $request->input('payment_method'));

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'payment_method' => [
                'required',
                // Same rule the participant's own form applies: a promissory
                // note is only on offer where the training was published as
                // accepting one.
                Rule::enum(PaymentMethod::class)->when(
                    ! $registration->training->accepts_promissory,
                    fn ($rule) => $rule->except(PaymentMethod::Promissory)
                ),
            ],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => [
                'nullable', 'string', 'max:64',
                Rule::requiredIf(fn () => $method?->requiresReference() ?? false),
            ],
            // The receipt is the whole point of recording it here, so unlike a
            // reviewed upload the OR is mandatory — except for a promissory
            // note, where no receipt has been issued because no money arrived.
            'or_number' => [
                'nullable', 'string', 'max:32',
                Rule::requiredIf(fn () => $method?->isSettlement() ?? false),
                Rule::unique('payments', 'or_number'),
            ],
            'or_date' => ['nullable', 'date', 'before_or_equal:today'],
            // Whoever actually took the money, when HRD is entering it on a
            // field office's behalf. Defaults to the officer doing the entry.
            'collecting_officer_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // The 20% PRIME-HRM incentive. The posted amount is ignored when
            // this is set — the service computes what is owed, so the figure
            // cannot be steered from the browser.
            'prime_hrm_discount' => ['boolean'],
        ]);

        PaymentService::recordAtCounter($registration, $request->user(), $validated);

        return back()->with(
            'success',
            'Payment recorded and verified against '.$registration->user->name.'.'
        );
    }

    /**
     * Save the bank-deposit details participants are told to pay into.
     *
     * The one place these live is the settings row, so updating it is updating
     * every approval notification and payment prompt in one move.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:64'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $setting = PaymentSetting::current();

        $setting->forceFill([
            'bank_name' => $validated['bank_name'],
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'],
            'instructions' => $validated['instructions'],
            'updated_by' => $request->user()->getKey(),
        ])->save();

        return back()->with('success', 'Bank deposit details updated.');
    }

    /**
     * The full account number goes only to the roles that actually disburse.
     * Everyone else gets the last four, which is enough to match a claim
     * against a bank advice without the whole number sitting on screen.
     */
    private function accountNumberFor(RefundRequest $refund, Request $request): ?string
    {
        return $request->user()->seesBankDetails()
            ? $refund->account_number
            : $refund->maskedAccountNumber();
    }

    /**
     * Move a refund one stage along, or decline it.
     *
     * The two are one endpoint because the screen presents them as one
     * decision, but they take different paths in the service: advancing is
     * forward-only and validated against the pipeline, declining needs a
     * reason and is reachable from anywhere.
     */
    public function reviewRefund(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $refundRequest = RefundRequest::whereKey($refundRequest->getKey())
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'payment.user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->firstOr(fn () => abort(404));

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['advance', 'reject'])],
            // Required only when advancing: the screen sends the stage it
            // displayed, and RefundService checks it against the live one.
            'target' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'advance'),
                Rule::enum(RefundStatus::class),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rejection_reason' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'reject'),
                'nullable', 'string', 'max:1000',
            ],
        ]);

        if ($validated['decision'] === 'advance') {
            $refund = RefundService::advance(
                $refundRequest,
                RefundStatus::from($validated['target']),
                $request->user(),
                $validated['notes'] ?? null,
            );

            return back()->with('success', "{$refund->request_code} moved to {$refund->status->label()}.");
        }

        RefundService::reject($refundRequest, $request->user(), $validated['rejection_reason']);

        return back()->with('success', 'Refund request declined.');
    }
}
