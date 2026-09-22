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
  const terminalStatuses = new Set(['completed', 'failed', 'cancelled']);
  let ownState = null;
  let refreshQueued = false;

  const labels = config.labels || {};
  const finalizingCopy = () => migration
    ? {
        title: labels.migrationFinalizingTitle || 'Finalizing migration',
        body: labels.migrationFinalizingBody || 'The migrated database has been restored and is being verified. Core Blueprint will switch the website next. Your WordPress session may expire during that final switch.',
      }
    : {
        title: labels.restoreFinalizingTitle || 'Finalizing restore',
        body: labels.restoreFinalizingBody || 'The restored database has been staged and is being verified. Core Blueprint will switch the website next. Your WordPress session may expire during that final switch.',
      };

  const progress = () => {
    const value = Number.parseFloat((progressEl?.textContent || '').replace('%', '').trim());
    return Number.isFinite(value) ? value : 0;
  };

  const isFinalWindow = () => !terminalStatuses.has(jobBox.dataset.status || '') && progress() >= 95;

  const ensureOwnState = () => {
    if (ownState?.isConnected) return ownState;
    ownState = document.createElement('div');
    ownState.id = 'cb-backups-handoff-state';
    ownState.className = 'cb-backups-monitor-state cb-backups-monitor-state--handoff';
    ownState.setAttribute('role', 'status');
    if (errorEl?.parentNode) errorEl.parentNode.insertBefore(ownState, errorEl);
    else jobBox.appendChild(ownState);
    return ownState;
  };

  const clearOwnState = () => {
    if (ownState?.isConnected) ownState.remove();
    ownState = null;
  };

  const renderFinalizing = () => {
    if (!isFinalWindow()) {
      clearOwnState();
      return;
    }

    const existingMonitorState = jobBox.querySelector('.cb-backups-monitor-state:not([hidden]):not(#cb-backups-handoff-state)');
    if (existingMonitorState) {
      clearOwnState();
      return;
    }

    const copy = finalizingCopy();
    const node = ensureOwnState();
    const signature = `${copy.title}|${copy.body}`;
    if (node.dataset.handoffSignature !== signature) {
      node.dataset.handoffSignature = signature;
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
    }

    if (statusEl && statusEl.textContent !== (labels.finalizing || 'Finalizing')) {
      statusEl.textContent = labels.finalizing || 'Finalizing';
    }
  };

  const scheduleRefresh = () => {
    if (refreshQueued) return;
    refreshQueued = true;
    queueMicrotask(() => {
      refreshQueued = false;
      renderFinalizing();
    });
  };

  new MutationObserver(scheduleRefresh).observe(jobBox, {
    attributes: true,
    attributeFilter: ['data-status'],
    childList: true,
    characterData: true,
    subtree: true,
  });

  scheduleRefresh();
})();
