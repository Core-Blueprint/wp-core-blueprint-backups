(() => {
  'use strict';

  const dataEl = document.getElementById('wp-script-module-data-@cb-backups/job-monitor');
  let config = {};
  try {
    config = dataEl ? JSON.parse(dataEl.textContent) : {};
  } catch {
    config = {};
  }

  const jobBox = document.getElementById('cb-backups-job');
  if (!config.ajaxUrl || !config.jobId || !jobBox) return;

  const pendingKey = `cb-backups:pending-job:${new URL(config.ajaxUrl, window.location.href).pathname}`;
  const rememberJob = () => {
    try { window.sessionStorage.setItem(pendingKey, config.jobId); } catch { /* Storage can be disabled. */ }
    const url = new URL(window.location.href);
    url.searchParams.set('job', config.jobId);
    window.history.replaceState(window.history.state, '', url.toString());
  };
  const forgetJob = () => {
    try {
      if (window.sessionStorage.getItem(pendingKey) === config.jobId) window.sessionStorage.removeItem(pendingKey);
    } catch { /* Storage can be disabled. */ }
  };

  const toast = window.cbCore?.toast;
  const modal = window.cbCore?.modal;
  const showToast = (message, variant = 'info', options = {}) => {
    if (!message || !toast) return;
    const method = typeof toast[variant] === 'function' ? toast[variant] : toast;
    method.call(toast, message, options);
  };

  const elements = {
    bar: document.getElementById('cb-backups-progress-bar'),
    value: document.getElementById('cb-backups-progress-value'),
    stage: document.getElementById('cb-backups-stage'),
    status: document.getElementById('cb-backups-status'),
    error: document.getElementById('cb-backups-job-error'),
    rows: document.getElementById('cb-backups-rows'),
    tables: document.getElementById('cb-backups-tables'),
    files: document.getElementById('cb-backups-files'),
    fileBytes: document.getElementById('cb-backups-file-bytes'),
    throughput: document.getElementById('cb-backups-throughput'),
    elapsed: document.getElementById('cb-backups-elapsed'),
    size: document.getElementById('cb-backups-size'),
    typical: document.getElementById('cb-backups-typical'),
    currentWrap: document.getElementById('cb-backups-current-wrap'),
    currentTable: document.getElementById('cb-backups-current-table'),
    currentRows: document.getElementById('cb-backups-current-table-rows'),
    currentFileWrap: document.getElementById('cb-backups-current-file-wrap'),
    currentFile: document.getElementById('cb-backups-current-file'),
    warning: document.getElementById('cb-backups-long-warning'),
    cancel: document.getElementById('cb-backups-cancel'),
  };

  const terminalStatuses = new Set(['completed', 'failed', 'cancelled']);
  const terminalMonitorErrors = new Set(['auth_required', 'nonce_expired', 'capability_required', 'migration_recovery_auth_required', 'job_not_found']);
  const liveRestoreStages = new Set(['commit_live', 'reconcile_destination', 'verify_live', 'finalize_live', 'await_recovery']);
  let elapsedBase = 0;
  let elapsedUpdatedAt = performance.now();
  let typicalDuration = 0;
  let timer = null;
  let pollTimer = null;
  let pollingStopped = false;
  let completionHandled = false;
  let failureNotified = false;
  let monitorState = null;
  let waitingForSession = false;
  let reconnecting = false;
  let migrationRecoveryInFlight = false;
  let lastJob = null;
  const returnedFromReconnect = new URL(window.location.href).searchParams.get('cb_monitor_reconnect') === '1';

  const number = (value) => new Intl.NumberFormat().format(Math.max(0, Number(value) || 0));
  const formatDuration = (seconds) => {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;
    const mm = String(minutes).padStart(2, '0');
    const ss = String(secs).padStart(2, '0');
    return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`;
  };

  const formatBytes = (bytes) => {
    let value = Math.max(0, Number(bytes) || 0);
    if (value < 1024) return `${Math.round(value)} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let unit = -1;
    do {
      value /= 1024;
      unit += 1;
    } while (value >= 1024 && unit < units.length - 1);
    const digits = value >= 100 ? 0 : value >= 10 ? 1 : 2;
    return `${value.toFixed(digits)} ${units[unit]}`;
  };

  const metricLabel = (element, label) => {
    const metric = element?.closest('.cb-backups-metric');
    const dt = metric?.querySelector('dt');
    if (dt && label) dt.textContent = label;
  };

  const renderMetric = (element, metric, formatter) => {
    if (!element || !metric) return;
    metricLabel(element, metric.label || '');
    const done = Math.max(0, Number(metric.done) || 0);
    const total = Math.max(0, Number(metric.total) || 0);
    if (total > 0) {
      element.textContent = `${formatter(done)} / ${formatter(total)}`;
    } else if (done > 0) {
      element.textContent = formatter(done);
    } else {
      element.textContent = '—';
    }
  };

  const currentElapsed = () => elapsedBase + Math.floor((performance.now() - elapsedUpdatedAt) / 1000);
  const updateWarning = (elapsed) => {
    if (!elements.warning) return;
    const unusuallyLong = typicalDuration > 0 && elapsed > typicalDuration * 1.5 && elapsed > typicalDuration + 30;
    elements.warning.hidden = !unusuallyLong;
  };
  const renderElapsed = () => {
    const elapsed = currentElapsed();
    if (elements.elapsed) elements.elapsed.textContent = formatDuration(elapsed);
    updateWarning(elapsed);
  };
  const startTimer = () => {
    if (timer) return;
    timer = window.setInterval(renderElapsed, 1000);
  };
  const stopTimer = () => {
    if (!timer) return;
    window.clearInterval(timer);
    timer = null;
  };
  const schedulePoll = (delay) => {
    if (pollingStopped) return;
    if (pollTimer) window.clearTimeout(pollTimer);
    pollTimer = window.setTimeout(poll, delay);
  };

  const rowsLabel = (job) => {
    const exact = Number(job.database_rows_total) || 0;
    const done = Number(job.database_rows_done) || 0;
    const estimated = Number(job.database_rows_estimated) || 0;
    if (exact > 0) return number(exact);
    if (estimated > 0) return `${number(done)} / ~${number(estimated)}`;
    return done > 0 ? number(done) : '—';
  };
  const tableRowsLabel = (job) => {
    const done = Number(job.current_table_rows_done) || 0;
    const estimated = Number(job.current_table_rows_estimated) || 0;
    if (estimated > 0) return `(${number(done)} / ~${number(estimated)})`;
    return done > 0 ? `(${number(done)})` : '';
  };

  const ensureMonitorState = () => {
    if (monitorState) return monitorState;
    monitorState = document.createElement('div');
    monitorState.className = 'cb-backups-monitor-state';
    monitorState.hidden = true;
    monitorState.setAttribute('role', 'status');
    const errorNode = elements.error;
    if (errorNode?.parentNode) {
      errorNode.parentNode.insertBefore(monitorState, errorNode);
    } else {
      jobBox.appendChild(monitorState);
    }
    return monitorState;
  };

  const clearMonitorState = () => {
    const node = ensureMonitorState();
    node.hidden = true;
    node.className = 'cb-backups-monitor-state';
    node.replaceChildren();
  };

  const showMonitorState = (message, kind, actionLabel = '', actionHref = null) => {
    const node = ensureMonitorState();
    node.hidden = false;
    node.className = `cb-backups-monitor-state cb-backups-monitor-state--${kind}`;
    node.replaceChildren();

    const text = document.createElement('span');
    text.textContent = message;
    node.appendChild(text);

    const href = actionHref === null ? (config.reconnectUrl || '') : actionHref;
    if (actionLabel && href) {
      const action = document.createElement('a');
      action.className = 'button button-primary';
      action.href = href;
      action.textContent = actionLabel;
      node.appendChild(action);
    }
  };

  const isLiveRestoreTransition = () => lastJob?.kind === 'restore' && liveRestoreStages.has(lastJob.stage || '');
  const liveRestoreMessage = (signInRequired = false) => {
    const migration = lastJob?.restore_mode === 'migration';
    if (signInRequired) {
      return migration
        ? (config.labels?.migrationSignIn || 'The migrated site is now active. Sign in again to confirm the final migration result.')
        : (config.labels?.restoreSignIn || 'The restored site is now active. Sign in again to confirm the final restore result.');
    }
    return migration
      ? (config.labels?.migrationSwitching || 'The migrated site is taking over. Your WordPress session may change while final safety checks finish.')
      : (config.labels?.restoreSwitching || 'The restored site is taking over. Your WordPress session may change while final safety checks finish.');
  };

  const render = (job) => {
    lastJob = job;
    const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
    jobBox.dataset.status = job.status || '';
    if (elements.bar) elements.bar.style.width = `${progress}%`;
    if (elements.value) elements.value.textContent = `${progress}%`;
    if (elements.stage) elements.stage.textContent = job.stage_label || job.stage || '';
    if (elements.status) elements.status.textContent = job.status_label || job.status || '';
    if (elements.error) elements.error.textContent = job.error || '';
    if (elements.rows) elements.rows.textContent = rowsLabel(job);

    const tablesDone = Number(job.database_tables_done) || 0;
    const tablesTotal = Number(job.database_tables_total) || 0;
    if (elements.tables) elements.tables.textContent = tablesTotal > 0 ? `${number(tablesDone)} / ${number(tablesTotal)}` : '—';

    renderMetric(elements.files, job.file_metric, number);
    renderMetric(elements.fileBytes, job.byte_metric, formatBytes);

    const packageFilesPerSecond = Math.max(0, Number(job.package_files_per_second) || 0);
    const packageBytesPerSecond = Math.max(0, Number(job.package_bytes_per_second) || 0);
    if (elements.throughput) {
      elements.throughput.textContent = packageFilesPerSecond > 0 || packageBytesPerSecond > 0
        ? `${packageFilesPerSecond.toFixed(1)} files/s · ${formatBytes(packageBytesPerSecond)}/s`
        : '—';
    }

    elapsedBase = Number(job.elapsed_seconds) || 0;
    elapsedUpdatedAt = performance.now();
    typicalDuration = Number(job.typical_duration_seconds) || 0;
    renderElapsed();

    if (elements.size) elements.size.textContent = formatBytes(job.output_bytes);
    if (elements.typical) elements.typical.textContent = typicalDuration > 0 ? formatDuration(typicalDuration) : '—';

    const currentTable = job.current_table || '';
    if (elements.currentWrap) elements.currentWrap.hidden = !currentTable;
    if (elements.currentTable) elements.currentTable.textContent = currentTable;
    if (elements.currentRows) elements.currentRows.textContent = tableRowsLabel(job);

    const currentFile = job.current_file || '';
    if (elements.currentFileWrap) elements.currentFileWrap.hidden = !currentFile;
    if (elements.currentFile) elements.currentFile.textContent = currentFile;

    if (elements.cancel) {
      const activeBackup = job.kind === 'backup' && ['queued', 'running', 'cancelling'].includes(job.status);
      elements.cancel.hidden = !activeBackup;
      elements.cancel.disabled = job.status === 'cancelling';
      elements.cancel.textContent = job.status === 'cancelling' ? config.labels?.cancelling : config.labels?.cancel;
    }

    if (terminalStatuses.has(job.status)) {
      stopTimer();
      if (elements.elapsed) elements.elapsed.textContent = formatDuration(Number(job.duration_seconds ?? job.elapsed_seconds) || 0);
    } else {
      startTimer();
    }
  };

  class MonitorRequestError extends Error {
    constructor(message, code = 'request_failed', status = 0) {
      super(message);
      this.name = 'MonitorRequestError';
      this.code = code;
      this.status = status;
    }
  }

  const request = async (action) => {
    const body = new URLSearchParams({
      action,
      nonce: config.nonce,
      job_id: config.jobId,
    });

    let response;
    try {
      response = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body,
      });
    } catch (error) {
      throw new MonitorRequestError(config.labels?.networkInterrupted || 'Connection to the restore monitor was interrupted. Retrying… The restore continues on the server.', 'network_error', 0);
    }

    const raw = await response.text();
    let payload = null;
    try {
      payload = JSON.parse(raw);
    } catch {
      throw new MonitorRequestError(config.labels?.networkInterrupted || 'Connection to the restore monitor was interrupted. Retrying… The restore continues on the server.', 'invalid_response', response.status);
    }

    if (!response.ok || !payload?.success || !payload?.data) {
      const code = payload?.data?.code || (response.status === 401 ? 'auth_required' : 'request_failed');
      const message = payload?.data?.message || config.labels?.requestFailed || 'Backup status request failed.';
      throw new MonitorRequestError(message, code, response.status);
    }

    return payload.data;
  };

  const completionUrl = (job) => {
    const url = new URL(window.location.href);
    url.searchParams.set('job', config.jobId);
    url.searchParams.delete('cb_error');
    url.searchParams.delete('cb_notice');
    url.searchParams.delete('cb_monitor_reconnect');
    url.searchParams.set('tab', job.kind === 'restore' ? 'restore' : 'backups');
    return url.toString();
  };

  const dismissTerminalJob = () => {
    stopTimer();
    pollingStopped = true;
    if (pollTimer) window.clearTimeout(pollTimer);
  };

  const installTerminalDismiss = () => {
    if (!terminalStatuses.has(jobBox.dataset.status || '') || document.getElementById('cb-backups-dismiss-result')) return;
    const actions = document.createElement('div');
    actions.className = 'cb-backups-job-actions';
    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'cb-backups-dismiss-result';
    button.className = 'button';
    button.textContent = config.labels?.dismissResult || 'Dismiss';
    button.addEventListener('click', () => {
      forgetJob();
      dismissTerminalJob();
      const url = new URL(window.location.href);
      url.searchParams.delete('job');
      url.searchParams.delete('cb_monitor_reconnect');
      url.searchParams.delete('cb_error');
      url.searchParams.delete('cb_notice');
      window.history.replaceState(window.history.state, '', url.toString());
      jobBox.remove();
    });
    actions.appendChild(button);
    const heading = jobBox.querySelector('.cb-backups-job-heading');
    jobBox.insertBefore(actions, heading || jobBox.firstChild);
  };

  const reloadSession = () => {
    if (reconnecting || !config.reconnectUrl) return;
    reconnecting = true;
    window.location.replace(config.reconnectUrl);
  };

  // A modal closing is only a hint. Verify authentication/permissions before
  // navigating: dismissing the login form must never imply restore success.
  const reconnectSession = async () => {
    if (!waitingForSession || reconnecting) return;
    reconnecting = true;
    try {
      const job = await request('cb_backups_job_monitor');
      reconnecting = false;
      waitingForSession = false;
      if (terminalStatuses.has(job.status)) {
        clearMonitorState();
        render(job);
        if (job.status === 'completed') {
          const completedLabel = job.stage_label || (job.restore_mode === 'migration' ? 'Migration completed' : 'Restore completed');
          showMonitorState(completedLabel, 'auth');
          showToast(completedLabel, 'success');
          window.setTimeout(() => window.location.replace(completionUrl(job)), 700);
        } else {
          window.location.replace(completionUrl(job));
        }
        return;
      }
      reloadSession();
    } catch (error) {
      reconnecting = false;
      if (error.code === 'nonce_expired') reloadSession();
      else if (error.code !== 'auth_required') handleMonitorError(error);
    }
  };

  const showSecureMigrationSignIn = (message = '') => {
    pollingStopped = true;
    waitingForSession = true;
    stopTimer();

    if (!config.loginUrl) {
      showMonitorState(
        config.labels?.recoveryFinalizeFailed || 'Destination recovery could not be finalized. Reload monitoring before continuing.',
        'error',
        config.labels?.reloadMonitor || 'Reload monitoring',
        config.reconnectUrl || ''
      );
      return;
    }

    showMonitorState(
      message || liveRestoreMessage(true),
      'auth',
      config.labels?.secureSignIn || 'Continue to secure sign-in',
      config.loginUrl
    );
  };

  const verifyMigrationRecovery = async (job) => {
    if (migrationRecoveryInFlight) return;
    if (!config.recoveryPrepareAction || !config.recoveryFinalizeAction) {
      showMonitorState(
        config.labels?.recoveryFinalizeFailed || 'Destination recovery could not be finalized. Retry verification before completing the migration.',
        'error',
        config.labels?.reloadMonitor || 'Reload monitoring'
      );
      return;
    }

    migrationRecoveryInFlight = true;
    pollingStopped = true;
    stopTimer();
    showMonitorState(
      config.labels?.recoveryVerifying || 'Verifying destination access and rewrite routing…',
      'auth'
    );

    try {
      const recovery = await request(config.recoveryPrepareAction);
      if (recovery.requires_probe) {
        if (!recovery.probe_url) {
          throw new MonitorRequestError(
            config.labels?.recoveryFinalizeFailed || 'Destination recovery could not be finalized. Retry verification before completing the migration.',
            'recovery_probe_missing'
          );
        }
        const probe = await fetch(recovery.probe_url, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'manual',
          headers: { 'Cache-Control': 'no-cache' },
        });
        if (probe.status !== 204) {
          throw new MonitorRequestError(
            config.labels?.recoveryProbeFailed || 'Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.',
            'recovery_probe_failed',
            probe.status
          );
        }
      }

      const completed = await request(config.recoveryFinalizeAction);
      clearMonitorState();
      render(completed);
      if (completed.status !== 'completed') {
        throw new MonitorRequestError(
          config.labels?.recoveryFinalizeFailed || 'Destination recovery could not be finalized. Retry verification before completing the migration.',
          'recovery_finalize_incomplete'
        );
      }

      completionHandled = true;
      forgetJob();
      window.location.replace(completionUrl(completed));
    } catch (error) {
      migrationRecoveryInFlight = false;
      const recoveryAuthRequired = config.restoreMode === 'migration'
        && Boolean(config.loginUrl)
        && ['migration_recovery_auth_required', 'auth_required', 'capability_required'].includes(error?.code || '');
      if (recoveryAuthRequired) {
        showSecureMigrationSignIn(error?.message || liveRestoreMessage(true));
        return;
      }
      showMonitorState(
        error?.message || config.labels?.recoveryFinalizeFailed || 'Destination recovery could not be finalized. Retry verification before completing the migration.',
        'error',
        config.labels?.reloadMonitor || 'Reload monitoring',
        config.reconnectUrl || ''
      );
    }
  };

  const handleMonitorError = (error) => {
    const code = error?.code || 'request_failed';
    const migrationHandoffRequired = config.restoreMode === 'migration'
      && Boolean(config.loginUrl)
      && (isLiveRestoreTransition() || ['auth_required', 'nonce_expired', 'capability_required', 'migration_recovery_auth_required'].includes(code));

    if (migrationHandoffRequired && ['auth_required', 'nonce_expired', 'capability_required', 'migration_recovery_auth_required'].includes(code)) {
      showSecureMigrationSignIn(error?.message || liveRestoreMessage(true));
      return;
    }

    if (code === 'auth_required') {
      pollingStopped = true;
      waitingForSession = true;
      stopTimer();
      showMonitorState(
        isLiveRestoreTransition() ? liveRestoreMessage(true) : (error.message || config.labels?.authRequired),
        'auth',
        (lastJob?.restore_mode === 'migration' ? config.labels?.secureSignIn : config.labels?.signInAgain) || 'Sign in again',
        config.loginUrl || ''
      );
      return;
    }

    if (code === 'nonce_expired') {
      pollingStopped = true;
      waitingForSession = true;
      stopTimer();
      if (!returnedFromReconnect) {
        reloadSession();
        return;
      }
      showMonitorState(
        error.message || config.labels?.nonceExpired,
        'auth',
        config.labels?.reloadMonitor || 'Reload monitoring'
      );
      return;
    }

    if (code === 'capability_required' || code === 'job_not_found') {
      pollingStopped = true;
      waitingForSession = false;
      stopTimer();
      showMonitorState(error.message, 'error', config.labels?.reloadMonitor || 'Reload monitoring');
      return;
    }

    if (isLiveRestoreTransition()) {
      showMonitorState(liveRestoreMessage(false), 'auth');
      schedulePoll(1500);
      return;
    }

    showMonitorState(
      config.labels?.networkInterrupted || 'Connection to the restore monitor was interrupted. Retrying… The restore continues on the server.',
      'network'
    );
    schedulePoll(3000);
  };

  async function poll() {
    if (pollingStopped) return;
    try {
      const job = await request('cb_backups_job_monitor');
      clearMonitorState();
      render(job);

      if (job.kind === 'restore' && job.restore_mode === 'migration' && job.stage === 'await_recovery') {
        await verifyMigrationRecovery(job);
        return;
      }

      if (job.status === 'completed') {
        if (!completionHandled) {
          completionHandled = true;
          pollingStopped = true;
          window.setTimeout(() => window.location.replace(completionUrl(job)), 500);
        }
        return;
      }
      if (job.status === 'failed') {
        if (!failureNotified) {
          failureNotified = true;
          showToast(job.error || config.labels?.failed, 'error', { persistent: true });
        }
        dismissTerminalJob();
        window.location.replace(completionUrl(job));
        return;
      }
      if (job.status === 'cancelled') {
        showToast(config.labels?.cancelled, 'info');
        dismissTerminalJob();
        window.location.replace(completionUrl(job));
        return;
      }
      schedulePoll(1200);
    } catch (error) {
      handleMonitorError(error);
    }
  }

  if (elements.cancel) {
    elements.cancel.addEventListener('click', async () => {
      if (!modal?.show) {
        showToast(config.labels?.requestFailed || 'Confirmation dialog is unavailable.', 'error', { persistent: true });
        return;
      }

      const confirmed = await modal.show({
        title: config.labels?.cancelTitle,
        body: config.labels?.cancelConfirm,
        confirmLabel: config.labels?.cancel,
        cancelLabel: config.labels?.modalCancel,
        confirmVariant: 'remediation',
      });
      if (!confirmed) return;

      elements.cancel.disabled = true;
      elements.cancel.textContent = config.labels?.cancelling;
      try {
        const job = await request('cb_backups_cancel_job');
        clearMonitorState();
        render(job);
        showToast(config.labels?.cancelRequested, 'warning');
        if (!terminalStatuses.has(job.status)) schedulePoll(500);
      } catch (error) {
        elements.cancel.disabled = false;
        elements.cancel.textContent = config.labels?.cancel;
        if (terminalMonitorErrors.has(error?.code)) {
          handleMonitorError(error);
        } else {
          showToast(error?.message || config.labels?.requestFailed, 'error', { persistent: true });
        }
      }
    });
  }

  const reconnectFlag = new URL(window.location.href).searchParams.get('cb_monitor_reconnect');
  if (reconnectFlag === '1') {
    showToast(config.labels?.reconnected || 'Restore monitoring reconnected.', 'success');
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('cb_monitor_reconnect');
    window.history.replaceState(window.history.state, '', cleanUrl.toString());
  }

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) window.location.reload();
  });

  // WordPress interim login changes this wrapper instead of reloading wp-admin.
  // MutationObserver keeps this integration independent of WordPress' jQuery.
  const authWrap = document.getElementById('wp-auth-check-wrap');
  if (authWrap) {
    let wasVisible = !authWrap.classList.contains('hidden');
    new MutationObserver(() => {
      const visible = !authWrap.classList.contains('hidden');
      if (wasVisible && !visible) reconnectSession();
      wasVisible = visible;
    }).observe(authWrap, { attributes: true, attributeFilter: ['class'] });
  }
  window.addEventListener('focus', reconnectSession);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') reconnectSession();
  });

  if (!terminalStatuses.has(jobBox.dataset.status || '')) {
    rememberJob();
    startTimer();
    schedulePoll(100);
  } else {
    forgetJob();
    stopTimer();
    installTerminalDismiss();
  }
})();
