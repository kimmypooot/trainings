<?php

namespace App\Http\Controllers;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participant's guide to the system.
 *
 * The office had no self-service surface at all: a participant who could not
 * work out what "Physical OR" meant, or why their payment still said pending,
 * had exactly one option, which was to telephone HRD. Every question this page
 * answers is one somebody was already asking.
 *
 * Two rules about what goes in it. **Nothing here is invented** — the file
 * limits, the password rule and the statuses below are the ones the validators
 * and enums actually enforce, and where a fact lives in config it is passed
 * through rather than retyped. And **no contact details are made up**: the
 * office block comes from the shared `office` prop, which the superadmin edits
 * at /admin/office, so a guide served by another regional office names that
 * office.
 */
class HelpController extends Controller
{
    /**
     * What the participant is allowed to upload, and where.
     *
     * These mirror the validation rules in PaymentController,
     * RegistrationController, RegistrationOutputController,
     * AgencyRequestController and ProfilePhotoController. They are stated here
     * rather than derived because a rule is an array of Laravel strings, not a
     * sentence — but they are stated *once*, and UploadLimitsTest asserts each
     * one still matches the controller that enforces it, so a limit raised in
     * one place cannot leave this page quietly telling people the old number.
     *
     * @return array<int, array<string, string>>
     */
    private function uploadLimits(): array
    {
        return [
            [
                'what' => 'Proof of payment',
                'where' => 'Payments',
                'size' => '5 MB',
                'types' => 'PDF, JPG, PNG',
            ],
            [
                'what' => 'Supporting document for a supervisory course',
                'where' => 'My Registrations',
                'size' => '5 MB',
                'types' => 'PDF, JPG, PNG, DOC, DOCX',
            ],
            [
                'what' => 'Training output',
                'where' => 'My Registrations',
                'size' => '10 MB',
                'types' => 'PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, PNG',
            ],
            [
                'what' => 'Agency request letter',
                'where' => 'Agency Requests',
                'size' => '10 MB',
                'types' => 'PDF, DOC, DOCX',
            ],
            [
                'what' => 'Profile photo',
                'where' => 'My Profile',
                'size' => '2 MB',
                'types' => 'JPG, PNG, WEBP',
            ],
        ];
    }

    /**
     * What each registration status means, in the participant's own terms.
     *
     * The labels come from RegistrationStatus so the guide and the badge on the
     * screen cannot disagree; the explanations are what the badge cannot say in
     * one word.
     *
     * @return array<int, array<string, string>>
     */
    private function statuses(): array
    {
        return [
            [
                'status' => 'pending',
                'means' => 'You have reserved a slot and CSC has not decided yet. Nothing is required from you unless the training has a fee.',
            ],
            [
                'status' => 'approved',
                'means' => 'Your slot is confirmed. Bring your QR code on the day — it is how attendance is taken at the door.',
            ],
            [
                'status' => 'waitlisted',
                'means' => 'The training is full. If somebody withdraws before it starts you are moved up automatically, and you will be emailed.',
            ],
            [
                'status' => 'rejected',
                'means' => 'CSC did not approve this registration. The reason is shown on the registration itself.',
            ],
            [
                'status' => 'cancelled',
                'means' => 'The registration was withdrawn, either by you or by CSC.',
            ],
            [
                'status' => 'completed',
                'means' => 'You attended and the training is closed out. A certificate follows once CSC releases it.',
            ],
        ];
    }

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Help/Index', [
            'uploadLimits' => $this->uploadLimits(),
            'statuses' => $this->statuses(),
            /*
             * The minimum length is read from the policy rather than typed, so
             * raising the floor in AppServiceProvider updates the guide too.
             * The breach check is production-only by design and is described
             * rather than numbered.
             */
            'passwordMinimum' => AppServiceProvider::PASSWORD_MINIMUM,
            /*
             * Whether this participant signs in with Google decides which
             * password advice is true for them, and a guide that offers the
             * wrong half is worse than one that offers neither.
             */
            'hasPassword' => $request->user()?->password !== null,
        ]);
    }
}
