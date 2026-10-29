const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8123';
const OUT = path.resolve(__dirname, '..', 'screenshots');

const ROLES = {
  public: null,
  student: { email: 'student@siasurf.test', password: 'password' },
  instructor: { email: 'coach.marlon@siasurf.test', password: 'password' },
  suspended: { email: 'coach.suspended@siasurf.test', password: 'password' },
  appealing: { email: 'coach.appealing@siasurf.test', password: 'password' },
  admin: { email: 'admin@siasurf.test', password: 'password' },
  unverified: { email: 'unverified@siasurf.test', password: 'password' },
};

// [slug, role, path]
const PAGES = [
  ['public-welcome', 'public', '/'],
  ['public-instructor-search', 'public', '/instructors'],
  ['public-instructor-profile', 'public', '/instructors/2'],
  ['public-surf-spots', 'public', '/surf-spots'],
  ['public-walkin-qr-scan', 'public', '/qr/2'],
  ['auth-login', 'public', '/login'],
  ['auth-register', 'public', '/register'],
  ['auth-forgot-password', 'public', '/forgot-password'],
  ['auth-reset-password', 'public', '/reset-password/demo-token?email=student%40siasurf.test'],
  ['payment-mock-checkout', 'public', '/mock-checkout/demo-txn-123'],
  ['auth-verify-email', 'unverified', '/verify-email'],

  ['student-dashboard', 'student', '/student/dashboard'],
  ['student-bookings', 'student', '/student/bookings'],
  ['student-booking-show', 'student', '/student/bookings/1'],
  ['student-booking-awaiting-payment', 'student', '/student/bookings/4'],
  ['student-reviews', 'student', '/student/reviews'],
  ['student-review-create', 'student', '/student/bookings/2/review'],
  ['student-feedback-hub', 'student', '/student/bookings/2/feedback'],
  ['booking-create-step1', 'student', '/bookings/create/2'],
  ['booking-waiver', 'student', '/bookings/8/waiver'],
  ['booking-parental-consent', 'student', '/bookings/9/parental-consent'],
  ['booking-payment', 'student', '/bookings/4/payment'],
  ['booking-confirmation', 'student', '/bookings/1/confirmation'],
  ['payment-show', 'student', '/payments/4'],
  ['profile-edit', 'student', '/profile'],
  ['auth-confirm-password', 'student', '/confirm-password'],

  ['instructor-dashboard', 'instructor', '/instructor/dashboard'],
  ['instructor-availability', 'instructor', '/instructor/availability'],
  ['instructor-bookings', 'instructor', '/instructor/bookings'],
  ['instructor-booking-show', 'instructor', '/instructor/bookings/4'],
  ['instructor-booking-live', 'instructor', '/instructor/bookings/6'],
  ['instructor-profile', 'instructor', '/instructor/profile'],
  ['instructor-pending', 'instructor', '/instructor/pending'],
  ['instructor-onboarding', 'instructor', '/instructor/onboarding'],
  ['instructor-suspended-form', 'suspended', '/instructor/suspended'],
  ['instructor-suspended-appeal-status', 'appealing', '/instructor/suspended'],

  ['admin-dashboard', 'admin', '/admin/dashboard'],
  ['admin-analytics', 'admin', '/admin/analytics'],
  ['admin-instructors', 'admin', '/admin/instructors'],
  ['admin-instructor-create', 'admin', '/admin/instructors/create'],
  ['admin-instructor-show', 'admin', '/admin/instructors/2'],
  ['admin-instructor-show-suspended', 'admin', '/admin/instructors/6'],
  ['admin-instructor-edit', 'admin', '/admin/instructors/2/edit'],
  ['admin-bookings', 'admin', '/admin/bookings'],
  ['admin-booking-show', 'admin', '/admin/bookings/1'],
  ['admin-change-requests', 'admin', '/admin/change-requests'],
  ['admin-students', 'admin', '/admin/students'],
  ['admin-student-show', 'admin', '/admin/students/8'],
  ['admin-incidents', 'admin', '/admin/incidents'],
  ['admin-incident-create', 'admin', '/admin/incidents/create'],
  ['admin-incident-show-pending', 'admin', '/admin/incidents/1'],
  ['admin-incident-show-confirmed', 'admin', '/admin/incidents/2'],
  ['admin-appeals', 'admin', '/admin/appeals'],
  ['admin-appeal-show-pending', 'admin', '/admin/appeals/1'],
  ['admin-appeal-show-approved', 'admin', '/admin/appeals/2'],
  ['admin-skill-upgrades', 'admin', '/admin/skill-upgrades'],
  ['admin-surf-spots', 'admin', '/admin/surf-spots'],
];

async function login(browser, creds) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 960 },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
  });
  const page = await context.newPage();
  await page.goto(`${BASE}/login`, { waitUntil: 'load', timeout: 45000 });
  await page.waitForSelector('#email', { timeout: 30000 });
  await page.fill('#email', creds.email);
  await page.fill('#password', creds.password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 45000 }).catch(() => {}),
    page.getByRole('button', { name: /sign in/i }).click(),
  ]);
  await page.waitForTimeout(800);
  return { context, page, startUrl: page.url() };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const sessions = {};
  const results = [];

  for (const [role, creds] of Object.entries(ROLES)) {
    if (!creds) {
      const context = await browser.newContext({
        viewport: { width: 1440, height: 960 },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
      });
      sessions[role] = { context, page: await context.newPage(), startUrl: null };
      continue;
    }
    try {
      sessions[role] = await login(browser, creds);
      console.log(`logged in: ${role} -> ${sessions[role].startUrl}`);
    } catch (e) {
      console.log(`LOGIN FAILED ${role}: ${e.message}`);
    }
  }

  let index = 0;
  for (const [slug, role, route] of PAGES) {
    index += 1;
    const session = sessions[role];
    const file = path.join(OUT, `${String(index).padStart(2, '0')}-${slug}.png`);
    if (!session) {
      results.push({ n: index, slug, role, route, error: 'no session', ok: false });
      console.log(`${index}. ${slug} SKIPPED (no session)`);
      continue;
    }
    const page = session.page;

    await page.addStyleTag({
      content: '*, *::before, *::after { animation-duration: 0s !important; transition-duration: 0s !important; caret-color: transparent !important; }',
    }).catch(() => {});

    let status = null;
    const onResponse = (res) => {
      if (res.request().resourceType() === 'document') status = res.status();
    };
    page.on('response', onResponse);

    try {
      await page.goto(`${BASE}${route}`, { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(route.includes('surf-spots') ? 3500 : 1400);
      const title = await page.title();
      const url = page.url();
      const bodyText = await page.evaluate(() => document.body.innerText.slice(0, 400)).catch(() => '');
      await page.screenshot({ path: file, fullPage: true });
      results.push({ n: index, slug, role, route, finalUrl: url, status, title, ok: true });
      console.log(`${index}. ${slug} [${status}] -> ${url}`);
      if (status && status >= 400) {
        console.log(`   BODY: ${bodyText.replace(/\s+/g, ' ').slice(0, 300)}`);
      }
    } catch (e) {
      results.push({ n: index, slug, role, route, status, error: e.message, ok: false });
      console.log(`${index}. ${slug} FAILED: ${e.message}`);
      try {
        await page.screenshot({ path: file, fullPage: true });
      } catch (_) {}
    } finally {
      page.off('response', onResponse);
    }
  }

  fs.writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(results, null, 2));
  await browser.close();
  console.log(`\nSaved ${results.filter((r) => r.ok).length}/${PAGES.length} screenshots to ${OUT}`);
})();
