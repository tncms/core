{{--
    CORE-ADMIN-A11Y-R2 A12 (WCAG 2.1.1 / 2.4.3): Escape closes the open off-canvas sidebar below
    Filament's lg breakpoint (64rem) and returns focus to the "Expand sidebar" button that opened it.
    Emitted once before </body> through Filament's public PanelsRenderHook::BODY_END, for signed-in
    admin pages only. It acts only when every condition holds, so other Escape users are untouched:
      - the key is Escape and nobody already handled it (defaultPrevented);
      - the viewport is below 64rem and Filament's sidebar store reports the sidebar open;
      - no Filament modal or dropdown panel is open (they own Escape);
      - focus is not in a text field, textarea or contenteditable (they may use Escape);
      - focus is inside the sidebar, in the top bar, or on <body>.
    No DOM is written; it only calls Filament's own $store.sidebar.close() and moves focus.
--}}
<script data-tn-admin-sidebar-escape>
(function () {
    if (window.__tnAdminSidebarEscape) {
        return;
    }
    window.__tnAdminSidebarEscape = true;

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || event.defaultPrevented) {
            return;
        }
        if (! window.Alpine || window.matchMedia('(min-width: 64rem)').matches) {
            return;
        }
        var store = window.Alpine.store('sidebar');
        if (! store || ! store.isOpen || document.querySelector('.fi-modal-open')) {
            return;
        }
        var panelOpen = Array.prototype.some.call(document.querySelectorAll('.fi-dropdown-panel'), function (panel) {
            return getComputedStyle(panel).display !== 'none';
        });
        var target = event.target instanceof Element ? event.target : document.body;
        if (panelOpen || target.closest('.fi-dropdown-panel')) {
            return;
        }
        var textEntry = target.isContentEditable
            || target.matches('textarea, input:not([type=button]):not([type=submit]):not([type=reset]):not([type=checkbox]):not([type=radio])');
        var inScope = target === document.body
            || target.closest('.fi-main-sidebar') !== null
            || target.closest('.fi-topbar-ctn') !== null;
        if (textEntry || ! inScope) {
            return;
        }

        store.close();
        window.Alpine.nextTick(function () {
            var opener = document.querySelector('.fi-topbar-open-sidebar-btn');
            if (opener) {
                opener.focus();
            }
        });
    });
})();
</script>
