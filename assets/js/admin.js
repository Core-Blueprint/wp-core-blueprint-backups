(() => {
  'use strict';

  const dataEl = document.getElementById('wp-script-module-data-@cb-backups/admin');
  let config = {};
  try {
    config = dataEl ? JSON.parse(dataEl.textContent) : {};
  } catch {
    config = {};
  }

  const modal = window.cbCore?.modal;
  const toast = window.cbCore?.toast;

  const showToast = (message, variant = 'info', options = {}) => {
    if (!message || !toast) return;
    const method = typeof toast[variant] === 'function' ? toast[variant] : toast;
    method.call(toast, message, options);
  };

  // Server redirects communicate one-shot operation feedback through query
  // arguments. Render those through the shared Toast Foundation, then clean
  // the URL immediately so a refresh can never replay the message.
  if (config.flash?.message) {
    showToast(config.flash.message, config.flash.variant || 'info');
  }

  const cleanFlashUrl = new URL(window.location.href);
  let flashUrlChanged = false;
  ['cb_notice', 'cb_error', 'deleted'].forEach((key) => {
    if (cleanFlashUrl.searchParams.has(key)) {
      cleanFlashUrl.searchParams.delete(key);
      flashUrlChanged = true;
    }
  });
  if (flashUrlChanged) {
    window.history.replaceState(window.history.state, '', cleanFlashUrl.toString());
  }

  // Shared Core Blueprint confirmation modal for destructive/consequential
  // form submissions. No native browser dialogs are used.
  document.querySelectorAll('form[data-cb-modal-confirm]:not([data-cb-chunk-upload])').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (form.dataset.cbModalConfirmed === '1') {
        return;
      }

      event.preventDefault();
      if (!modal?.show) {
        showToast(config.labels?.requestFailed || 'Confirmation dialog is unavailable.', 'error', { persistent: true });
        return;
      }

      const confirmed = await modal.show({
        title: form.dataset.cbModalTitle || 'Confirm action',
        body: form.dataset.cbModalBody || '',
        confirmLabel: form.dataset.cbModalConfirmLabel || 'Confirm',
        cancelLabel: form.dataset.cbModalCancelLabel || config.labels?.modalCancel || 'Cancel',
        confirmVariant: form.dataset.cbModalVariant || 'primary',
      });

      if (!confirmed) return;

      form.dataset.cbModalConfirmed = '1';
      if (event.submitter instanceof HTMLElement) {
        event.submitter.setAttribute('aria-disabled', 'true');
      }
      form.requestSubmit(event.submitter || undefined);
    });
  });


  const bulkDeleteForm = document.querySelector('form[data-cb-backups-bulk-delete]');
  if (bulkDeleteForm) {
    const rowCheckboxes = Array.from(document.querySelectorAll('[data-cb-backups-select]'));
    const selectAll = document.querySelector('[data-cb-backups-select-all]');
    const submit = bulkDeleteForm.querySelector('[data-cb-backups-bulk-submit]');
    const countLabel = bulkDeleteForm.querySelector('[data-cb-backups-selection-count]');

    const selectedCount = () => rowCheckboxes.filter((checkbox) => checkbox.checked).length;

    const updateBulkSelection = () => {
      const count = selectedCount();
      if (submit) submit.disabled = count < 1;
      if (countLabel) {
        countLabel.textContent = count === 1
          ? (config.labels?.bulkSelectedOne || '1 backup selected')
          : count > 1
            ? (config.labels?.bulkSelectedMany || '%d backups selected').replace('%d', String(count))
            : '';
      }
      if (selectAll) {
        selectAll.checked = rowCheckboxes.length > 0 && count === rowCheckboxes.length;
        selectAll.indeterminate = count > 0 && count < rowCheckboxes.length;
      }

      bulkDeleteForm.dataset.cbModalTitle = config.labels?.bulkDeleteTitle || 'Delete selected backups?';
      bulkDeleteForm.dataset.cbModalConfirmLabel = config.labels?.bulkDeleteConfirm || 'Delete selected';
      if (count === 1) {
        bulkDeleteForm.dataset.cbModalBody = config.labels?.bulkDeleteOne || 'Permanently delete 1 selected backup? This restore point cannot be recovered.';
      } else {
        const template = config.labels?.bulkDeleteMany || 'Permanently delete %d selected backups? These restore points cannot be recovered.';
        bulkDeleteForm.dataset.cbModalBody = template.replace('%d', String(count));
      }
    };

    rowCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateBulkSelection));
    selectAll?.addEventListener('change', () => {
      rowCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      updateBulkSelection();
    });

    updateBulkSelection();
  }


  const importForm = document.getElementById('cb-backups-import-form');
  if (importForm && config.ajaxUrl) {
    const fileInput = document.getElementById('cb-backups-import-file');
    const startButton = document.getElementById('cb-backups-import-start');
    const progressBox = document.getElementById('cb-backups-import-progress');
    const progressBar = document.getElementById('cb-backups-import-bar');
    const progressPercent = document.getElementById('cb-backups-import-percent');
    const progressBytes = document.getElementById('cb-backups-import-bytes');
    const progressStatus = document.getElementById('cb-backups-import-status');
    const progressFilename = document.getElementById('cb-backups-import-filename');
    const cancelImport = document.getElementById('cb-backups-import-cancel');

    let uploadId = '';
    let uploadRunning = false;
    let cancelRequested = false;

    const formatImportBytes = (bytes) => {
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

    const ajaxForm = async (formData) => {
      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      });
      let payload;
      try {
        payload = await response.json();
      } catch {
        throw new Error(config.labels?.importRequestFailed || 'Import request returned an invalid response.');
      }
      if (!response.ok || !payload?.success || !payload?.data) {
        throw new Error(payload?.data?.message || config.labels?.importRequestFailed || 'Import request failed.');
      }
      return payload.data;
    };

    const actionData = (action) => {
      const data = new FormData();
      data.append('action', action);
      data.append('nonce', config.nonce);
      return data;
    };

    const renderImport = (uploaded, total, status, filename = '') => {
      const safeTotal = Math.max(1, Number(total) || 1);
      const safeUploaded = Math.max(0, Math.min(safeTotal, Number(uploaded) || 0));
      const percent = Math.floor((safeUploaded / safeTotal) * 100);
      if (progressBox) progressBox.hidden = false;
      if (progressBar) progressBar.style.width = `${percent}%`;
      if (progressPercent) progressPercent.textContent = `${percent}%`;
      if (progressBytes) progressBytes.textContent = `${formatImportBytes(safeUploaded)} / ${formatImportBytes(safeTotal)}`;
      if (progressStatus) progressStatus.textContent = status;
      if (progressFilename) progressFilename.textContent = filename;
    };

    const resetImport = () => {
      uploadId = '';
      uploadRunning = false;
      cancelRequested = false;
      if (startButton) {
        startButton.disabled = false;
        startButton.textContent = config.labels?.importStart || 'Upload backup';
      }
      if (fileInput) fileInput.disabled = false;
      if (cancelImport) {
        cancelImport.disabled = false;
        cancelImport.textContent = config.labels?.importCancel || 'Cancel import';
      }
      if (progressBox) progressBox.hidden = true;
    };

    const abortImport = async () => {
      if (!uploadId) {
        resetImport();
        return;
      }
      const data = actionData('cb_backups_import_abort');
      data.append('upload_id', uploadId);
      await ajaxForm(data);
      resetImport();
      showToast(config.labels?.importCancelled || 'Import cancelled.', 'info');
    };

    importForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (uploadRunning) return;

      const file = fileInput?.files?.[0];
      if (!(file instanceof File)) {
        showToast(config.labels?.importSelectFile || 'Select a .cbbackup file first.', 'warning');
        return;
      }
      if (!file.name.toLowerCase().endsWith('.cbbackup')) {
        showToast(config.labels?.importOnlyBackup || 'Only .cbbackup files can be imported.', 'error', { persistent: true });
        return;
      }

      uploadRunning = true;
      cancelRequested = false;
      if (startButton) {
        startButton.disabled = true;
        startButton.textContent = config.labels?.importUploading || 'Uploading…';
      }
      if (fileInput) fileInput.disabled = true;
      renderImport(0, file.size, config.labels?.importPreparing || 'Preparing upload…', file.name);

      try {
        const init = actionData('cb_backups_import_init');
        init.append('name', file.name);
        init.append('size', String(file.size));
        init.append('last_modified', String(file.lastModified || 0));
        if (uploadId) init.append('upload_id', uploadId);
        let state = await ajaxForm(init);
        uploadId = state.upload_id || '';
        if (state.status === 'prepared') {
          uploadRunning = false;
          showToast(config.labels?.importPrepared || 'Backup imported and prepared for restore.', 'success');
          const url = new URL(window.location.href);
          url.searchParams.set('tab', 'restore');
          url.searchParams.set('cb_notice', 'import_prepared');
          window.setTimeout(() => window.location.replace(url.toString()), 250);
          return;
        }
        let offset = Math.max(0, Number(state.uploaded_bytes) || 0);
        const chunkSize = Math.max(65536, Number(state.chunk_size) || 1048576);

        if (offset > 0) {
          showToast(config.labels?.importResuming || 'Resuming previous import upload.', 'info');
        }
        renderImport(offset, file.size, config.labels?.importUploading || 'Uploading…', file.name);

        while (offset < file.size) {
          if (cancelRequested) {
            await abortImport();
            return;
          }
          const end = Math.min(file.size, offset + chunkSize);
          const chunk = file.slice(offset, end);
          const data = actionData('cb_backups_import_chunk');
          data.append('upload_id', uploadId);
          data.append('offset', String(offset));
          data.append('chunk', chunk, 'chunk.bin');
          state = await ajaxForm(data);
          const nextOffset = Math.max(0, Number(state.uploaded_bytes) || 0);
          if (nextOffset <= offset && offset < file.size) {
            throw new Error(config.labels?.importNoProgress || 'Import upload did not advance.');
          }
          offset = nextOffset;
          renderImport(offset, file.size, config.labels?.importUploading || 'Uploading…', file.name);
        }

        if (cancelRequested) {
          await abortImport();
          return;
        }

        renderImport(file.size, file.size, config.labels?.importValidating || 'Validating backup…', file.name);
        if (cancelImport) cancelImport.disabled = true;
        const finish = actionData('cb_backups_import_finish');
        finish.append('upload_id', uploadId);
        await ajaxForm(finish);
        uploadRunning = false;
        showToast(config.labels?.importPrepared || 'Backup imported and prepared for restore.', 'success');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'restore');
        url.searchParams.set('cb_notice', 'import_prepared');
        window.setTimeout(() => window.location.replace(url.toString()), 350);
      } catch (error) {
        uploadRunning = false;
        if (startButton) {
          startButton.disabled = false;
          startButton.textContent = config.labels?.importResume || 'Resume upload';
        }
        if (fileInput) fileInput.disabled = false;
        if (cancelImport) cancelImport.disabled = false;
        if (!cancelRequested) {
          showToast(error?.message || config.labels?.importRequestFailed || 'Import upload failed.', 'error', { persistent: true });
          if (progressStatus) progressStatus.textContent = config.labels?.importInterrupted || 'Upload interrupted — select the same file to resume.';
        }
      }
    });

    cancelImport?.addEventListener('click', async () => {
      if (!uploadId && !uploadRunning) {
        resetImport();
        return;
      }
      if (!modal?.show) {
        showToast(config.labels?.requestFailed || 'Confirmation dialog is unavailable.', 'error', { persistent: true });
        return;
      }
      const confirmed = await modal.show({
        title: config.labels?.importCancelTitle || 'Cancel import?',
        body: config.labels?.importCancelBody || 'The uploaded chunks for this incomplete import will be removed.',
        confirmLabel: config.labels?.importCancel || 'Cancel import',
        cancelLabel: config.labels?.modalCancel || 'Keep uploading',
        confirmVariant: 'remediation',
      });
      if (!confirmed) return;
      cancelRequested = true;
      if (cancelImport) {
        cancelImport.disabled = true;
        cancelImport.textContent = config.labels?.cancelling || 'Cancelling…';
      }
      if (!uploadRunning) {
        try {
          await abortImport();
        } catch (error) {
          showToast(error?.message || config.labels?.importRequestFailed || 'Import cancellation failed.', 'error', { persistent: true });
        }
      }
    });
  }

  const jobBox = document.getElementById('cb-backups-job');
  if (!config.ajaxUrl || !jobBox || !config.jobId) {
    return;
  }

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
  let elapsedBase = 0;
  let elapsedUpdatedAt = performance.now();
  let typicalDuration = 0;
  let timer = null;
  let completionHandled = false;
  let failureNotified = false;

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

  const render = (job) => {
    const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
    jobBox.dataset.status = job.status || '';
    if (elements.bar) elements.bar.style.width = `${progress}%`;
    if (elements.value) elements.value.textContent = `${progress}%`;
    if (elements.stage) elements.stage.textContent = job.stage || '';
    if (elements.status) elements.status.textContent = job.status === 'cancelling' ? config.labels.cancelling : (job.status || '');
    if (elements.error) elements.error.textContent = job.error || '';
    if (elements.rows) elements.rows.textContent = rowsLabel(job);

    const tablesDone = Number(job.database_tables_done) || 0;
    const tablesTotal = Number(job.database_tables_total) || 0;
    if (elements.tables) elements.tables.textContent = tablesTotal > 0 ? `${number(tablesDone)} / ${number(tablesTotal)}` : '—';

    const filesDone = Number(job.files_done) || 0;
    const filesTotal = Number(job.files_total) || 0;
    if (elements.files) elements.files.textContent = filesTotal > 0 ? `${number(filesDone)} / ${number(filesTotal)}` : (filesDone > 0 ? number(filesDone) : '—');

    const fileBytesDone = Number(job.files_bytes_done) || 0;
    const fileBytesTotal = Number(job.files_bytes_total) || 0;
    if (elements.fileBytes) elements.fileBytes.textContent = fileBytesTotal > 0 ? `${formatBytes(fileBytesDone)} / ${formatBytes(fileBytesTotal)}` : (fileBytesDone > 0 ? formatBytes(fileBytesDone) : '—');

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
      elements.cancel.textContent = job.status === 'cancelling' ? config.labels.cancelling : config.labels.cancel;
    }

    if (terminalStatuses.has(job.status)) {
      stopTimer();
      if (elements.elapsed) elements.elapsed.textContent = formatDuration(Number(job.duration_seconds ?? job.elapsed_seconds) || 0);
    } else {
      startTimer();
    }
  };

  const request = async (action) => {
    const body = new URLSearchParams({
      action,
      nonce: config.nonce,
      job_id: config.jobId,
    });
    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body,
    });
    const payload = await response.json();
    if (!payload.success || !payload.data) {
      throw new Error(payload?.data?.message || config.labels.requestFailed);
    }
    return payload.data;
  };

  const completionUrl = (job) => {
    const url = new URL(window.location.href);
    url.searchParams.delete('job');
    url.searchParams.delete('cb_error');
    url.searchParams.set('cb_notice', job.kind === 'restore' ? 'restore_completed' : 'backup_completed');
    return url.toString();
  };

  const dismissTerminalJob = () => {
    stopTimer();
    jobBox.hidden = true;
    const url = new URL(window.location.href);
    url.searchParams.delete('job');
    url.searchParams.delete('cb_error');
    window.history.replaceState({}, '', url.toString());
  };

  const poll = async () => {
    try {
      const job = await request('cb_backups_job_status');
      render(job);

      if (job.status === 'completed') {
        if (!completionHandled) {
          completionHandled = true;
          window.setTimeout(() => window.location.replace(completionUrl(job)), 500);
        }
        return;
      }
      if (job.status === 'failed') {
        if (!failureNotified) {
          failureNotified = true;
          showToast(job.error || config.labels.failed, 'error', { persistent: true });
        }
        dismissTerminalJob();
        return;
      }
      if (job.status === 'cancelled') {
        showToast(config.labels.cancelled, 'info');
        dismissTerminalJob();
        return;
      }
      window.setTimeout(poll, 1200);
    } catch (requestError) {
      if (elements.error) elements.error.textContent = requestError.message;
      window.setTimeout(poll, 3000);
    }
  };

  if (elements.cancel) {
    elements.cancel.addEventListener('click', async () => {
      if (!modal?.show) {
        showToast(config.labels.requestFailed, 'error', { persistent: true });
        return;
      }

      const confirmed = await modal.show({
        title: config.labels.cancelTitle,
        body: config.labels.cancelConfirm,
        confirmLabel: config.labels.cancel,
        cancelLabel: config.labels.modalCancel,
        confirmVariant: 'remediation',
      });
      if (!confirmed) return;

      elements.cancel.disabled = true;
      elements.cancel.textContent = config.labels.cancelling;
      try {
        const job = await request('cb_backups_cancel_job');
        render(job);
        showToast(config.labels.cancelRequested, 'warning');
        if (!terminalStatuses.has(job.status)) {
          window.setTimeout(poll, 500);
        }
      } catch (requestError) {
        elements.cancel.disabled = false;
        elements.cancel.textContent = config.labels.cancel;
        if (elements.error) elements.error.textContent = requestError.message;
        showToast(requestError.message, 'error', { persistent: true });
      }
    });
  }

  if (!terminalStatuses.has(jobBox.dataset.status || '')) {
    startTimer();
    window.setTimeout(poll, 250);
  }
})();
