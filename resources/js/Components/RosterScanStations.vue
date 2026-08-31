<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * Issuing and revoking the shareable scanning stations for one training.
 *
 * Lifted off the roster page whole — state, handlers and markup together —
 * because it is the one block on that page that owns its own world: a form, a
 * clipboard, a two-second acknowledgement, and a secret that exists for exactly
 * one render. None of that is roster state, and none of the roster's state is
 * needed here beyond the training's id and the links already issued.
 *
 * It stays *on* the roster rather than moving to its own screen for the reason
 * the card has always given: issuing a door is part of preparing a session, and
 * the person doing it is already looking at who is expected at that door.
 */
const props = defineProps({
    training: { type: Object, required: true },
    scanLinks: { type: Array, default: () => [] },
});

const page = usePage();

const stationLabel = ref('');
const issuing = ref(false);

/**
 * Issue this station as a rehearsal.
 *
 * Super administrators only — the server refuses it from anyone else — and
 * always reset after issuing, so the next station created is a real one unless
 * somebody deliberately says otherwise.
 */
const stationIsTest = ref(false);
const canIssueTest = computed(() => page.props.auth?.user?.role === 'superadmin');

/**
 * The freshly issued link, code and all.
 *
 * Read from the flash bag because the plaintext code exists exactly once, in
 * the response to the request that created it — see Admin\ScanLinkController.
 * Reloading this page will not bring it back, which is why the card says so.
 */
const newStation = computed(() => page.props.flash?.scan_link ?? null);

/**
 * Which field was copied last, so only that button acknowledges.
 *
 * Tracked as a name rather than a boolean because the link and the code are
 * copied separately and usually one after the other — a shared flag would light
 * up both and leave the operator unsure which one is actually on the clipboard.
 */
const copiedField = ref(null);
let copiedTimer = null;

function issueStation() {
    issuing.value = true;

    router.post(
        `/admin/trainings/${props.training.id}/scan-links`,
        { label: stationLabel.value || null, is_test: stationIsTest.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                stationLabel.value = '';
                stationIsTest.value = false;
            },
            onFinish: () => (issuing.value = false),
        }
    );
}

function revokeStation(link) {
    router.delete(`/admin/scan-links/${link.id}`, { preserveScroll: true });
}

/**
 * Copy one field.
 *
 * Separately rather than as one block: the link and the code travel by
 * different routes in practice — the link into a chat message or a QR, the code
 * read aloud or sent after it — and pasting them together is what puts a
 * working credential and its password in the same place.
 */
async function copyField(field, value) {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        // Clipboard access needs a secure context; on plain http over a LAN
        // address it simply is not there. Say nothing rather than claim a copy
        // that did not happen — the value is on screen and selectable.
        return;
    }

    copiedField.value = field;

    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copiedField.value = null), 2000);
}
</script>

<template>
    <AppCard
        title="Scanning stations"
        subtitle="Hand a door to someone without an account — a phone, a link and a code."
        collapsible
        remember-as="roster.stations"
        class="print:hidden"
    >
        <!-- The one and only sighting of the code. -->
        <div v-if="newStation" class="rounded-xl border border-success/40 bg-success-soft p-4">
            <p class="text-sm font-semibold text-csc-ink">
                {{ newStation.is_test ? 'Practice station ready' : 'Station ready' }}<span v-if="newStation.label"> · {{ newStation.label }}</span>
            </p>
            <p v-if="newStation.is_test" class="mt-1 text-xs font-semibold text-csc-ink">
                This station records nothing. Scans are answered as they would be live, but no
                attendance is saved.
            </p>
            <p class="mt-1 text-xs leading-relaxed text-csc-ink-muted">
                Copy each to whoever is working the door. Sending the code by a different
                route than the link is safer, since either one alone is useless. The code
                is shown once and cannot be recovered — if it is lost, issue a new station.
            </p>

            <!--
                Each value owns its copy control. The button sits inside
                the field rather than under the pair, so there is never a
                question of which one it acts on.
            -->
            <div class="mt-3 space-y-2">
                <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2">
                    <p class="min-w-0 flex-1 font-mono text-xs break-all text-csc-ink">
                        {{ newStation.url }}
                    </p>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1.5 text-csc-ink-subtle transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-blue"
                        :title="copiedField === 'url' ? 'Link copied' : 'Copy link'"
                        @click="copyField('url', newStation.url)"
                    >
                        <AppIcon
                            :name="copiedField === 'url' ? 'check' : 'clipboard'"
                            :label="copiedField === 'url' ? 'Link copied' : 'Copy link'"
                        />
                    </button>
                </div>

                <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2">
                    <p class="min-w-0 flex-1 font-mono text-lg font-bold tracking-[0.3em] text-csc-ink">
                        {{ newStation.code }}
                    </p>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1.5 text-csc-ink-subtle transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-blue"
                        :title="copiedField === 'code' ? 'Code copied' : 'Copy code'"
                        @click="copyField('code', newStation.code)"
                    >
                        <AppIcon
                            :name="copiedField === 'code' ? 'check' : 'clipboard'"
                            :label="copiedField === 'code' ? 'Code copied' : 'Copy code'"
                        />
                    </button>
                </div>
            </div>

            <p class="mt-3 text-xs text-csc-ink-subtle">Expires {{ newStation.expires_at }}</p>
        </div>

        <!-- Issue -->
        <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="min-w-48 flex-1">
                <span class="text-xs font-medium text-csc-ink-muted">Label (optional)</span>
                <input
                    v-model="stationLabel"
                    type="text"
                    maxlength="60"
                    placeholder="Front door, Hall B…"
                    class="mt-1 w-full rounded-lg border border-csc-ink/20 px-3 py-2 text-sm focus:border-csc-blue focus:outline-none"
                />
            </label>

            <AppButton size="sm" icon="qr" :disabled="issuing" @click="issueStation">
                {{ issuing ? 'Creating…' : stationIsTest ? 'Create practice station' : 'Create scanning station' }}
            </AppButton>
        </div>

        <!-- Rehearsal stations, for super administrators only. -->
        <label v-if="canIssueTest" class="mt-3 flex items-start gap-2">
            <input
                v-model="stationIsTest"
                type="checkbox"
                class="mt-0.5 size-4 rounded border-csc-ink/30 text-csc-blue focus:ring-csc-blue"
            />
            <span class="text-xs leading-relaxed text-csc-ink-muted">
                <strong class="font-semibold text-csc-ink">Practice station</strong> — scans are
                checked against the real roster and answered exactly as they would be, but no
                attendance is ever recorded. Use this to prove phones, cameras and signal at the
                venue before the session starts.
            </span>
        </label>

        <!-- Live stations -->
        <ul v-if="scanLinks.length" class="mt-4 space-y-2 border-t border-csc-ink/10 pt-4">
            <li
                v-for="link in scanLinks"
                :key="link.id"
                class="flex flex-wrap items-center gap-3 rounded-lg bg-csc-blue-tint/40 px-3 py-2.5"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-csc-ink">
                        {{ link.label ?? 'Unlabelled station' }}
                        <AppBadge v-if="link.is_test" tone="warning" class="ml-1">Practice</AppBadge>
                    </p>
                    <p class="truncate text-xs text-csc-ink-subtle">
                        Expires {{ link.expires_at }} ·
                        <template v-if="link.last_used_at">last used {{ link.last_used_at }}</template>
                        <template v-else>never used</template>
                    </p>
                </div>

                <AppButton size="sm" variant="ghost" icon="close" @click="revokeStation(link)">Revoke</AppButton>
            </li>
        </ul>

        <p v-else class="mt-4 border-t border-csc-ink/10 pt-4 text-xs text-csc-ink-subtle">
            No station is currently active for this training.
        </p>
    </AppCard>
</template>
