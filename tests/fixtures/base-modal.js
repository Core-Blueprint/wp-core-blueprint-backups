/**
 * core/modal.js - Core Blueprint Suite
 *
 * Generic modal dialog with Promise-based API. Built on the native
 * <dialog> element - focus trap, Esc-to-close, and inert-background
 * are handled by the browser. We add the visual layer + the convenience
 * of awaitable confirmation flows.
 *
 * Single API for all confirmation patterns across CB Base and its
 * extensions (Hub, Access Control, Protected Content, Invoice, …).
 *
 * Public API:
 *   const result = await window.cbCore.modal.show({
 *     title:        'Modal title',          // required
 *     body:         'Body copy',            // optional, string or HTMLElement
 *     confirmLabel: 'Confirm',              // optional, default 'OK'
 *     cancelLabel:  'Cancel',               // optional, default 'Cancel'
 *     confirmVariant:'primary',             // primary|secondary|remediation|danger
 *     confirmIcon:  'quarantine',            // optional semantic/canonical icon name
 *     dismissOnly:  false,                  // informational modal: one Close action
 *     size:         'wide',                 // optional presentation: default|wide
 *     initialFocus: 'input[name=title]',    // optional selector or HTMLElement
 *     onConfirm:    async ({ dialog, form, body, confirmButton, value }) => true,
 *                                              // return false to keep modal open
 *
 *     // Mode selectors (mutually exclusive - pick at most one):
 *     typedConfirm: 'DELETE ALL',           // user must type this exact string
 *     input: { type, label, placeholder, … }, // user enters text/password
 *   });
 *
 * Modes & resolved values:
 *
 *   Mode 1 - Confirm (no typedConfirm, no input)
 *     await modal.show({ title, body, confirmLabel })  → boolean
 *     true  = user confirmed
 *     false = user cancelled (button, Esc, or backdrop)
 *
 *   Mode 2 - Type-to-confirm (typedConfirm set)
 *     await modal.show({ title, body, confirmLabel, typedConfirm: 'PHRASE' })  → boolean
 *     Confirm button stays disabled until input matches the phrase exactly.
 *     Returns boolean - same semantics as Mode 1.
 *
 *   Mode 3 - Input (input.type set, typically 'text' or 'password')
 *     await modal.show({ title, body, confirmLabel, input: { type: 'password' } })  → string|null
 *     string = user confirmed; the entered value is returned
 *     null   = user cancelled
 *     The `input.required` option (default true) forces a non-empty value
 *     before Confirm is enabled. Set to false for optional reasons/notes.
 *
 * confirmVariant controls only the semantic presentation of the confirm
 * action. The danger variant also marks the title. Variants never change
 * resolution semantics.
 *
 * Native DOM API only. No jQuery. No bundler.
 *
 * @package CB\Core
 * @since   1.0.0
 */

import { create as createIcon } from './icon.js';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/modal' );
let data = {};
try {
	data = dataEl ? JSON.parse( dataEl.textContent ) : {};
} catch {
	data = {};
}
const i18n = data.i18n || {};
const presentation = data.presentation === 'core' ? 'core' : 'wp-native';

let modalSequence = 0;

/**
 * Resolve which mode the modal runs in. Mutually exclusive - typedConfirm
 * wins over input if both are passed (typedConfirm is more constrained).
 * Default mode is plain confirm.
 */

function resolveConfirmVariant( opts ) {
	const allowed = [ 'primary', 'secondary', 'remediation', 'danger' ];
	const requested = String( opts.confirmVariant || '' );
	if ( allowed.includes( requested ) ) return requested;
	if ( opts.dismissOnly ) return 'secondary';
	return 'primary';
}

function resolveMode( opts ) {
	if ( opts.typedConfirm ) return 'typed';
	if ( opts.input ) return 'input';
	return 'confirm';
}

/**
 * Build the dialog DOM. Returns the <dialog> element with refs to its
 * interactive children stashed on the element for the show() handler.
 *
 * @param {object} opts
 * @returns {HTMLDialogElement}
 */
function buildDialog( opts ) {
	const mode = resolveMode( opts );
	const confirmVariant = resolveConfirmVariant( opts );

	const dialog = document.createElement( 'dialog' );
	const modalId = ++modalSequence;
	const sizeClass = opts.size === 'wide' ? ' cb-core-modal--wide' : '';
	dialog.className = `cb-core-modal cb-core-modal--${ presentation } cb-scrollbar` + ( presentation === 'core' ? ' cb-core-form-scope' : '' ) + ( confirmVariant === 'danger' ? ' cb-core-modal--danger' : '' ) + sizeClass;
	dialog.dataset.confirmVariant = confirmVariant;
	dialog.setAttribute( 'aria-modal', 'true' );

	const form = document.createElement( 'form' );
	form.method    = 'dialog';
	form.className = 'cb-core-modal__form';

	// ─── Title ──────────────────────────────────────────────────────────
	if ( opts.title ) {
		const title = document.createElement( 'h2' );
		title.id          = `cb-core-modal-title-${ modalId }`;
		title.className   = 'cb-core-modal__title';
		title.textContent = opts.title;
		form.appendChild( title );
		dialog.setAttribute( 'aria-labelledby', title.id );
	}

	// ─── Body ───────────────────────────────────────────────────────────
	// Accepts a string (split on blank lines into paragraphs) or an
	// HTMLElement (appended as-is so callers can build rich content).
	let body = null;
	if ( opts.body ) {
		body = document.createElement( 'div' );
		body.id = `cb-core-modal-body-${ modalId }`;
		body.className = 'cb-core-modal__body cb-scrollbar';
		if ( opts.body instanceof HTMLElement ) {
			body.appendChild( opts.body );
		} else {
			String( opts.body ).split( /\n\n+/ ).forEach( ( para ) => {
				const p = document.createElement( 'p' );
				p.textContent = para;
				body.appendChild( p );
			} );
		}
		form.appendChild( body );
		dialog.setAttribute( 'aria-describedby', body.id );
	}

	// ─── Mode-specific input ────────────────────────────────────────────
	let input  = null;
	let status = null;

	if ( mode === 'typed' ) {
		// "Type X to confirm" hint with the phrase rendered in <code>.
		const hint     = document.createElement( 'p' );
		hint.id = `cb-core-modal-hint-${ modalId }`;
		hint.className = 'cb-core-modal__hint';
		const hintText = opts.typedConfirmHint || i18n.typeToConfirm || 'Type to confirm:';
		hint.textContent = hintText + ' ';
		const codeEl    = document.createElement( 'code' );
		codeEl.textContent = opts.typedConfirm;
		hint.appendChild( codeEl );
		form.appendChild( hint );

		input = document.createElement( 'input' );
		input.type         = 'text';
		input.className    = 'regular-text cb-core-modal__input';
		input.autocomplete = 'off';
		input.spellcheck   = false;
		input.placeholder  = opts.typedConfirm;
		input.setAttribute( 'aria-label', opts.typedConfirmInputLabel || hintText );
		form.appendChild( input );

		status = document.createElement( 'p' );
		status.id = `cb-core-modal-status-${ modalId }`;
		status.className = 'cb-core-modal__status';
		status.setAttribute( 'aria-live', 'polite' );
		form.appendChild( status );
		input.setAttribute( 'aria-describedby', `${ hint.id } ${ status.id }` );
	}

	if ( mode === 'input' ) {
		const inputOpts   = opts.input || {};
		const inputType   = inputOpts.type === 'password' ? 'password' : 'text';
		const isPassword  = inputType === 'password';

		input = document.createElement( 'input' );
		input.type         = inputType;
		input.className    = 'regular-text cb-core-modal__input';
		input.autocomplete = isPassword ? 'current-password' : 'off';
		input.spellcheck   = false;
		if ( inputOpts.placeholder ) input.placeholder = String( inputOpts.placeholder );
		if ( inputOpts.maxLength )   input.maxLength   = parseInt( inputOpts.maxLength, 10 );
		input.setAttribute( 'aria-label', String( inputOpts.label || inputOpts.placeholder || opts.title || i18n.input || 'Input' ) );
		form.appendChild( input );

		status = document.createElement( 'p' );
		status.id = `cb-core-modal-status-${ modalId }`;
		status.className = 'cb-core-modal__status';
		status.setAttribute( 'aria-live', 'polite' );
		form.appendChild( status );
		input.setAttribute( 'aria-describedby', status.id );
	}

	// ─── Actions ────────────────────────────────────────────────────────
	const actions    = document.createElement( 'menu' );
	actions.className = 'cb-core-modal__actions';

	const dismissOnly = opts.dismissOnly === true && mode === 'confirm';
	let cancelBtn = null;
	if ( ! dismissOnly ) {
		cancelBtn = document.createElement( 'button' );
		cancelBtn.type        = 'button';
		cancelBtn.className   = 'button cb-core-button cb-core-button--secondary';
		cancelBtn.textContent = opts.cancelLabel || i18n.cancel || 'Cancel';
	}

	const confirmBtn = document.createElement( 'button' );
	confirmBtn.type      = 'button';
	confirmBtn.className = 'button cb-core-button cb-core-button--' + confirmVariant;
	if ( confirmVariant === 'primary' ) {
		confirmBtn.classList.add( 'button-primary' );
	}
	const confirmLabel = opts.confirmLabel || ( dismissOnly ? ( i18n.close || 'Close' ) : ( i18n.confirm || 'OK' ) );
	if ( opts.confirmIcon ) {
		const icon = createIcon( opts.confirmIcon, { size: 'default', className: 'cb-core-button__icon' } );
		if ( icon ) confirmBtn.appendChild( icon );
		const label = document.createElement( 'span' );
		label.className = 'cb-core-button__label';
		label.textContent = confirmLabel;
		confirmBtn.appendChild( label );
	} else {
		confirmBtn.textContent = confirmLabel;
	}

	// Confirm button starts disabled when there's a gate to clear:
	// typed-confirm always gates; input mode gates by default unless
	// the caller explicitly sets `input.required: false`.
	if ( mode === 'typed' ) {
		confirmBtn.disabled = true;
	} else if ( mode === 'input' ) {
		const required = opts.input?.required !== false;
		if ( required ) {
			confirmBtn.disabled = true;
		}
	}

	if ( cancelBtn ) actions.appendChild( cancelBtn );
	actions.appendChild( confirmBtn );
	form.appendChild( actions );

	dialog.appendChild( form );

	// Stash refs for the show() handler.
	dialog._cbMode       = mode;
	dialog._cbForm       = form;
	dialog._cbBody       = body;
	dialog._cbCancelBtn  = cancelBtn;
	dialog._cbConfirmBtn = confirmBtn;
	dialog._cbInput      = input;
	dialog._cbStatus     = status;

	return dialog;
}

/**
 * Show a modal and resolve the Promise based on user action.
 *
 * Resolution semantics:
 *   - 'confirm' mode  → boolean
 *   - 'typed' mode    → boolean
 *   - 'input' mode    → string (entered value) on confirm, null on cancel
 *
 * @param {object} opts See API docblock at top of file.
 * @returns {Promise<boolean|string|null>}
 */
function show( opts ) {
	return new Promise( ( resolve ) => {
		const returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		const dialog = buildDialog( opts || {} );
		document.body.appendChild( dialog );

		const mode = dialog._cbMode;
		let settled = false;
		let confirmPending = false;

		const settle = ( confirmed ) => {
			if ( settled ) return;
			settled = true;

			let value;
			if ( mode === 'input' ) {
				value = confirmed ? ( dialog._cbInput?.value ?? '' ) : null;
			} else {
				value = !! confirmed;
			}

			if ( dialog.open ) {
				dialog.close();
			}
			resolve( value );

			requestAnimationFrame( () => {
				dialog.remove();
				if ( returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function' ) {
					returnFocus.focus();
				}
			} );
		};

		if ( dialog._cbCancelBtn ) {
			dialog._cbCancelBtn.addEventListener( 'click', () => {
				if ( ! confirmPending ) settle( false );
			} );
		}

		dialog.addEventListener( 'cancel', ( ev ) => {
			ev.preventDefault();
			if ( ! confirmPending ) settle( false );
		} );

		dialog.addEventListener( 'click', ( ev ) => {
			if ( ev.target === dialog && ! confirmPending ) {
				settle( false );
			}
		} );

		const confirm = async () => {
			if ( confirmPending || dialog._cbConfirmBtn.disabled ) return;

			if ( typeof opts.onConfirm !== 'function' ) {
				settle( true );
				return;
			}

			confirmPending = true;
			const button = dialog._cbConfirmBtn;
			const cancelButton = dialog._cbCancelBtn;
			const wasDisabled = button.disabled;
			const cancelWasDisabled = cancelButton?.disabled ?? false;
			button.disabled = true;
			if ( cancelButton ) cancelButton.disabled = true;
			dialog.setAttribute( 'aria-busy', 'true' );

			try {
				const result = await opts.onConfirm( {
					dialog,
					form: dialog._cbForm,
					body: dialog._cbBody,
					confirmButton: button,
					value: dialog._cbInput?.value ?? null,
				} );

				if ( result !== false ) {
					settle( true );
				}
			} catch ( error ) {
				console.error( 'Core Blueprint modal onConfirm failed.', error );
			} finally {
				confirmPending = false;
				dialog.removeAttribute( 'aria-busy' );
				if ( ! settled ) {
					button.disabled = wasDisabled;
					if ( cancelButton ) cancelButton.disabled = cancelWasDisabled;
				}
			}
		};

		dialog._cbConfirmBtn.addEventListener( 'click', confirm );
		if ( typeof opts.onConfirm === 'function' ) {
			dialog._cbForm.addEventListener( 'submit', ( ev ) => {
				ev.preventDefault();
				confirm();
			} );
		}

		if ( mode === 'typed' ) {
			const expected = opts.typedConfirm;
			const onInput = () => {
				const matches = dialog._cbInput.value === expected;
				dialog._cbConfirmBtn.disabled = ! matches;
				if ( dialog._cbStatus ) {
					if ( dialog._cbInput.value === '' || matches ) {
						dialog._cbStatus.textContent = '';
						dialog._cbStatus.classList.remove( 'is-error' );
					} else {
						dialog._cbStatus.textContent = opts.typedConfirmMismatch || i18n.textDoesNotMatch || 'Text does not match.';
						dialog._cbStatus.classList.add( 'is-error' );
					}
				}
			};
			dialog._cbInput.addEventListener( 'input', onInput );
			dialog._cbInput.addEventListener( 'keydown', ( ev ) => {
				if ( ev.key === 'Enter' && dialog._cbInput.value === expected ) {
					ev.preventDefault();
					confirm();
				}
			} );
		}

		if ( mode === 'input' ) {
			const required = opts.input?.required !== false;
			if ( required ) {
				dialog._cbInput.addEventListener( 'input', () => {
					dialog._cbConfirmBtn.disabled = dialog._cbInput.value.trim() === '';
				} );
			}
			dialog._cbInput.addEventListener( 'keydown', ( ev ) => {
				if ( ev.key === 'Enter' && ( ! required || dialog._cbInput.value.trim() !== '' ) ) {
					ev.preventDefault();
					confirm();
				}
			} );
		}

		dialog.showModal();

		let focusTarget = null;
		if ( opts.initialFocus instanceof HTMLElement ) {
			focusTarget = opts.initialFocus;
		} else if ( typeof opts.initialFocus === 'string' && opts.initialFocus ) {
			focusTarget = dialog.querySelector( opts.initialFocus );
		}

		( focusTarget || dialog._cbInput || dialog._cbCancelBtn || dialog._cbConfirmBtn )?.focus();
	} );
}

const modal = { show };

// Expose under cbCore so consumers across CB Suite use the same global.
// Extensions importing this module directly via ESM also get the named
// `show` and the default export. window.cbCore is initialised idempotently
// - any core/* module may be the first to touch it.
window.cbCore = window.cbCore || {};
window.cbCore.modal = modal;

export { show };
export default modal;
