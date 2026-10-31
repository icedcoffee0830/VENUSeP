// Flow (auth area) — two things the sign-in side must get right in a real browser.
//
// A. COUNTER MODE STAYS ON (Bug 1). Staff open the counter from the dashboard and
//    then use EVERY link of the customer top bar — the logo, each venue, Hostel,
//    How it works, FAQ — from the landing page, the FAQ, a room page and a hostel
//    page. Every link must keep ?counter=1 (or be an in-page anchor / the way back
//    to the admin side), and every page reached must still be the counter, never
//    the customer sign-in page.
// B. A DISABLED CUSTOMER IS TOLD (Bug 2). A signed-in customer is disabled by staff
//    in Customer Management; their next click lands on the sign-in page with the
//    "Account disabled" modal. Signing in again with the right password shows it
//    again; a wrong password shows only the generic message.

const STAFF = 'staff@gmail.com';
const CUSTOMER = 'customer@gmail.com';
const VENUE_ROOM = 'r2';    // Heritage Function Room, Bahay Alumni
const HOSTEL_ROOM = 'h2';   // Hostel Room 2

/* Every link the customer top bar renders (bar + phone menu + logo) must keep counter
   mode: an in-page anchor, a URL with counter=1, or the staff side (Leave counter mode). */
async function checkNavLinks(ctx, page) {
  const hrefs = await page.locator('#cnNav a[href]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  ctx.expect.ok(hrefs.length > 0, 'the customer top bar has no links');
  const bad = hrefs.filter((h) => !(h.startsWith('#') || /[?&]counter=1(#|&|$)/.test(h) || /\/admin\//.test(h)));
  if (bad.length) ctx.fail(`on ${page.url()} these top-bar links drop counter mode: ${bad.join(', ')}`);
  ctx.note(`${page.url().replace(/^https?:\/\/[^/]+/, '')}: ${hrefs.length} top-bar links keep counter mode`);
}

/* Still the counter: the "Counter" chip, no customer Log in / Sign up. */
async function stillCounter(ctx, page, urlRe) {
  await ctx.expect.url(page, urlRe);
  ctx.expect.ok(!page.url().includes('customer-login'), `counter mode was lost: ${page.url()}`);
  await ctx.expect.visible(page.locator('#cnNav .cn-name', { hasText: 'Counter' }), `no "Counter" chip on ${page.url()}`);
  ctx.expect.equal(await page.locator('#cnNav .cn-login').count(), 0, `a customer "Log in" link on ${page.url()}`);
  await checkNavLinks(ctx, page);
}

/* Click a top-bar link by its text (the desktop bar, not the hidden phone menu). */
async function clickNav(page, name) {
  await Promise.all([page.waitForLoadState('load'), page.locator('#cnNav .cn-links a', { hasText: name }).first().click()]);
}
async function clickLogo(page) {
  await Promise.all([page.waitForLoadState('load'), page.locator('#cnNav a.cn-logo').click()]);
}

/* the shared venusepAlert/venusepConfirm modal (assets/js/confirm-modal.js) */
function modalBox(page) {
  return page.locator('.vz-modal-overlay .vz-modal').last();
}

export default {
  name: 'auth-counter-and-disabled',
  title: 'Counter mode survives every top-bar link; a disabled customer is told',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    /* ================= A. counter mode ================= */

    await step('Staff open the counter from the dashboard — every top-bar link keeps ?counter=1', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      await page.getByRole('link', { name: '+ New Booking' }).click();
      await stillCounter(ctx, page, /\/customer\/venues\?counter=1/);
      return page;
    });

    await step('Landing → FAQ → logo: still the counter', async () => {
      const page = s.staff;
      await clickNav(page, 'FAQ');
      await stillCounter(ctx, page, /\/customer\/faq\?counter=1/);
      await clickLogo(page);
      await stillCounter(ctx, page, /\/customer\/venues\?counter=1$/);
      return page;
    });

    await step('From the FAQ: each venue link, Hostel and How it works stay in counter mode', async () => {
      const page = s.staff;
      const names = await page.locator('#cnNav .cn-links a').evaluateAll((as) => as.map((a) => a.textContent.trim()));
      const sections = names.filter((n) => n !== 'FAQ');   // venues, the hostel, How it works (no "My bookings": staff are not a customer)
      expect.ok(sections.length >= 3, `expected venue / hostel / how links, got ${names.join(', ')}`);
      expect.ok(!names.includes('My bookings'), 'counter mode shows the customer-only "My bookings" link');
      for (const name of sections) {
        await page.goto(ctx.url('/customer/faq?counter=1'));
        await clickNav(page, name);
        await stillCounter(ctx, page, /\/customer\/venues\?counter=1#/);
      }
      ctx.note('checked: ' + sections.join(', '));
      return page;
    });

    await step('A room page and a hostel page in counter mode: top bar + logo keep it', async () => {
      const page = s.staff;
      await page.goto(ctx.url(`/customer/room-reservation?room=${VENUE_ROOM}&counter=1`));
      await expect.text(page, 'Counter booking');
      await checkNavLinks(ctx, page);
      await clickLogo(page);
      await stillCounter(ctx, page, /\/customer\/venues\?counter=1$/);

      await page.goto(ctx.url(`/customer/hostel-reservation?room=${HOSTEL_ROOM}&counter=1`));
      await checkNavLinks(ctx, page);
      await clickLogo(page);
      await stillCounter(ctx, page, /\/customer\/venues\?counter=1$/);
      return page;
    });

    await step('"Leave counter mode" goes back to the staff dashboard', async () => {
      const page = s.staff;
      await Promise.all([page.waitForLoadState('load'), page.locator('#cnNav a.cn-leave').click()]);
      await expect.url(page, /\/admin\/dashboard/);
      return page;
    });

    /* ================= B. disabled customer ================= */

    await step('A customer is signed in and on their bookings', async () => {
      s.cust = await ctx.loginCustomer(CUSTOMER);
      await s.cust.goto(ctx.url('/customer/booking-history'));
      await expect.url(s.cust, /\/customer\/booking-history/);
      return s.cust;
    });

    await step('The admin disables that customer in Customer Management', async () => {
      s.admin = await ctx.loginAdmin();
      const page = s.admin;
      await page.goto(ctx.url('/admin/customer-management'));
      await page.locator('#table-filter').fill(CUSTOMER);
      const row = page.locator('.tabulator-row', { hasText: CUSTOMER });
      await expect.visible(row, `no row for ${CUSTOMER}`);
      const reloaded = page.waitForEvent('load').then(() => true, () => false);
      await row.locator('.btn-cust-toggle', { hasText: 'Disable' }).click();
      const box = modalBox(page);
      await expect.visible(box, 'no confirm dialog');
      await box.locator('button[data-vz="ok"]').click();
      expect.ok(await reloaded, 'the page did not reload after Disable');
      const u = await dbOne('SELECT is_active FROM users WHERE email = ?', [CUSTOMER]);
      expect.equal(u.is_active, 0, 'users.is_active');
      return page;
    });

    await step('The customer\'s next click lands on the sign-in page with the "Account disabled" modal', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/transaction-history'));
      await expect.url(page, /\/customer\/customer-login/);
      const box = modalBox(page);
      await expect.visible(box, 'no "Account disabled" modal');
      expect.match(await box.textContent(), /Account disabled[\s\S]*has been disabled/, 'modal text');
      await box.locator('button[data-vz="ok"]').click();
      await expect.visible(page.locator('#accountDisabledNotice'), 'the notice did not stay after closing the modal');
      return page;
    });

    await step('Signing in again: the right password shows the notice, a wrong one the generic message', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/customer-login'));
      expect.equal(await page.locator('#accountDisabledNotice').count(), 0, 'the notice is shown only once');

      await page.locator('#customerEmail').fill(CUSTOMER);
      await page.locator('#customerPassword').fill(ctx.password);
      await Promise.all([page.waitForLoadState('load'), page.locator('#customerLoginForm button[type="submit"]').click()]);
      await expect.visible(modalBox(page), 'no modal after the right password');
      await modalBox(page).locator('button[data-vz="ok"]').click();
      expect.ok(page.url().includes('/customer/customer-login'), 'a disabled account got past the sign-in page');

      await page.locator('#customerEmail').fill(CUSTOMER);
      await page.locator('#customerPassword').fill('not-the-password');
      await Promise.all([page.waitForLoadState('load'), page.locator('#customerLoginForm button[type="submit"]').click()]);
      await expect.text(page, 'Invalid email or password.');
      expect.equal(await page.locator('#accountDisabledNotice').count(), 0, 'a wrong password revealed the disabled notice');
      expect.equal(await page.locator('.vz-modal-overlay').count(), 0, 'a wrong password opened the modal');
      return page;
    });
  },
};
