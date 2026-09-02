(() => {
  'use strict';

  // The rendered card owns monitoring, including an explicit terminal result.
  if (document.getElementById('cb-backups-job')) return;

  const dataEl = document.getElementById('wp-script-module-data-@cb-backups/job-monitor');
  let config = {};
  try { config = dataEl ? JSON.parse(dataEl.textContent) : {}; } catch { return; }
  if (!config.ajaxUrl) return;

  const pendingKey = `cb-backups:pending-job:${new URL(config.ajaxUrl, window.location.href).pathname}`;
  let jobId = config.jobId;
  try { jobId ||= window.sessionStorage.getItem(pendingKey); } catch { /* Storage can be disabled. */ }
  if (!jobId) return;

  const resultUrl = (job = null) => {
    const url = new URL(window.location.href);
    url.searchParams.set('job', jobId);
    url.searchParams.delete('cb_error');
    url.searchParams.delete('cb_notice');
    url.searchParams.set('cb_monitor_reconnect', '1');
    if (job) url.searchParams.set('tab', job.kind === 'restore' ? 'restore' : 'backups');
    return url.toString();
  };

  let inFlight = false;
  let waitingForSession = false;
  let state = null;
  const showError = (message) => {
    state ||= document.createElement('div');
    state.className = 'cb-backups-monitor-state cb-backups-monitor-state--error';
    state.setAttribute('role', 'status');
    state.replaceChildren();
    const text = document.createElement('span');
    text.textContent = message || config.labels?.requestFailed || 'Job status could not be confirmed.';
    const retry = document.createElement('a');
    retry.className = 'button button-primary';
    retry.href = resultUrl();
    retry.textContent = config.labels?.reloadMonitor || 'Reload monitoring';
    state.append(text, retry);
    if (!state.parentNode) (document.querySelector('.cb-backups') || document.body).prepend(state);
  };

  const recover = async () => {
    if (inFlight) return;
    inFlight = true;
    try {
      const response = await fetch(config.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({ action: 'cb_backups_job_monitor', nonce: config.nonce, job_id: jobId }),
      });
      const payload = await response.json();
      if (!response.ok || !payload?.success || !payload?.data) {
        const code = payload?.data?.code;
        waitingForSession = code === 'auth_required' || code === 'nonce_expired';
        if (code === 'nonce_expired' && new URL(window.location.href).searchParams.get('cb_monitor_reconnect') !== '1') {
          window.location.replace(resultUrl());
          return;
        }
        showError(payload?.data?.message);
        return;
      }
      const job = payload.data;
      if (!['queued', 'running', 'cancelling', 'completed', 'failed', 'cancelled'].includes(job.status)) {
        showError(config.labels?.requestFailed);
        return;
      }
      waitingForSession = false;
      // The rendered result card acknowledges/clears the pending job. Retain
      // the pointer until then, in case navigation itself is interrupted.
      // The destination renders the stored job outcome; URL flags never imply success.
      window.location.replace(resultUrl(job));
    } catch {
      showError(config.labels?.networkInterrupted);
    } finally { inFlight = false; }
  };

  const authWrap = document.getElementById('wp-auth-check-wrap');
  if (authWrap) {
    let wasVisible = !authWrap.classList.contains('hidden');
    new MutationObserver(() => {
      const visible = !authWrap.classList.contains('hidden');
      if (wasVisible && !visible && waitingForSession) recover();
      wasVisible = visible;
    }).observe(authWrap, { attributes: true, attributeFilter: ['class'] });
  }
  window.addEventListener('focus', () => { if (waitingForSession) recover(); });
  recover();
})();
