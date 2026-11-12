{{--
    CORE-H-Z1-REMEDIATION-R2, O-N11 (WCAG 2.4.3 Focus Order; supports 3.3.1): keep focus in a logical place
    after a Save that stays on the page.

    Root cause (vendor, Filament 5.6.6): every Core Admin save control (the form submit buttons of resource
    create / edit pages and settings pages, and the "Save layout" wire:click button) carries
    wire:loading.attr="disabled". While the save request is in flight the focused button is disabled, the
    browser moves focus to <body>, and nothing moves it back: after a success, after a validation failure,
    and after "Create & create another". Measured on every surface in doc r2/05.

    Integration (no vendor edit; separate from the dialog integration, whose opener is a mount…Action call):
      - a passive capture "click" listener only READS which save control was activated: a submit button of a
        form with wire:submit (including a Filament action dialog's own form), or a button whose wire:click
        calls save…() / create…() outside any dialog (keyboard Enter / Space on a button fires click;
        implicit form submission too);
      - after the Livewire response is morphed, if focus was lost (on <body>) and - for a page save - no
        dialog is open, or - for a dialog save - that dialog is still open (it failed validation):
          * when the form now shows server validation errors, focus moves to the first invalid control,
            as marked by the H-N4 integration (aria-invalid, with its error as description), so the user
            lands on what failed and hears why;
          * otherwise focus returns to the save control (or, if Livewire replaced it, the control in the
            same form with the same accessible text). The success notification is already announced by
            Filament's role="status" region, so it is not focused.
      - a save that navigates (create -> edit page) loads a new document; this script does nothing there
        (the old document and its state are gone), and focus starts at the top of the new page.
      - retries are bounded (animation frames for 2 s, to outlast wire:loading); any focus the user or the
        workflow places elsewhere wins and cancels the restore. A pending record older than 15 s, or one whose
        click started no Livewire request within 1 s, is dropped.
    No event is prevented or stopped and no DOM is written. Emitted once before </body> for signed-in admin
    pages only (PanelsRenderHook::BODY_END), after field-errors so its morph hook runs first.
--}}
<script data-tn-admin-save-focus>
(function () {
    if (window.__tnAdminSaveFocus) {
        return;
    }
    window.__tnAdminSaveFocus = true;

    var SAVE_CALL = /^\s*(save|create)[A-Za-z]*\s*(\(|$)/;
    var RETRY_MS = 2000;
    var PENDING_MS = 15000;
    var SEND_MS = 1000;
    var pending = null;
    var retryUntil = 0;
    var frame = 0;

    function inDialog(el) {
        return el.closest('.fi-modal-window') !== null;
    }

    function wireSubmit(form) {
        return !! form && Array.prototype.some.call(form.attributes, function (attribute) {
            return attribute.name === 'wire:submit' || attribute.name.indexOf('wire:submit.') === 0;
        });
    }

    // A dialog's own form (Filament renders the action dialog window as <form wire:submit.prevent=
    // "callMountedAction">) counts too: its submit has the same wire:loading root cause. Only the phase
    // while that dialog stays open (a validation failure) is handled here; once it closes, the dialog
    // integration (action-focus) returns focus to its opener.
    function saveControl(target) {
        var button = target instanceof Element ? target.closest('button') : null;
        if (! button) {
            return null;
        }
        if (button.type === 'submit' && wireSubmit(button.form)) {
            return button;
        }
        var call = button.getAttribute('wire:click');
        return call && SAVE_CALL.test(call) && ! inDialog(button) ? button : null;
    }

    function text(el) {
        return (el.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function visible(el) {
        return el instanceof HTMLElement && el.isConnected && el.getClientRects().length > 0;
    }

    function usable(el, state) {
        return visible(el) && ! el.disabled && (state.dialog ? state.dialog.contains(el) : ! inDialog(el));
    }

    function scopeOf(state) {
        if (state.dialog) {
            return state.dialog;
        }
        return state.form && state.form.isConnected ? state.form : document;
    }

    // In-page saves stop when any dialog is open; a dialog save stops when its own dialog has closed.
    function outOfScope(state) {
        if (state.dialog) {
            return ! state.dialog.isConnected || state.dialog.closest('.fi-modal-open') === null;
        }
        return document.querySelector('.fi-modal-open') !== null;
    }

    function findSave(state) {
        if (usable(state.el, state)) {
            return state.el;
        }
        var candidates = scopeOf(state).querySelectorAll('button');
        for (var i = 0; i < candidates.length; i++) {
            if (usable(candidates[i], state) && text(candidates[i]) === state.label && saveControl(candidates[i]) !== null) {
                return candidates[i];
            }
        }
        // CORE-H-Z1-PRE-RERUN-RESIDUAL-CLOSURE-R1, L-2: a save that removes its own form (Homepage layout
        // "Apply changes" closes the section editor) declares where focus goes instead, as element ids in
        // data-tn-focus-fallback (looked up with getElementById, never a constructed selector).
        for (var j = 0; j < state.fallback.length; j++) {
            var fallback = document.getElementById(state.fallback[j]);
            if (fallback && usable(fallback, state)) {
                return fallback;
            }
        }
        return null;
    }

    function firstInvalid(state) {
        var invalid = scopeOf(state).querySelectorAll('[aria-invalid="true"]');
        for (var i = 0; i < invalid.length; i++) {
            if (visible(invalid[i]) && (state.dialog || ! inDialog(invalid[i]))) {
                return invalid[i];
            }
        }
        return null;
    }

    function hasErrors(state) {
        var errors = scopeOf(state).querySelectorAll('[data-validation-error]');
        for (var i = 0; i < errors.length; i++) {
            if (state.dialog || ! inDialog(errors[i])) {
                return true;
            }
        }
        return false;
    }

    function focusLost() {
        var active = document.activeElement;
        return active === null || active === document.body;
    }

    function attempt() {
        frame = 0;
        if (! pending) {
            return;
        }
        // A click that started no Livewire request (e.g. blocked by native validation) is forgotten, so a
        // later unrelated re-render can never act on it.
        if (outOfScope(pending) || ! focusLost() || ! pending.sent || Date.now() - pending.at > PENDING_MS) {
            pending = null;
            return;
        }
        // Still saving (wire:loading keeps the control disabled): an unrelated morph started this loop, so
        // keep waiting for the save response rather than giving up after RETRY_MS.
        if (pending.el.isConnected && pending.el.disabled) {
            retryUntil = Date.now() + RETRY_MS;
            frame = window.requestAnimationFrame(attempt);
            return;
        }
        var expired = Date.now() >= retryUntil;
        var target = null;
        if (hasErrors(pending)) {
            // field-errors marks controls in its own morph hook; give it the same retry window.
            target = firstInvalid(pending) || (expired ? findSave(pending) : null);
        } else {
            target = findSave(pending);
        }
        if (target) {
            pending = null;
            target.focus();
            return;
        }
        if (! expired) {
            frame = window.requestAnimationFrame(attempt);
        } else {
            pending = null;
        }
    }

    function scheduleRestore() {
        if (! pending || frame) {
            return;
        }
        retryUntil = Date.now() + RETRY_MS;
        frame = window.requestAnimationFrame(attempt);
    }

    document.addEventListener('click', function (event) {
        var control = saveControl(event.target);
        if (control) {
            pending = { el: control, form: control.form || control.closest('form'), dialog: control.closest('.fi-modal-window'), label: text(control), at: Date.now(), sent: false,
                fallback: (control.getAttribute('data-tn-focus-fallback') || '').split(/\s+/).filter(Boolean) };
        }
    }, { capture: true, passive: true });

    function hookLivewire(Livewire) {
        if (! Livewire || typeof Livewire.hook !== 'function') {
            return false;
        }
        Livewire.hook('morphed', scheduleRestore);
        // CORE-H-Z1-REMEDIATION-R2: Filament re-renders schema parts (e.g. an action dialog's form after a
        // validation error) through its own partial renderer (support/resources/js/partials.js), which
        // calls Alpine.morph directly, so Livewire's `morphed` hook does not fire for them. Livewire's public
        // message interceptor does; the handler runs on the next task, after Filament's microtask morph.
        if (typeof Livewire.interceptMessage === 'function') {
            Livewire.interceptMessage(function (message) {
                if (pending && ! pending.sent && Date.now() - pending.at <= SEND_MS) {
                    pending.sent = true;
                }
                message.onSuccess(function () {
                    window.setTimeout(scheduleRestore, 0);
                });
            });
        }
        return true;
    }

    if (! hookLivewire(window.Livewire)) {
        document.addEventListener('livewire:init', function () {
            hookLivewire(window.Livewire);
        }, { once: true });
    }
})();
</script>
