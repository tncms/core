{{--
    CORE-H-Z1-REMEDIATION-R1 H-N2 (WCAG 2.4.3 Focus Order): return focus to the control that opened a
    Filament action dialog when that dialog closes (Escape, Cancel, the close button, or a confirm that
    keeps the page).

    Root cause (vendor, Filament 5.6.6): the opener carries wire:loading.attr="disabled", so while the
    mountAction request is in flight it is disabled and focus falls to <body>. The dialog's x-trap
    then records <body> as the element to return to, so on close focus is lost to the top of the page.

    Integration (no vendor edit, no global interception):
      - a passive capture "click" listener only READS which button was activated, and only when its
        wire:click (record/header actions) or x-on:click (table bulk actions) calls a Filament
        mount…Action( method outside any open dialog (keyboard Enter/Space on a button fires click);
      - the opener is remembered only after Filament reports a dialog opened (x-modal-opened);
      - when the dialog has closed (modal-closed, or a Livewire morph for quiet closes) and focus sits on
        <body> with no Filament dialog still open, focus moves back to that opener - or, if Livewire
        replaced it, to the connected, enabled element whose same attribute is exactly equal
        (string comparison, never a constructed selector). If none is usable, nothing happens.
      - retries are bounded (animation frames for 2 s) to wait for wire:loading to re-enable it;
        any focus the user or the workflow places elsewhere wins and cancels the restore.
    No event is prevented or stopped and no DOM is written. Emitted once before </body> for signed-in
    admin pages only (PanelsRenderHook::BODY_END), like sidebar-escape and select-a11y.
--}}
<script data-tn-admin-action-focus>
(function () {
    if (window.__tnAdminActionFocus) {
        return;
    }
    window.__tnAdminActionFocus = true;

    var MOUNT = /^\s*mount[A-Za-z]*Action\(/;
    var OPENER_ATTRIBUTES = ['wire:click', 'x-on:click'];
    var OPENER_SELECTOR = '[wire\\:click], [x-on\\:click]';
    var RETRY_MS = 2000;
    var OPEN_WINDOW_MS = 10000;
    var VERIFY_MS = 1500;
    var pending = null;
    var retryUntil = 0;
    var frame = 0;

    function usable(el, state) {
        return el instanceof HTMLElement
            && el.isConnected
            && ! el.disabled
            && el.getAttribute(state.attribute) === state.call
            && el.closest('.fi-modal-window') === null
            && el.getClientRects().length > 0;
    }

    function findOpener(state) {
        if (usable(state.el, state)) {
            return state.el;
        }
        var candidates = document.querySelectorAll(OPENER_SELECTOR);
        for (var i = 0; i < candidates.length; i++) {
            if (usable(candidates[i], state)) {
                return candidates[i];
            }
        }
        return null;
    }

    // Called only when no dialog is open: focus on <body>, or still inside the closing dialog window
    // (its leave transition), counts as lost. Anywhere else, the user or the workflow put it there.
    function focusLost() {
        var active = document.activeElement;
        return active === null || active === document.body || active.closest('.fi-modal-window') !== null;
    }

    function attempt() {
        frame = 0;
        if (! pending || ! pending.opened) {
            return;
        }
        // R2: after a restore, watch briefly. A bulk action deselects its rows right after the dialog
        // closes, which hides (x-show) or removes the toolbar button focus was just returned to; focus
        // then falls to <body> again and goes to the changed table instead.
        if (pending.restoredTo) {
            verifyRestore();
            return;
        }
        // A quiet close (close-modal-quietly after the action ran) lands after the Livewire morph that
        // scheduled this attempt, so keep polling until the deadline. A dialog that stays open (a
        // chained action) keeps the opener remembered for its own later close.
        if (document.querySelector('.fi-modal-open')) {
            if (Date.now() < retryUntil) {
                frame = window.requestAnimationFrame(attempt);
            }
            return;
        }
        if (! focusLost()) {
            pending = null;
            return;
        }
        var target = findOpener(pending);
        if (target) {
            target.focus();
            pending.restoredTo = target;
            pending.verifyUntil = Date.now() + VERIFY_MS;
            frame = window.requestAnimationFrame(attempt);
            return;
        }
        // An opener that is still rendered but disabled (wire:loading) is waited for; one the workflow
        // removed is not coming back, so focus goes to the region it acted on.
        var expired = Date.now() >= retryUntil;
        if ((expired || openerGone(pending)) && focusRegion(pending)) {
            pending = null;
            return;
        }
        if (! expired) {
            frame = window.requestAnimationFrame(attempt);
        } else {
            pending = null;
        }
    }

    function verifyRestore() {
        var restored = pending.restoredTo;
        var active = document.activeElement;
        if (active !== restored && active !== null && active !== document.body) {
            pending = null; // the user or the workflow moved focus on: done
            return;
        }
        if (active !== restored) {
            if (findOpener(pending) === restored) {
                restored.focus();
            } else if (focusRegion(pending)) {
                pending = null;
                return;
            } else {
                var again = findOpener(pending);
                if (! again) {
                    pending = null;
                    return;
                }
                again.focus();
                pending.restoredTo = again;
            }
        }
        if (Date.now() < pending.verifyUntil) {
            frame = window.requestAnimationFrame(attempt);
        } else {
            pending = null;
        }
    }

    // CORE-H-Z1-REMEDIATION-R2, H-N2 residual (WCAG 2.4.3): a table bulk action (e.g. "Change status"
    // with deselectRecordsAfterCompletion, "Delete selected") or a row action that deletes its row
    // removes its own opener once confirmed, so there is nothing to return to and focus stayed on
    // <body>. No equivalent action control survives (the bulk toolbar only exists while rows are
    // selected), so the destination is the table the action changed: the empty-state heading when no
    // row is left, otherwise its <table> element. It gets tabindex="-1" only if it has no tabindex, so it is
    // programmatically focusable without joining the Tab order; Tab then continues inside the table.
    function openerGone(state) {
        var candidates = [state.el].concat(Array.prototype.slice.call(document.querySelectorAll(OPENER_SELECTOR)));
        for (var i = 0; i < candidates.length; i++) {
            var el = candidates[i];
            if (el instanceof HTMLElement && el.isConnected && el.getAttribute(state.attribute) === state.call
                && el.closest('.fi-modal-window') === null && el.getClientRects().length > 0) {
                return false;
            }
        }
        return true;
    }

    function regionTarget(state) {
        var region = state.region;
        if (! region) {
            return null;
        }
        if (! region.isConnected) {
            var tables = document.querySelectorAll('.fi-ta');
            region = tables.length === 1 ? tables[0] : null;
        }
        if (! region) {
            return null;
        }
        // The empty-state heading ("No posts") first: when the action left no row it names the outcome, and
        // Filament keeps the header-only <table> rendered around it.
        var choices = ['.fi-ta-empty-state-heading', 'table.fi-ta-table'];
        for (var i = 0; i < choices.length; i++) {
            var el = region.querySelector(choices[i]);
            if (el && el.getClientRects().length > 0) {
                return el;
            }
        }
        return null;
    }

    var regionFocus = null;

    function focusRegion(state) {
        var target = regionTarget(state);
        if (! target) {
            return false;
        }
        if (! target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
        }
        regionFocus = target;
        target.focus();
        return true;
    }

    // A later Livewire morph drops the client-only tabindex from the server HTML; while that element
    // still holds focus, put it back so the browser does not move focus to <body>.
    function keepRegionFocus() {
        if (regionFocus && document.activeElement !== regionFocus) {
            regionFocus = null;
            return;
        }
        if (regionFocus && ! regionFocus.hasAttribute('tabindex')) {
            regionFocus.setAttribute('tabindex', '-1');
        }
    }

    function scheduleRestore() {
        if (! pending || ! pending.opened || frame) {
            return;
        }
        retryUntil = Date.now() + RETRY_MS;
        frame = window.requestAnimationFrame(attempt);
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target.closest(OPENER_SELECTOR) : null;
        if (! target || target.closest('.fi-modal-window')) {
            return;
        }
        for (var i = 0; i < OPENER_ATTRIBUTES.length; i++) {
            var call = target.getAttribute(OPENER_ATTRIBUTES[i]);
            if (call && MOUNT.test(call)) {
                pending = { el: target, attribute: OPENER_ATTRIBUTES[i], call: call, opened: false, at: Date.now(), region: target.closest('.fi-ta') };
                return;
            }
        }
    }, { capture: true, passive: true });

    // Only a dialog that opens soon after the click belongs to it: an action that opened no dialog
    // (gated, failed, or run without confirmation) must not adopt an unrelated dialog later.
    document.addEventListener('x-modal-opened', function () {
        if (! pending || pending.opened) {
            return;
        }
        if (Date.now() - pending.at > OPEN_WINDOW_MS) {
            pending = null;
            return;
        }
        pending.opened = true;
    });

    window.addEventListener('modal-closed', scheduleRestore);

    function hookLivewire(Livewire) {
        if (! Livewire || typeof Livewire.hook !== 'function') {
            return false;
        }
        var afterRender = function () {
            keepRegionFocus();
            scheduleRestore();
        };
        Livewire.hook('morphed', afterRender);
        // CORE-H-Z1-REMEDIATION-R2: Filament re-renders schema parts (e.g. an action dialog's form after a
        // validation error) through its own partial renderer (support/resources/js/partials.js), which
        // calls Alpine.morph directly, so Livewire's `morphed` hook does not fire for them. Livewire's public
        // message interceptor does; the handler runs on the next task, after Filament's microtask morph.
        if (typeof Livewire.interceptMessage === 'function') {
            Livewire.interceptMessage(function (message) {
                message.onSuccess(function () {
                    window.setTimeout(afterRender, 0);
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
