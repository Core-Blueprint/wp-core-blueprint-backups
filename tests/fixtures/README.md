# Base modal contract fixture

`base-modal.js` is the unmodified Core Blueprint Base modal from commit `eff8de2d072e78783506ffc2f16fa07744d13856`, path `assets/js/core/modal.js`, Git blob `9a012e47802026e03dd5443e83bcf342d6f7ad3f`. It is used only by Chromium regression tests and excluded from release ZIPs.

The browser fixture serves the actual modal module and shipped Backups modules. Its unused icon factory returns null. Tests cover the custom-body/onConfirm contract, the scoped confirmation-button binding, keyboard/cancel behavior and failure when that control is unavailable. Update this snapshot deliberately when adopting a changed Base modal contract; do not replace it with a permissive modal mock.
