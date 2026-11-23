# SiaSurf — Feedback, Strikes, Suspension, and Appeals

**Status:** Updated to V3 (November 2026)  
**Source of truth:** code + tests + `words/Capstone2_Introduction_V3.docx` (§1.23, U6, I8).

## 1. Core policy (V3)

1. A public review never directly creates a strike.
2. A private student report starts as `pending` (`severity = null`) and does not affect the instructor until SISA decides.
3. Tourist checklist items are grouped by neutral report topic (`IncidentReportTopic`). System-suggested severity/outcomes (`no_strike`, `1_strike`, `2_strikes`, `3_strikes`, `critical`) are computed by highest severity item (never summed) and are **visible to SISA administrators only** — hidden from tourists and instructors.
4. Every SISA final decision (including dismissal/`no_strike`) requires a `decision_reason` (sent to the reporter) and an internal `admin_note` (SISA-only). `instructor_summary` is strictly separate and redacted.
5. **3-Strike Penalty Ladder** (tier-for-total on active strikes):
   - **1 active strike:** Official warning + **₱500 fine** recorded as `due` in `instructor_fines` (collected by SISA outside the system). Instructor remains active.
   - **2 active strikes:** **1-week (7-day) suspension**. Profile is hidden from booking; upcoming bookings cancelled. Reinstatement requires SISA administrator approval and **preserves** the 2 active strikes on record.
   - **3+ active strikes:** **1-month (30-day) suspension** + physical SISA ID confiscation notice outside the system. Profile is hidden from booking; upcoming bookings cancelled. Reinstatement requires SISA administrator approval and marks served strikes as `served` (`strike_count = 0`).
6. **Critical incidents** are recorded (`handled_externally = true`, `strike_weight = 0`, no strike ledger row, no automatic suspension) and SISA is notified to handle them outside the system. Admins may optionally place a manual administrative hold while handled externally.
7. **Reinstatement:** Elapsed suspensions transition to `awaiting_reinstatement` (staying hidden from booking) until a SISA administrator explicitly approves reinstatement.
8. **Appeals & Revocations:** Instructors may submit one appeal per suspension. Revoking a 1-strike incident voids its `due` ₱500 fine; revoking strikes down from 3 to 2 downgrades a Tier 3 (1-month) suspension to Tier 2 (1-week); dropping below 2 lifts the automatic suspension.

## 2. Student feedback hub

One page: `Student/Feedback/Create.vue`.

| Track | Visibility | Strike effect |
|---|---|---|
| Public review | Public profile | **0** |
| Private concern / report | Student + SISA only (suggested severity hidden from student/instructor) | Pending; SISA decides final outcome |

### Private report fields

- Primary report topic (`unsafe_surf_zone`, `no_safety_protocol`, `unprofessional_conduct`, `service_complaint`, `other`)
- Multi-item checklist grouped by neutral topic (zero minor/major/critical or strike labels shown)
- Description, minimum 30 characters
- Injury / near-miss flags
- Optional evidence (PDF/JPG/PNG, max 10 MB)
- Truthfulness declaration

## 3. Database & state transitions

- `safety_incidents` — `report_type`, `checked_items` (JSON), `suggested_outcome`, `final_outcome`, `decision_reason`, `admin_note`, `handled_externally`, `external_handled_at`, `strike_weight`, `instructor_summary`
- `instructor_strikes` — append-only ledger (`active` / `revoked` / `served`)
- `instructor_fines` — fine ledger for 1-strike penalties (`due` / `paid` / `waived` / `voided`)
- `instructor_suspensions` — `type` (`manual`/`automatic`/`legacy`), `strike_tier` (`2`/`3`), `status` (`active` → `awaiting_reinstatement` → `completed` | `lifted_on_appeal` | `revoked_by_admin`), `requires_sisa_id_confiscation`, `reinstated_at`, `reinstated_by`, `reinstatement_note`
- `instructor_appeals` — one per suspension

## 4. Key implementation files

| Concern | File |
|---|---|
| Outcomes, topics & fines | `app/Enums/IncidentOutcome.php`, `app/Enums/IncidentReportTopic.php`, `app/Enums/FineStatus.php` |
| Category taxonomy & student filter | `app/Enums/FeedbackCategory.php` |
| Student submission | `app/Http/Controllers/Student/FeedbackController.php`, `app/Services/FeedbackService.php` |
| Strike ladder & fines | `app/Services/StrikeService.php`, `app/Models/InstructorFine.php` |
| Suspension & reinstatement | `app/Services/SuspensionService.php`, `app/Console/Commands/LiftExpiredSuspensions.php` |
| Appeal review | `app/Services/AppealService.php`, `app/Http/Controllers/Admin/AppealController.php` |
| Admin incident & instructor controls | `app/Http/Controllers/Admin/IncidentController.php`, `app/Http/Controllers/Admin/InstructorController.php` |
| Instructor standing & suspended UI | `app/Http/Controllers/Instructor/SuspensionController.php`, `resources/js/Pages/Instructor/Dashboard.vue`, `resources/js/Pages/Instructor/Suspended.vue` |
| Feedback UI (hidden suggestions) | `resources/js/Pages/Student/Feedback/Create.vue` |
