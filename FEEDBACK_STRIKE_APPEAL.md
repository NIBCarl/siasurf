# SiaSurf — Feedback, Strikes, Suspension, and Appeals

**Status:** Implemented (October 2026)  
**Source of truth:** code + tests; this document summarizes the policy.

## 1. Core policy

1. A public review never directly creates a strike.
2. A private student concern starts as `pending` and does not affect the instructor until SISA confirms it.
3. Confirmed severity determines the strike: **Minor = 1, Major = 2, Critical = 3**.
4. Three active confirmed strikes trigger an automatic **30-day suspension**.
5. One report per booking. Multiple tags in one report do not stack strikes; the final confirmed severity wins.
6. Suspension reason, dates, and active strikes are visible to the instructor.
7. The instructor may submit **one appeal per suspension**.
8. SISA approves or rejects the appeal with a written response. Approval lifts the suspension and may revoke selected incidents; the active strike count must fall below 3 for an automatic suspension to be overturned.
9. SISA can suspend manually with a required reason and duration.
10. Automatic suspension clears served strikes after expiry. Manual suspension does not reset strikes unless an incident is revoked.

## 2. Student feedback hub

One page: `Student/Feedback/Create.vue`.

| Track | Visibility | Strike effect |
|---|---|---|
| Public review | Public profile | **0** |
| Private concern | Student + SISA only | Pending; 1/2/3 after confirmation |

The student can submit either track, or both. The private report links to the public review when both exist.

### Public review fields

- Optional 1–5 rating
- Comment, minimum 20 characters
- Optional photo
- Positive predefined tags
- “Other positive comment”

### Private concern fields

- One primary category
- Description, minimum 30 characters
- Injury / near-miss flags
- Optional evidence (PDF/JPG/PNG, max 10 MB)
- Truthfulness declaration

“Other concern” is unclassified and requires SISA to assign the final severity.

## 3. Predefined categories

The code-defined list lives in `App\Enums\FeedbackCategory`.

### Positive — 0 strikes

- Clear coaching and encouragement
- Felt safe and well supervised
- Good local, tide, and current knowledge
- Appropriate board and equipment
- Punctual, prepared, and professional
- Other positive comment

### Minor — 1 strike

- Late start or early end without notice
- Poor communication or unclear instructions
- Phone or social-media distraction
- Did not follow the agreed lesson plan
- Ill-fitting or poorly checked equipment
- Unprofessional language or attitude
- Asked to pay or cancel outside the platform
- Other minor concern

### Major — 2 strikes

- No safety briefing
- Ignored tide/current/weather warnings
- Exceeded the certified student ratio
- Taught above the booked skill level
- Briefly left the student/group unsupervised
- Pressured the student beyond their comfort level
- Unsafe equipment or leash failure
- Unwanted personal comments/photos without consent
- Failed first aid/injury response
- Allowed an unverified assistant to teach
- Other major concern

### Critical — 3 strikes

- Left the student alone/unattended in the water
- Took a beginner to a dangerous or advanced break
- Harassment, threats, or discrimination
- Under the influence of alcohol/drugs
- Physical assault/violence
- Continued after the student said stop or reported injury
- Taught without valid certification
- Serious negligence causing/worsening injury
- Theft, extortion, or fraud
- Other critical concern

## 4. Database model

- `reviews.feedback_tags` — positive tags (JSON)
- `safety_incidents` — source, status, category, suggested/final severity, strike weight, review link, evidence, instructor summary, confirmation/rejection fields
- `instructor_strikes` — append-only ledger (weight, reason, source, status active/revoked/served)
- `instructor_suspensions` — type manual/automatic/legacy, status, reason, start/end, triggering incident
- `instructor_appeals` — one per suspension, statement, ground, evidence, status, admin response, revoked incident IDs
- `instructor_status_logs` — status change audit trail
- `instructor_profiles.suspension_reason` / `suspended_at` — current-state fallback for the suspended page

## 5. State transitions

```text
Student concern: pending → confirmed → revoked
                         → rejected
Admin incident:  confirmed (immediate)
```

Only `confirmed` incidents create strikes.

```text
Suspension: active → completed (expiry)
                   → lifted_on_appeal
                   → revoked_by_admin
Appeal:     pending → approved | rejected | withdrawn | expired
```

## 6. Key implementation files

| Concern | File |
|---|---|
| Category taxonomy | `app/Enums/FeedbackCategory.php` |
| Student submission | `app/Http/Controllers/Student/FeedbackController.php` |
| Submission orchestration | `app/Services/FeedbackService.php` |
| Strike ledger + threshold | `app/Services/StrikeService.php` |
| Suspension + booking cancellation | `app/Services/SuspensionService.php` |
| Appeal review | `app/Services/AppealService.php` |
| Admin report queue | `app/Http/Controllers/Admin/IncidentController.php` |
| Admin appeal queue | `app/Http/Controllers/Admin/AppealController.php` |
| Suspended page + appeal | `app/Http/Controllers/Instructor/SuspensionController.php` |
| Suspended UI | `resources/js/Pages/Instructor/Suspended.vue` |
| Feedback UI | `resources/js/Pages/Student/Feedback/Create.vue` |

## 7. Tests

`tests/Feature/FeedbackStrikeAppealTest.php` covers:

- positive review → no report/no strike
- pending concern → no strike
- admin confirmation → correct weight
- three minors → suspension
- one critical → immediate suspension
- rejection → no strike
- one report per booking
- suspended page reason and appeal availability
- suspended instructor blocked from booking workspace
- appeal approval → strike revoked, suspension lifted
- manual suspension reason visible
- admin direct incident applies its strike

## 8. Documentation alignment

This behavior is reflected in:

- `prd.md` §2.5.5 and BR-5.8–BR-5.15
- `AGENTS.md` Phase 4, Phase 9, Phase 10 and the Business Rules table
- `PRODUCT.md` capabilities
- `siasurf_methodology.md` §1.6 and §3.2
- `DOCUMENT/SiaSurf_IntroductionMethodology...` Safety and Strike Management

