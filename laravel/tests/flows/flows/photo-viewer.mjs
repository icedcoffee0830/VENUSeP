// The photo viewer on the room and hostel pages: any strip photo opens it at that photo,
// whole and uncropped, with ✕ / Esc / a tap outside, arrows, keys, swipe and "2 / 7".
// A drag in the strip does not open it, and the 360° tile still opens the tour.

const PAGES = [
  ['room', '/customer/room-reservation?room=r1'],
  ['hostel', '/customer/hostel-reservation?room=h1'],
];

/* A finger swipe on the viewer, as the browser would report it. */
const swipe = (page, from, to) => page.evaluate(([a, b]) => {
  const pv = document.getElementById('photoViewer');
  const touch = (x) => new Touch({ identifier: 1, target: pv, clientX: x, clientY: 300 });
  pv.dispatchEvent(new TouchEvent('touchstart', { touches: [touch(a)], changedTouches: [touch(a)], bubbles: true }));
  pv.dispatchEvent(new TouchEvent('touchend', { touches: [], changedTouches: [touch(b)], bubbles: true }));
}, [from, to]);

export default {
  name: 'photo-viewer',
  title: 'Photo viewer: opens at the tapped photo, whole, on the room and hostel pages',

  async run(ctx) {
    const { step, expect } = ctx;
    const s = ctx.state;
    const viewer = (page) => page.locator('#photoViewer');
    const count = (page) => page.locator('#photoViewer .pv-count');
    const shown = (page) => page.locator('#photoViewer img');

    await step('Signs in (the booking pages need an account)', async () => {
      s.page = await ctx.loginCustomer('nikkaarena@gmail.com');
      return s.page;
    });

    for (const [label, path] of PAGES) {
      await step(`${label}: the second photo opens the viewer at that photo, uncropped`, async () => {
        const page = s.page;
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto(ctx.url(path));
        s.urls = await page.evaluate(() => getRoom().photoUrls.filter(Boolean));
        expect.ok(s.urls.length > 1, 'this room needs two photos for the test');
        await page.locator('.bk-gal-photo').nth(1).click();
        await expect.visible(viewer(page), 'no viewer after tapping a photo');
        expect.equal(await count(page).textContent(), `2 / ${s.urls.length}`, 'the counter');
        expect.ok((await shown(page).getAttribute('src')) === s.urls[1], 'the viewer opened on another photo');
        await page.waitForFunction(() => document.querySelector('#photoViewer img').naturalWidth > 0);
        const fit = await shown(page).evaluate((i) => {
          const b = i.getBoundingClientRect();
          return { ratio: b.width / b.height, natural: i.naturalWidth / i.naturalHeight, inside: b.left >= 0 && b.top >= 0 && b.right <= innerWidth && b.bottom <= innerHeight };
        });
        expect.ok(Math.abs(fit.ratio - fit.natural) < 0.02, 'the photo is cropped or stretched: ' + JSON.stringify(fit));
        expect.ok(fit.inside, 'the photo runs off the screen');
        return page;
      });

      await step(`${label}: arrows and keys change the photo; ✕, Esc and a tap outside close it`, async () => {
        const page = s.page;
        await page.getByRole('button', { name: 'Next photo' }).click();
        expect.equal((await count(page).textContent()).split(' ')[0], 3 > s.urls.length ? '1' : '3', 'after the next arrow');
        await page.getByRole('button', { name: 'Previous photo' }).click();
        expect.equal((await count(page).textContent()).split(' ')[0], '2', 'after the previous arrow');
        await page.keyboard.press('ArrowLeft');
        expect.equal((await count(page).textContent()).split(' ')[0], '1', 'after the left key');
        await page.keyboard.press('ArrowLeft');
        expect.equal((await count(page).textContent()).split(' ')[0], String(s.urls.length), 'the left key wraps to the last photo');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Escape');
        await viewer(page).waitFor({ state: 'detached', timeout: 2000 });   // Esc
        await page.locator('.bk-gal-photo').first().click();
        await page.getByRole('button', { name: 'Close photos' }).click();
        await viewer(page).waitFor({ state: 'detached', timeout: 2000 });   // ✕
        await page.locator('.bk-gal-photo').first().click();
        await page.mouse.click(640, 20);
        await viewer(page).waitFor({ state: 'detached', timeout: 2000 });   // a tap outside the photo
      });

      await step(`${label}: a drag in the strip does not open it, and the 360° tile opens the tour`, async () => {
        const page = s.page;
        await page.locator('.bk-gal-track').evaluate((t) => { t.scrollLeft = 0; });
        const box = await page.locator('.bk-gal-photo').first().boundingBox();
        await page.mouse.move(box.x + 150, box.y + 80);
        await page.mouse.down();
        await page.mouse.move(box.x + 120, box.y + 80, { steps: 6 });
        await page.mouse.up();
        expect.equal(await viewer(page).count(), 0, 'a drag opened the viewer');
        await page.locator('.bk-gal-track').evaluate((t) => { t.style.scrollBehavior = ''; t.scrollLeft = 0; });
        await page.waitForTimeout(500);
        await page.locator('.bk-gal-360s').click();
        expect.equal(await viewer(page).count(), 0, 'the 360° tile opened the photo viewer');
        await expect.visible(page.getByRole('dialog', { name: /360/ }).or(page.locator('#panoOverlay, .pnlm-container').first()), 'the 360° tile opened nothing');
      });

      await step(`${label}: on a phone a swipe changes the photo and the page keeps its width`, async () => {
        const page = s.page;
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(ctx.url(path));
        await page.locator('.bk-gal-photo').first().click();
        await swipe(page, 300, 100);
        expect.equal((await count(page).textContent()).split(' ')[0], '2', 'a swipe left');
        await swipe(page, 100, 300);
        expect.equal((await count(page).textContent()).split(' ')[0], '1', 'a swipe right');
        await swipe(page, 200, 190);
        expect.equal((await count(page).textContent()).split(' ')[0], '1', 'a tiny move changed the photo');
        expect.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'sideways scroll on a phone');
        return page;
      });
    }

    await step('The small picture on the review step opens the same viewer', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/room-reservation?room=r1'));
      await page.evaluate(() => { state.screen = 'review'; render(); });
      await page.getByRole('button', { name: /^Open .* photos$/ }).click();
      await expect.visible(viewer(page), 'the summary picture opened nothing');
      expect.match(await count(page).textContent(), /^1 \/ \d+$/, 'it opens at the first photo');
    });

    await step('Sign-up: the Terms link opens a new tab, leaves the box and the form alone', async () => {
      const page = await ctx.newPage();
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto(ctx.url('/customer/customer-register'));
      const name = page.locator('input[name="full_name"], #fullName').first();
      await name.fill('Half Filled');
      const [tab] = await Promise.all([page.waitForEvent('popup'), page.getByRole('link', { name: 'Terms and Conditions' }).click()]);
      await tab.waitForLoadState();
      expect.match(await tab.title(), /Terms and Conditions/, 'the new tab');
      await tab.setViewportSize({ width: 390, height: 844 });
      expect.ok(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'the terms page scrolls sideways on a phone');
      await tab.close();
      expect.ok(!(await page.locator('#terms').isChecked()), 'the tap ticked the box');
      expect.equal(await name.inputValue(), 'Half Filled', 'the form was lost');
      await page.locator('.form-check-label', { hasText: 'I agree to the' }).click({ position: { x: 4, y: 6 } });
      expect.ok(await page.locator('#terms').isChecked(), 'the label no longer ticks the box');
    });
  },
};
