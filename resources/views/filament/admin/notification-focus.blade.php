{{--
    CORE-H-Z1-R2-F1-CLOSURE-R1, R2-F1 (WCAG 2.4.11 Focus Not Obscured (Minimum)). Filament draws its notifications in
    one fixed stack (.fi-no, `inset-4`, z-50) at the vertical edge chosen by Notifications::verticalAlignment()
    (default: top). That stack covers whatever sits under it: at the top it hid the focused top-bar controls
    (Expand sidebar, User menu, Language, Switch language) after a keyboard Save at narrow widths; a fixed bottom
    placement would hide a focused Save button there instead, so no single edge set through the Filament API is safe.

    While a notification overlaps the focused element, the stack is moved to the vertical edge away from that element:
    the bottom when the element's centre is in the upper half of the viewport, otherwise the top. The choice depends
    only on the element's position, so it cannot oscillate; it is kept while notifications are on screen (until another
    overlap needs the other edge) and dropped once none is, so the next notification starts at Filament's placement.
    It is checked when focus moves (including into an iframe, which only blurs this window), when a notification is
    added, when a page is swapped in after a save, and on scroll / resize (the browser scrolls a newly focused element
    into view after focus events). Only the part of the element inside the viewport counts. Nothing is checked while
    no notification is on screen.

    Only an attribute on <html> is written (outside every Livewire component, so a morph never resets it); the CSS
    below reuses the exact flex values of Filament's own vertical alignments. Notification content, role="status",
    Close button, timing and focus are not touched. If Filament no longer renders .fi-no, nothing happens.
--}}
<style data-tn-admin-notification-focus>
html[data-tn-no-edge="bottom"] .fi-no.fi-vertical-align-start { flex-direction: column; justify-content: flex-end; }
html[data-tn-no-edge="top"] .fi-no.fi-vertical-align-end { flex-direction: column-reverse; justify-content: flex-end; }
</style>
<script data-tn-admin-notification-focus>
(function () {
    if (window.__tnAdminNotificationFocus) {
        return;
    }
    window.__tnAdminNotificationFocus = true;

    var root = document.documentElement;

    var notifications = function () {
        var list = [];
        var nodes = document.querySelectorAll('.fi-no .fi-no-notification');
        for (var i = 0; i < nodes.length; i++) {
            var rect = nodes[i].getBoundingClientRect();
            // A closed notification stays in the stack as visibility: hidden until Livewire removes it.
            if (rect.width > 0 && rect.height > 0 && window.getComputedStyle(nodes[i]).visibility !== 'hidden') {
                list.push(rect);
            }
        }
        return list;
    };

    var check = function () {
        var list = notifications();
        if (! list.length) {
            // Nothing on screen: back to the placement configured through Filament.
            if (root.hasAttribute('data-tn-no-edge')) {
                root.removeAttribute('data-tn-no-edge');
            }
            return;
        }
        var active = document.activeElement;
        if (! active || active === document.body || active === root || ! (active instanceof Element) || active.closest('.fi-no')) {
            return;
        }
        // Only the part of the element inside the viewport can be covered (a tall editor may extend past it).
        var box = active.getBoundingClientRect();
        var r = {
            left: Math.max(box.left, 0),
            right: Math.min(box.right, window.innerWidth),
            top: Math.max(box.top, 0),
            bottom: Math.min(box.bottom, window.innerHeight),
        };
        if (r.right <= r.left || r.bottom <= r.top) {
            return;
        }
        var overlaps = false;
        for (var i = 0; i < list.length; i++) {
            var n = list[i];
            if (r.left < n.right && r.right > n.left && r.top < n.bottom && r.bottom > n.top) {
                overlaps = true;
                break;
            }
        }
        if (! overlaps) {
            return;
        }
        var edge = (r.top + r.bottom) / 2 < window.innerHeight / 2 ? 'bottom' : 'top';
        if (root.getAttribute('data-tn-no-edge') !== edge) {
            root.setAttribute('data-tn-no-edge', edge);
        }
    };

    var queued = false;
    var schedule = function () {
        if (queued) {
            return;
        }
        queued = true;
        window.requestAnimationFrame(function () {
            queued = false;
            check();
        });
    };

    var observer = new MutationObserver(function () {
        check();
        schedule();
    });
    var observed = null;
    var bind = function () {
        var stack = document.querySelector('.fi-no');
        if (stack && stack !== observed) {
            observer.disconnect();
            observer.observe(stack, { childList: true });
            observed = stack;
        }
    };

    document.addEventListener('focusin', function () {
        bind();
        check();
        schedule();
    });
    // Focus moving into an iframe (the TinyMCE editor) fires no focusin here, only a blur of this window.
    window.addEventListener('blur', schedule);
    window.addEventListener('scroll', schedule, { passive: true, capture: true });
    window.addEventListener('resize', schedule, { passive: true });
    // A page swapped in after a save arrives with its notification already rendered and focus restored without a
    // focusin, so the new stack is bound and checked once it is in place.
    var start = function () {
        bind();
        check();
        schedule();
    };
    document.addEventListener('livewire:navigated', start);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>
