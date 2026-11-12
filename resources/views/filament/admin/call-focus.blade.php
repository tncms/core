{{--
    CORE-H-Z1-PRE-RERUN-RESIDUAL-CLOSURE-R1, L-2 (WCAG 2.4.3 Focus Order): keep focus in a logical place
    after a button that calls a Livewire method on the same page (no dialog, no navigation, no save).

    Root cause (vendor, Filament 5.6.6): Filament buttons carry wire:loading.attr="disabled". While the
    call they start is in flight the focused button is disabled, the browser moves focus to <body>, and
    nothing moves it back - even when the button is still there afterwards. Measured in Chrome and Firefox on
    every such control of the bounded inventory (doc 05): Homepage layout (Edit, Move up / down, Duplicate,
    Hide / Show, Delete, Add, Cancel), Import demo (Preview) and Widgets (Add). Saves are handled by
    save-focus and dialog openers by action-focus; this script excludes both.

    Integration (no vendor edit, no global interception):
      - a passive capture "click" listener only READS which button was activated: a button outside any
        dialog whose wire:click is not a mount…Action / save… / create… call and which carries Filament's
        wire:loading.attr="disabled" (keyboard Enter / Space on a button fires click);
      - after the Livewire response is morphed, and only if focus was lost (on <body>) and no dialog opened,
        focus goes to the first usable of:
          1. the activated button, if it still has the same wire:click (the control survived);
          2. the connected element whose wire:click is exactly equal (string comparison, never a constructed
             selector) - e.g. "Move down" of the section that moved, wherever it is now;
          3. the ids the activated control declared at render time in data-tn-focus-fallback (space
             separated, looked up with getElementById), for a control that disappears or becomes disabled:
             e.g. Cancel -> that section's Edit, Delete -> the next section's Edit.
        A candidate the server rendered disabled (Filament's fi-disabled class / aria-disabled="true"; a control
        with a tooltip keeps no disabled attribute) is skipped; one disabled only by wire:loading is waited for.
      - only a control that held focus when it was activated is restored (keyboard, or a pointer on platforms
        that focus buttons on click); a pointer press anywhere else while the call runs cancels the restore, and
        only a request of the control's own Livewire component counts as the one it started.
      - retries are bounded (animation frames for 2 s); any focus the user or the workflow places elsewhere
        wins and cancels the restore. A click that started no Livewire request within 1 s (e.g. a declined
        wire:confirm) is forgotten.
    CORE-H-Z1-REMEDIATION-R3 (ER-3, WCAG 2.1.1 / 2.5.7 / 2.4.3): Core repeaters now offer Filament's Move up / Move
    down buttons. They call mountAction('moveUp' | 'moveDown', {item: <key>}, ...) - excluded above as an action
    opener - yet open no dialog and leave focus on <body> like the calls above. Only that exact call shape on a button
    inside a Filament repeater or builder is treated as a same-page call. The item key is stable, so the moved item's
    button has the same wire:click wherever the item now is (rule 2); when it became disabled (first / last place)
    the item's other move button - the same wire:click with moveUp and moveDown swapped - is the fallback.
    No event is prevented or stopped and no DOM is written. Emitted once before </body> for signed-in admin
    pages only (PanelsRenderHook::BODY_END).
--}}
<script data-tn-admin-call-focus>
(function () {
    if (window.__tnAdminCallFocus) {
        return;
    }
    window.__tnAdminCallFocus = true;

    var EXCLUDED_CALL = /^\s*(mount[A-Za-z]*Action|callMountedAction|save[A-Za-z]*|create[A-Za-z]*)\s*(\(|$)/;
    var REPEATER_MOVE = /^\s*mountAction\('(moveUp|moveDown)',/;
    var RETRY_MS = 2000;
    var SEND_MS = 1000;
    var PENDING_MS = 15000;
    var pending = null;
    var retryUntil = 0;
    var frame = 0;

    function inDialog(el) {
        return el.closest('.fi-modal-window') !== null;
    }

    function callControl(target) {
        var button = target instanceof Element ? target.closest('button') : null;
        if (! button || inDialog(button) || button.getAttribute('wire:loading.attr') !== 'disabled') {
            return null;
        }
        var call = button.getAttribute('wire:click');
        return call && (! EXCLUDED_CALL.test(call) || repeaterMove(button, call)) ? button : null;
    }

    function repeaterMove(button, call) {
        return REPEATER_MOVE.test(call) && button.closest('.fi-fo-repeater, .fi-fo-builder') !== null;
    }

    // The same item's other move button (ER-3).
    function otherMove(button, call) {
        if (! repeaterMove(button, call)) {
            return null;
        }
        return call.replace(REPEATER_MOVE, function (match, name) {
            return match.replace(name, name === 'moveUp' ? 'moveDown' : 'moveUp');
        });
    }

    // 'wait' while only wire:loading keeps it disabled; false when it cannot take focus.
    function state(el) {
        if (! (el instanceof HTMLElement) || ! el.isConnected || inDialog(el) || el.getClientRects().length === 0) {
            return false;
        }
        if (el.classList.contains('fi-disabled') || el.getAttribute('aria-disabled') === 'true') {
            return false;
        }
        return el.disabled ? 'wait' : true;
    }

    function candidates(p) {
        var list = [];
        if (p.el.getAttribute('wire:click') === p.call) {
            list.push(p.el);
        }
        var clickers = document.querySelectorAll('[wire\\:click]');
        for (var i = 0; i < clickers.length; i++) {
            if (clickers[i] !== p.el && clickers[i].getAttribute('wire:click') === p.call) {
                list.push(clickers[i]);
            }
        }
        if (p.other) {
            for (var k = 0; k < clickers.length; k++) {
                if (clickers[k].getAttribute('wire:click') === p.other) {
                    list.push(clickers[k]);
                }
            }
        }
        for (var j = 0; j < p.fallback.length; j++) {
            var el = document.getElementById(p.fallback[j]);
            if (el) {
                list.push(el);
            }
        }
        return list;
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
        if (! pending.sent || ! focusLost() || document.querySelector('.fi-modal-open') || Date.now() - pending.at > PENDING_MS) {
            pending = null;
            return;
        }
        var list = candidates(pending);
        var waiting = false;
        for (var i = 0; i < list.length; i++) {
            var s = state(list[i]);
            if (s === true && ! waiting) {
                pending = null;
                list[i].focus();
                return;
            }
            if (s === 'wait') {
                waiting = true;
            }
        }
        if (Date.now() < retryUntil) {
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
        var control = callControl(event.target);
        if (control && document.activeElement === control) {
            pending = {
                el: control,
                call: control.getAttribute('wire:click'),
                other: otherMove(control, control.getAttribute('wire:click')),
                fallback: (control.getAttribute('data-tn-focus-fallback') || '').split(/\s+/).filter(Boolean),
                at: Date.now(),
                sent: false,
            };
        }
    }, { capture: true, passive: true });

    document.addEventListener('pointerdown', function (event) {
        if (pending && ! (event.target instanceof Node && pending.el.contains(event.target))) {
            pending = null;
        }
    }, { capture: true, passive: true });

    function hookLivewire(Livewire) {
        if (! Livewire || typeof Livewire.hook !== 'function') {
            return false;
        }
        Livewire.hook('morphed', scheduleRestore);
        // As in save-focus: Filament partial renders bypass `morphed`; the public message interceptor does
        // not. The handler runs on the next task, after Filament's microtask morph.
        if (typeof Livewire.interceptMessage === 'function') {
            Livewire.interceptMessage(function (message) {
                var component = message.message && message.message.component;
                var owner = component && component.el instanceof Element ? component.el : null;
                if (pending && ! pending.sent && Date.now() - pending.at <= SEND_MS && (! owner || owner.contains(pending.el))) {
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
