# SiaSurf — Island-Vibe Redesign Specification

**Reference:** `inspo-ui/siasurf_island_vibe_ui_redesign_instructor_student_sisa_admin.html`
**Date:** 2026-10-26
**Companion to:** `UI_UX_AUDIT.md`
**Rule applied:** adopt the reference's **design language and layout only**. Every label, number, badge and action must map to something the Laravel app actually has. Nothing invented.

---

## 0. Verdict

The reference is a **genuine upgrade in structure and depth** and a **regression in accessibility and content honesty**.

**Adopt:** the warm sand canvas, white paper cards, deep-reef sidebar, grouped navigation, one accent action colour, flat 16px geometry, and the calm/urgent KPI hierarchy.

**Reject:** the white-on-orange buttons (2.80:1), `text-stone-400` labels (2.52:1), 7 competing hues, emoji-as-icons, 10px labels, left-stripe cards, chat/payout/escrow features that do not exist, and every fabricated statistic.

**Net:** a strong skeleton that needs an accessibility pass, a palette collapse, and a content rewrite before it touches the codebase.

---

## 1. What the Reference Actually Contains

A three-portal prototype sharing one shell:

```
Prototype bar (dev only — discard)
└── Sidebar (deep reef, 256px, grouped nav, user card)
    └── Topbar (title + live pill + chat + QR)
        └── View 1: Instructor — hero, payout banner, 3 quick actions,
        │            active/empty session, 4-up earnings & standing
        ├── View 2: Student — gradient hero + live forecast, next session,
        │            verified instructor cards
        └── View 3: Admin — 5 KPI cards, revenue + status charts,
                     priority governance table
Mobile bottom nav (portal switcher — dev only)
5 modals: roll call, QR pass, booking, chat, token inspector
```

Useful ideas worth keeping: **grouped sidebar sections**, a **single accent CTA**, **status-driven KPI surfaces**, **inset metadata wells**, and **explicit empty states with a next action**.

---

## 2. Colour & Depth Analysis

### 2.1 Palette as written

| Token | Hex | Role in the reference |
|---|---|---|
| `sand-50` | `#FAF6F0` | App canvas — warm paper |
| `sand-100` | `#F3ECE1` | Inset controls, secondary buttons |
| `sand-200` | `#E6D9C6` | Card hairline (declared) |
| `reef-950` | `#062633` | Sidebar footer, dark chips |
| `reef-900` | `#083344` | Sidebar, topbar text |
| `reef-800` | `#0E485E` | Hover |
| `lagoon-600` | `#0D9488` | Verification, success, links |
| `lagoon-500` | `#14B8A6` | Live dots, gradient mid |
| `coral-500` | `#F97316` | **Primary CTA + urgency** |
| `coral-600` | `#EA580C` | CTA hover |
| `amber-*` / `rose-*` | Tailwind | Attention / critical |
| `emerald-*` | Tailwind | Success (duplicate of lagoon) |

### 2.2 The four-plane depth model (this is the reference's best idea)

| Plane | Surface | Value | Border | Shadow | Used for |
|---|---|---|---|---|---|
| 0 — Canvas | `sand-50` | `#FAF6F0` | none | none | Page ground |
| 1 — Paper | white | `#FFFFFF` | `#E6E0D6` 1px | `0 2px 8px -2px rgb(8 51 68 / .05)` + `0 1px 3px -1px rgb(8 51 68 / .03)` | Cards, tables |
| 2 — Well | `sand-50` | `#FAF6F0` | `stone-200` 1px | none (recessed) | Metadata strips inside cards |
| 3 — Chrome | `reef-900` | `#083344` | `cyan-900/60` | none | Sidebar, dark chips, modals |
| 3b — Chrome base | `reef-950` | `#062633` | — | none | Sidebar footer, timer chip |

This is coherent: **dark chrome anchors, warm canvas lights, white paper lifts, sand wells recede.** It is materially better than the current app, where canvas (`#F8FAFC`), cards (`#FFFFFF`) and insets (`#F8FAFC`) are nearly the same value and everything floats on `shadow-sm`.

Two imperfections to fix:

1. `.sand-card` uses `#E6E0D6` for borders, but the declared `sand-200` is `#E6D9C6`. **Token drift on day one.**
2. Nesting reaches three levels (card → sand well → bordered chip). Cap it at two; a well should not contain another bordered box.

### 2.3 Measured contrast (WCAG 2.1)

| Pair | Ratio | Normal text (4.5) | Large text (3.0) | Verdict |
|---|---|---|---|---|
| `reef-900` on `sand-50` | **12.44** | Pass | Pass | Excellent |
| `reef-950` on `sand-50` | **14.63** | Pass | Pass | Excellent |
| `stone-600` on white | **7.63** | Pass | Pass | Safe body |
| `stone-500` on white | **4.80** | Pass | Pass | Minimum body |
| `stone-400` on white | **2.52** | **Fail** | **Fail** | Used for 10px labels — worst case |
| white on `coral-500` | **2.80** | **Fail** | **Fail** | Every primary CTA |
| white on `coral-600` | **3.56** | **Fail** | Pass (large only) | CTA hover |
| white on `coral-700` | **5.18** | Pass | Pass | **Fix: use this** |
| white on `lagoon-600` | **3.74** | **Fail** | Pass | Icon chips only |
| `lagoon-600` on white | **3.74** | **Fail** | Pass | Links need `lagoon-700` |
| `teal-200` on `reef-900` | **10.63** | Pass | Pass | Sidebar labels |
| white on `rose-600` | **4.70** | Pass | Pass | Critical pill |
| `amber-900` on `amber-100` | **8.15** | Pass | Pass | Warning banner |

**The single biggest defect in the reference is the primary button.** White 14px semibold on `#F97316` is 2.80:1 — worse than the current app's ocean buttons. Fix by deepening the accent to `#C2410C` (5.18:1) or by making coral a fill accent and putting the primary CTA on reef-900.

### 2.4 Hue discipline

The reference uses **seven** accent families in one screen: reef, lagoon, teal, cyan, coral, amber, rose, plus emerald. That is why it reads slightly "AI dashboard" despite the tasteful surfaces. Collapse to **five semantic roles**:

| Role | Token | Hex | Never used for |
|---|---|---|---|
| Chrome / ink | `reef-900` | `#083344` | success, actions |
| Verified / success | `lagoon-700` | `#0F766E` | primary CTA |
| Primary action | `coral-700` | `#C2410C` | warning, urgency |
| Attention | `amber-500` | `#F59E0B` | primary action |
| Critical | `rose-600` | `#E11D48` | decoration |

Retire `emerald` (duplicate success), `cyan` (duplicate chrome tint), and `teal` (duplicate lagoon). Two greens and three teals is the visual equivalent of three fonts.

**Conflict to resolve:** the reference uses coral for *both* "Book a lesson" and "Payout action required". A colour cannot mean "go" and "warning". Recommendation: coral = **action only**; urgency uses the amber surface + rose icon, not coral.

---

## 3. Typography

### What the reference does

- Body: **Inter** 400–700, `text-xs` (12px) and `text-[11px]` dominate.
- Display: **Plus Jakarta Sans** 600–800.
- Labels: 10–11px, uppercase, `tracking-wider`, `text-stone-400`.
- Headings: uppercase on almost every panel ("WHO IS NEXT IN THE WATER?", "REVENUE VELOCITY").

### Assessment

| Issue | Why it matters |
|---|---|
| Inter | The most generic UI face in existence; the `frontend-design` skill explicitly lists it as an AI-slop signal. It also throws away the current app's distinctive Outfit identity. |
| 10–11px labels | Repeats the current app's worst accessibility defect (307 instances at 10px). |
| Uppercase everywhere | When every heading is caps + tracking, hierarchy collapses — the reader has no "quiet" level to rest on. |
| `font-extrabold` on every heading | Same flatness as the current 419 `font-black`. |

### Recommendation — do not adopt Inter

Keep **Outfit** as the single family. It is already loaded, geometric, and reads "island modern" rather than "SaaS template". Fix hierarchy with scale and weight instead of a second family:

| Role | Size / line-height | Weight | Case |
|---|---|---|---|
| Display | 32 / 40 → 40 / 48 (desktop) | 700 | Sentence |
| H1 | 24 / 32 | 700 | Sentence |
| H2 | 18 / 28 | 600 | Sentence |
| H3 | 16 / 24 | 600 | Sentence |
| Body | 16 / 26 (public) · 14 / 22 (dense admin) | 400 | Sentence |
| Small | 13 / 20 | 400 | Sentence |
| Label | 12 / 16 | 600 | Sentence, `0.02em` tracking |
| Eyebrow | 12 / 16 | 700 | Uppercase, `0.08em` — **max one per section** |
| Mono | 13 / 20 | 500 | IDs, timers, prices only |

Two rules that kill most slop:

1. **No text below 12px.** Ever.
2. **Maximum three weights per screen** (400 / 600 / 700). Retire `font-black` as a default.

If you want the reference's exact display feel, add **Plus Jakarta Sans 600/700 for headings only** and keep Outfit for body. Never both all-caps and extrabold.

---

## 4. Shape, Spacing, Shadow, Motion

| Property | Reference | Recommendation |
|---|---|---|
| Card radius | `rounded-2xl` (16px) | **Adopt 16px** — current app uses 40px, which reads toy-like |
| Control radius | `rounded-xl` (12px) | Adopt 10–12px |
| Chip radius | `rounded-full` | Adopt, but max one chip per row where possible |
| Card border | `#E6E0D6` | `sand-200` `#E6D9C6`, 1px |
| Card padding | `p-4` / `p-5` / `p-6` | 16 / 20 / 24 — keep |
| Section gap | `space-y-6` | 24px — keep |
| Shadow | 2-layer 5% + 3% | Adopt, formalise as `shadow-card` |
| Hover | `translateY(-2px)` + border teal | Replace with border + shadow only (no movement on data cards) |
| Motion | default 150ms | 150–200ms, transform/opacity only |
| Infinite pulse | live dots | Keep **only** for genuine live states, gate behind `prefers-reduced-motion` |

### Tailwind 3.4 compatibility bug (important)

The reference uses three utilities that **do not exist in Tailwind 3.4** and are silently dropped:

- `shadow-2xs` (v4 only)
- `shadow-xs` (v4 only)
- `backdrop-blur-xs` (v4 only)

The app is on Tailwind 3.2 via PostCSS. Define real tokens (`shadow-card`, `shadow-lift`, `backdrop-blur-sm`) instead of copying these class names.

---

## 5. What to Adopt (real improvements over the current UI)

| # | Improvement | Why it wins | Current state |
|---|---|---|---|
| 5.1 | **Dark reef sidebar** | Anchors the page, makes the light workspace feel lit, gives the product an identity | White sidebar with a blue active pill — reads like a generic admin template |
| 5.2 | **Warm sand canvas** | `#FAF6F0` reads like paper/beach light; slate-50 reads like a spreadsheet | `#F8FAFC` cool canvas |
| 5.3 | **Grouped navigation** | 2–3 named groups beat 11 flat items; scanning cost drops | 11 flat admin links, two duplicated icons |
| 5.4 | **One accent action colour** | Users learn "orange = the thing to do"; blue buttons everywhere currently compete with blue links and blue badges | ocean used for CTA, links, icons, badges, gradients |
| 5.5 | **Status-driven KPI surfaces** | Calm white when the value is 0, rose surface when critical. Encodes priority visually | Live Sessions 0 still gets a saturated gradient block |
| 5.6 | **Inset sand wells for metadata** | Creates "ledger" density without extra cards | Current pages nest white cards or use loose text |
| 5.7 | **16px radius** | Professional, contemporary, less toy-like | 32–40px radii on almost every card |
| 5.8 | **Explicit empty state + next action** | Turns a dead end into a task | Several pages show a blank area or "no data" |
| 5.9 | **Session timer in a dark chip** | Instrument-panel contrast; reads as live telemetry | Countdowns styled three different ways |
| 5.10 | **Role subtitle under the logo** | Confirms which portal you are in (a real safety concern with 3 roles) | Role is implied only by nav items |
| 5.11 | **Mobile bottom nav** | Thumb-reachable on a beach phone | Hamburger drawer only |

---

## 6. What to Fix Before Implementing (AI-slop and accessibility traps)

| # | Trap in the reference | Fix |
|---|---|---|
| 6.1 | **White text on `coral-500`** (2.80:1) | Primary buttons use `coral-700 #C2410C` (5.18:1) or reef-900 |
| 6.2 | **`text-stone-400` labels at 10–11px** (2.52:1) | `stone-600` minimum, 12px minimum |
| 6.3 | **Emoji as icons**: 🏄‍♂️ 🌊 🛡️ 📷 💬 ⬇ ▶ 🌴 ✓ | Heroicons (already a dependency); emoji only in human copy, never as a control |
| 6.4 | **Three-colour gradient strip** (`reef → lagoon → coral`) | One accent rule or none; a 3-colour bar is decoration without meaning |
| 6.5 | **Seven accent hues** | Five semantic roles (§2.4) |
| 6.6 | **`border-l-4` on sidebar, banners and KPIs** | Pick one severity treatment: tinted surface + icon chip + 3px top rule. No stripe soup |
| 6.7 | **Badge confetti** — every chip has bg + border + radius | Only status chips get borders; counts are plain text |
| 6.8 | **Uppercase + extrabold on every heading** | One eyebrow per section; headings sentence case, weight 600/700 |
| 6.9 | **Three-level card nesting** | Cap at two planes (paper + well) |
| 6.10 | **`shadow-2xs` / `shadow-xs` / `backdrop-blur-xs`** | Non-existent in v3 — replace with real tokens |
| 6.11 | **Prototype bar, portal switcher, token modal, "preview state" toggles** | Never ship; they exist to demo the mockup |
| 6.12 | **Avatar initials as the primary identity** | Use `avatar_path` when present, initials only as fallback |
| 6.13 | **Fake precision**: "+25% MoM", "86% completion rate", "100% Standardized Rate", "No 70% school cut" | Remove or compute from real data |
| 6.14 | **Invented legal citations**: DOT MC 2020-01, General Luna Ordinance, RA 10173, SISA ID #2026-0042 | Remove unless already in the app |
| 6.15 | **Chat UI / unread badges** | No chat backend exists (`instructor_module_fixes_proposal.md`: "Do not exist. Chat deferred.") |
| 6.16 | **"Releases GCash payout" / "escrow"** | No payout or escrow engine; attendance is recorded, earnings are computed |
| 6.17 | **Infinite `animate-ping` on three elements** | Only on genuine live state; respect `prefers-reduced-motion` |

### The "one view, one accent" rule

For each screen, exactly one element may use `coral`. Everything else is reef, lagoon, or neutral. This alone removes most of the template feel.

---

## 7. Content Authenticity Audit

Legend: **KEEP** = real data exists · **REWRITE** = concept real, copy wrong · **EXPOSE** = data exists in backend but is not passed to the page yet · **REMOVE** = not in the product.

### 7.1 Instructor portal

| Reference element | Real source | Verdict |
|---|---|---|
| "Kumusta, {name}!" | `auth.user.name` | KEEP (drop emoji) |
| "Level 3 SISA / ISA Certified" | `instructor_profile.level` + `certificates.type` | KEEP |
| "BLS & WaSAR Active" | `certificates` where type in `bls`, `wasar`, status verified | KEEP only if records exist; otherwise hide the chip |
| Vacation toggle | `instructor.availability.toggle-status` → status `inactive` | KEEP |
| "Stormglass Tide Synced" | `StormglassService` + `stormglass:fetch-tides` command | REWRITE → "Tide data: Stormglass" only when configured; never claim "synced" without a timestamp |
| "Roll call needed" banner | `activities.attendance_due` | KEEP |
| "releases ₱2,400 to GCash" | — | REWRITE → "Mark attendance to complete these lessons"; no payout language |
| "5 Days Open" | availability rows | EXPOSE (count from backend) |
| "6 Confirmed" | `instructor.bookings.index` count | EXPOSE |
| "My Beach QR Pass" | `instructor_profile.qr_code_path`, `route('walkin.scan')` | KEEP |
| "Who is next in the water" | `activities.ongoing_session`, `activities.upcoming_bookings` | KEEP |
| "Recommended board 9'0" Soft-Top, 72kg/175cm" | `Booking::getRecommendedBoardSize()` returns type + length range + volume | REWRITE → use the real derived values, not a fixed size |
| "Waiver signed" | `booking.waiver` | KEEP (eager-load it) |
| "₱1,200 Paid via GCash (Escrow)" | `booking.total_amount`, `payments.method/status` | REWRITE → actual amount + status; delete "escrow" |
| "Safe Reef Depth (1.4m)" | current tide from Stormglass | REWRITE → show tide separately; `SafetyService` does not validate depth |
| "Booking #SIA-2026-884" | `booking.id` | KEEP (use the app's existing reference format) |
| "Released Pay / GCash Ready / pending roll call" | `stats.total_earnings`, `stats.this_month_earnings` | REWRITE → "Total earnings" / "This month" |
| "5.0 ★ (14 reviews)" | `profile.average_rating` + review count | EXPOSE count |
| "0/3 strikes · Clean SISA Record" | `profile.strike_count` | KEEP |
| "BLS & WaSAR valid thru Aug 2027" | `certificates.expires_at` | KEEP when present |

### 7.2 Student portal

| Reference element | Real source | Verdict |
|---|---|---|
| "Welcome back, {name}" | `auth.user.name` | KEEP |
| Live swell / wind / temp / condition | Open-Meteo weather + marine API (`Student\DashboardController@index`) | KEEP |
| Current tide | `StormglassService::getCurrentTide()` | KEEP |
| Spot selector (Jacking Horse / Cloud 9) | `surf_spots` table | REMOVE from dashboard **or** EXPOSE a spots query; the dashboard currently reports one hardcoded spot ("Cloud 9, Siargao") |
| Tide curve + "0.4m shallow reef threshold" | tide extremes; real threshold constant is **0.3m** | REWRITE → 0.3m, labelled as the beginner caution threshold, not a safety-engine rule |
| "Swell period @ 11s" | marine API fetches `wave_period` but the controller does not return it | EXPOSE (one-line addition) or omit |
| "SISA Zone Rule: All Skill Levels Open" | `surf_spots.difficulty` + `SafetyService` | REWRITE → real spot difficulty / skill rule |
| "Book a Verified Lesson" | `instructors.search` | KEEP |
| "Scan Walk-In Beach QR" | Only `/qr/{instructor}` landing exists; no camera scanner (`html5-qrcode` not installed) | REMOVE the scan action; keep a link to the walk-in page if needed |
| "View Past Sessions (2)" | `student.bookings.index` | REWRITE → link only, no invented count |
| Next session card | `activeSession`, `upcomingBookings` | KEEP |
| "9'0 Foamboard Reserved" | No board reservation exists | REMOVE |
| "Chat with {instructor} (1 new)" | No chat backend | REMOVE |
| "View SISA ID" | `instructors.profile`, QR | KEEP |
| "Top verified instructors available today" | Not passed to dashboard; search supports it | EXPOSE a real query or link to search |
| "Compare all certified instructors →" | `instructors.search` | KEEP |

### 7.3 SISA admin portal

| Reference element | Real source | Verdict |
|---|---|---|
| Total bookings + "today" | `stats.total_bookings.value` / `.today` | KEEP |
| Verified instructors + pending | `stats.active_instructors` (`pending_verification`) | KEEP |
| Revenue total / this month | `stats.revenue.total` / `.this_month` | KEEP |
| Safety incidents + critical | `stats.incidents.total` / `.critical` / `.this_month` | KEEP |
| Live sessions | `stats.ongoing_sessions.value` + `ongoingSessions[]` | KEEP |
| "+25% MoM", "86% completion rate" | Not computed anywhere | REMOVE |
| "Reverb Active" | Broadcast stack exists but no exposed connection state | REMOVE or show a real health check |
| Revenue chart monthly/weekly | `GET /admin/api/dashboard/revenue?period=` | KEEP |
| Booking status mix | `GET /admin/api/dashboard/booking-distribution` | KEEP |
| Top instructors / popular spots / activity feed | Real endpoints and components exist | KEEP (the reference dropped them; we should not) |
| "Priority governance queue" | Pending instructors + critical incidents pages exist | PARTIAL — render real rows only if the controller supplies them; otherwise use two action cards |
| "Log 3rd strike & suspend" | `StrikeService` + incident adjudication | KEEP |
| "Approve & generate QR" | Instructor verification + QR generation | KEEP |
| "Export SISA Report (PDF / XLSX)" | Four **XLSX** endpoints only: instructor-performance, revenue, session-logs, safety-incidents | REWRITE → "Export reports (XLSX)" with the real four links; no PDF export exists |
| "DOT MC 2020-01", "General Luna Ordinance" | Not in the app | REMOVE |
| "SISA ID #2026-0042" | No such field | REMOVE / use the real user id |

### 7.4 Shell, chat, modals

| Reference element | Reality | Verdict |
|---|---|---|
| Booking chat button, unread badge, chat drawer | No `conversations` / `messages` tables, no routes | REMOVE |
| "SISA Beach QR" topbar action | Instructor QR exists | KEEP for instructors only |
| Notification bell | `NotificationDropdown` + Reverb events exist | KEEP |
| Roll-call modal | Attendance routes exist (`instructor.bookings.attendance`) | KEEP as a modal or inline action |
| QR pass modal | QR path + public profile exist | KEEP |
| Booking modal | Real flow is 6 pages with waiver + payment | REJECT the modal; restyle the existing pages |
| Token inspector modal | Dev artifact | REMOVE |
| Mobile bottom nav with portal switcher | Roles are separate accounts | REWRITE → role-specific nav (Student: Home · Find · Bookings · Profile; Instructor: Home · Availability · Bookings · QR) |

---

## 8. Screen-by-Screen Mapping

### 8.1 Shell (`AppSidebarLayout`)

```
[reef-900 sidebar 264px]
  brand block        logo + SIASURF + role subtitle    (real role)
  group label        "TEACHING" / "SESSIONS" / "GOVERNANCE"
  nav items          existing routes, grouped 2–3 sections
  user card          avatar_path or initials, name, role badge, settings/logout
[topbar white/85 blur]
  page title         existing #header slot
  right              notification dropdown + role action (QR for instructor)
[main sand-50 canvas, max-w-[1440px], p-6 → p-8]
```

- Replace `bg-[#F8FAFC]` with `bg-sand-50`.
- Active nav: filled `bg-white/10` + `text-white` + a 2px coral indicator on the **left edge of the sidebar item only** — not on cards.
- Keep the existing notification dropdown and logout dropdown.
- Mobile: bottom nav (role-specific) + the existing drawer; keep 44px targets.

### 8.2 Instructor dashboard — real data available today

| Section | Real props | Layout |
|---|---|---|
| Hero | `auth.user.name`, `profile.level`, `profile.status`, certificates | Name + level chip + certification chips + vacation toggle (`status === 'inactive'`) |
| Action banner | `activities.attendance_due` | Amber surface, count + "Mark attendance" → booking show. **No payout copy** |
| Quick actions | routes: availability, bookings, QR | 3 cards; QR card uses the reef gradient |
| Next session | `activities.ongoing_session` (student_name, spot_name, started_at, duration_hours, time_remaining) | Dark timer chip + session facts in a sand well |
| Empty state | when no ongoing/upcoming | Copy + "Open availability" / "Show QR" |
| Earnings & standing | `stats.total_earnings`, `stats.this_month_earnings`, `stats.completed_lessons`, `profile.average_rating`, `profile.strike_count` | 2×2 metric grid; no invented percentages |

### 8.3 Student dashboard — real data available today

| Section | Real props | Layout |
|---|---|---|
| Hero | `auth.user.name` | Reef gradient, one coral CTA → `instructors.search` |
| Conditions | `surfReport.spot/height/condition/wind/temp/tide/icon` | White card, sand well, tide line; label the source honestly |
| Next session | `activeSession` or first `upcomingBookings` | Status chip + amount + instructor + spot; no chat, no board reservation |
| Re-book | `pastInstructors[]` | Existing "Book again" row, restyled |
| Explore | link to `instructors.search` | Single CTA, not a fake "available today" list unless the query is added |

### 8.4 Admin dashboard — real data available today

| Section | Real props | Layout |
|---|---|---|
| KPI row | `stats` (5 values) | 5 cards; `incidents.critical > 0` gets the rose surface, `ongoing_sessions.value === 0` stays calm white |
| Revenue | `RevenueChart` | Keep the existing component; restyle axes/colors |
| Booking mix | `BookingDistributionChart` | Keep; verify the legend has no duplicated or zero-value rows before shipping |
| Activity / top instructors / popular spots | Existing organisms | Keep all three — the reference dropped real features |
| Ongoing sessions | `ongoingSessions[]` | Dark header table with mono timer + progress bar |
| Quick actions | create instructor, verify, export, log incident | 4 actions |

---

## 9. Implementation Plan

### Phase 0 — Make the content true (before any pixel moves)

| Task | File |
|---|---|
| Single pricing source; align UI estimate with `PricingService` | `Booking/Create.vue`, `app/Services/PricingService.php` |
| Expose `getRecommendedBoardSize()` on the next-session payload | `Student/DashboardController.php` |
| Expose review count + verified certificate names | `DashboardService::getInstructorDashboardData()` |
| Return `wave_period` from the marine API (only if we show it) | `Student/DashboardController.php` |
| Use the real 0.3m beginner caution threshold, not 0.4m | tide copy |
| Delete demo stats/instructors from the landing page | `Welcome.vue` |

### Phase 1 — Tokens

| Task | File |
|---|---|
| Add sand/deep/lagoon/coral ramps, radius/shadow/type tokens | `tailwind.config.js` |
| Set base canvas, text colour, reduced-motion guard, focus-visible ring | `resources/css/app.css` |
| Load Outfit once (remove the Blade duplicate; drop Cinzel) | `app.blade.php`, `app.css` |
| Delete unused Tailwind v4 dep and Lucide duplicates | `package.json` |

### Phase 2 — Primitives (build once, replace everywhere)

`resources/js/Components/UI/`

| Component | Variants |
|---|---|
| `AppButton` | primary (coral-700), secondary (sand-100), ghost, danger, link; sm/md/lg; loading |
| `AppCard` | plain, interactive, well, flush; optional `as` |
| `AppPanel` | sand inset with optional label |
| `StatCard` | calm, attention, critical; label, value, meta, footer link |
| `StatusPill` | verified, live, pending, completed, cancelled, critical |
| `SectionHeader` | eyebrow, title, description, action |
| `EmptyState` | icon, title, body, primary + secondary action |
| `Countdown` | dark chip and inline; tabular numerals; aria-live="off" |
| `DataTable` | header, row hover, sticky head, responsive scroll |
| `Field` | label, hint, error, required — wires `for`/`id`/`aria-describedby` |

Then replace inline CTAs page by page. Delete `PrimaryButton`, `SecondaryButton`, `DangerButton`, `StatCard` (the dead one), and the duplicate `Badge` usage.

### Phase 3 — Shell

| Task | File |
|---|---|
| Dark reef sidebar, grouped nav, role subtitle, active state | `AppSidebarLayout.vue` |
| Mobile bottom nav per role + drawer | `AppSidebarLayout.vue` |
| Topbar: title, notification, role action | `AppSidebarLayout.vue` |
| Public layout (home, instructors, spots, safety, login) | new `PublicLayout.vue` |
| Retire `AuthenticatedLayout` + `Pages/Dashboard.vue` stub | delete |

### Phase 4–6 — Dashboards

1. Instructor (flagship; proves the system on real data)
2. Student (surf report + next session)
3. Admin (KPI hierarchy + charts + live monitor)

### Phase 7 — Public + booking

Landing, search, profile, booking step 1, waiver, payment, confirmation. Same primitives; no new visual language.

### Phase 8 — Accessibility gate

Run and fix: axe/Lighthouse, keyboard pass, 200% zoom, 375px viewport, reduced motion, contrast on every new pair.

---

## 10. Proposed Token Set (Tailwind 3.2 compatible)

```js
// tailwind.config.js — theme.extend
fontFamily: {
  sans: ['Outfit', ...defaultTheme.fontFamily.sans],
  display: ['Outfit', ...defaultTheme.fontFamily.sans],
},
colors: {
  sand:   { 50:'#FAF6F0', 100:'#F3ECE1', 200:'#E6D9C6', 300:'#D5C2A5' },
  deep:   { 950:'#062633', 900:'#083344', 800:'#0E485E', 700:'#155E75' },
  lagoon: { 50:'#F0FDFA', 100:'#CCFBF1', 300:'#5EEAD4', 500:'#14B8A6',
            600:'#0D9488', 700:'#0F766E', 800:'#115E59' },
  coral:  { 50:'#FFF7ED', 100:'#FFEDD5', 300:'#FDBA74', 500:'#F97316',
            600:'#EA580C', 700:'#C2410C', 800:'#9A3412' },
},
borderRadius: {
  control: '10px',
  card: '16px',
  sheet: '20px',
},
boxShadow: {
  card: '0 1px 2px rgba(8,51,68,.04), 0 2px 8px -2px rgba(8,51,68,.06)',
  lift: '0 2px 4px rgba(8,51,68,.05), 0 12px 24px -8px rgba(8,51,68,.12)',
},
fontSize: {
  'label':    ['12px', { lineHeight: '16px', letterSpacing: '0.02em' }],
  'body-sm':  ['13px', { lineHeight: '20px' }],
  'body':     ['14px', { lineHeight: '22px' }],
  'body-lg':  ['16px', { lineHeight: '26px' }],
  'title':    ['18px', { lineHeight: '28px' }],
  'headline': ['24px', { lineHeight: '32px' }],
  'display':  ['32px', { lineHeight: '40px' }],
  'hero':     ['40px', { lineHeight: '48px' }],
},
transitionTimingFunction: {
  out: 'cubic-bezier(0.22, 1, 0.36, 1)',
},
```

Migration note: the app already has `sand`, `reef`, `ocean`, `wave`. Keep `sand`, re-point `ocean` → `lagoon`, `reef` → `lagoon`, and `wave` → `deep` mechanically, then delete the aliases. Renaming hues without aliases will break 623 `ocean-*` and 28 `reef-*` classes.

### Utility contracts (in `app.css`)

```css
.surface        { @apply bg-white border border-sand-200 rounded-card shadow-card; }
.surface-well   { @apply bg-sand-50 border border-sand-200 rounded-control; }
.surface-chrome { @apply bg-deep-900 text-white; }
.eyebrow        { @apply text-label font-bold uppercase tracking-[0.08em] text-stone-600; }
.metric         { @apply font-semibold tabular-nums text-deep-900; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
```

---

## 11. Decisions Needed

| # | Decision | Recommendation | Cost if wrong |
|---|---|---|---|
| 11.1 | Keep **Outfit** or adopt **Inter + Plus Jakarta Sans** | Keep Outfit; add Jakarta for headings only if the reference's feel is essential | A font swap touches every page and loses the current identity |
| 11.2 | Migrate neutral text from **slate → stone** | Yes, phased with the shell rewrite | Slate reads slightly cold on a warm canvas |
| 11.3 | Deepen **coral** to `#C2410C` for buttons | Yes — 5.18:1 vs 2.80:1 | Shipping the reference as-is fails WCAG on every CTA |
| 11.4 | Keep **hand-rolled SVG charts** or add Chart.js | Keep SVG; add `aria-label` summaries | Chart.js adds ~60KB and canvas is not accessible by default |
| 11.5 | Dark sidebar for **all three roles** | Yes — it is the strongest identity move | A white sidebar will make the rest look unchanged |
| 11.6 | Show **attendance action** as modal or inline | Inline banner + modal confirm | The reference's payout framing must not ship |
| 11.7 | Add **"top instructors available today"** to the student dashboard | Only if we add the query; otherwise a single "Find an instructor" CTA | A fake list breaks the authenticity rule |
| 11.8 | Public pages get a new shell **now or later** | Later, after the authenticated shell proves the tokens | Public navigation is currently a dead end (P0-6) |

---

## 12. Definition of Done

The redesign is not done until all of these are true:

- [ ] No text below **12px** anywhere (`text-[10px]` = 0)
- [ ] No `text-slate-400` / `text-stone-400` on a light surface
- [ ] Every button and link meets **4.5:1**; every large heading meets 3:1
- [ ] Exactly **5 hues** in use: deep, lagoon, coral, amber, rose
- [ ] **2 shadows**, **2 radii** (+ pill), **3 font weights** per screen
- [ ] One `coral` element per view
- [ ] Zero emoji used as an icon
- [ ] All images have `alt`; below-the-fold images `loading="lazy"`
- [ ] `focus-visible` ring on every interactive element
- [ ] Safety and validation errors announced via `aria-live`
- [ ] `prefers-reduced-motion` disables ambient animation
- [ ] No fabricated number, name, certification, legal citation, chat, payout or scan feature
- [ ] 375px viewport: no horizontal scroll, 44px targets, readable type
- [ ] `npm run build` passes (`vue-tsc`)

---

## 13. Recommended First Slice

Do not restyle 93 files. Ship this slice, review it, then propagate:

1. **Tokens + base** — `tailwind.config.js`, `app.css`, font cleanup.
2. **Shell** — `AppSidebarLayout`: deep reef sidebar, grouped nav, sand canvas, topbar.
3. **Instructor dashboard** — the flagship screen, because every value it needs is already real:
   `stats.*`, `profile.*`, `activities.ongoing_session`, `activities.upcoming_bookings`,
   `activities.attendance_due`, `activities.recent_reviews`.

That is roughly **3 files plus 8 primitives**, and it answers the only question that matters: does the island-vibe system look right with real SiaSurf data? If yes, the student and admin screens become assembly work rather than design work.

---

## 14. Implementation Log — 2026-10-26

### Shipped

| Area | Files |
|---|---|
| Tokens | `tailwind.config.js` — warm `sand` ramp, `deep` / `lagoon` / `coral` ramps, `rounded-control/card/sheet`, `shadow-card/lift`, semantic `text-label` → `text-hero`, `ease-out`, `max-w-shell` |
| Base layer | `resources/css/app.css` — sand canvas, deep ink, global `focus-visible` ring, `.surface` / `.surface-well` / `.surface-chrome` / `.eyebrow` / `.metric` / `.skip-link`, reduced-motion guard |
| Fonts | `resources/views/app.blade.php` — Outfit loaded once (400–900); Cinzel and the duplicate `@import` removed |
| Primitives | `resources/js/Components/UI/` — `AppButton`, `AppCard`, `AppPanel`, `AppSwitch`, `StatCard`, `StatusPill`, `SectionHeader`, `EmptyState`, `Countdown` |
| Shell | `AppSidebarLayout.vue` — 264px deep-reef sidebar, grouped nav for all three roles, role subtitle, account card, glass topbar, role-specific mobile bottom nav, skip link, public topbar with real links, corrected role-prefixed dashboard routes |
| Navigation badges | `HandleInertiaRequests.php` shares real instructor counts (`openSlots`, `totalSlots`, `pendingRequests`, `status`); sidebar shows `14/14`, pending requests, and Live/Paused QR state |
| Instructor dashboard | `Pages/Instructor/Dashboard.vue` — real `stats`, `profile`, `activities`, attendance, requests with live countdown, session timer, quick actions, earnings/standing, recent reviews |
| Instructor lesson schedule | `Pages/Instructor/Bookings/Index.vue` — colour-coded status tabs, status-tinted date blocks, 2-hour countdown, real board recommendation, payment/waiver/attendance facts, accept/decline/attendance/change actions, restyled modals |
| Instructor availability | `Pages/Instructor/Availability/Index.vue` — full-width seven-day board, state colours (open/off/blocked/locked), presets, vacation + instant-book switches, real date blocks, publish bar visible only while unsaved |
| Student dashboard | `Pages/Student/Dashboard.vue` — real `activeSession`, `upcomingBookings`, `pastInstructors`, `surfReport`; honest "tide data unavailable" state; no chat/scan/board-reservation fabrications |

### Verified

- `npm run build` (`vue-tsc` + Vite) passes.
- Production CSS contains the new tokens and utilities (`#faf6f0`, `rgb(8 51 68)`, `rounded-control`, `shadow-card`, `.surface`, reduced-motion).
- Browser captures at 1440px and 390px:
  `screenshots/redesign-instructor-dashboard.png`, `redesign-instructor-mobile.png`,
  `redesign-student-dashboard.png`, `redesign-admin-dashboard.png`, `redesign-public-search.png`,
  `redesign-instructor-lessons.png`, `redesign-instructor-lessons-mobile.png`,
  `redesign-instructor-availability.png`, `redesign-instructor-availability-mobile.png`.
- Sidebar active state fixed: routes are `instructor.dashboard` / `student.dashboard` / `admin.dashboard`, not `dashboard`.
- Instructor sidebar badges verified in the browser: `Availability 14/14`, `My beach QR Live`, pending-request badge when present.
- Booking roster verified with real data: board recommendation, payment status, waiver state, attendance, capacity, and status colours.

### Removed from the reference, per the authenticity rule

Chat and unread badges, payout/escrow language, board reservation, camera QR scanning, invented percentages, legal citations, prototype bar/tabs/token inspector, emoji-as-icons in the new surfaces.

### Next

1. Admin dashboard inside the new shell (KPI hierarchy + existing SVG charts restyled).
2. Public layout rollout: landing, search, profile, surf spots (remove the remaining emoji and 40px radii).
3. Booking flow surfaces with a sticky price summary.
4. Accessibility gate: axe/Lighthouse, keyboard pass, 375px check, contrast re-check on every remaining page.

> **Local note:** restart `npm run dev` once. The running Vite instance was serving a stale Tailwind cache, so the new arbitrary utilities (`w-[264px]`, `lg:pl-[264px]`) did not appear until the production build. The built output is correct.

---

## 15. Contrast & Depth Pass — 2026-10-26

The first pass was intentionally quiet, but white cards, pale secondary buttons, and open availability tiles sat too close in value. This pass adds separation without turning the interface heavy.

### Surface depth

| Plane | Before | After |
|---|---|---|
| Canvas | `#FAF6F0` | **`#F2EADE`** (deeper sunlit sand) |
| Card | white, 4–6% shadow | white, **6–12% two-layer shadow** |
| Border | `#E6D9C6` | **`#DFCFB4`**, strong border **`#C9B394`** |
| Well | sand-50, hairline | sand-50 with **sand-300 border** |
| Chrome | `deep-900 #083344` | **`deep-950 #062633`** |

### Component emphasis

- **Secondary buttons**: now white with a `sand-300` border, `shadow-sm`, and a lagoon border on hover — no longer blends into the canvas.
- **Disabled buttons**: neutral stone surfaces with muted text instead of a faded coral, so "disabled" cannot be mistaken for "primary".
- **Status pills**: stronger tints (`lagoon-100`, `amber-100`, `rose-100`) with 300-level borders and darker text.
- **Panels**: attention/positive/critical surfaces strengthened the same way, so KPI callouts read before their labels.
- **Availability tiles**: open = `lagoon-100` + `lagoon-400` border; off = `sand-100` + dashed `sand-300`; personal block = rose; lesson lock = deep.
- **Section headers**: raised from 18px to 24px, so page sections outrank card titles.
- **Topbar**: white/90 with a shadow, so it separates from the warm canvas.

### One chrome colour

The desktop sidebar and mobile bottom navigation now share **`deep-950`**:

- Desktop active item: `lagoon-500/20` fill, white text, coral left indicator.
- Mobile active item: lagoon text with a 2px lagoon bar at the top edge.
- Inactive labels: `lagoon-100/70`; icons `lagoon-200/60`.
- Account card: translucent white on deep, with a visible border.

This answers the reference's dark mobile nav and gives both viewports the same navigation identity.

### Verified

- `npm run build` passes.
- Captures refreshed: `redesign-instructor-availability.png`, `redesign-instructor-availability-mobile.png`, `redesign-instructor-lessons.png`, `redesign-instructor-dashboard.png`.
- Mobile availability confirms the dark nav, neutral disabled controls, and higher-contrast open tiles.

---

## 16. Student Area Pass — 2026-10-26

All remaining `Student/*` surfaces now use the same shell, primitives and colour language as the instructor area.

### Bookings list — `Pages/Student/Bookings/Index.vue`

- Server-side filters and counts: **All / Upcoming / Completed / Cancelled**, including terminal states (`declined`, `payment_timeout`, `expired`, `no_show`) under Cancelled.
- Status-tinted date blocks, booking reference, instructor identity (photo or initials), amount and payment state.
- **Replaced the browser `prompt()` cancellation** with a real reason modal + validation.
- Mobile: scrollable tabs, compact count pills, single-column status cards.

### Booking detail — `Pages/Student/Bookings/Show.vue`

- Four-step session timeline (Booked → Confirmed → In progress → Completed).
- Live session countdown (no map: SiaSurf does not use GPS tracking).
- Payment window with the real deadline countdown and PayMongo checkout link.
- Session facts (spot, difficulty, skill, age, participants, height/weight), special requests, payment method/amount, waiver state.
- Sidebar instructor card links to the correct profile id; actions are limited to **Leave feedback** and **Cancel booking**.
- **Removed** the non-existent chat action, the no-op “Download Invoice”, the fabricated support email, and the Unsplash avatar fallback.

### Reviews hub — `Pages/Student/Reviews/Index.vue`

- Pending-feedback queue links to the dual-track feedback hub.
- Review history shows stars or an honest “No rating” state (rating is nullable).
- Accurate policy copy: public reviews never add strikes; private concerns are reviewed by SISA first.
- **Removed** the no-op “Request Removal” button.

### Feedback hub — `Pages/Student/Feedback/Create.vue`

- Two selectable tracks: public review (lagoon) and private concern (rose).
- Review track: optional star rating, optional photo, positive tags, comment with live count.
- Concern track: grouped categories with the real proposed strike weight, description, injury/near-miss flags, evidence upload, truth declaration.
- All original validation, handlers and payload fields preserved.

### Navigation

- Student sidebar now shows real badges: **upcoming bookings** on My bookings and **pending feedback** on My reviews, shared from `HandleInertiaRequests`.

### Schema fix

`Student/Feedback/Create` was failing with `safety_incidents.deleted_at` missing. The database was eight migrations behind. Applied all pending migrations (feedback tags, incident workflow, strikes, suspensions, appeals, suspension fields, status logs, coast fields) and added:

`2026_10_26_000009_add_deleted_at_to_safety_incidents_table.php`

The feedback hub now renders and the concern form opens with zero page errors.

### Verified captures

- `redesign-student-bookings.png` / `-mobile.png`
- `redesign-student-booking-show.png`
- `redesign-student-reviews.png`
- `redesign-student-feedback.png`
- `redesign-student-feedback-concern.png` (concern track selected, category guidance shown)

### Still legacy

- `Student/Reviews/Create.vue` is no longer reachable: `ReviewController@create` redirects to the feedback hub.
- `Profile/Edit.vue` and its Breeze partials still use the old form styling.
- Admin dashboard, public pages and the booking flow remain from the earlier phases.

---

## 17. Admin Area Pass — 2026-10-26

The admin console now shares the same shell, palette, typography and components as the instructor/student areas.

### Completed

- **Dashboard** — 5 KPI cards with calm zero states and tinted attention/critical states; restyled Revenue, Skill Mix, Leaderboard, Spot Demand, Operations and Activity organisms; live sessions table; removed saturated gradient blocks and 40px radii.
- **Instructors index** — functional debounced search and status/level filters (previously inert), real pagination, initials fallback for missing avatars, status pills, coral “Onboard instructor” action.
- **Students index** — functional debounced search, real pagination, initials identity, skill/status pills.
- **Bookings index** — status filters, real pagination, status pills; removed the fake “Export CSV” and “Manual Booking” buttons.
- **Incidents index** — KPI cards for pending/confirmed/rejected/revoked, colour-coded tabs, incident cards, empty state, real pagination.
- **Chart organisms** — revenue bars fixed (percentage-height bug), colour-coded doughnut with a single legend, leaderboard and spot-demand bars restyled, activity feed flattened into the dashboard card.

### Verified captures

- `redesign-admin-dashboard.png`
- `redesign-admin-instructors.png` / `-mobile.png`
- `redesign-admin-students.png`
- `redesign-admin-bookings.png`
- `redesign-admin-incidents.png`

### Still legacy in admin

- Analytics, Change Requests, Live Sessions, Appeals, Skill Upgrades, Surf Spots index.
- All detail/editor pages: Instructor Show/Create/Edit, Student Show, Booking Show, Incident Show/Create, Appeal Show.

These use the new shell but retain the previous inner layouts; they are the next admin pass.

---

## 18. Instructor Roster Compaction — 2026-10-28

`Pages/Instructor/Bookings/Index.vue` was rebuilt around the reference's "lesson card" pattern, optimised for vertical space rather than a second content pass.

### What changed

| Before | After |
|---|---|
| Section header + 3 KPI panels + filter tabs (≈ 2 stacked blocks, ~200px of chrome) | One row: tinted filter chips left, "N awaiting attendance" right |
| 68×80 status-coloured date block per card | `OCT 28 · AM` pill inline with the student name |
| Price in a 256px right-hand rail with 4 stacked buttons | Price right-aligned in the header, single action row |
| 3-column `surface-well` of board / payment / attendance (~90px) | One wrapped chip row (`board · waiver · payment · attendance · change · chat`) |
| Separate bordered countdown box | Countdown folded into the status pill (`Request · 1h 34m`, rose when < 30 min) |
| Reschedule / Cancel as permanent buttons | `Details` menu (Open lesson, Reschedule, Cancel lesson, Withdraw change) |
| `space-y-4` single column, ~330px per card | Full-width lesson rows, **172px** each (250px at 390px) |

### Layout correction (same day)

The first cut used a two-column grid on `xl`. With an odd number of lessons — and especially with a single booking — that left half the roster area empty and the card stranded in the left column.

The roster is now a **single-column list of full-width rows**, capped at `max-w-6xl` and centred so ultra-wide screens do not stretch the gap between chips and actions:

- Row 1: date pill + student + meta | price + status pill.
- Row 2: chip row left, actions right. Below `sm` the actions drop to their own line at full width; between `sm` and `lg` they wrap under the chips.
- A lone booking is now a complete row at full width, so the page never shows a hole.

### Accept / decline states

`BookingAcceptanceService` refuses an accept when the response window has closed **or** the lesson is inside the 1-hour pre-lesson buffer. The roster now mirrors those guards instead of offering a button that fails:

| Server state | Pill | Chip | Actions |
|---|---|---|---|
| Pending, window open | `Request · 1h 43m` (amber, rose under 30 min) | — | Accept request, Decline, Details |
| Pending, window closed | `Request · expired` (stone) | `Window closed · released` (rose) | Details only |
| Pending, starts within 1h | `Request · starts soon` (amber) | `Starts too soon to accept` (amber) | Decline, Details |

New payload fields on `Instructor\BookingController@index`: `can_be_accepted` and `accept_block` (`window_closed` | `too_close` | `null`), computed from the same model methods the service uses. `canAccept()` also re-checks the live countdown, so a button disappears the moment the timer hits zero without waiting for a refresh.

### System additions

- `app.css` — `.chip` + `.chip-neutral / -positive / -warning / -critical`, `.menu-item` + `.menu-item-danger`.
- Cards carry `relative hover:z-10 focus-within:z-20` so an open Details menu paints above the next row.
- Countdown interval now only starts when a pending booking has time remaining (no per-second re-render on an idle roster).
- Status helper copy is compact but keeps the precise window in the pill's `aria-label`.

### Fixed in passing

- `text-lagoon-900` used in `statusMeta()` and `StatusPill` does not exist in the palette (ramp stops at 800); corrected to `lagoon-800` in this page and in `.chip-positive`.
- Pagination disabled links moved from `text-stone-400` (2.56:1) to `text-stone-500`.

### Verified captures

- `redesign-instructor-lessons-compact-desktop.png` — four lessons, whole roster inside one viewport (no page scroll).
- `redesign-instructor-lessons-compact-mobile.png` — 390px, chips wrap to two rows, all filters visible.
- `redesign-instructor-lessons-menu.png` — Details menu open above the following card.
- `redesign-instructor-lessons-decline.png` — decline modal unchanged and reachable.
- `npm run build` (`vue-tsc` + Vite) passes; filter tabs still drive the server-side `?status=` query.

### Still legacy

- `Instructor/Bookings/Show.vue` (detail page) keeps the earlier card grammar; it is the natural next surface for the same compaction.

---

## 19. Lesson Detail Pass — 2026-10-28

`Pages/Instructor/Bookings/Show.vue` was rebuilt on the reference's command-bar + two-column layout, with one hard rule: **every value had to exist in the database already**.

### Structure

| Band | Content | Source |
|---|---|---|
| Command bar | `Session #SURF-00017`, status pill, live response-window pill, date · period · time range · spot, `Accept request` / `Decline` / `More` | booking, `acceptTimeRemaining` |
| Live banner (in progress only) | Running countdown, start time, booked hours, promote-skill toggle, `Complete session` | `started_at`, `duration_hours` |
| Four facts | Surf spot + difficulty · group & skill · equipment (`N rentals needed` + board range/type) · booking total + payment method/status | `surf_spot`, `student_count`, `participants[].has_board`, `board_recommendation`, `payment` |
| Surfing party & board match | Per participant: role, name, skill, age, height, weight, waiver chip, own-board/rental chip, board match, medical note, emergency contact | `participants`, `participants[].waivers` |
| Instructor briefing notes | `booking.notes`, honest empty copy when absent | `notes` |
| Surfer contact | Name, email, phone, `tel:`/`mailto:` actions, in-app chat | `student`, chat thread |
| Pre-surf safety audit | `X of Y signed` waivers, payment method + status + total, attendance label, start-window state | relations + `canStartSession` |

### Deliberately not carried over from the reference

Net earning / 75% payout (no commission model exists), "Bring from Shop", tide height, "Direct Line" badge, "Attendance roll call unlocks at 3:00 PM", and the fabricated participant board detail. The earlier page already had a fabricated `Net Earning = total × 0.75` tile; it is gone.

### Server changes

`Instructor\BookingController@show` now also sends:

- `booking.board_recommendation` — the model's own `getRecommendedBoardSize()`, replacing the duplicate formula that lived in the Vue file.
- `participants[].board_match` — `BookingParticipant::getRecommendedBoardSize()` per person.
- `booking.attendance_label` — same shape the roster uses.
- `canBeAccepted` + `acceptBlock` — the accept guards that already existed in `BookingAcceptanceService`.

### Verified

- `npm run build` passes; `php artisan test --filter="InstructorBookingWorkflowTest|BookingParticipantsTest"` → 24 passed (101 assertions).
- Captures: `redesign-lesson-show-pending.png`, `-confirmed.png`, `-live.png`, `-pending-mobile.png`, `-menu.png`.
- Detail page fits a 1440×960 viewport (960–975px document height).
- All six modals keep their original handlers, routes and validation.

---

## 20. Student Discovery Pass — 2026-10-28

`Pages/Public/Instructors/Search.vue` now uses the reference's search-first directory: one search field, area and level chips, one row per instructor.

### What each row states, and where it comes from

| Element | Source |
|---|---|
| Avatar (or initials) | `avatar_url` |
| Name | `users.name` |
| ★ rating (count) / "New instructor" | visible reviews only (`withAvg`/`withCount`) |
| `Verified` chip | the query already requires a current verified certificate (BR-2.1) |
| `Level 1/2/3` | `InstructorLevel::shortLabel()` |
| `All levels` / `Beginners only` | `InstructorLevel::allowedSkillLevels()` |
| Area · max students | `instructor_profiles.area`, `InstructorLevel::maxStudents()` (BR-3.1–3.3) |
| ₱ rate / hour | `rate_per_hour` |
| `Available today` | new `AvailabilityService::openPeriodsForMany()` |

### "Available today", honestly

`periodsFor()` answers this per instructor but enumerates start times and costs a dozen queries each — unusable for a directory. The new `openPeriodsForMany()` applies the same period-level rules in **two queries for the whole page**:

- a published weekly schedule → the day's recurring periods, minus manual date blocks;
- no published schedule → bookable unless a manual or booking block covers the day (the service's existing fallback);
- booking locks are ignored at period level, exactly as `periodsFor()` documents, so a partly booked day still reads as open.

The badge is hidden when nothing is open, and the periods are exposed in the `title` ("Open morning and afternoon"). It is a schedule signal, not a remaining-slot count.

### Removed from the page

Emoji-as-icons (🏄), `font-black`, 10px labels, 40px radii, the `rounded-[2.5rem]` search slab, `v-html` pagination labels, and the duplicated instructor-card grid. The map view, area/price/level/skill filters and sort options are all preserved.

### Verified

- `npm run build` passes; `InstructorDirectoryTest` → 5 passed (72 assertions).
- Captures: `redesign-discovery-{guest,student,guest-mobile,filtered,map}.png`.
- Guest: 5 rows, no Book button, sign-in banner. Student: Book appears per row. Area chip `Cloud 9` → 1 result, filter badge shows 1.
- Seeded fixtures were removed afterwards (5 users / 3 instructors / 3 certificates).
