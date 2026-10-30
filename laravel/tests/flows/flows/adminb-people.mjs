// Flow — staff look after customer accounts and the FAQ.
// a customer registers with a name that contains "</script>" → staff see it as plain
// text on Customer Management (no script runs) → staff disable the account: stored,
// and the customer's open session ends → staff re-enable it: they can sign in again →
// the counter search treats "%%%" literally → staff add a FAQ question with typed
// HTML (customers see it as text), move it to another section by editing it (it goes
// to the end there), then delete it.

const STAFF = 'staff@gmail.com';

/* the shared venusepAlert/venusepConfirm modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  return text;
}

/* Customer Management: filter to one email, then wait for its row */
async function customerRow(ctx, page, email) {
  await page.goto(ctx.url('/admin/customer-management'));
  await page.locator('#table-filter').fill(email);
  const row = page.locator('#customers-table .tabulator-row', { hasText: email });
  await ctx.expect.visible(row, `${email} is not in the customer table`);
  return row;
}

/* click Disable/Enable in the row, confirm, wait for the save and the reload */
async function toggleCustomer(ctx, page, row, label, okLabel = label) {
  await row.locator('.btn-cust-toggle', { hasText: label }).click();
  /* .catch: a dangling wait must not crash the runner when the step fails first */
  const answer = page.waitForResponse((r) => r.url().includes('/admin/customer-save') && r.request().method() === 'POST').catch(() => null);
  const reloaded = page.waitForEvent('load').then(() => true, () => false);
  await modal(ctx, page, { expect: new RegExp(okLabel), ok: okLabel });
  const res = await answer;
  if (!res) ctx.fail(`${label} sent no request to /admin/customer-save`);
  if (res.status() !== 200) ctx.fail(`${label}: POST /admin/customer-save answered HTTP ${res.status()} ${(await res.text()).slice(0, 200)}`);
  if (!(await reloaded)) ctx.fail(`the page did not reload after ${label}`);
}

export default {
  name: 'adminb-people',
  title: 'Customer accounts (disable / enable, search) and FAQ edit / delete',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    s.email = `flow.people.${ctx.uniq.replace(/\D/g, '')}@example.com`;
    s.name = 'Flow </script><script>window.__adminbPwned=1</script> Person';

    await step('A customer registers with "</script>" in their name', async () => {
      const page = await ctx.newPage('new customer');
      await page.goto(ctx.url('/customer/customer-register'));
      await page.locator('#fullName').fill(s.name);
      await page.locator('#email').fill(s.email);
      await page.locator('#contactNumber').fill('09171112244');
      await page.locator('#password').fill(ctx.password);
      await page.locator('#confirmPassword').fill(ctx.password);
      await page.locator('#terms').check();
      await page.getByRole('button', { name: 'Create Account' }).click();
      await expect.text(page, 'Account created successfully');
      const u = await dbOne(`SELECT u.id, u.is_active, c.full_name FROM users u JOIN customers c ON c.user_id = u.id WHERE u.email = ?`, [s.email]);
      expect.ok(u, 'no users/customers row for the new account');
      expect.equal(u.full_name, s.name, 'customers.full_name (stored as typed)');
      s.userId = u.id;
      return page;
    });

    await step('The customer signs in (and stays signed in)', async () => {
      s.cust = await ctx.loginCustomer(s.email);
      await s.cust.goto(ctx.url('/customer/booking-history'));
      await expect.url(s.cust, /\/customer\/booking-history/);
      return s.cust;
    });

    await step('Staff open Customer Management: the name is plain text, no script ran', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      const row = await customerRow(ctx, page, s.email);
      expect.ok((await row.textContent()).includes('</script><script>window.__adminbPwned=1</script>'), 'the row does not show the name as typed');
      expect.equal(await page.evaluate(() => typeof window.__adminbPwned), 'undefined', 'a script from the customer name ran on the staff page');
      expect.match(await row.textContent(), /Active/, 'status in the row');
      return page;
    });

    await step('Staff disable the account → stored, and the customer is signed out', async () => {
      const page = s.staff;
      await toggleCustomer(ctx, page, await customerRow(ctx, page, s.email), 'Disable');
      expect.equal((await dbOne(`SELECT is_active FROM users WHERE id = ?`, [s.userId])).is_active, 0, 'users.is_active after Disable');
      const row = await customerRow(ctx, page, s.email);
      await expect.visible(row.getByText('Disabled', { exact: true }), 'the row does not say Disabled after the reload');

      await s.cust.goto(ctx.url('/customer/booking-history'));
      await expect.url(s.cust, /\/customer\/customer-login/);
      return page;
    });

    await step('Staff re-enable the account → stored, the customer can sign in again', async () => {
      const page = s.staff;
      await toggleCustomer(ctx, page, await customerRow(ctx, page, s.email), 'Enable', 'Re-enable');   // the button says Enable, the dialog Re-enable
      expect.equal((await dbOne(`SELECT is_active FROM users WHERE id = ?`, [s.userId])).is_active, 1, 'users.is_active after Enable');
      const again = await ctx.loginCustomer(s.email);
      await expect.url(again, /\/customer\//);
      return again;
    });

    await step('Counter customer search: "%%%" matches nothing, part of the email finds them', async () => {
      const page = s.staff;
      const search = (q) => page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).json(),
        ctx.url('/admin/customer-search?q=' + encodeURIComponent(q)));
      const wild = await search('%%%');
      expect.equal(JSON.stringify(wild), JSON.stringify({ ok: true, results: [] }), '"%%%" search');
      const found = await search(s.email.split('@')[0]);
      expect.equal(found.results.length, 1, 'results for the email');
      expect.equal(found.results[0].name, s.name, 'name in the search result');
      expect.match(found.results[0].phone, /^0917•+2244$/, 'masked phone');
      return page;
    });

    await step('Staff add a FAQ question with typed HTML → customers see it as text', async () => {
      const page = s.staff;
      s.faqQ = `Flow people question ${ctx.uniq}?`;
      await page.goto(ctx.url('/admin/faq-management'));
      await page.locator('#fqSection').selectOption('cash');
      await page.locator('#fqPolicy').selectOption('any');
      await page.locator('#fqQuestion').fill(s.faqQ);
      await page.locator('#fqAnswerEditor').click();
      await page.keyboard.type('Bring <b>exact</b> change.');
      await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Add question' }).click()]);
      await page.waitForLoadState('load');
      await expect.visible(page.locator('.fq-flash.ok'), 'no success message after adding the question');
      const f = await dbOne(`SELECT id, answer FROM faqs WHERE question = ?`, [s.faqQ]);
      expect.ok(f, 'no faqs row for the new question');
      expect.equal(f.answer, 'Bring <b>exact</b> change.', 'faqs.answer (stored as typed)');
      s.faqId = f.id;

      const guest = await ctx.newPage('guest');
      await guest.goto(ctx.url('/customer/faq'));
      await expect.text(guest, s.faqQ);
      await guest.getByText(s.faqQ).click();                                  // the answer sits in a closed <details>
      await expect.text(guest, 'Bring <b>exact</b> change.');
      expect.equal(await guest.locator('details', { hasText: s.faqQ }).locator('b').count(), 0, 'typed <b> rendered as a tag');
      return guest;
    });

    await step('Staff edit the question into "After you book" → it is the last one there', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/admin/faq-management'));
      const row = page.locator(`#faq-${s.faqId}`);
      await row.locator(`[data-edit="faq-${s.faqId}"]`).click();
      await row.locator('form.fq-edit select[name="section"]').selectOption('after');
      await Promise.all([page.waitForLoadState('load'), row.getByRole('button', { name: 'Save changes' }).click()]);
      await page.waitForLoadState('load');
      await expect.visible(page.locator('.fq-flash.ok'), 'no success message after saving');

      const f = await dbOne(`SELECT section_key, sort_order,
          (SELECT MAX(sort_order) FROM faqs WHERE section_key = 'after' AND id <> ?) AS others_max FROM faqs WHERE id = ?`, [s.faqId, s.faqId]);
      expect.equal(f.section_key, 'after', 'faqs.section_key');
      expect.equal(Number(f.sort_order), Number(f.others_max) + 1, 'faqs.sort_order (end of the new section)');
      expect.equal(await page.locator('#after .fq-row').last().getAttribute('id'), `faq-${s.faqId}`, 'the last row of "After you book"');
      expect.ok(await page.locator(`#faq-${s.faqId} button[aria-label="Move down"]`).isDisabled(), '"Move down" should be off on the last row');
      return page;
    });

    await step('Staff delete the question → gone for customers too', async () => {
      const page = s.staff;
      await page.locator(`[data-delete="${s.faqId}"]`).click();
      await expect.visible(page.locator('#fqModal'), 'the delete dialog did not open');
      expect.equal((await page.locator('#fqModalQ').textContent()).trim(), s.faqQ, 'question shown in the delete dialog');
      await Promise.all([page.waitForLoadState('load'), page.locator('#fqModalForm button[type="submit"]').click()]);
      await page.waitForLoadState('load');
      await expect.text(page, 'Question deleted.');
      expect.ok(!(await dbOne(`SELECT id FROM faqs WHERE id = ?`, [s.faqId])), 'the faqs row is still there');

      const guest = await ctx.newPage('guest');
      await guest.goto(ctx.url('/customer/faq'));
      expect.equal(await guest.getByText(s.faqQ).count(), 0, 'the deleted question on the customer FAQ page');
      return guest;
    });
  },
};
