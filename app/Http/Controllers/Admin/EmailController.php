<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendTrainingAnnouncement;
use App\Models\EmailLog;
use App\Models\Training;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRD's outbound mail: the log of what was sent, and the form to send more.
 *
 * Ported from v1's `admin/hrd/send-emails.php`.
 */
class EmailController extends Controller
{
    public function index(Request $request): Response
    {
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'training_id' => ['required', 'exists:trainings,id'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'statuses' => ['required', 'array', 'min:1'],
            'statuses.*' => [Rule::enum(RegistrationStatus::class)],
        ]);

        SendTrainingAnnouncement::dispatch(
            Training::findOrFail($validated['training_id']),
            $validated['subject'],
            $validated['message'],
            $validated['statuses'],
            $request->user()->scopedFieldOfficeId(),
        );

        return back()->with('success', 'Announcement queued for delivery.');
    }
}
