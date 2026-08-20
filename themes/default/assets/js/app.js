/*
 * Default Theme — app.js
 * Progressive enhancement for the site navigation. No dependencies, no framework.
 *
 * Adds `js` to <html> so CSS switches from the pure-hover dropdown fallback to
 * JS-driven hover intent. Handles: the mobile hamburger, submenu toggles
 * (inline accordion on mobile, hover intent on desktop), aria-expanded state,
 * Escape to close, and outside-click to close. Desktop keyboard users open
 * dropdowns via CSS :focus-within, so Tab order works without extra JS.
 */
(function () {
    'use strict';

    var HOVER_CLOSE_DELAY = 160; // ms — hover intent, avoids flicker on quick exits
    var DESKTOP = '(min-width: 768px)';

    document.documentElement.classList.add('js');

    function isDesktop() {
        return window.matchMedia(DESKTOP).matches;
    }

    function initNav(nav) {
        var toggle = document.querySelector('[data-nav-toggle]');
        var items = Array.prototype.slice.call(nav.querySelectorAll('.menu__item.has-children'));

        function closeMobileNav() {
            if (toggle && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('menu-open');
            }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                var open = nav.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', String(open));
                document.body.classList.toggle('menu-open', open);
            });
        }

        items.forEach(function (item) {
            var btn = item.querySelector(':scope > .menu__toggle');
            if (!btn) {
                return;
            }
            var closeTimer = null;

            function open() {
                if (closeTimer) {
                    clearTimeout(closeTimer);
                    closeTimer = null;
                }
                item.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
            }

            function close() {
                item.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            }

            // Click: accordion on mobile, explicit open/close on desktop.
            btn.addEventListener('click', function () {
                if (item.classList.contains('is-open')) {
                    close();
                } else {
                    open();
                }
            });

            // Hover intent (desktop only): open at once, delay the close.
            item.addEventListener('mouseenter', function () {
                if (isDesktop()) {
                    open();
                }
            });
            item.addEventListener('mouseleave', function () {
                if (isDesktop()) {
                    closeTimer = setTimeout(close, HOVER_CLOSE_DELAY);
                }
            });

            // Keyboard: mirror focus into aria-expanded on desktop.
            item.addEventListener('focusin', function () {
                if (isDesktop()) {
                    open();
                }
            });
            item.addEventListener('focusout', function (event) {
                if (isDesktop() && !item.contains(event.relatedTarget)) {
                    close();
                }
            });

            item._closeSubmenu = close;
        });

        function closeAllSubmenus(focusToggle) {
            items.forEach(function (item) {
                if (!item.classList.contains('is-open')) {
                    return;
                }
                if (item._closeSubmenu) {
                    item._closeSubmenu();
                }
                if (focusToggle) {
                    var btn = item.querySelector(':scope > .menu__toggle');
                    if (btn) {
                        btn.focus();
                    }
                }
            });
        }

        // Escape closes open dropdowns and the mobile drawer.
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' && event.key !== 'Esc') {
                return;
            }
            closeAllSubmenus(true);
            closeMobileNav();
        });

        // Click outside the nav closes everything.
        document.addEventListener('click', function (event) {
            var insideNav = nav.contains(event.target);
            var insideToggle = toggle && toggle.contains(event.target);
            if (!insideNav && !insideToggle) {
                closeAllSubmenus(false);
                closeMobileNav();
            }
        });
    }

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var nav = document.querySelector('[data-nav]');
        if (nav) {
            initNav(nav);
        }
    });
})();
