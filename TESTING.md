# SiaSurf Testing Plan

## Overview
This document outlines the testing strategy for SiaSurf platform covering all critical user flows and system functionality. Currently the automated suite contains **49 test files (438 test methods)** running on PHPUnit with `RefreshDatabase` against the MySQL `siasurf_test` database.

## Test Coverage Requirements

### Minimum Coverage Targets
- **Models**: 90%+
- **Controllers**: 85%+
- **Services**: 95%+
- **Policies**: 100%

## Test Types

### 1. Unit Tests
**Location**: `tests/Unit/`

#### Services & Domain Enums
- [x] `SafetyServiceTest` - All 6 safety rules
- [x] `WaiverServiceTest` - Liability waivers, parental consent
- [x] `RefundCalculatorServiceTest` - Long-term / short-term refund rules
- [x] `SkillAssessmentServiceTest` - Student skill evaluation
- [x] `StrikeLadderTest` - V3 3-strike ladder, fines, suspensions, revocations
- [x] `Enums/SkillLevelMatchingTest` - Skill, spot, and instructor level matching
- [ ] `PaymentServiceTest` - Unit tests for Mock, Xendit, and PayMongo drivers
- [ ] `QRCodeServiceTest` - QR generation
- [ ] `DashboardServiceTest` - Statistics calculation

*(Pricing and service capacity are currently tested at the feature level in `tests/Feature/ServiceBasedPricingAndCapacityTest.php` and `tests/Feature/RatePerHourValidationTest.php`.)*

#### Models
- [ ] UserTest - Relationships, scopes, role checks
- [ ] BookingTest - Status transitions, relationships
- [ ] InstructorProfileTest - Level calculations, strike system
- [ ] ReviewTest - Rating calculations, response methods

### 2. Feature Tests
**Location**: `tests/Feature/`

#### Authentication
- [x] Login/Logout functionality
- [x] Registration with role selection
- [x] Password reset flow
- [x] Role-based access control

#### Booking Flow
- [x] Booking creation with validation
- [x] Safety rule enforcement
- [x] Booking confirmation by instructor
- [x] Booking cancellation
- [x] Authorization checks

#### Admin Functions
- [x] Instructor CRUD operations
- [x] Certificate upload and verification
- [x] Instructor verification workflow
- [x] Suspension and reactivation

#### Feedback & Reviews
- [x] Review creation & public/private strike separation (`FeedbackStrikeAppealTest.php`, `CancellationFeedbackTest.php`)
- [ ] Review editing (24h window)
- [ ] Instructor responses
- [ ] Admin moderation

#### Payments
- [x] Payment creation (`PaymentFlowTest.php`)
- [x] GCash integration & receipt upload (`PaymentFlowTest.php`, `GcashReceiptTest.php`, `ReceiptPdfTest.php`)
- [x] Cash on hand flow (`PaymentFlowTest.php`)
- [x] Payment webhooks (`AuditSecurityAndFlowRegressionTest.php`)

### 3. E2E Tests (Playwright — Planned, Not Yet Configured)
**Location**: `tests/e2e/` *(directory, Playwright dependency, and `test:e2e` npm script are not yet configured; `package.json` currently defines `build` and `dev` only)*

#### Critical User Flows
1. **Student Registration → Booking → Payment**
   - Register as student
   - Search for instructor
   - Create booking
   - Sign waiver
   - Complete payment
   - Verify booking confirmed

2. **Instructor Verification → Booking Management**
   - Admin registers instructor
   - Upload certificates
   - Verify instructor
   - Instructor accepts booking
   - Mark session complete

3. **Walk-in QR Flow**
   - Scan instructor QR code
   - Register as new user
   - Quick book session
   - Complete waiver
   - Pay cash

4. **Safety Rules Enforcement**
   - Attempt booking with too many students for Level 1
   - Verify error message displayed
   - Try with valid parameters
   - Confirm booking succeeds

5. **Review Submission Flow**
   - Complete booking
   - Wait for review email
   - Submit review with photo
   - Verify review appears on instructor profile

### 4. API Tests *(Planned)*
**Location**: `tests/Feature/Api/` *(directory does not exist yet)*

#### Endpoints to Test
- [ ] GET /api/instructors - Search with filters
- [ ] GET /api/availability/{instructor} - Available slots
- [ ] GET /api/instructors/{instructor}/reviews - Reviews list
- [ ] GET /api/reviews/recent - Recent reviews

### 5. Security Tests *(Planned Directory — Regression Tests Live in `AuditSecurityAndFlowRegressionTest.php`)*
**Location**: `tests/Feature/Security/` *(directory does not exist yet; current security & webhook regression coverage is in `tests/Feature/AuditSecurityAndFlowRegressionTest.php`)*

- [ ] SQL injection attempts
- [ ] XSS attempts in reviews/bookings
- [ ] CSRF protection verification
- [ ] Authorization bypass attempts
- [ ] File upload security (malicious files)

## Running Tests

### All Tests
```bash
php artisan test
```

### Specific Test Suite
```bash
php artisan test --filter=BookingControllerTest
```

### With Coverage
```bash
php artisan test --coverage --min=80
```

### E2E Tests (Planned)
Playwright is not installed yet (`package.json` has `build` and `dev` scripts only). Once configured, add a `test:e2e` script for `tests/e2e/`.

## Test Data

### Factories Available
- `AvailabilityFactory`
- `BookingFactory`
- `BookingParticipantFactory`
- `CertificateFactory`
- `InstructorProfileFactory`
- `PaymentFactory`
- `ReviewFactory`
- `SafetyIncidentFactory`
- `SurfSpotFactory`
- `UserFactory`
- `WaiverFactory`

## CI/CD Integration

Tests should run on:
1. Every pull request
2. Before deployment to staging
3. Before deployment to production

## Known Limitations

1. **Payment Testing**: Xendit / PayMongo live or sandbox testing requires API keys; tests default to `MockPaymentService` when unset
2. **WebSocket Testing**: Requires running Reverb server
3. **Email Testing**: Uses mailtrap or log driver in tests
4. **File Uploads**: Uses `Storage::fake()` in tests
5. **Test Isolation**: `tests/TestCase.php` does not yet reset Spatie's `PermissionRegistrar` cache between tests, so full-suite runs can be order-sensitive

## Test Checklist

### Before Release
- [ ] All unit tests passing
- [ ] All feature tests passing
- [ ] E2E critical flows passing
- [ ] Code coverage above 80%
- [ ] Security scan completed
- [ ] Performance benchmarks met

### Post-Deployment
- [ ] Smoke tests on production
- [ ] Payment flow verified (small amount)
- [ ] Email delivery confirmed
- [ ] WebSocket connections working
