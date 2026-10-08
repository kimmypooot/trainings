{{--
    The IMIS loading screens at first paint, as in every system under the IMIS
    hub (styles: resources/css/imis-loading.css; removed by
    resources/js/imisBoot.js once the first page renders).

    $system: the system's short name, e.g. "ProCTAD".
    $office: the line under "Civil Service Commission" on the preloader, e.g.
      "Regional Office VIII" (TIMS reads it from config('office.name')).
    $opening: true on the page IMIS opens (/sso). IMIS has just shown its own
      light "Opening <system>" card, so the same card is drawn here instead of
      the navy preloader, and the page's splash takes over without a flash.
--}}
@if ($opening ?? false)
    <div id="boot-preloader" data-instant class="imis-tr" role="status" aria-live="polite" aria-busy="true">
        <div class="imis-tr__card">
            <div class="imis-tr__rings" aria-hidden="true">
                <svg class="imis-tr__ring-outer" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" class="imis-tr__track" stroke-width="2.5" />
                    <circle cx="12" cy="12" r="10" class="imis-tr__arc--navy" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="62.832" stroke-dashoffset="20" />
                </svg>
                <svg class="imis-tr__ring-inner" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="8" class="imis-tr__track" stroke-width="1.5" />
                    <circle cx="12" cy="12" r="8" class="imis-tr__arc--red" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="50.265" stroke-dashoffset="15" />
                </svg>
                <img src="/images/imis/csc-logo.png" class="imis-tr__logo" alt="" draggable="false">
            </div>
            <div class="imis-tr__msg">Opening {{ $system }}</div>
            <div class="imis-tr__sub">Signing you in from IMIS</div>
            <div class="imis-tr__dots" aria-hidden="true"><span class="is-active"></span><span></span><span></span></div>
        </div>
    </div>
@else
    <div id="boot-preloader" class="imis-preloader" role="status" aria-live="polite" aria-busy="true" aria-label="{{ $system }} is loading. Please wait.">
        <div class="imis-preloader__accent" aria-hidden="true"></div>
        <div class="imis-preloader__logo-wrap" aria-hidden="true">
            <img src="/images/imis/csc-logo.png" class="imis-preloader__logo" alt="" draggable="false">
        </div>
        <div aria-hidden="true">
            <span class="imis-preloader__agency-main">Civil Service Commission</span>
            <span class="imis-preloader__agency-sub">{{ $office }}</span>
        </div>
        <div class="imis-preloader__divider" aria-hidden="true"></div>
        <p class="imis-preloader__label" aria-hidden="true">
            <span>Loading</span>
            <span class="imis-preloader__dots"><span></span><span></span><span></span></span>
        </p>
        <div class="imis-preloader__bar-wrap" aria-hidden="true">
            <div class="imis-preloader__bar"></div>
        </div>
    </div>
@endif
