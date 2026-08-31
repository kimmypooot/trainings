/**
 * The icon set.
 *
 * Every icon is a 24×24 stroked path drawn on the same grid, so they share one
 * `viewBox`, `stroke-width`, and `stroke-linecap` — see AppIcon, which is the
 * only place those attributes are written down.
 *
 * Icons are referenced by name, never by path. A path string copied into a
 * component is a path string that will drift from the five other copies of it.
 *
 * Brand marks (the Google "G", the CSC seal) and decorative shapes are not
 * icons and deliberately do not live here: they carry their own fills, sizes,
 * and viewBoxes, and flattening them into this set would lose that.
 */
export const icons = {
    // Navigation and layout
    home: 'M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z',
    menu: 'M4 7h16M4 12h16M4 17h16',
    close: 'M6 6l12 12M18 6L6 18',
    'chevron-left': 'M15 6l-6 6 6 6',
    'chevron-right': 'M9 6l6 6-6 6',
    'chevron-down': 'm6 9 6 6 6-6',
    'arrow-left': 'M9 14l-4-4 4-4M5 10h9a5 5 0 0 1 0 10h-3',
    'arrow-right': 'M15 10l4 4-4 4M19 14H10a5 5 0 0 0-5 5v1',
    /*
     * A straight arrow, distinct from the turning `arrow-right` above.
     *
     * The two are not interchangeable: `arrow-right` doubles back on itself and
     * reads as "go to that", which is what a button leading somewhere else
     * wants. This one reads as "continue", which is what an inline "View
     * details" affordance at the foot of a card wants.
     */
    'arrow-forward': 'M5 12h14M13 5l7 7-7 7',
    'sign-out': 'M15 17l5-5-5-5M20 12H9M12 20H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h6',

    // Objects in the domain
    list: 'M4 6h16M4 12h16M4 18h10',
    calendar: 'M8 3v3M16 3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z',
    certificate: 'M12 4a5 5 0 1 1 0 10 5 5 0 0 1 0-10ZM9 14.5V21l3-1.8 3 1.8v-6.5',
    bookmark: 'M7 4h10v16l-5-3-5 3z',
    card: 'M3 10h18M5 6h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z',
    document: 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z',
    envelope: 'M3 7l9 6 9-6M3 7v10h18V7M3 7l9-4 9 4',
    building: 'M4 20h16M6 20V8l6-4 6 4v12M10 12h4',
    qr: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z',
    shield: 'M12 3l7 3v5c0 4.4-2.9 8.4-7 9.6C7.9 19.4 5 15.4 5 11V6l7-3Z',
    lock: 'M8 10V7a4 4 0 1 1 8 0v3M6 10h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z',
    'map-pin': 'M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z',
    // A price tag rather than a currency glyph: it names the *kind* of fact its
    // row carries, the way the calendar and pin do, instead of restating an
    // amount that already carries its own ₱.
    tag: 'M20.6 13.4 12 22l-9-9V3h10l7.6 7.6a2 2 0 0 1 0 2.8ZM7.5 7.5h.01',
    link: 'M10.5 13.5a4 4 0 0 0 5.7 0l2.3-2.3a4 4 0 0 0-5.7-5.7l-1.2 1.2M13.5 10.5a4 4 0 0 0-5.7 0l-2.3 2.3a4 4 0 0 0 5.7 5.7l1.2-1.2',
    phone: 'M4 5c0-.6.4-1 1-1h3l2 5-2.5 1.5a12 12 0 0 0 5 5L14 13l5 2v3c0 .6-.4 1-1 1h-1A15 15 0 0 1 4 6V5Z',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM12 4v2m0 12v2m8-8h-2M6 12H4m13.7-5.7-1.4 1.4M7.7 16.3l-1.4 1.4m0-11.4 1.4 1.4m8.6 8.6 1.4 1.4',
    analytics: 'M4 19V5m0 14h16M8 15V9m4 6v-3m4 3V7',

    // People
    user: 'M4.5 20a7.5 7.5 0 0 1 15 0M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7',
    users: 'M3 20a6 6 0 0 1 12 0M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M17 20a5 5 0 0 0-3-4.6M16 11a3 3 0 1 0 0-6',

    // Feedback and state
    bell: 'M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 13 6 9ZM10 18.5a2 2 0 0 0 4 0',
    check: 'M5 13l4 4L19 7',
    'check-circle': 'M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18ZM8.5 12.5l2.5 2.5 4.5-5',
    warning: 'M12 7v6M12 16.5v.5',
    // Carries its own circle, as `check-circle` does — this one is used inline
    // beside prose, where there is no tinted container to supply the shape.
    info: 'M12 16v-4M12 8h.01M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18Z',
    clock: 'M12 8v4l2.5 2.5M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z',
    eye: 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z',
    'eye-off': 'M4 20 20 4',
    plus: 'M12 5v14M5 12h14',
    download: 'M12 4v11m0 0 4-4m-4 4-4-4M5 19h14',
    print: 'M7 8V3h10v5M7 18h10v3H7zM4 8h16a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-3v-4H7v4H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z',
    upload: 'M12 16V4m0 0L8 8m4-4 4 4M4 20h16',
    // Board plus clip, drawn as one path so it keeps the 1.8 stroke of the set.
    clipboard: 'M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1ZM8 6H6.5A1.5 1.5 0 0 0 5 7.5v12A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5v-12A1.5 1.5 0 0 0 17.5 6H16',
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14ZM20 20l-4.05-4.05',
};

/** Names, for prop validators. */
export const iconNames = Object.keys(icons);
