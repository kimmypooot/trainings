<script setup>
import { Head, Link } from '@inertiajs/vue3';
import LegalLayout from '@/Layouts/LegalLayout.vue';
</script>

<template>
    <Head title="Privacy Policy" />

    <LegalLayout title="Privacy Policy" updated="12 August 2026">
        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">1. Scope</h2>
            <p>
                This Privacy Policy governs the Training Information Management System (TIMS) operated by the Civil
                Service Commission. It applies to all users of this site, including agency coordinators, nominated
                personnel, and Commission staff. It should be read together with the Data Privacy Notice presented when
                you first visit the site.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">2. Information We Collect</h2>
            <ul class="list-inside list-disc space-y-1 text-csc-ink/80">
                <li><strong>Account data:</strong> name, official email address, agency, position, and role in TIMS.</li>
                <li>
                    <strong>Google account data:</strong> if you sign in with Google, we receive your Google account
                    identifier, email address, name, and profile picture. We do not receive your Google password.
                </li>
                <li><strong>Training data:</strong> nominations, attendance, assessment results, and certificates.</li>
                <li><strong>Technical data:</strong> session cookies and server logs needed to operate the service.</li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">3. How We Use Information</h2>
            <p>
                Personal data is processed to authenticate users, process training nominations, issue certificates,
                generate institutional reports, and comply with legal obligations. We do not sell personal data, and we
                do not use it for advertising.
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
                the purposes described above. Disclosure to any other party occurs only where required by law or with
                your consent.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">6. Retention and Security</h2>
            <p>
                Records are retained in line with CSC records retention policies and then securely disposed of or
                anonymised. Access is restricted by role, transmissions are encrypted, and credentials are stored in
                hashed form.
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
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">8. Contact</h2>
            <div class="rounded-lg border border-csc-line bg-csc-blue-tint p-4">
                <p class="text-xs font-semibold tracking-wide text-csc-ink uppercase">Data Protection Officer</p>
                <p class="mt-1 text-sm">
                    Email:
                    <a href="mailto:dpo@csc.gov.ph" class="font-medium text-csc-blue hover:underline">dpo@csc.gov.ph</a>
                </p>
            </div>
        </section>

        <p class="border-t border-csc-line pt-6 text-xs text-csc-ink/60">
            See also our
            <Link href="/terms-of-service" class="font-medium text-csc-blue hover:underline">Terms of Service</Link>.
        </p>
    </LegalLayout>
</template>
