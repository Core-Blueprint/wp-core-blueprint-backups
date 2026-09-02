'use strict';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
// Exact Base modal source snapshot, commit eff8de2d072e78783506ffc2f16fa07744d13856.
// Only its unused icon factory is stubbed; modal DOM, focus and handlers are real.
const baseModal = fs.readFileSync(path.join(__dirname, 'fixtures/base-modal.js'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'assets/js/admin.js'), 'utf8');
const consent = fs.readFileSync(path.join(root, 'assets/js/restore-confirmation.js'), 'utf8');

async function fixture(browser, action = 'cb_backups_restore_import', missingControl = false) {
  const page = await browser.newPage();
  const posts = [], errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.route('https://consent.test/**', async route => {
    const url = new URL(route.request().url());
    const js = { '/base/modal.js': baseModal, '/base/icon.js': 'export const create = () => null;', '/admin.js': admin + '\nwindow.adminReady = true;', '/restore-confirmation.js': consent };
    if (js[url.pathname]) return route.fulfill({ contentType: 'text/javascript', body: js[url.pathname] });
    if (route.request().method() === 'POST') {
      posts.push(Object.fromEntries(new URLSearchParams(route.request().postData())));
      return route.fulfill({ contentType: 'text/html', body: '<h1>Request received</h1>' });
    }
    const ack = action.includes('restore') ? '<input type="hidden" name="restore_acknowledged" value="" data-cb-restore-acknowledgement="I understand that the current database on https://target.test will be replaced.">' : '';
    const setup = `window.toasts = []; window.cbCore.toast = { error: s => window.toasts.push(s) }; ${missingControl ? "const originalShow = window.cbCore.modal.show; window.cbCore.modal.show = opts => { const p = originalShow(opts); opts.body.closest('dialog').querySelector('menu').className = 'changed'; return p; };" : ''}`;
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html><form action="/submitted" method="post" data-cb-modal-confirm="1" data-cb-modal-title="Confirm restore" data-cb-modal-body="Selected backup" data-cb-modal-confirm-label="Execute" data-cb-modal-variant="danger"><input type="hidden" name="action" value="${action}"><input type="hidden" name="archive" value="example.cbbackup">${ack}<button id="open">Open</button></form><script type="module">import '/base/modal.js'; ${setup} await import('/admin.js');</script>` });
  });
  await page.goto('https://consent.test/');
  await page.waitForFunction(() => window.adminReady);
  return { page, posts, errors };
}
const confirmButton = page => page.locator('dialog[open] .cb-core-modal__actions .cb-core-button--danger');
const checkbox = page => page.locator('dialog[open] input[type=checkbox]');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const action of ['cb_backups_restore', 'cb_backups_restore_import']) {
      const { page, posts, errors } = await fixture(browser, action);
      await page.click('#open');
      assert.equal(await checkbox(page).isChecked(), false);
      assert.equal(await confirmButton(page).isDisabled(), true);
      await checkbox(page).press('Enter');
      assert.equal(posts.length, 0);
      // Even bypassing disabled in devtools cannot bypass the onConfirm check.
      await confirmButton(page).evaluate(el => { el.disabled = false; el.click(); });
      await page.waitForFunction(() => !document.querySelector('dialog').hasAttribute('aria-busy'));
      assert.equal(posts.length, 0);
      assert.equal(await page.locator('dialog[open]').count(), 1);
      await checkbox(page).check();
      assert.equal(await confirmButton(page).isEnabled(), true);
      await checkbox(page).uncheck();
      assert.equal(await confirmButton(page).isDisabled(), true);
      await checkbox(page).check();
      await Promise.all([page.waitForURL('**/submitted'), confirmButton(page).click()]);
      assert.equal(posts.length, 1);
      assert.equal(posts[0].restore_acknowledged, '1');
      assert.equal(posts[0].action, action);
      assert.deepEqual(errors, []);
      await page.close();
      console.log(`PASS ${action}: disabled, Enter/direct-click blocked, check/uncheck, one acknowledged POST`);
    }
    {
      const { page, posts, errors } = await fixture(browser);
      await page.evaluate(() => { const f = document.querySelector('form'); f.requestSubmit(); f.requestSubmit(); });
      assert.equal(await page.locator('dialog[open]').count(), 1);
      await checkbox(page).check();
      await page.locator('dialog[open]').getByRole('button', { name: 'Cancel', exact: true }).click();
      await page.click('#open');
      assert.equal(await checkbox(page).isChecked(), false);
      assert.equal(await confirmButton(page).isDisabled(), true);
      await checkbox(page).check();
      await page.keyboard.press('Escape');
      await page.click('#open');
      assert.equal(await checkbox(page).isChecked(), false);
      assert.equal(await confirmButton(page).isDisabled(), true);
      assert.equal(posts.length, 0);
      assert.deepEqual(errors, []);
      await page.close();
      console.log('PASS duplicate open, cancel and Escape never reuse agreement');
    }
    {
      const { page, posts } = await fixture(browser);
      await page.evaluate(() => { const f = document.querySelector('form'); f.dataset.cbModalConfirmed = '1'; f.elements.restore_acknowledged.value = '1'; window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
      await page.click('#open');
      assert.equal(await checkbox(page).isChecked(), false);
      assert.equal(await confirmButton(page).isDisabled(), true);
      assert.equal(posts.length, 0);
      await page.close();
      console.log('PASS browser-back restored page requires fresh agreement');
    }
    {
      const { page, posts, errors } = await fixture(browser, 'cb_backups_delete');
      await page.click('#open');
      assert.equal(await checkbox(page).count(), 0);
      assert.equal(await confirmButton(page).isEnabled(), true);
      await Promise.all([page.waitForURL('**/submitted'), confirmButton(page).click()]);
      assert.equal(posts.length, 1);
      assert.equal(posts[0].restore_acknowledged, undefined);
      assert.deepEqual(errors, []);
      await page.close();
      console.log('PASS unrelated confirmation keeps its existing behavior');
    }
    {
      const { page, posts, errors } = await fixture(browser, 'cb_backups_restore', true);
      await page.click('#open');
      await page.waitForFunction(() => window.toasts.length === 1);
      assert.equal(posts.length, 0);
      assert.equal(await page.locator('dialog[open]').count(), 0);
      assert.deepEqual(errors, []);
      await page.close();
      console.log('PASS unavailable Base confirmation control fails closed');
    }
    console.log('6 restore acknowledgement browser scenarios: PASS');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
