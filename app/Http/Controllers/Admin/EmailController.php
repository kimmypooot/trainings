<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendTrainingAnnouncement;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Training;
use App\Notifications\StaffAnnouncement;
use App\Support\AnnouncementAudience;
use App\Support\EmailTemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRD's outbound mail: the log of what was sent, the templates it is written
 * from, and the form to send more.
 *
 * Ported from v1's `admin/hrd/send-emails.php` plus its four supporting API
 * endpoints (`count-recipients`, `preview-recipients`, `save-email-template`,
 * `send-test-email`).
 */
class EmailController extends Controller
{
    public function index(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $logs = EmailLog::with('sender')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($inner) => $inner->where('recipient_email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
            ))
            ->latest('sent_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Emails/Index', [
            'logs' => $logs->through(fn (EmailLog $log) => [
                'id' => $log->id,
                'recipient_email' => $log->recipient_email,
                'recipient_name' => $log->recipient_name,
                'subject' => $log->subject,
                'status' => $log->status,
                'sent_at' => $log->sent_at?->format('d M Y, g:i A'),
            ]),
            'filters' => ['search' => $request->string('search')->toString()],
            'trainings' => Training::orderByDesc('starts_at')
                ->limit(50)
                ->get()
                ->map(fn (Training $training) => [
                    'value' => $training->id,
                    'label' => $training->title,
                ])->all(),
            'audiences' => SendTrainingAnnouncement::audiences(),
            'audienceFilters' => AnnouncementAudience::filterOptions($officeId),
            // Optional: computed only when the compose form asks for it by
            // partial reload, so an ordinary page load does not pay for a
            // recipient count nobody has requested yet.
            'audiencePreview' => Inertia::optional(fn () => $this->buildPreview($request, $officeId)),
            'variables' => EmailTemplateRenderer::variableOptions(),
            'templates' => EmailTemplate::orderBy('category')->orderBy('name')->get()
                ->map(fn (EmailTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'category' => $template->category,
                    // System templates are what other features look up by code,
                    // so the UI must not offer to delete them.
                    'is_system' => $template->is_system,
                ])->all(),
            'categories' => EmailTemplate::CATEGORIES,
        ]);
    }

    /**
     * The filters an announcement is addressed with.
     *
     * Shared by store(), count() and preview() so the three cannot disagree
     * about who the recipients are.
     *
     * @return array<string, mixed>
     */
    private function audienceRules(): array
    {
        return [
            'training_id' => ['nullable', 'exists:trainings,id'],
            'statuses' => ['array'],
            'statuses.*' => ['string'],
            'sectors' => ['array'],
            'sectors.*' => ['string'],
            'regions' => ['array'],
            'regions.*' => ['string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersFrom(array $validated): array
    {
        return [
            'training_id' => $validated['training_id'] ?? null,
            'statuses' => $validated['statuses'] ?? [],
            'sectors' => $validated['sectors'] ?? [],
            'regions' => $validated['regions'] ?? [],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->audienceRules(),
            // Required on an actual send, unlike sector and region. Those are
            // narrowing filters where "none picked" sensibly means all, but a
            // send addressed to no registration status at all is a slip.
            'statuses' => ['required', 'array', 'min:1'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $filters = $this->filtersFrom($validated);
        $officeId = $request->user()->scopedFieldOfficeId();

        // Refusing an empty send rather than queueing a no-op: "queued for
        // delivery" against nobody reads as success, and the sender walks away
        // believing the announcement went out.
        $count = AnnouncementAudience::count($filters, $officeId);

        if ($count === 0) {
            return back()->withErrors([
                'audience' => 'No participants match those filters. Widen the selection and try again.',
            ]);
        }

        SendTrainingAnnouncement::dispatch(
            $filters,
            $validated['subject'],
            $validated['message'],
            $officeId,
        );

        return back()->with('success', "Announcement queued for {$count} participant(s).");
    }

    /**
     * How many people the current filters reach, and what the message will
     * look like for the first few of them.
     *
     * Served as an optional prop on this same page, refreshed by partial
     * reload as the sender adjusts filters — no second endpoint, no hand-rolled
     * CSRF handling, and the count comes from the same query the send uses.
     *
     * @return array<string, mixed>
     */
    private function buildPreview(Request $request, ?int $officeId): array
    {
        $filters = $this->filtersFrom($request->validate([
            ...$this->audienceRules(),
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]));

        return [
            'count' => AnnouncementAudience::count($filters, $officeId),
            'samples' => AnnouncementAudience::preview(
                $filters,
                $request->string('subject')->toString(),
                $request->string('message')->toString(),
                $officeId,
            ),
        ];
    }

    /**
     * Send the drafted message to the sender's own address.
     *
     * The one honest way to check how a message renders — placeholders, line
     * breaks, and the mail client's own formatting — before it reaches two
     * hundred people.
     */
    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->audienceRules(),
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $filters = $this->filtersFrom($validated);
        $officeId = $request->user()->scopedFieldOfficeId();

        // Rendered against a real matching registration where one exists, so
        // the test shows the placeholders filled rather than the raw tokens.
        $sample = AnnouncementAudience::query($filters, $officeId)->first();

        $subject = $sample
            ? EmailTemplateRenderer::render($validated['subject'], $sample)
            : $validated['subject'];
        $body = $sample
            ? EmailTemplateRenderer::render($validated['message'], $sample)
            : $validated['message'];

        Notification::send(
            $request->user(),
            new StaffAnnouncement("[TEST] {$subject}", $body),
        );

        return back()->with('success', "Test message sent to {$request->user()->email}.");
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'subject' => ['required', 'string', 'max:512'],
            'body' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(EmailTemplate::CATEGORIES)],
        ]);

        EmailTemplate::create([
            ...$validated,
            'created_by' => $request->user()->getKey(),
        ]);

        return back()->with('success', 'Template saved.');
    }

    public function destroyTemplate(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        // System templates back features that look them up by code; deleting
        // one would break a send that has nothing to do with this screen.
        abort_if($emailTemplate->is_system, 403, 'System templates cannot be deleted.');

        $emailTemplate->delete();

        return back()->with('success', 'Template deleted.');
    }
}
