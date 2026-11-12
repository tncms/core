{{--
    CORE-H-Z1-REMEDIATION-R3, ER-6 (WCAG 1.4.13 Content on Hover or Focus). Filament's x-tooltip directive
    creates one Tippy instance per trigger (stored by Filament on the trigger as `__x_tippy`) with Tippy's
    defaults: not interactive, so the tooltip hides as soon as the pointer moves onto it, and no Escape
    handling, so it cannot be dismissed without moving the pointer or focus. For the signed-in admin:
      - hoverable: the first time a pointer or focus reaches a tooltip trigger (capture phase, before
        Tippy's own listeners on the trigger), that instance is made interactive (with a border wide
        enough to cross Tippy's 10px offset gap) and appended to <body>, so a card's overflow cannot
        clip it. Filament's own later setProps() calls (content, theme) merge and keep these props;
      - dismissible: Escape hides every visible tooltip. The listener is on document in the bubble phase,
        so the focused control handles the key first; only when a tooltip was visible is the key then
        stopped before window-level handlers, so the same press does not also close a dialog; the next
        Escape works as before;
      - persistent: Tippy keeps the tooltip until the pointer leaves the trigger and the tooltip, focus
        leaves the trigger, or Escape - unchanged.
    The content is unchanged; nothing is parsed or written as HTML. If Filament no longer exposes the
    instance, nothing happens (the tooltip keeps Filament's behaviour).
--}}
<script data-tn-admin-tooltip-a11y>
(function () {
    if (window.__tnAdminTooltipA11y) {
        return;
    }
    window.__tnAdminTooltipA11y = true;

    var prepare = function (event) {
        var target = event.target instanceof Element ? event.target : null;
        for (var node = target; node && node !== document; node = node.parentNode) {
            var instance = node.__x_tippy;
            if (instance && typeof instance.setProps === 'function') {
                if (! instance.__tnA11y) {
                    instance.__tnA11y = true;
                    instance.setProps({
                        interactive: true,
                        interactiveBorder: 12,
                        appendTo: function () {
                            return document.body;
                        },
                    });
                }
                return;
            }
        }
    };
    document.addEventListener('mouseover', prepare, true);
    document.addEventListener('focusin', prepare, true);

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var roots = document.querySelectorAll('[data-tippy-root]');
        var hidden = false;
        for (var i = 0; i < roots.length; i++) {
            var instance = roots[i]._tippy;
            if (instance && instance.state && instance.state.isVisible) {
                instance.hide();
                hidden = true;
            }
        }
        if (hidden) {
            event.stopPropagation();
        }
    });
})();
</script>
