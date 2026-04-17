# SiaSurf Testing Plan

## Overview
This document outlines the testing strategy for SiaSurf platform covering all critical user flows and system functionality.

## Test Coverage Requirements

### Minimum Coverage Targets
- **Models**: 90%+
- **Controllers**: 85%+
- **Services**: 95%+
- **Policies**: 100%

## Test Types

### 1. Unit Tests
**Location**: `tests/Unit/`

#### Services
- [x] SafetyServiceTest - All 6 safety rules
- [x] PricingServiceTest - Base rates, add-ons, discounts
- [x] WaiverServiceTest - Liability waivers, parental consent
- [ ] PaymentServiceTest - Mock and PayMongo implementations
- [ ] QRCodeServiceTest - QR generation
- [ ] DashboardServiceTest - Statistics calculation

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

#### Reviews
- [ ] Review creation
- [ ] Review editing (24h window)
- [ ] Instructor responses
- [ ] Admin moderation

#### Payments
- [ ] Payment creation
- [ ] GCash integration (mock)
- [ ] Cash on hand flow
- [ ] Payment webhooks

### 3. E2E Tests (Playwright)
**Location**: `tests/e2e/`

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

### 4. API Tests
**Location**: `tests/Feature/Api/`

#### Endpoints to Test
- [ ] GET /api/instructors - Search with filters
- [ ] GET /api/availability/{instructor} - Available slots
- [ ] GET /api/instructors/{instructor}/reviews - Reviews list
- [ ] GET /api/reviews/recent - Recent reviews

### 5. Security Tests
**Location**: `tests/Feature/Security/`

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

### E2E Tests
```bash
npm run test:e2e
```

## Test Data

### Factories Available
- UserFactory
- InstructorProfileFactory
- BookingFactory
- SurfSpotFactory
- ReviewFactory
- PaymentFactory
- SafetyIncidentFactory
- CertificateFactory
- WaiverFactory

## CI/CD Integration

Tests should run on:
1. Every pull request
2. Before deployment to staging
3. Before deployment to production

## Known Limitations

1. **Payment Testing**: PayMongo integration requires sandbox keys
2. **WebSocket Testing**: Requires running Reverb server
3. **Email Testing**: Uses mailtrap or log driver in tests
4. **File Uploads**: Uses Storage::fake() in tests

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
