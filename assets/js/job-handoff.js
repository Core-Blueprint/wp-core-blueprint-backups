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
  if (!jobBox || config.jobKind !== 'restore' || !config.restoreMode) return;

  const migration = config.restoreMode === 'migration';
  const progressEl = document.getElementById('cb-backups-progress-value');
  const statusEl = document.getElementById('cb-backups-status');
  const errorEl = document.getElementById('cb-backups-job-error');
  const authWrap = document.getElementById('wp-auth-check-wrap');
  const terminalStatuses = new Set(['completed', 'failed', 'cancelled']);
  const toast = window.cbCore?.toast;
  let ownState = null;
  let appliedNotified = false;
  let refreshQueued = false;

  const labels = config.labels || {};
  const finalizingCopy = () => migration
    ? {
        title: labels.migrationFinalizingTitle || 'Finalizing migration',
        body: labels.migrationFinalizingBody || 'The migrated database has been restored and is being verified. Core Blueprint will switch the website next. Your WordPress session may expire during that final switch — this is expected.',
      }
    : {
        title: labels.restoreFinalizingTitle || 'Finalizing restore',
        body: labels.restoreFinalizingBody || 'The restored database has been staged and is being verified. Core Blueprint will switch the website next. Your WordPress session may expire during that final switch — this is expected.',
      };

  const appliedCopy = () => migration
    ? {
        title: labels.migrationAppliedTitle || 'Migration applied successfully',
        body: labels.migrationAppliedBody || 'The migrated site is now active. Your previous WordPress session was replaced as expected. Sign in again to complete the final verification and view the migration result.',
        toast: labels.migrationAppliedToast || 'Migration applied successfully. Sign in again to complete final verification.',
      }
    : {
        title: labels.restoreAppliedTitle || 'Restore applied successfully',
        body: labels.restoreAppliedBody || 'The restored site is now active. Your previous WordPress session was replaced as expected. Sign in again to complete the final verification and view the restore result.',
        toast: labels.restoreAppliedToast || 'Restore applied successfully. Sign in again to complete final verification.',
      };

  const progress = () => {
    const value = Number.parseFloat((progressEl?.textContent || '').replace('%', '').trim());
    return Number.isFinite(value) ? value : 0;
  };

  const isFinalWindow = () => !terminalStatuses.has(jobBox.dataset.status || '') && progress() >= 95;
  const authVisible = () => Boolean(authWrap && !authWrap.classList.contains('hidden'));
  const setFinalizingStatus = () => {
    const label = labels.finalizing || 'Finalizing';
    if (statusEl && statusEl.textContent !== label) statusEl.textContent = label;
  };

  const showToast = (message) => {
    if (!message || !toast || appliedNotified) return;
    const method = typeof toast.success === 'function' ? toast.success : toast;
    method.call(toast, message, { persistent: true });
    appliedNotified = true;
  };

  const ensureOwnState = () => {
    if (ownState?.isConnected) return ownState;
    ownState = document.createElement('div');
    ownState.id = 'cb-backups-handoff-state';
    ownState.className = 'cb-backups-monitor-state';
    ownState.setAttribute('role', 'status');
    if (errorEl?.parentNode) errorEl.parentNode.insertBefore(ownState, errorEl);
    else jobBox.appendChild(ownState);
    return ownState;
  };

  const setState = (node, copy, kind, action = null) => {
    const signature = `${kind}|${copy.title}|${copy.body}`;
    if (node.dataset.handoffSignature === signature) {
      if (action && action.parentNode !== node) node.appendChild(action);
      return;
    }

    node.dataset.handoffSignature = signature;
    node.className = `cb-backups-monitor-state cb-backups-monitor-state--${kind}`;
    node.hidden = false;
    node.replaceChildren();

    const content = document.createElement('div');
    content.className = 'cb-backups-monitor-state__content';
    const title = document.createElement('strong');
    title.className = 'cb-backups-monitor-state__title';
    title.textContent = copy.title;
    const body = document.createElement('span');
    body.className = 'cb-backups-monitor-state__body';
    body.textContent = copy.body;
    content.append(title, body);
    node.appendChild(content);
    if (action) node.appendChild(action);
  };

  const clearOwnState = () => {
    if (ownState?.isConnected) ownState.remove();
    ownState = null;
  };

  const signInState = () => {
    const state = jobBox.querySelector('.cb-backups-monitor-state--auth:not([hidden]), .cb-backups-monitor-state--success:not([hidden])');
    if (!state || state.id === 'cb-backups-handoff-state') return null;
    return state.querySelector('a.button, button.button') ? state : null;
  };

  const decorateAuthOverlay = () => {
    if (!isFinalWindow()) return;
    const dialog = authWrap?.querySelector('#wp-auth-check');
    if (!dialog) return;

    const copy = appliedCopy();
    let notice = dialog.querySelector('#cb-backups-auth-context');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'cb-backups-auth-context';
      notice.className = 'cb-backups-auth-context';
      notice.setAttribute('role', 'status');
      const form = dialog.querySelector('#wp-auth-check-form');
      dialog.insertBefore(notice, form || dialog.firstChild);
    }

    const signature = `${copy.title}|${copy.body}`;
    if (notice.dataset.handoffSignature === signature) return;
    notice.dataset.handoffSignature = signature;
    notice.replaceChildren();

    const title = document.createElement('strong');
    title.className = 'cb-backups-auth-context__title';
    title.textContent = copy.title;
    const body = document.createElement('span');
    body.className = 'cb-backups-auth-context__body';
    body.textContent = copy.body;
    notice.append(title, body);
  };

  const renderApplied = () => {
    if (!isFinalWindow()) return;
    const copy = appliedCopy();
    const state = signInState();
    if (state) {
      const action = state.querySelector('a.button, button.button');
      setState(state, copy, 'success', action);
      clearOwnState();
    } else {
      setState(ensureOwnState(), copy, 'success');
    }
    setFinalizingStatus();
    showToast(copy.toast);
    decorateAuthOverlay();
  };

  const renderFinalizing = () => {
    if (!isFinalWindow()) {
      clearOwnState();
      return;
    }

    const state = signInState();
    if (state || authVisible()) {
      renderApplied();
      return;
    }

    const existingMonitorState = jobBox.querySelector('.cb-backups-monitor-state:not([hidden]):not(#cb-backups-handoff-state)');
    if (existingMonitorState) {
      if (existingMonitorState.classList.contains('cb-backups-monitor-state--network')) {
        setState(existingMonitorState, finalizingCopy(), 'handoff');
        clearOwnState();
        setFinalizingStatus();
      } else {
        clearOwnState();
      }
      return;
    }

    setState(ensureOwnState(), finalizingCopy(), 'handoff');
    setFinalizingStatus();
  };

  const refresh = () => {
    refreshQueued = false;
    if (terminalStatuses.has(jobBox.dataset.status || '')) {
      clearOwnState();
      return;
    }
    if (authVisible() || signInState()) renderApplied();
    else renderFinalizing();
  };

  const scheduleRefresh = () => {
    if (refreshQueued) return;
    refreshQueued = true;
    queueMicrotask(refresh);
  };

  new MutationObserver(scheduleRefresh).observe(jobBox, {
    attributes: true,
    attributeFilter: ['data-status'],
    childList: true,
    characterData: true,
    subtree: true,
  });

  if (authWrap) {
    new MutationObserver(scheduleRefresh).observe(authWrap, {
      attributes: true,
      attributeFilter: ['class'],
      childList: true,
      subtree: true,
    });
  }

  scheduleRefresh();
})();
