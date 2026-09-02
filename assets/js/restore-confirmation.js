/** Required restore acknowledgement inside the existing Base modal. */
export async function confirmRestore(modal, options, acknowledgement) {
  if (!acknowledgement) return false;
  const body = document.createElement('div');
  const description = document.createElement('p');
  description.textContent = options.body;
  body.append(description);

  const label = document.createElement('label');
  label.className = 'cb-backups-restore-acknowledgement';
  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.required = true;
  checkbox.checked = false;
  const text = document.createElement('span');
  text.textContent = acknowledgement;
  label.append(checkbox, text);
  body.append(label);

  const result = modal.show({
    ...options,
    body,
    initialFocus: checkbox,
    onConfirm: () => checkbox.checked,
  });

  // Base's custom-body API mounts synchronously. Scope the button lookup to
  // this body; never mutate a different modal or use Base's private refs.
  const dialog = body.closest('dialog');
  const confirm = dialog?.querySelector('.cb-core-modal__actions .cb-core-button--danger');
  if (!confirm) {
    dialog?.dispatchEvent(new Event('cancel', { cancelable: true }));
    throw new Error('Restore confirmation control is unavailable.');
  }
  confirm.disabled = true;
  checkbox.addEventListener('change', () => { confirm.disabled = !checkbox.checked; });
  return (await result) === true && checkbox.checked;
}
