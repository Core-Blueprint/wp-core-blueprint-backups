(() => {
  'use strict';

  // An explicitly requested job can become terminal while WordPress is showing
  // the login screen. Page::current_job() intentionally omits terminal jobs,
  // so reconcile that one final server state after authentication returns.
  if (document.getElementById('cb-backups-job')) return;

  const dataEl = document.getElementById('wp-script-module-data-@cb-backups/job-monitor');
  let config = {};
  try {
    config = dataEl ? JSON.parse(dataEl.textContent) : {};
  } catch {
    config = {};
  }
  if (!config.ajaxUrl || !config.jobId) return;

  const body = new URLSearchParams({
    action: 'cb_backups_job_monitor',
    nonce: config.nonce,
    job_id: config.jobId,
  });

  const completionUrl = (job) => {
    const url = new URL(window.location.href);
    url.searchParams.delete('job');
    url.searchParams.delete('cb_error');
    url.searchParams.delete('cb_monitor_reconnect');
    if (job.kind === 'restore' && job.restore_mode === 'migration') {
      url.searchParams.set('cb_notice', 'migration_completed');
    } else {
      url.searchParams.set('cb_notice', job.kind === 'restore' ? 'restore_completed' : 'backup_completed');
    }
    return url.toString();
  };

  const cleanTerminalUrl = () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('job');
    url.searchParams.delete('cb_monitor_reconnect');
    window.history.replaceState(window.history.state, '', url.toString());
  };

  fetch(config.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body,
  })
    .then((response) => response.json())
    .then((payload) => {
      if (!payload?.success || !payload?.data) return;
      const job = payload.data;
      if (job.status === 'completed') {
        window.location.replace(completionUrl(job));
        return;
      }
      if (job.status === 'failed' || job.status === 'cancelled') {
        cleanTerminalUrl();
      }
    })
    .catch(() => {
      // This probe is only a stale-page reconciliation helper. The normal
      // monitor owns retry/error UX whenever an active progress card exists.
    });
})();
