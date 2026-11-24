# SiaSurf Security Checklist

## OWASP Top 10 Compliance

### A01: Broken Access Control
- [x] All routes protected by middleware (auth, role checks)
- [x] Policies implemented for all resources
- [x] Authorization checks in controllers
- [x] Rate limiting on authentication endpoints (`throttle:login`, 5/min per email+IP)
- [x] Session timeout configuration (120 minutes in `config/session.php`)

### A02: Cryptographic Failures
- [x] Passwords hashed with bcrypt (Laravel default)
- [x] HTTPS enforced in production
- [ ] Sensitive data encrypted at rest (waivers, medical info)
- [x] API keys stored in .env (not committed)

### A03: Injection
- [x] No raw SQL queries with string concatenation
- [x] All queries use Eloquent/Query Builder with bindings
- [x] Input validation on all Form Requests
- [ ] Prepared statements verified

### A04: Insecure Design
- [x] Safety rules cannot be overridden (hardcoded in SafetyService)
- [x] Business logic in Services, not Controllers
- [x] Proper error handling without information disclosure
- [x] Throttling on payment endpoints (`throttle:payments`, 10/min)

### A05: Security Misconfiguration
- [x] Debug mode disabled in production (.env)
- [x] Detailed error messages hidden in production
- [x] Default passwords changed
- [ ] Security headers implemented (`SecurityHeaders` middleware exists in `app/Http/Middleware/SecurityHeaders.php`, not yet registered in `bootstrap/app.php`)
- [ ] Unnecessary features disabled

### A06: Vulnerable Components
- [ ] Composer dependencies audited
- [ ] NPM dependencies audited
- [ ] Known vulnerabilities checked (snyk, dependabot)
- [ ] Regular update schedule established

### A07: Authentication Failures
- [x] Strong password requirements
- [x] Session management (Laravel default)
- [x] CSRF protection enabled
- [ ] MFA implementation (future)
- [x] Brute force protection (`throttle:login` + `LoginRequest::ensureIsNotRateLimited()`, 5 attempts/min)

### A08: Data Integrity Failures
- [x] Payment webhook signature/token verification (`XenditPaymentService` via `x-callback-token`, `PayMongoPaymentService` via `Paymongo-Signature`)
- [x] Digital signatures on waivers
- [ ] File upload integrity checks

### A09: Security Logging Failures
- [x] Failed login attempts logged
- [x] Security incidents logged
- [ ] Payment failures logged
- [ ] Admin actions audit trail

### A10: Server-Side Request Forgery
- [x] No user-controlled URLs in server requests
- [x] Payment provider URLs hardcoded/configured (`XenditPaymentService` / `PayMongoPaymentService`)
- [ ] URL validation on any external requests

## Laravel-Specific Security

### Authentication & Authorization
- [x] Spatie Laravel Permission implemented
- [x] Policies for all models
- [x] Gates defined in `AppServiceProvider` (`app/Providers/AppServiceProvider.php`)
- [x] Middleware for role verification

### Input Validation
- [x] Form Request classes for all endpoints
- [x] Validation rules strict
- [x] File upload validation (type, size)
- [x] No `$request->all()` without validation

### Output Encoding
- [x] No `{!! !!}` with user data
- [x] No `v-html` with untrusted content
- [x] Blade auto-escaping used

### File Uploads
- [x] MIME type validation
- [x] File size limits (10MB for PDFs, 5MB for images)
- [x] Stored outside web root
- [ ] Virus scanning (ClamAV integration — `FileScanService` supports `clamdscan` when `VIRUS_SCAN_ENABLED=true`, disabled by default on local XAMPP)

### Database Security
- [x] No SQL injection vulnerabilities
- [x] Mass assignment protection ($fillable, not $guarded)
- [x] Foreign key constraints
- [x] Soft deletes for sensitive data

### Session Security
- [x] Session driver: `file` (configured in `.env`; `database` default in `config/session.php`)
- [x] Session timeout: 120 minutes
- [x] Secure session cookies
- [x] HttpOnly flag set

### API Security
- [x] Webhook signature/token verification (Xendit `x-callback-token` / PayMongo `Paymongo-Signature`)
- [x] Rate limiting configured
- [x] CORS properly configured
- [x] No sensitive data in API responses

## Application-Specific Security

### Booking System
- [x] Safety rules enforced server-side
- [x] No race conditions on availability
- [x] Payment status validated before confirmation

### Payment Processing
- [x] Payment gateway integration (`XenditPaymentService` active via `XENDIT_SECRET_KEY`, `PayMongoPaymentService` fallback, `MockPaymentService` when unset)
- [x] Webhook signature/token verification
- [ ] Idempotency keys for payments (not implemented; Xendit uses generated `reference_id` only)
- [x] Amount validation before processing

### Instructor Verification
- [x] Certificate validation required
- [x] QR codes non-modifiable
- [x] V3 3-strike ladder automatic on SISA-confirmed incidents

### Digital Waivers
- [x] PDF generation tamper-proof
- [x] Electronic signatures stored
- [x] 7-year retention enforced

## Security Headers (Implemented in Middleware — Not Yet Registered)

`app/Http/Middleware/SecurityHeaders.php` implements these headers, but it is not yet registered in `bootstrap/app.php`:

```php
// app/Http/Middleware/SecurityHeaders.php
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains // production only
Content-Security-Policy: default-src 'self'; ...
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
```

## Rate Limiting Configuration

Configured in `app/Providers/AppServiceProvider.php` (`configureRateLimiting()`):

```php
// app/Providers/AppServiceProvider.php
RateLimiter::for('login', function (Request $request) {
    $key = mb_strtolower((string) $request->input('email')) . '|' . $request->ip();
    return Limit::perMinute(5)->by($key);
});

RateLimiter::for('payments', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('bookings', function (Request $request) {
    return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('chat', function (Request $request) {
    return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('public-api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

## Security Audit Commands

```bash
# Audit PHP and Node dependencies
composer audit
npm audit

# Check PHP code style compliance
./vendor/bin/pint --test

# Run automated test suite (including security regression tests)
php artisan test
```

## Pre-Deployment Security Checklist

- [ ] Run full test suite
- [ ] Security scan completed
- [ ] Dependencies audited
- [ ] No debug mode in production
- [ ] HTTPS enforced
- [ ] Security headers configured (register `SecurityHeaders` in `bootstrap/app.php`)
- [ ] Rate limiting active
- [ ] Error logging configured (not displaying)
- [ ] Backup strategy tested
- [ ] Disaster recovery plan documented

## Security Contacts

**Security Issues**: Report to admin@siasurf.com
**Emergency**: +63 XXX XXX XXXX
