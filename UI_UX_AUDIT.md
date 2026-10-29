# SiaSurf — Current UI/UX Audit

**Audit date:** 2026-10-26
**Scope:** `siasurf-app/resources/js` (93 `.vue` files, 16,232 lines), `resources/css/app.css`, `resources/views/app.blade.php`, `tailwind.config.js`, `DESIGN.md`, `PRODUCT.md`, plus `screenshots/`
**Purpose:** Establish exactly what is implemented today, so an incoming inspiration UI can be mapped onto it deliberately instead of pasted on top.

---

## 0. TL;DR Scorecard

| Dimension | Score | Verdict |
|---|---|---|
| Visual identity | 6/10 | Distinctive "ocean ledger" instinct, but three different brands compete |
| Design-system discipline | 4/10 | Tokens exist and are bypassed; `DESIGN.md` is aspirational, not descriptive |
| Typography | 4/10 | 419 `font-black` + 307 `text-[10px]` — hierarchy is flat and micro-text is unreadable |
| Color & contrast | 3/10 | `slate-400` used 293× at 2.56:1 contrast (WCAG AA needs 4.5:1) |
| Accessibility | 3/10 | No `aria-live`, no `focus-visible`, no reduced-motion, 10 images without `alt` |
| Component consistency | 4/10 | 4 layouts, 3 button systems, 3 icon packages, dead imports |
| Public UX | 5/10 | Landing is polished; discovery/profile are dead-ends inside an app shell |
| Transactional UX | 6/10 | Clear 6-step flow, but displayed price ≠ charged price (2× mismatch) |
| Admin/ops UX | 7/10 | Dense, purposeful, best-executed area of the product |
| Responsive | 6/10 | Grids collapse correctly; some wrappers double-scroll, 10px text hurts mobile |
| Dark mode | 0/10 | Zero `dark:` classes (Phase 10 requires it) |

**One-line conclusion:** the product has a genuine visual idea (bento "verified ledger") and strong operational screens, but no enforced system. It reads as three products stitched together — a teal marketing site, a blue booking app, and an ocean/sand auth screen — with a pervasive micro-typography and contrast problem that undermines the trust the product is selling.

---

## 1. What Is Actually Implemented

### 1.1 Stack (verified)

| Layer | Reality |
|---|---|
| Framework | Laravel 12 + Inertia 2 + Vue 3.4 + TypeScript |
| Styling | Tailwind CSS **3.2** via PostCSS (`postcss.config.js`) |
| Tailwind v4 | `@tailwindcss/vite ^4.0.0` installed but **unused** in `vite.config.js` — dead dependency, migration trap |
| Fonts | Outfit loaded **twice**: `app.css` Google import (400–900) and `app.blade.php` link (400–700). Cinzel loaded in Blade but never wired to a Tailwind family |
| Icons | `@heroicons/vue` (36 imports), `@lucide/vue` (1), `lucide-vue-next` (0) — three packages for one job |
| Charts | Hand-rolled SVG/CSS, client-side `fetch('/admin/api/dashboard/...')` — no chart library |
| Maps | Leaflet + OSM (`LiveMap.vue`) |
| Real-time | laravel-echo + pusher-js |
| Dark mode | Not implemented (`dark:` = 0) |

### 1.2 Page inventory

| Area | Pages |
|---|---|
| Public | 5 (`Welcome`, `Instructors/Search`, `Instructors/Profile`, `SurfSpots/Index`, `WalkIn/Scan`) |
| Auth | 6 (login, register, forgot/reset, verify, confirm) |
| Booking | 5 (`Create`, `Waiver`, `ParentalConsent`, `Payment`, `Confirmation`) |
| Student | 6 (dashboard, bookings index/show, reviews index/create, feedback hub) |
| Instructor | 8 (dashboard, onboarding, pending, suspended, availability, bookings index/show, profile edit) |
| Admin | 19 (dashboard, instructors, students, bookings, incidents, appeals, change requests, skill upgrades, live sessions, surf spots, analytics) |
| Payment / Profile | 7 |

### 1.3 Layout architecture

```
AppLayout ──wraps──> AppSidebarLayout (25 usages indirect, 18 direct)
                        ├─ fixed 288px sidebar (role-aware: admin / instructor / student)
                        ├─ sticky glass top bar (64/80px) + notification dropdown
                        └─ main max-w-[1600px], p-4 → p-8
GuestLayout ─ cinematic 50/50 split (image left, form right)
AuthenticatedLayout ─ Breeze legacy, used by 1 stub page (Pages/Dashboard.vue "You're logged in!")
```

Four layouts for three roles. `AppSidebarLayout` is also used by the **public** search and profile pages; guests get `shouldShowSidebar = false`, which leaves a bare bar with only "Page title / Login / Join SiaSurf".

### 1.4 Design tokens (declared)

`tailwind.config.js` defines four ramps:

| Token | Base | Declared purpose | Actual usage count |
|---|---|---|---|
| `ocean` | `#0284c7` | verification, primary | **623** |
| `reef` | `#0d9488` | success, level labels | **28** |
| `sand` | `#c4ab7c` | page grounds, paper | **46** |
| `wave` | `#818cf8` | tertiary accent | **29** |
| `slate` (default) | `#0f172a` | ink / shell | **1,904** |
| `teal` (Tailwind default, not a token) | `#0d9488` | — | **106** |

Shadows: `ocean`, `reef`, `sand`, `wave`, `glow-ocean`, `glow-reef`.
Motion: `wave` 3s, `float` 6s, `pulse-slow` 4s, `shimmer` 2s.
Radii are **not** declared in config; pages use raw `rounded-2xl` (243), `rounded-3xl` (84), `rounded-full` (132), plus arbitrary `rounded-[2rem]` / `rounded-[2.5rem]`.

### 1.5 Component inventory

| Tier | Components | State |
|---|---|---|
| Atoms | `BaseButton`, `Badge`, `SidebarLink`, `StarRating` | Valid, lightly used |
| Molecules | `BookingStepper`, `InstructorCard`, `NotificationDropdown`, `RateCapDialog`, `ReviewCard`, `SignaturePad`, `StatCard` | `StatCard` imported by admin dashboard but never rendered (dead import) |
| Organisms | `ActivityFeed`, `RevenueChart`, `BookingDistributionChart`, `PopularSpotsChart`, `TopInstructorsTable`, `LiveMap` (static surf-spot pins only) | Real, functional |
| Legacy Breeze | `PrimaryButton`, `SecondaryButton`, `DangerButton`, `TextInput`, `InputLabel`, `InputError`, `Checkbox`, `Modal`, `Dropdown`, `DropdownLink`, `NavLink`, `ResponsiveNavLink` | Still used by auth + profile |

**Three parallel button systems:**

1. `BaseButton` → `rounded-lg`, `bg-teal-600`, `font-medium`
2. `PrimaryButton` (Breeze) → `rounded-md`, `bg-slate-800`, `uppercase tracking-widest`
3. ~100 hand-written inline CTAs → `rounded-full`, `bg-ocean-600`, `px-8 py-3.5`, `font-black`, `shadow-xl shadow-ocean-100`, `active:scale-95`

The same action ("view", "book", "save") renders differently on nearly every page.

---

## 2. The Current Design Language (what it actually looks like)

From `screenshots/01-public-welcome.png` and `02-public-instructor-search.png`:

**Marketing (`Welcome.vue`)**

- Full-bleed 100vh surf photo, white display type, `teal-300` italic accent on "Verified"
- Pill CTA in **teal-600**, glass secondary CTA
- Wave SVG divider into `slate-50`
- 4-up stat strip (500+ / 12k+ / 100% / PayMongo)
- 3 feature cards → 3 spot cards → 2 instructor cards → full-bleed teal CTA → slate-900 footer
- Verdict: attractive but generic surf-tourism landing page. **Zero `ocean-*` usage** — it is a teal site, not the documented ocean system.

**Product (`Search`, dashboards)**

- White cards on `slate-50`, 40px radii, `shadow-sm`
- `font-black` everywhere; `text-[10px] uppercase tracking-widest` micro-labels on every field
- Ocean-blue primary buttons; teal only inside `InstructorCard` badges
- Verdict: reads as a different brand from the landing page.

**Auth (`Login.vue` + `GuestLayout`)**

- Cinematic split image, `sand-50` ground, `ocean-900` headings, `sand-600` subtitles, `reef-600` status
- Verdict: closest to `DESIGN.md`, but `sand-600` on `sand-50` is 2.66:1 contrast.

**Admin**

- Dense bento stats, live gradient session block, audit table with mono IDs, progress bars, uppercase column heads
- Verdict: the strongest execution — it genuinely looks like an operations console.

### The visual signature that repeats

1. **Bento cards** — `rounded-[2rem]`/`rounded-[2.5rem]`, white, hairline `border-slate-100`
2. **Stamp pills** — verification `rounded-full bg-white/90 backdrop-blur-md text-teal-600`
3. **Micro-caps labels** — 10px, 900 weight, ~0.2em tracking, `text-slate-400`
4. **Oversized numbers** — `text-4xl font-black` tabular
5. **Ambient motion** — float/pulse/shimmer, hover lift `-translate-y-1`, `hover:scale-[1.02]`
6. **Ocean gradient hero blocks** — `from-ocean-600 to-ocean-800` with blurred white blobs

---

## 3. The Real Problem: Three Design Systems

| | Marketing | Auth | Product / Admin |
|---|---|---|---|
| Primary hue | `teal-600 #0d9488` | `ocean-900 → ocean-600` | `ocean-600 #0284c7` |
| Ground | photography → `slate-50` | `sand-50 #fdfbf7` | `slate-50 #f8fafc` |
| Radius | `rounded-full`, `rounded-[2rem]` | `rounded-xl` | `rounded-[2rem]`, `rounded-[2.5rem]` |
| Type weight | `font-bold` / `font-extrabold` | `font-extrabold` | `font-black` (900) |
| Labels | `text-xs uppercase tracking-widest` | `text-sm` | `text-[10px] uppercase tracking-[0.2em]` |
| CTA shape | pill | Breeze rounded-md | pill + rounded-2xl mixed |
| Status color | teal | reef | ocean + emerald + rose |

`DESIGN.md` describes a fourth, theoretical system ("The Reef Ledger": sand grounds, ocean/reef verification, Cinzel seal). Reality:

- Sand is used **46×** against **1,904** slate usages — the "sand ground rule" is not in force
- `font-serif` is used **0×** and `tailwind.config.js` has no `serif` family — Cinzel is downloaded and never rendered
- `DESIGN.md` bans `border-l-4` (0 uses — good) and `animate-bounce` (1 use remains), yet 326 instances of 9–10px text violate its own legibility intent

**Conclusion:** `DESIGN.md` is a design *proposal*, not a description of the codebase. Any new inspiration UI must establish one enforceable source of truth, or it will become a fifth system.

---

## 4. Findings Register

Severity: **P0** = trust/legal/accessibility blocker · **P1** = visibly inconsistent or broken UX · **P2** = polish/debt

### P0 — Blockers

| # | Finding | Evidence | Impact |
|---|---|---|---|
| P0-1 | **Displayed price is double the charged price** | `Booking/Create.vue` estimates `rate × 2 hours × students`; `PricingService::STANDARD_HOURS = 1` and `calculatePrice()` multiplies by 1; `BookingController:218` stores `pricing['total']` | Tourist sees ₱1,200, is charged ₱600. Dispute and trust failure in the core transaction |
| P0-2 | **A third conflicting price truth** | `AGENTS.md` BR-4.2 says "standard 2-hour package ₱1,200"; `PricingService::STANDARD_PACKAGE_PRICE = 800` is declared but unused | Nobody can state what a lesson costs |
| P0-3 | **293 uses of `text-slate-400` at 2.56:1** | Measured: `#94a3b8` on `#ffffff` = 2.56:1; on `#f8fafc` = 2.45:1 | WCAG 2.1 AA failure for all normal text, and it is the default for every micro-label |
| P0-4 | **307 instances of `text-[10px]`** (+13 at 11px, +6 at 9px) | `Instructor/Bookings/Show` 29 · `Admin/Bookings/Show` 25 · `Admin/Dashboard` 22 · `Instructor/Availability/Index` 20 | Illegible on a phone at the beach — the primary persona and context |
| P0-5 | **No `aria-live` / `role="alert"` anywhere** | `role="alert"` = 0, `aria-live` = 0 across 93 files | Safety-rule violations and payment errors — the product's most important messages — are silent to screen readers |
| P0-6 | **Public discovery has no wayfinding** | `Public/Instructors/Search.vue:70` and `Profile.vue:47` render `AppSidebarLayout`; guests see only "Instructor Discovery / Login / Join SiaSurf" (confirmed in screenshot 02) | No link home, to surf spots, or to safety info. Dead-end for the tourist journey |
| P0-7 | **10 images without `alt`** | Admin instructors, instructor edit, public profile, walk-in scan, admin bookings, student dashboard | Screen readers announce filenames; fails WCAG 1.1.1 |

### P1 — Visibly inconsistent

| # | Finding | Evidence |
|---|---|---|
| P1-1 | Three brand primaries coexist | `Welcome.vue` teal only; `Login.vue` ocean/sand/reef; `AppSidebarLayout` ocean; `InstructorCard` teal |
| P1-2 | Flat type hierarchy | 419 `font-black` vs 307 `text-[10px]`; nearly every heading and label is weight 900 |
| P1-3 | Radius inflation | 132 `rounded-full` + 243 `rounded-2xl` + 84 `rounded-3xl` + arbitrary `[2rem]`/`[2.5rem]`; cards differ between landing, search, profile |
| P1-4 | Three button styles for the same action | See §1.5 |
| P1-5 | Two layouts in production + one stub | `AppLayout` → `AppSidebarLayout`, plus `AuthenticatedLayout` still routed via `Pages/Dashboard.vue` |
| P1-6 | Emoji used as UI icons | `Search.vue:163` 🏄 in H2; availability ☀️🌊⚡🏖️🔥; instructor dashboard greeting 🏄; booking show 🏄 |
| P1-7 | Icon set split | Heroicons 36 files, Lucide 1 file, a second Lucide package unused |
| P1-8 | No dark mode | `dark:` = 0 despite AGENTS.md Phase 10 |
| P1-9 | No reduced-motion support | `prefers-reduced-motion` = 0; four infinite animations + `animate-ping` + `animate-float` + 28 `hover:scale` |
| P1-10 | Filter labels not associated | `Search.vue:107,122,131,144` — `<label>` without `for`, `<select>`/`<input>` without `id` |
| P1-11 | Booking flow loses context on reload | Six separate Inertia pages (`Create → Waiver → ParentalConsent → Payment → Confirmation`); stepper is server-driven, no draft persistence |
| P1-12 | **The 15-minute payment hold is invisible** | `BR-5.1` requires a 15-minute hold; `Booking/Payment.vue` has no countdown, expiry copy, or `expires_at` display (grep for timer/expires = 0). Payment page also uses `rounded-2xl`/`border-slate-200`, a different generation from the `rounded-[2.5rem]` bento used elsewhere |

### P2 — Debt / polish

| # | Finding | Evidence |
|---|---|---|
| P2-1 | Font loaded twice | `app.css:1` (400–900) + `app.blade.php:12` (400–700) |
| P2-2 | Cinzel downloaded but unused | Blade link exists; `font-serif` = 0; no `serif` family in config |
| P2-3 | Tailwind v4 package unused | `@tailwindcss/vite ^4.0.0` in devDependencies |
| P2-4 | Dead import | `StatCard` in `Admin/Dashboard.vue:4`, never rendered |
| P2-5 | Arbitrary hex instead of token | `AppSidebarLayout.vue:92` `bg-[#F8FAFC]` |
| P2-6 | `transition-all` on 140 elements | Animates layout properties; jank and perf cost |
| P2-7 | Charts swallow errors | `RevenueChart.vue:16-17` logs to console; no user-facing error or empty state |
| P2-8 | Screenshot pipeline broken | `12-student-dashboard.png` is a 500 "out of memory"; `27-instructor-dashboard.png` is blank |
| P2-9 | Placeholder credibility content | "500+ Verified Instructors", "12k+ Safe Sessions", demo instructors on a verification platform |
| P2-10 | Copy register whiplash | "Hang loose!", "Kumusta", "shaka@siargao.com" beside institutional ledger micro-caps |

---

## 5. Accessibility Detail (measured)

Contrast ratios computed against the actual token hex values:

| Pair | Ratio | AA normal (4.5) | AA large (3.0) | Where it is used |
|---|---|---|---|---|
| `slate-400` on white | **2.56** | Fail | Fail | 293 places — all micro-labels, table heads, helper text |
| `slate-400` on `slate-50` | **2.45** | Fail | Fail | Page grounds, card metadata |
| `sand-600` on `sand-50` | **2.66** | Fail | Fail | Login/Register subtitles |
| `teal-600` on `teal-50` | **3.59** | Fail | Pass | Level chips, verification pills |
| `ocean-500` on white | **2.77** | Fail | Fail | Icons, hover text |
| `ocean-600` on white | **4.10** | Fail | Pass | Links, secondary CTAs, "Experts" accent |
| `reef-600` on `sand-50` | **3.62** | Fail | Pass | Auth status messages |

Only `slate-500` on white (4.76) and `slate-900` on white clear AA for normal text. **The reliable palette is effectively two colors.**

Other gaps:

| Check | Result |
|---|---|
| `aria-label` | 13 instances (mostly icon-only nav buttons) |
| `aria-live` / `role="alert"` | 0 — validation + safety errors are not announced |
| `focus-visible:` | 0 — 149 `focus:` rings also fire on mouse click |
| `focus:outline-none` | 20 (some without a replacement ring) |
| Skip-to-content link | None |
| `sr-only` | 5 |
| `prefers-reduced-motion` | 0 |
| `loading="lazy"` | 0 of 27 images |
| `<img>` without `alt` | 10 |
| Labels without `for` | 4 filter groups in Search |
| Dark mode | 0 |

---

## 6. UX Journey Review

### 6.1 Tourist: landing → discovery → profile → booking

| Step | Assessment |
|---|---|
| Landing | Clear thesis ("Surf with Confidence. Verified by SiaSurf."), strong imagery, one obvious CTA. But the stat strip and instructor cards carry demo data, which undercuts a verification brand. |
| Discovery | Search + filter bar is good; auto-search on every keystroke (debounced 500ms) causes a full Inertia round-trip and page-level state churn. Results grid is clean. |
| **Navigation** | **Broken.** Once inside Search/Profile the marketing nav disappears. No logo-home link, no Surf Spots, no Safety Rules. The only exits are Login/Join. |
| Profile | Rich and credible: verification badge, level explanation, weekly availability, price, reviews. But the "Book This Expert" CTA and "Back to Discovery" fight for attention, and the page is inside a shell built for signed-in operators. |
| Booking step 1 | Dense single page: date, time slots, skill level, age, height/weight, student count, spot, board ownership, notes, price estimate, tide warning. This is the highest-cognitive-load screen in the product and it has no progressive disclosure. |
| **Price** | Shows 2-hour total; server charges 1 hour. See P0-1. |
| Waiver / consent | Dedicated pages with signature pad — appropriate gravity, good legal copy placement. |
| Payment | GCash vs cash choice is clear and well-designed. But there is **no visible 15-minute hold countdown**, no expiry copy, and the page uses an older visual generation (`rounded-2xl`, `border-slate-200`) that does not match the rest of the flow. |
| Confirmation | Ticket metaphor, next-step cards, arrival instruction. Best-composed transactional screen. |

### 6.2 Student dashboard

- Strong bento hero: ocean gradient welcome block, active-session countdown, surf report card, upcoming lessons, "Book Again".
- Weakness: the welcome block competes with the active session for primacy; if a session is live it should own the top of the page, not share it.
- `space-y-8` + `rounded-[2.5rem]` + `font-black` + 10px labels = visually loud but hard to scan.
- Screenshot 12 is a 500 error, so this page is currently unverified visually.

### 6.3 Instructor dashboard

- Correct priority: pending booking requests with accept countdowns, live session timer, earnings, strikes/standing, reviews.
- Best product thinking in the app (attendance due, vacation mode, strike visibility).
- Blank screenshot; needs recapture.

### 6.4 Admin

- Best-executed area: 5-up bento stats, live gradient session monitor, audit table with progress bars, quick actions.
- 19 pages is a large surface; the sidebar lists 11 items with two different icons reused (`UserGroupIcon` for Instructors *and* Students, `MapPinIcon` for Live Sessions *and* Surf Spots) — scanning cost.
- Charts load via client `fetch` after mount with only a spinner; no error state.

### 6.5 Cross-cutting UX observations

1. **No global toast/modal system.** Notifications are per-page; destructive actions rely on browser/native confirms.
2. **No loading skeletons.** Spinners only; layout shifts as data arrives.
3. **No persistent primary navigation for public users** (see P0-6).
4. **Two "home" concepts.** `AppSidebarLayout` logo always routes to `dashboard`, which differs by role; the marketing logo routes to `/`. Users cannot predict where the logo goes.
5. **Countdowns are the product's signature interaction** (session time left, accept-request window, payment hold). They exist, but with three different visual treatments and no shared component.

---

## 7. Implementation-Level Observations

These matter because an inspiration UI will land on this code, not on a blank slate.

| # | Observation | Why it matters |
|---|---|---|
| 7.1 | **No token layer between design and code.** Colors/fonts live in `tailwind.config.js`, but radii, spacing, type scale, and component variants are hard-coded per file | A visual redesign means editing ~93 files; no theme swap is possible |
| 7.2 | **Utility soup.** Long inline class strings (some 300+ chars) with no `@apply` abstraction except `glass-base`, `glass-dark`, `ocean-glow` | Changing a card style requires regex across the repo |
| 7.3 | **Inconsistent formatting engine.** Mix of Breeze components, Atoms/Molecules, and raw HTML | New pages copy whichever pattern the author last saw |
| 7.4 | **Every page imports its layout directly.** `AppSidebarLayout` (18), `AppLayout` (25), `GuestLayout` (6) | A new shell must be rolled out file-by-file; parallel layouts must be retired first |
| 7.5 | **Inertia page-per-entry build.** `app.blade.php` does `@vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])` | 93 entry points; large JS graph, slower cold loads on 3G/beach wifi |
| 7.6 | **Client-side data fetches inside charts** with no shared request layer | Duplicated `fetch()` + `isLoading` + `console.error` logic in 4 components |
| 7.7 | **Price logic duplicated** between `Booking/Create.vue` and `PricingService` | Two truths that already disagree (P0-1) |
| 7.8 | **No visual regression baseline** | Screenshots are stale/broken; before/after comparison is impossible until recaptured |
| 7.9 | **TypeScript is shallow** | Many `any` props (`instructor: any`, `safetyRules: any`) — refactors will not be caught by `vue-tsc` |
| 7.10 | **Accessibility is not enforced** | No eslint-plugin-vuejs-accessibility, no axe tests, no CI gate |

---

## 8. What Must Be Preserved (regardless of the inspiration UI)

These are product-defining, not decorative. The inspo must accommodate them:

1. **Verification affordance.** The checked "Verified" stamp must remain the loudest trust signal on any instructor surface.
2. **Safety copy is first-class.** `SafetyViolationException` messages are user-facing product UI, not error toasts. They need a dedicated, high-contrast, announced presentation.
3. **Level semantics.** Level 1 = beginner only, max 2 · Level 2 = 1-on-1 · Level 3 = group ≤5. These must stay legible at a glance and never rely on color alone.
4. **Live session countdown + strike standing.** Instructor and admin dashboards must keep time-remaining and strike visibility above the fold.
5. **Six-step booking stepper.** Order and labels: Details → Safety → Waiver → Consent → Payment → Confirmed.
6. **Role-aware shell.** Admin density is a feature; do not flatten admin into the student layout.
7. **Mobile-first walk-in path.** QR scan → profile → book must survive on a 375px screen in sunlight.
8. **Trust content rules.** No fabricated counts or testimonials; placeholder stats must become real aggregates or be removed.

---

## 9. Recommended Sequence Before Applying the Inspo

Applying a new visual language on top of three existing systems will fail. Suggested order:

**Phase A — Foundation (no visual change)**

1. Decide the single brand primary (ocean `#0284c7` vs teal/reef `#0d9488`) and delete the loser.
2. Move radii, type scale, spacing, and motion into `tailwind.config.js` as named tokens (`rounded-card`, `text-label`, `shadow-lift`).
3. Delete `@tailwindcss/vite`, `lucide-vue-next`, the dead `StatCard` import, and the duplicate font import.
4. Retire `AuthenticatedLayout` + `Pages/Dashboard.vue` stub; keep `AppSidebarLayout` + `GuestLayout` only.
5. Fix P0-1/P0-2 (single pricing source) before touching checkout visuals.

**Phase B — Accessible primitives**

6. Raise the floor: no text below 12px; `slate-400` body text → `slate-600`; add `focus-visible` rings, `aria-live`, `alt`, `loading="lazy"`, reduced-motion guard.
7. Build one `Button`, `Card`, `Input`, `Badge`, `Stat`, `EmptyState`, `Countdown` on the new tokens.
8. Replace inline CTAs page-by-page with the primitives.

**Phase C — Structure**

9. Give public pages their own `PublicLayout` (logo → home, Instructors, Surf Spots, Safety, Login/CTA).
10. Break the booking form into progressive sections with a sticky price summary.
11. Standardize loading (skeletons) and error/empty states.

**Phase D — Adopt the inspiration**

12. Map the inspo's palette/type/radius to token names, not to raw files.
13. Restyle the 8 primitives; every page inherits.
14. Hand-tune only the 6 hero surfaces: landing, discovery, profile, booking step 1, student dashboard, admin dashboard.

---

## 10. What I Need From You to Start

To turn the inspiration UI into an implementation plan, please share:

1. **The reference itself** — screenshot(s), Figma link, or live URL. If it is an image, tell me which screens it covers.
2. **Which surfaces should adopt it** — all roles, or only public/student first?
3. **What you like about it** — layout, type, color, density, motion, or specific components. This determines what gets extracted into tokens vs copied.
4. **Hard constraints** — must the teal/ocean/sand identity survive? Must the sidebar stay? Is photography mandatory?
5. **Dark mode** — in scope now or later?

Once I have those, I will produce: a token diff, a component mapping table (inspo element → existing SiaSurf component), a surface-by-surface implementation order, and the first restyled screen for review.

---

## Appendix — Measured Metrics

| Metric | Value |
|---|---|
| Vue single-file components | 93 |
| Total frontend lines | 16,232 |
| `ocean-*` class usages | 623 |
| `slate-*` class usages | 1,904 |
| `teal-*` class usages (non-token) | 106 |
| `sand-*` class usages | 46 |
| `reef-*` class usages | 28 |
| `wave-*` class usages | 29 |
| `font-black` (900) | 419 |
| `text-[10px]` | 307 |
| `text-[11px]` / `text-[9px]` | 13 / 6 |
| `text-slate-400` | 293 |
| `text-slate-500` | 243 |
| `rounded-full` | 132 |
| `rounded-3xl` | 84 |
| `rounded-2xl` | 243 |
| `transition-all` | 140 |
| `hover:scale` | 28 |
| `aria-*` attributes | 19 |
| `role="alert"` / `aria-live` | 0 |
| `focus-visible:` | 0 |
| `dark:` | 0 |
| `prefers-reduced-motion` | 0 |
| `<img>` / missing `alt` | 27 / 10 |
| `loading="lazy"` | 0 |
| Layouts in use | 4 |
| Icon packages installed | 3 |
