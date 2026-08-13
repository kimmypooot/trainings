/**
 * Deciding what a scanned code means, with no network involved.
 *
 * Everything the server does in AttendanceService::checkIn has to be reproduced
 * here — which day a moment belongs to, whether an arrival is Present or Late,
 * and whether this is a repeat scan — because the operator needs an answer in
 * the half second before the next person steps forward, and the server may not
 * hear about any of it until the afternoon.
 *
 * The two copies of that rule are kept honest by shipping the numbers rather
 * than restating them: `late_after_minutes` and the day calendar both come down
 * inside the roster bundle.
 */

/**
 * Pull the participant token out of whatever the camera read.
 *
 * The QR encodes a full `/scan/{token}` URL so that a plain phone camera can
 * open the check-in page — see ParticipantQrCode. The station therefore has to
 * accept that URL, and, for a hand-typed fallback, a bare token too.
 */
export function tokenFrom(text) {
    if (typeof text !== 'string') {
        return null;
    }

    const trimmed = text.trim();
    const fromUrl = trimmed.match(/\/scan\/([A-Za-z0-9]{16,64})/);

    if (fromUrl) {
        return fromUrl[1];
    }

    return /^[A-Za-z0-9]{16,64}$/.test(trimmed) ? trimmed : null;
}

/**
 * SHA-256, hex encoded, to compare against the digests in the roster.
 *
 * The device never holds a working token: it hashes what it just read and looks
 * for a match. A tablet left behind in a function room is then worth nothing to
 * whoever picks it up, which is the reason the roster ships digests at all.
 *
 * `crypto.subtle` needs a secure context, which the camera needs anyway — over
 * plain http neither works, so this adds no deployment constraint of its own.
 */
export async function digest(token) {
    const bytes = new TextEncoder().encode(token);
    const hashed = await crypto.subtle.digest('SHA-256', bytes);

    return Array.from(new Uint8Array(hashed))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

/** The device's own calendar date, as the roster writes its days. */
export function localDate(at = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`;
}

export function localTime(at = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${pad(at.getHours())}:${pad(at.getMinutes())}:${pad(at.getSeconds())}`;
}

/** Which training day `at` falls on, or null when the training is not running. */
export function dayNumberFor(training, at = new Date()) {
    const today = localDate(at);
    const match = training.days.find((day) => day.date === today);

    return match ? match.day : null;
}

/**
 * Present unless the participant arrived past the grace period.
 *
 * Mirrors AttendanceService::statusForArrival: the training's scheduled time of
 * day applies to every day of a multi-day run, so only the clock is compared,
 * never the date.
 */
export function statusForArrival(training, at = new Date()) {
    const [hours, minutes] = training.starts_at_time.split(':').map(Number);
    const expected = new Date(at);

    expected.setHours(hours, minutes + training.late_after_minutes, 0, 0);

    return at > expected ? 'late' : 'present';
}

/**
 * Has this participant already been recorded for this day?
 *
 * Both halves matter. The roster carries what the server knew at download time,
 * which covers a second station across the room; the local queue covers this
 * device's own scans, including ones that have not synced yet. Checking only one
 * of them is how a participant ends up in the register twice.
 */
export function existingFor(participant, scans, day) {
    const fromServer = participant.attendance?.[String(day)];

    if (fromServer) {
        return {
            source: 'server',
            time_in: fromServer.time_in,
            status: fromServer.status,
            status_label: fromServer.status_label,
        };
    }

    const local = scans.find(
        (scan) =>
            scan.registration_id === participant.registration_id &&
            scan.training_day === day &&
            scan.state !== 'failed'
    );

    return local
        ? {
              source: 'device',
              time_in: local.time_in,
              status: local.status,
              status_label: local.status === 'late' ? 'Late' : 'Present',
              state: local.state,
          }
        : null;
}

/**
 * The full verdict for one scan.
 *
 * Five outcomes, each with its own colour and sound at the door:
 *
 *  - `success`   recognised, first time today — record it;
 *  - `duplicate` recognised, already marked — do nothing, say so plainly;
 *  - `off-day`   recognised, but the training is not running today. This is the
 *                guard that stops a code scanned at the wrong venue, or on the
 *                wrong date, from quietly landing on day 1. A practice station
 *                is the one caller allowed past it, since a rehearsal is rarely
 *                held on a training day — it lands on day 1 deliberately and
 *                flags the result as `simulatedDay`;
 *  - `unknown`   a valid CSC code that is not on *this* roster — usually the
 *                operator has the wrong training loaded, so the message says so;
 *  - `invalid`   not a CSC participant code at all.
 */
export async function resolveScan(text, roster, scans, { practice = false } = {}) {
    const token = tokenFrom(text);

    if (!token) {
        return { verdict: 'invalid', message: 'That code is not a CSC participant QR code.' };
    }

    const hash = await digest(token);
    const participant = roster.participants.find((row) => row.token_hash === hash);

    if (!participant) {
        return {
            verdict: 'unknown',
            message: `Not on the roster for “${roster.training.title}”. Check that the right training is loaded.`,
        };
    }

    const at = new Date();
    let day = dayNumberFor(roster.training, at);
    let simulatedDay = false;

    if (day === null) {
        /*
         * A rehearsal is almost never held on a training day.
         *
         * Someone proving the phones in the office on Tuesday for a course that
         * runs next Monday would otherwise get "not running today" for every
         * scan and learn nothing — the off-day guard would be the only thing
         * they ever managed to test. So practice stations fall through onto
         * day 1 and say so; the guard stays absolute for live scanning, which
         * is the case it exists for.
         */
        if (!practice) {
            return {
                verdict: 'off-day',
                participant,
                message: `“${roster.training.title}” is not running today, so there is no day to mark.`,
            };
        }

        day = roster.training.days[0]?.day ?? 1;
        simulatedDay = true;
    }

    const existing = existingFor(participant, scans, day);

    if (existing) {
        return { verdict: 'duplicate', participant, day, existing, simulatedDay };
    }

    return {
        verdict: 'success',
        participant,
        day,
        simulatedDay,
        status: statusForArrival(roster.training, at),
        at,
    };
}
