# Prompt: CSC TIMS — Home Page & Login Page

Copy everything below the line into Claude Code.

---

Build the **Home page** and **Login page** for CSC TIMS in this Laravel + Inertia + Vue 3 + Tailwind v4 project. This is the first UI work in the repo, so establish the design foundation as you go — later pages will follow it.

## Current state (verify before assuming)

- Laravel 13, PHP 8.3, `inertiajs/inertia-laravel` installed.
- `resources/js/app.js` already bootstraps Inertia with pages resolved from `resources/js/Pages/**/*.vue` and the title template `"{title} - CSC TIMS"`.
- There is **no** `resources/views/app.blade.php` root view, **no** `HandleInertiaRequests` middleware registered, and **no** `Pages/` directory yet. Create and wire these up.
- Tailwind v4 is configured via `@tailwindcss/vite`; theme tokens live in the `@theme` block in `resources/css/app.css`. Font is Instrument Sans (already loaded via the Vite fonts plugin).
- Only `routes/web.php` exists with a single `/` closure returning the `welcome` view. Replace it.

## Brand

Define these as Tailwind v4 theme tokens in `resources/css/app.css` (inside `@theme`) so they're usable as `bg-csc-blue`, `text-csc-red`, etc. — never hardcode hex values in components.

| Token | Value | Role |
| --- | --- | --- |
| `--color-csc-blue` | `#2a338f` | Primary. Headers, primary buttons, the login panel, headings. |
| `--color-csc-red` | `#ec1c2d` | Accent only. CTA hover/active, active nav underline, focus rings, error states, small emphasis marks. |
| white | `#ffffff` | Surfaces, cards, body background, text on blue. |

Also derive and register a small set of supporting tints so the UI isn't only three flat colors: a light blue tint for section backgrounds (~`#eef0f9`), a deep blue for gradient ends / hover on primary (~`#1e2668`), and neutral grays for body text (`#374151`) and borders (`#e5e7eb`).

**Color discipline:** blue carries the layout, white carries the content, red is used sparingly — think one or two red elements per viewport, never large red fills.

## Deliverables

### 1. Foundation

- `resources/views/app.blade.php` — Inertia root view with `@vite(['resources/css/app.css', 'resources/js/app.js'])`, `@inertiaHead`, `@inertia`, and a favicon link.
- `app/Http/Middleware/HandleInertiaRequests.php` registered in `bootstrap/app.php`, sharing at minimum the app name and flash messages.
- Brand tokens in `resources/css/app.css`.
- Shared components in `resources/js/Components/`: `AppButton.vue` (variants: `primary` blue-filled, `accent` red-filled, `ghost` outlined), `AppInput.vue` (label, error slot, focus ring in csc-blue), `AppLogo.vue`.
- A `resources/js/Layouts/PublicLayout.vue` used by the Home page (header + footer). The Login page does **not** use it — it is a standalone full-viewport layout.
- Delete `resources/views/welcome.blade.php`.

### 2. Home page — `resources/js/Pages/Home.vue`, route `GET /` named `home`

Sections, top to bottom:

1. **Header** — white bar, CSC logo left, nav right (`Home`, `About`, `Programs`, `Contact`), and a `Login` button in csc-blue on the far right. Active nav item marked with a 2px csc-red underline. Sticky on scroll with a subtle shadow once scrolled. Collapses to a hamburger menu below `md`.
2. **Hero** — full-width csc-blue background (subtle gradient toward the deeper blue), white heading and supporting copy, two CTAs: a primary white-on-blue button ("Get Started" → login) and a ghost outlined button ("Learn More"). Right side holds an illustration slot or a stylized graphic — do not fetch external images; use inline SVG or a CSS composition.
3. **Feature cards** — white section, 3 cards on a light-blue tinted background. Each card: icon in a blue circle, title, one-line description, and a red top-border accent that appears on hover. Grid of 3 → 2 → 1 across breakpoints.
4. **Stats strip** — csc-blue band with 3–4 white stat figures and labels.
5. **CTA band** — light tint background, short heading, one red primary button.
6. **Footer** — deep blue, logo, three short link columns, copyright line.

Copy can be sensible placeholder text for a Civil Service Commission training/information management system. Keep it short and professional — no lorem ipsum.

### 3. Login page — `resources/js/Pages/Auth/Login.vue`, route `GET /login` named `login`

**Split-screen, form on the right.** Exact requirements:

- Two-column layout at `lg` and above, each column exactly 50% width and `min-h-screen`.
- **Left panel (branding):** csc-blue background with a subtle gradient toward the deeper blue, plus a low-opacity decorative geometric SVG pattern. Contains, vertically centered: the CSC logo in white, a welcome headline, one or two lines of supporting copy, and a short white/red divider mark. Purely decorative — no form controls.
- **Right panel (form):** white background, form vertically and horizontally centered with `max-w-md` and generous padding.
  - Heading "Sign in to your account" + subheading.
  - Email field, Password field with a show/hide toggle.
  - "Remember me" checkbox left, "Forgot password?" link (csc-blue, red on hover) right, on the same row.
  - Full-width submit button in csc-blue, hover shifts to csc-red, with a loading/disabled state bound to Inertia's `processing`.
  - Server-side validation errors rendered under each field in csc-red; a general error alert above the form.
- **Below `lg`:** the left panel collapses away — hide it and show a compact csc-blue header strip with the logo above the form, so the mobile view is a single centered column.

Use Inertia's `useForm` for state. Wire `POST /login` to a `LoginController@store` that validates `email` (required, email) and `password` (required) and, for now, redirects back with an error since auth isn't implemented yet — leave a clear `// TODO: authenticate` marker. Do not install a starter kit or auth package.

### 4. Logo

There is no logo asset in the repo. Create `resources/js/Components/AppLogo.vue` as an inline SVG placeholder: a blue roundel with a red accent element and the "CSC TIMS" wordmark, accepting `variant="light|dark"` and a size prop. Note in the component with a comment that it should be swapped for the official asset.

## Quality bar

- Responsive and tested mentally at 375px, 768px, 1024px, 1440px. No horizontal scroll at any width.
- Accessibility: real `<label>` elements tied to inputs, visible focus rings (csc-blue, red on the primary CTA), `aria-invalid` and `aria-describedby` on errored fields, semantic landmarks (`header`/`main`/`footer`/`nav`), decorative SVGs `aria-hidden`. Text on colored backgrounds must clear WCAG AA — check white on `#ec1c2d` specifically and switch to a darker red or larger/bolder text if it fails.
- Vue 3 `<script setup>`, Composition API, no Options API.
- Tailwind utility classes only; no separate component CSS files, no `!important`, no inline `style` except for genuinely dynamic values.
- Transitions on hover/focus states (150–200ms), and respect `prefers-reduced-motion`.

## Done means

`npm run build` succeeds, `php artisan route:list` shows `home`, `login`, and the login POST route, and both pages render correctly with `npm run dev` + `php artisan serve`. Run `vendor/bin/pint` on any PHP you touch. Show me a short summary of the files created and anything you deviated on.
