import { confirmRestore } from './restore-confirmation.js';

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
        delete form.dataset.cbModalConfirmed;
        return;
      }

      event.preventDefault();
      if (form.dataset.cbModalPending === '1') return;
      if (!modal?.show) {
        showToast(config.labels?.requestFailed || 'Confirmation dialog is unavailable.', 'error', { persistent: true });
        return;
      }

      const options = {
        title: form.dataset.cbModalTitle || 'Confirm action',
        body: form.dataset.cbModalBody || '',
        confirmLabel: form.dataset.cbModalConfirmLabel || 'Confirm',
        cancelLabel: form.dataset.cbModalCancelLabel || config.labels?.modalCancel || 'Cancel',
        confirmVariant: form.dataset.cbModalVariant || 'primary',
      };
      const acknowledgement = form.querySelector('[data-cb-restore-acknowledgement]');
      if (acknowledgement) acknowledgement.value = '';
      form.dataset.cbModalPending = '1';
      let confirmed = false;
      try {
        confirmed = acknowledgement
          ? await confirmRestore(modal, options, acknowledgement.dataset.cbRestoreAcknowledgement)
          : await modal.show(options);
      } catch {
        showToast(config.labels?.requestFailed || 'Confirmation dialog is unavailable.', 'error', { persistent: true });
      } finally {
        delete form.dataset.cbModalPending;
      }

      if (!confirmed) return;

      if (acknowledgement) acknowledgement.value = '1';
      form.dataset.cbModalConfirmed = '1';
      if (event.submitter instanceof HTMLElement) {
        event.submitter.setAttribute('aria-disabled', 'true');
      }
      form.requestSubmit(event.submitter || undefined);
    });
  });

  window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-cb-restore-acknowledgement]').forEach((input) => {
      input.value = '';
      delete input.form.dataset.cbModalConfirmed;
      delete input.form.dataset.cbModalPending;
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

})();
