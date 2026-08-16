// Opt-in, consent-gated analytics.
//
// GA4 never loads until the visitor has accepted the privacy notice (the same
// sessionStorage flag the notice itself sets) AND a measurement id is
// configured. With no id in the environment this module is a no-op and zero
// analytics code ships to the browser. `window.__enableCscAnalytics` lets the
// notice's accept handler start analytics the moment consent is given, so a
// visitor who consents mid-visit is counted from that point on.
const STORAGE_KEY = 'csc_tims_privacy_notice_v1';

let loaded = false;

const load = () => {
    if (loaded) return;
    loaded = true;

    const id = document.body.dataset.gaMeasurementId;
    if (!id) return;

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${id}`;
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
        window.dataLayer.push(arguments);
    };
    window.gtag('js', new Date());
    window.gtag('config', id);
};

if (document.body.dataset.gaMeasurementId) {
    window.__enableCscAnalytics = load;
    if (sessionStorage.getItem(STORAGE_KEY) === '1') load();
}
