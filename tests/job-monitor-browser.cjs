'use strict';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const monitor = fs.readFileSync(path.join(__dirname, '../assets/js/job-monitor.js'), 'utf8');
const recovery = fs.readFileSync(path.join(__dirname, '../assets/js/job-terminal-recovery.js'), 'utf8');
const jobId = 'test-restore-123';
const storageKey = 'cb-backups:pending-job:/wp-admin/admin-ajax.php';
const origin = 'https://monitor.test';
const completed = { success: true, data: { job_id: jobId, kind: 'restore', restore_mode: 'migration', stage: 'completed', stage_label: 'Migration completed', status: 'completed', progress: 100 } };
const lateMigration = { success: true, data: { job_id: jobId, kind: 'restore', restore_mode: 'migration', stage: 'commit_live', stage_label: 'Applying restored website…', status: 'running', progress: 98 } };
const error = (code) => ({ success: false, data: { code, message: `Monitor error: ${code}` } });

async function fixture(browser, options = {}) {
  const page = await browser.newPage();
  const calls = [], errors = [];
  const responses = options.responses || [completed];
  let documents = 0;
  const config = {
    ajaxUrl: `${origin}/wp-admin/admin-ajax.php`, nonce: 'old-nonce',
    jobId: options.noId ? '' : jobId,
    reconnectUrl: `${origin}/wp-admin/admin.php?page=core-blueprint-backups&job=${jobId}&cb_monitor_reconnect=1`,
    labels: {
      networkInterrupted: 'Network interrupted; result unconfirmed.',
      migrationSwitching: 'Migration switch in progress; final checks are still running.',
      restoreSwitching: 'Restore switch in progress; final checks are still running.',
      migrationSignIn: 'Migrated site active. Sign in again to confirm the final migration result.',
      restoreSignIn: 'Restored site active. Sign in again to confirm the final restore result.',
      dismissResult: 'Dismiss',
    },
  };
  page.on('pageerror', e => errors.push(e.message));
  await page.route(`${origin}/**`, async route => {
    if (route.request().url().includes('admin-ajax.php')) {
      const body = new URLSearchParams(route.request().postData());
      calls.push(Object.fromEntries(body));
      const reply = responses[Math.min(calls.length - 1, responses.length - 1)];
      if (reply === 'html') return route.fulfill({ status: 503, contentType: 'text/html', body: '<html>Maintenance</html>' });
      return route.fulfill({ status: reply.success ? 200 : reply.data.code === 'auth_required' ? 401 : 403, json: reply });
    }
    documents++;
    // Subsequent requests stand in for a new server-rendered result/nonce.
    if (documents > 1) return route.fulfill({ contentType: 'text/html', body: '<p id="fresh-page">Fresh authenticated request</p>' });
    const initialStorage = options.pending ? `sessionStorage.setItem(${JSON.stringify(storageKey)}, ${JSON.stringify(jobId)});` : '';
    const denyStorage = options.denyStorage ? `Object.defineProperty(window, 'sessionStorage', { get() { throw new Error('Storage disabled'); } });` : '';
    const card = options.noCard ? '' : `<section id="cb-backups-job" data-status="${options.status || 'running'}"><span id="cb-backups-status">Running</span><span id="cb-backups-progress-value">95%</span><span id="cb-backups-progress-bar"></span><span id="cb-backups-stage"></span><p id="cb-backups-job-error"></p></section>`;
    await route.fulfill({ contentType: 'text/html', body: `<!doctype html><div class="cb-backups">${card}</div><div id="wp-auth-check-wrap" class="hidden"></div><script>${initialStorage}${denyStorage}</script><script id="wp-script-module-data-@cb-backups/job-monitor" type="application/json">${JSON.stringify(config)}</script><script>${monitor}</script><script>${recovery}</script>` });
  });
  await page.goto(`${origin}/wp-admin/admin.php?page=core-blueprint-backups${options.returned ? '&cb_monitor_reconnect=1' : ''}`);
  return { page, calls, errors };
}
async function closeAuthModal(page) {
  await page.evaluate(() => document.getElementById('wp-auth-check-wrap').classList.remove('hidden'));
  await page.evaluate(() => document.getElementById('wp-auth-check-wrap').classList.add('hidden'));
}
async function waitError(page, code) {
  await page.waitForFunction(c => document.body.textContent.includes(`Monitor error: ${c}`), code);
}
async function waitText(page, text) {
  await page.waitForFunction(value => document.body.textContent.includes(value), text);
}
async function resultUrl(page) {
  await page.waitForSelector('#fresh-page');
  const url = new URL(page.url());
  assert.equal(url.searchParams.get('job'), jobId);
  assert.equal(url.searchParams.has('cb_notice'), false, 'No success claim in a query flag.');
  return url;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  let passed = 0;
  const test = async (name, fn) => { await fn(); passed++; console.log(`PASS ${name}`); };
  try {
    await test('Completed migration navigates to persistent server result', async () => {
      const f = await fixture(browser); const url = await resultUrl(f.page);
      assert.equal(url.searchParams.get('tab'), 'restore'); assert.equal(f.calls.length, 1); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Interim login resumes stopped monitor and refreshes nonce', async () => {
      const f = await fixture(browser, { responses: [error('auth_required'), error('nonce_expired')] });
      await waitError(f.page, 'auth_required'); await closeAuthModal(f.page); await resultUrl(f.page);
      assert.equal(f.calls.length, 2); assert.equal(f.calls[1].job_id, jobId); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Closing login without authenticating does not imply success', async () => {
      const f = await fixture(browser, { responses: [error('auth_required')] });
      await waitError(f.page, 'auth_required'); await closeAuthModal(f.page); await f.page.waitForTimeout(500);
      assert.equal(f.calls.length, 2); assert.equal(await f.page.locator('#fresh-page').count(), 0); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Re-login with revoked permission preserves truthful error', async () => {
      const f = await fixture(browser, { responses: [error('auth_required'), error('capability_required')] });
      await waitError(f.page, 'auth_required'); await closeAuthModal(f.page); await waitError(f.page, 'capability_required');
      assert.equal(await f.page.locator('#fresh-page').count(), 0); assert.equal(f.calls.length, 2); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Expired nonce reloads once and avoids a reconnect loop', async () => {
      const f = await fixture(browser, { responses: [error('nonce_expired')] }); await resultUrl(f.page); await f.page.close();
      const g = await fixture(browser, { returned: true, responses: [error('nonce_expired')] });
      await waitError(g.page, 'nonce_expired'); await g.page.waitForTimeout(300);
      assert.equal(await g.page.locator('#fresh-page').count(), 0); assert.equal(g.calls.length, 1); assert.deepEqual(g.errors, []); await g.page.close();
    });
    await test('Maintenance response retries without claiming completion', async () => {
      const f = await fixture(browser, { responses: ['html', completed] }); await resultUrl(f.page);
      assert.equal(f.calls.length, 2); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Late migration transport loss is presented as site switch, not failure', async () => {
      const f = await fixture(browser, { responses: [lateMigration, 'html'] });
      await waitText(f.page, 'Migration switch in progress; final checks are still running.');
      assert.equal((await f.page.locator('body').textContent()).includes('Network interrupted; result unconfirmed.'), false);
      assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Late migration re-auth confirms terminal success after sign-in', async () => {
      const f = await fixture(browser, { responses: [lateMigration, error('auth_required'), completed] });
      await waitText(f.page, 'Migrated site active. Sign in again to confirm the final migration result.');
      await closeAuthModal(f.page); await resultUrl(f.page);
      assert.equal(f.calls.length, 3); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Terminal server card remains visible without polling or redirect', async () => {
      const f = await fixture(browser, { status: 'completed', pending: true }); await f.page.waitForTimeout(200);
      assert.equal(f.calls.length, 0); assert.equal(await f.page.evaluate(k => sessionStorage.getItem(k), storageKey), null); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Terminal job result can be dismissed without deleting history', async () => {
      const f = await fixture(browser, { status: 'failed', pending: true });
      await f.page.evaluate(id => { const url = new URL(window.location.href); url.searchParams.set('job', id); history.replaceState(history.state, '', url.toString()); }, jobId);
      await f.page.locator('#cb-backups-dismiss-result').click();
      assert.equal(await f.page.locator('#cb-backups-job').count(), 0);
      assert.equal(new URL(f.page.url()).searchParams.has('job'), false);
      assert.equal(f.calls.length, 0); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Returning without job query recovers the remembered job', async () => {
      const f = await fixture(browser, { noCard: true, noId: true, pending: true }); await resultUrl(f.page);
      assert.equal(f.calls[0].job_id, jobId); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Failed result also survives returning without job query', async () => {
      const f = await fixture(browser, { noCard: true, noId: true, pending: true, responses: [{ success: true, data: { status: 'failed', kind: 'restore', error: 'Failure fixture' } }] });
      await resultUrl(f.page); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Recovery permission errors are visible instead of swallowed', async () => {
      const f = await fixture(browser, { noCard: true, pending: true, responses: [error('capability_required')] });
      await waitError(f.page, 'capability_required'); assert.equal(await f.page.locator('#fresh-page').count(), 0); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('Browser storage denial does not break the current job', async () => {
      const f = await fixture(browser, { denyStorage: true }); await resultUrl(f.page); assert.deepEqual(f.errors, []); await f.page.close();
    });
    await test('No pending job performs no speculative status request', async () => {
      const f = await fixture(browser, { noCard: true, noId: true }); await f.page.waitForTimeout(200); assert.equal(f.calls.length, 0); assert.deepEqual(f.errors, []); await f.page.close();
    });
    console.log(`${passed} browser regressions passed.`);
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
