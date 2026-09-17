---
name: SiaSurf
description: Verified surf booking and live safety monitoring for Siargao Island
colors:
  ocean-primary: "#0284c7"
  ocean-500: "#0ea5e9"
  ocean-600: "#0284c7"
  ocean-700: "#0369a1"
  reef-primary: "#0d9488"
  reef-500: "#14b8a6"
  sand-primary: "#c4ab7c"
  sand-50: "#fdfbf7"
  sand-100: "#f7f3eb"
  wave-primary: "#818cf8"
  wave-600: "#6366f1"
  neutral-slate-900: "#0f172a"
  neutral-slate-50: "#f8fafc"
  neutral-white: "#ffffff"
  critical-rose-500: "#f43f5e"
  success-emerald-600: "#059669"
typography:
  display:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2.25rem, 6vw, 4.5rem)"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(1.5rem, 3vw, 1.875rem)"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.01em"
  title:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "normal"
  body:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
  label:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.625rem"
    fontWeight: 900
    lineHeight: 1.2
    letterSpacing: "0.12em"
  serif-accent:
    fontFamily: "Cinzel, ui-serif, Georgia, serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  full: "9999px"
spacing:
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  2xl: "40px"
  section: "96px"
components:
  button-primary:
    backgroundColor: "{colors.ocean-primary}"
    textColor: "{colors.neutral-white}"
    rounded: "{rounded.full}"
    padding: "12px 20px"
  button-primary-hover:
    backgroundColor: "{colors.ocean-700}"
    textColor: "{colors.neutral-white}"
    rounded: "{rounded.full}"
    padding: "12px 20px"
  button-secondary:
    backgroundColor: "{colors.neutral-white}"
    textColor: "{colors.neutral-slate-900}"
    rounded: "{rounded.full}"
    padding: "12px 20px"
  badge-verified:
    backgroundColor: "rgba(255,255,255,0.9)"
    textColor: "{colors.reef-primary}"
    rounded: "{rounded.full}"
    padding: "6px 12px"
  card-bento:
    backgroundColor: "{colors.neutral-white}"
    textColor: "{colors.neutral-slate-900}"
    rounded: "{rounded.xl}"
    padding: "28px"
  input-field:
    backgroundColor: "{colors.neutral-white}"
    textColor: "{colors.neutral-slate-900}"
    rounded: "{rounded.md}"
    padding: "10px 14px"
---

# Design System: SiaSurf

## Overview

**Creative North Star: "The Reef Ledger"**

SiaSurf is a shoreline registry, not a surf brochure. The system reads as a verified ledger brought to the beach: institutional calm, stamped validation, and live accountable monitoring. Every surface balances SISA authority with island approachability — tonal sand and slate grounds, ocean/reef as the single signal of verification, and bento containers that feel lifted only when they earn attention.

The philosophy is verified & sunlit. Density is composed, not crowded: wave-dividers, generous section padding (96px vertical rhythm), and rounded-[2rem] bento that breathe between tasks. Motion is ambient (wave 3s, float 6s, pulse-slow 4s, shimmer 2s) and never bounce-elastic; verification is tactile — badges, QR seals, and countdown bars — not decorative chrome. Anti-references preserved from detectors: no side-tab border-l-4, no gray text on colored fields, no purple-to-blue gradients, no Inter-as-display rendering, no cards nested in cards, no bounce easing.

**Key Characteristics:**
- Ledger-first: bento cards, audit tables, and real-time streams over marketing gloss
- Ocean-led signal: neutral grounds carried by sand/slate, ocean/reef reserved for verified state
- Soft-layered lift: flat at rest, ambient shadow+lift on interaction
- Tactile & verified: pill badges, stamp-like verification, and human-readable safety copy
- Mobile beach to desktop watchtower: same tokens, rhythmic density shifts

## Colors

A restrained, committed palette. Restrained neutrals (sand + slate) carry 70% of any screen; ocean/reef carry verification and live states; wave is an occasional tertiary for spot grading, never competing with verification.

### Primary
- **Siargao Cerulean** (#0284c7 / {colors.ocean-primary}): verification, primary actions, live session gradients (`gradient-ocean 135deg #0ea5e9 → #0284c7 → #0369a1`). Use on ≤12% of any screen; when it appears, it means verified/live.
- **Reef Teal** (#0d9488 / {colors.reef-primary}, ramp #14b8a6 #0d9488 #0f766e): success, instructor Level labels, secondary verification. Never beside ocean at equal weight; reef is the quieter authority.
- **Ocean 500** (#0ea5e9 / {colors.ocean-500}): lighter interactive hover for primary, not the default flat.

### Secondary
- **Wave Indigo** (#818cf8 / {colors.wave-primary}, {colors.wave-600} #6366f1): tertiary accent for spot grading and subtle analytics fills. Used sparingly; never for verification or pricing.
- **Critical Rose** (#f43f5e / {colors.critical-rose-500}): incidents only (`bg-rose-50` → `rose-500` bump). Not a general accent.

### Tertiary
- **Warm Sand** (#c4ab7c / {colors.sand-primary}, {colors.sand-50} #fdfbf7 / {colors.sand-100} #f7f3eb): page grounds (`bg-sand-50`, `bg-slate-50`), ledger paper, and calm dividers. Carries the beach light that keeps ocean from reading cold.

### Neutral
- **Slate Ink** (#0f172a / {colors.neutral-slate-900}): body text, navigation, footer. Never pure black (#000); always tinted slate.
- **Slate Shell** (#f8fafc / {colors.neutral-slate-50}): card paper and section grounds (`bg-white`, `bg-slate-50` alternation in `Welcome.vue:130,154,196,241`).
- **White** (#ffffff / {colors.neutral-white}): elevated cards, badges with `backdrop-blur-md`, audit tables.

### Named Rules
**The One Verification Color Rule.** Only ocean/reaf may signal verified/live/success. Sand, slate, wave, and rose never borrow that meaning; a teal badge off-system is a defect.
**The Sand Ground Rule.** Neutral grounds are tinted sand/slate, never pure gray (#e5e7eb). Gray washes verification; sand tints it.
**The 12% Cap Rule.** Saturated ocean/reef fields occupy ≤12% of any viewport; their rarity is the trust signal.

## Typography

**Display Font:** Outfit (with ui-sans-serif, system-ui)
**Body Font:** Outfit (same stack; weight and tracking carry hierarchy, not a second sans)
**Label/Mono Font:** Outfit tabular / JetBrains Mono where mono needed (IDs, countdown `font-mono`)
**Serif Accent Font:** Cinzel (with ui-serif, Georgia) — occasional heritage stamps and ceremonial headings only; not the display workhorse.

**Character:** Outfit is the ledger's type: geometric but warm, high legibility at small caps sizes, confident without editorial affectation. Cinzel appears as a scarce seal — heritage mark, not running text — so the system never drifts into cream-paper editorial or AI-default Fraunces/Playfair/Inter-as-display.

### Hierarchy
- **Display** (800, clamp(2.25rem, 6vw, 4.5rem), 1.0, -0.02em tracking): `Welcome.vue:92-95` hero, single-thesis hero only. Never below the fold.
- **Headline** (700, clamp(1.5rem, 3vw, 1.875rem), 1.2): section theses (`Engineered for Safety. Designed for the Ocean.`), bento titles uppercase tracking-tight.
- **Title** (700, 1.25rem, 1.3): card titles (`TopInstructorsTable`, `BookingDistribution`), instructor names.
- **Body** (400, 0.875rem, 1.6): descriptions, bio clamps (`line-clamp-2`), table copy. Max 65–72ch for docs; cards cap at ~48ch.
- **Label** (900, 0.625rem / 10px, 1.2, 0.12em, uppercase): eyebrow chips (`text-[10px] font-black uppercase tracking-widest`), badges (`Verified`, `Level 3 Expert`, `+3 today`). Never set sentence-case at this size.

### Named Rules
**The Weight-Not-Face Rule.** Hierarchy is built with weight (400→700→900) and tracking (normal→widest), not a second family. Introducing a new display face requires an explicit ledger reason.
**The Cinzel Seal Rule.** Cinzel appears ≤1× per viewport, as a seal or ceremony; running headings stay Outfit. If a page would guess Outfit from category alone, it passes; if it would guess Cinzel-everywhere, it fails the self-check.

## Layout

Spatial model is a **bento ledger grid**: 12-column max-w-7xl containers (`mx-auto max-w-7xl`), section vertical rhythm `96px` (`py-24`) with interior card padding `28–40px` (`p-7`, `p-8`, `p-10`). Card rhythm: `gap-6` for stat grids, `gap-8` for secondary grids, `gap-12` for feature triads (`Welcome.vue:133,161,203,253`). Density: hero is thesis-dense (single viewport, full-bleed), ledger grids are airy with hover lift; never uniform density throughout.

Responsive: mobile beach is single-column, stacked full-width CTAs (`flex-col` → `sm:flex-row`), cards lose side-by-side imagery. `md` unlocks 2-up bento, `lg` unlocks 3–5 up (`lg:grid-cols-3`, `lg:grid-cols-4 xl:grid-cols-5` in `Admin/Dashboard.vue:83`). Navigation is full at `md` (hidden below, hamburger via `Components/ResponsiveNavLink` pattern), guest cinematic split only at `lg` (`GuestLayout.vue:9`).

Container behavior: bleeding imagery (hero, instructor card `h-48 overflow-hidden group-hover:scale-110`) is edge-to-edge inside `rounded-[2rem]` clipping; audit tables scroll horizontally (`overflow-x-auto`) rather than stacking at mid-breakpoints.

Spacing rhythm: 8px base (Tailwind 2 → 0.5rem). Stack steps: 8 / 16 / 24 / 32 / 40 within components; 96 anchors sections; 12px–20px anchors button internals. Consistent `tracking-widest` micro-labels get `mb-1` separation from 4xl numbers to hold hierarchy without extra space.

## Elevation & Depth

Flat-by-default, lifted-on-intent. At rest, surfaces are flat tonal layering (white cards on sand/slate grounds with `border border-slate-100`). Depth appears only as response to interaction: hover lifts (`hover:-translate-y-1`, `hover:-translate-y-2`), soft ambient shadows, and live pulse glows for active sessions. This keeps the ledger calm and the live stream unmistakably live.

### Shadow Vocabulary
- **Ocean lift** (`box-shadow: 0 4px 20px -2px rgba(14,165,233,0.25)` / `shadow-ocean`): bento stat cards on hover (`hover:shadow-xl hover:shadow-ocean-100/50` in `Admin/Dashboard.vue:85`).
- **Reef lift** (`0 4px 20px -2px rgba(20,184,166,0.25)` / `shadow-reef`): secondary verification cards.
- **Sand lift** (`0 4px 20px -2px rgba(196,171,124,0.25)` / `shadow-sand`): revenue/sand cards.
- **Wave lift** (`0 4px 20px -2px rgba(129,140,248,0.25)` / `shadow-wave`): tertiary analytics fills.
- **Glow ocean** (`0 0 40px -10px rgba(14,165,233,0.5)` / `shadow-glow-ocean`): live session bento (`Admin/Dashboard.vue:140` `hover:shadow-glow-ocean`) and active CTAs.
- **Glow reef** (`0 0 40px -10px rgba(20,184,166,0.5)` / `shadow-glow-reef`): success confirmation.

No side-tab, no inner bevel, no heavy drop. Cards never nest inside cards visually; when nesting is structural, parent goes flat (`bg-slate-50`) and child goes elevated (`bg-white`).

### Named Rules
**The Lift Means Live Rule.** Shadows and glow signal live/verified/hover only. A static marketing card with persistent glow is a defect.
**The Flat Ledger Rule.** Interiors of registry tables and forms sit tonal and flat; depth is reserved for the container's state, not its chrome.

## Shapes

Form language is **soft ledger**: pill-first for actions and badges, generous `16–32px` for containers, never sharp-cornered editorial or brutalist square. Every radius is measurable and re-used.

- `full` (9999px): primary CTAs (`Book a Lesson` `rounded-full`, `GuestLayout` pill), filter chips, verification badges (`rounded-full bg-white/90`). This is the signature — the ledger's stamp.
- `xl` (32px / `rounded-[2rem]`): bento stat cards, revenue/leaderboard panels (`Admin/Dashboard.vue:85,161,176`), spot gallery cards (`Welcome.vue:215`), section CTA (`Welcome.vue:294` `rounded-[2rem]`).
- `lg` (24px / `rounded-3xl`): feature triad (`Welcome.vue:162` `rounded-3xl`), instructor pair (`Welcome.vue:254` `rounded-[2rem]` variations).
- `md` (16px / `rounded-2xl`): icon cradles (`w-12 h-12 rounded-2xl`), table access pills (`rounded-xl`).
- `sm` (8px / `rounded-lg`): inputs and alerts (receipt interior, field errors) in `BaseButton.vue:15`.

Borders: `border border-slate-100` on white cards against sand grounds; never thick accent borders (`border-l-4` removed per detector — use tonal pill instead). Clipping: `overflow-hidden` with image scale (`group-hover:scale-110`) inside rounded containers, not outside. Wave SVG divider is the sole organic silhouette (`Welcome.vue:122-125`); no other blob or arch.

## Components

Each entry leads with character, then shape/color/states. Expansion to HTML/CSS lives in `.impeccable/design.json`.

### Buttons
Tactile and verified, pill-stamped, not ghost-first. Full-width on mobile beaches, auto on desktop.

- **Shape:** pill (`{rounded.full}`), `font-semibold`/`font-bold`, `shadow-sm` to `shadow-lg shadow-ocean-600/20` for primary.
- **Primary:** `bg-ocean-primary text-white hover:bg-ocean-700` (`Login.vue:35` `bg-slate-900`, `Welcome.vue:104` `bg-teal-600→hover:bg-teal-700`). Padding `px-5 py-2` (nav) to `px-8 py-4` (hero). Focus `focus:ring-2 focus:ring-offset-2` (`BaseButton.vue:15`).
- **Hover / Focus:** lift (`hover:shadow-*`) + color deepening, not letter-spread or outline flash.
- **Secondary:** `bg-white text-slate-900 border-2 border-slate-900 hover:bg-slate-900 hover:text-white` (`Welcome.vue:248` `View All 500+ Pros`); `lg:justify-start` detail preserved.
- **Destructive / Success:** `rose-600` / `emerald-600` per `BaseButton.vue:22-23` (`variant danger/success`), used for suspensions and completed states only.

### Chips
Verified-line capsules. Verified caps are white/90 with blur + teal text (`InstructorCard.vue: verified badge`); level chips are tinted `teal-50/reef` vs `slate-100` vs `ocean-50` with `text-[10px] font-bold uppercase tracking-wider`. Selected state is saturated fill; unselected stays `bg-slate-100`.

### Cards / Containers
Ledger bento, not SaaS tiles. White paper on sand, `rounded-[2rem]`/`rounded-[2.5rem]`, `p-7`–`p-10`, `border-slate-100`, `shadow-sm` → `hover:shadow-xl`. Live variant overrides to `bg-gradient-to-br from-ocean-500 to-ocean-700` with white text and pulsing dot (`Admin/Dashboard.vue:140`). Spot gallery cards `h-64` image + `p-8` body; instructor pair `flex-col md:flex-row p-8 md:p-12`. Internal padding never collapses below `p-6` even at `sm`.

### Inputs / Fields
Quiet ledger fields: `border-slate-200 bg-white rounded-lg`, Outfit body. Focus: `focus:ring-2 focus:ring-teal-500 focus:border-teal-500` with `30-minute` transition, not harsh outline. Error: `text-rose-600` via `InputError.vue`; success: `text-reef-600`. Placeholder `shaka@siargao.com` tone is lower-contrast `text-sand-600` placeholder on `text-slate-900` input, never gray on colored ground.

### Navigation
Ledger header: fixed, transparent at top (`bg-transparent` + `text-white/90`) → `bg-white/80 backdrop-blur-md shadow-sm` when scrolled (`Welcome.vue:29` `isScrolled`). Links `text-sm font-medium hover:text-teal-500`. Active is `ocean-600` with subtle `bg-ocean-50`. Mobile: `ResponsiveNavLink.vue` without side-tab; use `bg-slate-50` tint for active, not `border-l-4`.

### Signature Component: InstructorCard
Ledger identity card, not marketplace tile. `rounded-3xl border-slate-100 overflow-hidden` → top `h-48` image (fallback `bg-teal-50`), top-right verified pill (`CheckBadgeIcon + Verified`), body `p-6` with Level chip (`teal-50` / `slate-100` / `ocean-50`) + max-students micro (`text-[10px] text-slate-500` + students icon) + rate `text-lg font-black` + `per hour` label + `line-clamp-2` bio + CTA `rounded-full bg-slate-900 hover:bg-slate-800`. Character: stamped, countable, scan-able.

### Signature Component: Audit Table (Admin Active Sessions)
Registry row, not dashboard widget. Header `bg-slate-50 text-[10px] font-black uppercase tracking-[0.2em]`, rows `hover:bg-ocean-50/30`, mono ID `font-mono text-slate-400`, team stacked (`instructor_name` bold + `student_name` italic), location pill `bg-slate-50 rounded-lg`, time mono `text-ocean-600` + `w-32 h-1.5` progress (gradient `ocean-500→600`). Access pill `bg-ocean-50 → hover:bg-ocean-500`.

## Do's and Don'ts

### Do:
- **Do** keep verification pill-first (`rounded-full`, `backdrop-blur-md`, `CheckBadgeIcon`) — its calm stamp is the trust signal (`InstructorCard.vue` pattern).
- **Do** use weight+tracking for hierarchy (900 + `tracking-widest` at 10px) over new families; check `flat-type-hierarchy` detector before adding a size.
- **Do** pair white cards on sand grounds (`bg-white` on `bg-sand-50`/`bg-slate-50`); the tint carries the island light.
- **Do** animate with `wave/float/pulse-slow/shimmer` + `ease-out-quart` only; no `animate-bounce`.
- **Do** resolve every `gray-on-color` finding: gray text on `bg-teal-*`/`bg-rose-*` becomes `text-white`, `text-slate-900`, or a darker tint of the field (`text-teal-900`/`text-rose-900`).

### Don't:
- **Don't** add `border-l-4` side-tab accents to cards or nav — 4 files flagged (`ReviewCard.vue:126`, `ResponsiveNavLink.vue:12`, `Admin/Bookings/Show.vue:127`, `Booking/Create.vue:262`). Use pill or tonal fill instead.
- **Don't** place gray text on colored fields (`text-gray-400 on bg-green-500` in `Booking/Confirmation.vue:55`, `Booking/Waiver.vue:95`, etc.) or `text-slate-400 on bg-rose-600` (`Admin/Incidents/Index.vue:152`). These wash out per detector.
- **Don't** use `animate-bounce` (`Pages/Public/WalkIn/Scan.vue:39`) or elastic/bounce easing — the system decelerates smoothly (`ease-out-quart/quint/expo`).
- **Don't** add marketing buzzwords (`world-class`, `streamline`, `enterprise-grade`) to copy (`Welcome.vue` flagged 2 phrases). Say what SiaSurf literally does: verified, matched, monitored.
- **Don't** add numbered section markers `01 / 02 / 03` (`Instructor/Onboarding.vue`) or new `Playfair/Inter-as-display` faces — Outfit carries display, Cinzel only as seal.
- **Don't** nest cards in cards for the sake of enclosure; flatten parent to `bg-slate-50` and let child be the signal.
