import jsQR from 'jsqr';

/**
 * The camera and the decoder.
 *
 * Two decoders, chosen at runtime. Where the browser ships `BarcodeDetector` —
 * which is most Android phones and tablets, the devices this station will
 * actually be used on — decoding happens in native code off the main thread and
 * costs almost nothing. Everywhere else jsQR runs over a downscaled frame.
 *
 * jsQR is bundled, not fetched: the station's whole reason to exist is working
 * with the network unplugged, and a decoder loaded from a CDN would fail at
 * exactly the moment it is needed.
 */

/** Frames per second we attempt to decode. */
const DECODE_FPS = 10;

/** The longest edge we hand the JS decoder. */
const MAX_EDGE = 640;

/**
 * How long the same code is ignored after it decodes.
 *
 * A participant holds their phone still under the camera for a second or two,
 * which is dozens of frames of the identical code. Without this the station
 * would re-announce the same person over and over and the operator would never
 * be sure whether the beep was a new arrival.
 */
const REPEAT_COOLDOWN_MS = 2500;

export class Scanner {
    constructor({ video, onResult, onError }) {
        this.video = video;
        this.onResult = onResult;
        this.onError = onError;
        this.stream = null;
        this.detector = null;
        this.canvas = null;
        this.context = null;
        this.timer = null;
        this.running = false;
        this.lastText = null;
        this.lastAt = 0;
        this.busy = false;
    }

    /** True where the camera can be opened at all — https or localhost only. */
    static isSupported() {
        return Boolean(navigator.mediaDevices?.getUserMedia && window.isSecureContext);
    }

    async start() {
        if (this.running) {
            return;
        }

        // `environment` is a request, not a guarantee — a laptop with only a
        // front camera still works, it just points the wrong way, which is
        // better than refusing to start.
        this.stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
            audio: false,
        });

        this.video.srcObject = this.stream;
        this.video.setAttribute('playsinline', 'true');
        await this.video.play();

        await this.prepareDetector();

        this.running = true;
        this.tick();
    }

    stop() {
        this.running = false;

        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;

        if (this.video) {
            this.video.srcObject = null;
        }
    }

    /**
     * Torch control, where the hardware exposes it.
     *
     * Venues are lit for a projector, not for a camera, and a printed code on
     * matte paper at a badge table is genuinely hard to read without it.
     */
    async setTorch(on) {
        const track = this.stream?.getVideoTracks()?.[0];

        if (!track?.getCapabilities?.().torch) {
            return false;
        }

        await track.applyConstraints({ advanced: [{ torch: on }] });

        return true;
    }

    hasTorch() {
        return Boolean(this.stream?.getVideoTracks()?.[0]?.getCapabilities?.().torch);
    }

    async prepareDetector() {
        if (!('BarcodeDetector' in window)) {
            return;
        }

        try {
            const formats = await window.BarcodeDetector.getSupportedFormats();

            if (formats.includes('qr_code')) {
                this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            }
        } catch {
            // An unusable native detector is not an error worth showing anyone;
            // jsQR covers the same ground a little more slowly.
            this.detector = null;
        }
    }

    /**
     * One decode attempt, then schedule the next.
     *
     * setTimeout rather than requestAnimationFrame: rAF stops entirely when the
     * page is backgrounded, and more importantly it would run the decoder at
     * 60fps, which flattens a phone battery over a three-hour registration
     * window for no extra accuracy.
     */
    tick() {
        if (!this.running) {
            return;
        }

        this.timer = setTimeout(async () => {
            if (!this.busy) {
                this.busy = true;

                try {
                    await this.decodeFrame();
                } catch (error) {
                    this.onError?.(error);
                } finally {
                    this.busy = false;
                }
            }

            this.tick();
        }, 1000 / DECODE_FPS);
    }

    async decodeFrame() {
        if (this.video.readyState < 2) {
            return;
        }

        const text = this.detector ? await this.detectNative() : this.detectJs();

        if (!text) {
            return;
        }

        const now = Date.now();

        if (text === this.lastText && now - this.lastAt < REPEAT_COOLDOWN_MS) {
            return;
        }

        this.lastText = text;
        this.lastAt = now;

        this.onResult?.(text);
    }

    async detectNative() {
        const codes = await this.detector.detect(this.video);

        return codes?.[0]?.rawValue ?? null;
    }

    detectJs() {
        const { videoWidth: width, videoHeight: height } = this.video;

        if (!width || !height) {
            return null;
        }

        // Downscaled before decoding: jsQR's cost is linear in pixels, and a
        // 640px frame still resolves a code held at arm's length comfortably.
        const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
        const targetWidth = Math.round(width * scale);
        const targetHeight = Math.round(height * scale);

        if (!this.canvas) {
            this.canvas = document.createElement('canvas');
            this.context = this.canvas.getContext('2d', { willReadFrequently: true });
        }

        if (this.canvas.width !== targetWidth || this.canvas.height !== targetHeight) {
            this.canvas.width = targetWidth;
            this.canvas.height = targetHeight;
        }

        this.context.drawImage(this.video, 0, 0, targetWidth, targetHeight);

        const image = this.context.getImageData(0, 0, targetWidth, targetHeight);
        const code = jsQR(image.data, image.width, image.height, {
            inversionAttempts: 'dontInvert',
        });

        return code?.data ?? null;
    }

    /** Let the same code be read again immediately — used after a manual reset. */
    forget() {
        this.lastText = null;
        this.lastAt = 0;
    }
}

/**
 * A short tone per outcome.
 *
 * An operator at a badge table is looking at the participant, not the screen, so
 * the pitch has to carry the verdict on its own: a bright rising note for a
 * clean check-in, a flat mid tone for a duplicate, a low buzz for a refusal.
 * Built with WebAudio rather than audio files so it works offline and needs no
 * assets.
 */
export function beep(verdict) {
    const tones = {
        success: [880, 0.12],
        duplicate: [520, 0.18],
        'off-day': [300, 0.3],
        unknown: [300, 0.3],
        invalid: [220, 0.3],
    };

    const [frequency, duration] = tones[verdict] ?? tones.invalid;

    try {
        const context = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = context.createOscillator();
        const gain = context.createGain();

        oscillator.frequency.value = frequency;
        oscillator.type = verdict === 'success' ? 'sine' : 'square';
        gain.gain.value = 0.08;

        oscillator.connect(gain).connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + duration);

        oscillator.onended = () => context.close();
    } catch {
        // Audio is a courtesy; a browser that blocks it must not stop the scan.
    }
}

/** A matching haptic, for the phone in a noisy hall where the beep is lost. */
export function buzz(verdict) {
    if (!navigator.vibrate) {
        return;
    }

    navigator.vibrate(verdict === 'success' ? 40 : [60, 60, 60]);
}
