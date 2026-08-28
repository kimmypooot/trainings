<?php

namespace App\Http\Controllers;

use App\Models\Registration;
use App\Models\RegistrationOutput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Post-training deliverables, ported from v1's `submit-output.php`.
 */
class RegistrationOutputController extends Controller
{
    /** Uploads never touch a public disk. */
    public const DISK = 'local';

    public function store(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless($registration->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'required',
                'file',
                'max:10240',
                // An allow-list, not a deny-list: anything not named here is
                // refused, so a new dangerous extension cannot slip through.
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png',
            ],
        ]);

        $file = $request->file('file');

        $output = new RegistrationOutput([
            'registration_id' => $registration->getKey(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            // Laravel generates the stored name, so a crafted filename cannot
            // steer where the file lands.
            'file_path' => $file->store("outputs/{$registration->getKey()}", self::DISK),
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        $output->save();

        return back()->with('success', 'Your output has been submitted.');
    }

    /**
     * Download a submitted output.
     *
     * Open to the participant who submitted it and to staff who review it;
     * nobody else, and never by direct storage URL.
     */
    public function download(Request $request, RegistrationOutput $output): StreamedResponse
    {
        $output->loadMissing('registration');

        $isOwner = $output->registration->user_id === $request->user()->getKey();

        if (! $isOwner) {
            abort_unless($request->user()->role->isStaff(), 403);

            // Same office guard as the participant detail page and the roster
            // this output is reached from.
            $officeId = $request->user()->scopedFieldOfficeId();

            if ($officeId !== null) {
                $output->registration->loadMissing('user.profile');
                abort_unless($output->registration->user->profile?->field_office_id === $officeId, 404);
            }
        }

        return Storage::disk(self::DISK)->download($output->file_path, $output->original_filename);
    }
}
