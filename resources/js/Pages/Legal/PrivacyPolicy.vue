<script setup>
import { Head, Link } from '@inertiajs/vue3';
import LegalLayout from '@/Layouts/LegalLayout.vue';
</script>

<template>
    <Head title="Privacy Policy" />

    <LegalLayout title="Privacy Policy" updated="8 September 2026">
        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">1. Scope</h2>
            <p>
                This Privacy Policy governs the Training Information Management System (TIMS) operated by the Civil
                Service Commission. It applies to all users of this site, including agency coordinators, nominated
                personnel, subject matter experts, and Commission staff. It should be read together with the Data
                Privacy Notice presented when you first visit the site.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">2. Information We Collect</h2>
            <ul class="list-inside list-disc space-y-1 text-csc-ink-muted">
                <li>
                    <strong>Account data:</strong> name, official email address, agency, position, role in TIMS, and,
                    if you choose to add one, a profile photo.
                </li>
                <li>
                    <strong>Google account data:</strong> if you sign in with Google, we receive your Google account
                    identifier, email address, name, and — if you choose to import it — your Google profile picture.
                    We do not receive your Google password.
                </li>
                <li>
                    <strong>Employer and location data:</strong> your agency, sector, and workplace region, province,
                    and city or municipality. These decide which CSC field office serves you and, for participants
                    based outside this office's region, whether a physical copy of an official receipt may be
                    couriered to you instead of collected in person.
                </li>
                <li>
                    <strong>Training data:</strong> registrations and nominations, QR-code attendance scans (including
                    scans recorded offline at a venue and synced afterward), assessment and evaluation results,
                    supervisory-course output submissions, and issued certificates and their verification codes.
                </li>
                <li>
                    <strong>Payment and refund data:</strong> proof of payment you upload for a training fee (such as a
                    deposit slip or GCash confirmation), and, for a refund, the payee's bank account number — which is
                    encrypted and never displayed in full to staff — account name, and bank name.
                </li>
                <li>
                    <strong>Physical receipt delivery data:</strong> if you request a printed official receipt be
                    couriered to you, proof that you have paid the courier fee, and the delivery details needed to
                    reach you.
                </li>
                <li>
                    <strong>Evaluation data:</strong> if you attend a training with a resource speaker, the ratings and
                    comments you submit about them.
                </li>
                <li>
                    <strong>Communications:</strong> a record of the emails TIMS sends you about your registrations,
                    payments, requests, and certificates.
                </li>
                <li>
                    <strong>Technical data:</strong> session cookies, server logs, and security logs (such as sign-in
                    activity) needed to operate and protect the service. With your consent to the cookie notice on this
                    site, we also collect anonymised usage analytics — see Section 8.
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">3. How We Use Information</h2>
            <p>
                Personal data is processed to authenticate users, process training nominations and registrations,
                verify attendance, process payments and refunds, arrange courier delivery of a requested physical
                receipt, issue certificates, compile subject-matter-expert evaluations, generate institutional and
                agency-level reports, administer accounts, and comply with legal obligations. We do not sell personal
                data, and we do not use it for advertising.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">4. Legal Basis</h2>
            <p>
                Processing is carried out under the Data Privacy Act of 2012 (RA 10173), principally on the basis of the
                Commission's mandate as a government body and, where applicable, your consent.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">5. Disclosure</h2>
            <p>
                Data may be shared with CSC regional, field, and satellite offices and with your nominating agency for
                the purposes described above. Where you have requested a physical receipt be couriered to you, the
                delivery details necessary to complete that delivery are shared with the courier we engage, solely for
                that purpose. Where you sign in with Google, the minimum information needed to authenticate you is
                shared with Google as part of that sign-in. Disclosure to any other party occurs only where required by
                law or with your consent.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">6. Retention and Security</h2>
            <p>
                Records are retained in line with CSC records retention policies and then securely disposed of or
                anonymised. Access to your records — including certificates, payment proofs, and other supporting
                documents — is restricted to you and to Commission staff whose role requires it, and is never made
                available through a public link. Refund bank account numbers are encrypted at rest. Transmissions are
                encrypted, credentials are stored in hashed form, backups are encrypted, and sign-in activity is logged
                separately from other records for security monitoring.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">7. Your Rights</h2>
            <p>
                You have the right to be informed, to access, to object, to rectify, to erase or block, to data
                portability, to damages, and to lodge a complaint with the National Privacy Commission. To exercise any
                of these rights, contact the Data Protection Officer below.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">8. Cookies and Analytics</h2>
            <p>
                TIMS uses cookies that are strictly necessary to keep you signed in and to protect the site against
                forgery — these cannot be switched off. Separately, and only once you accept the cookie notice shown on
                your first visit, we load anonymised usage analytics (Google Analytics) to understand how the site is
                used. Declining or dismissing the notice keeps analytics off; it does not affect your ability to use
                TIMS.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">9. Contact</h2>
            <div class="rounded-lg border border-csc-line bg-csc-blue-tint p-4">
                <p class="text-xs font-semibold tracking-wide text-csc-ink uppercase">Data Protection Officer</p>
                <p class="mt-1 text-sm">
                    Email:
                    <a href="mailto:dpo@csc.gov.ph" class="font-medium text-csc-blue hover:underline">dpo@csc.gov.ph</a>
                </p>
            </div>
        </section>

        <p class="border-t border-csc-line pt-6 text-xs text-csc-ink-subtle">
            See also our
            <Link href="/terms-of-service" class="font-medium text-csc-blue hover:underline">Terms of Service</Link>.
        </p>
    </LegalLayout>
</template>
