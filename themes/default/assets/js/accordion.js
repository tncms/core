/*
 * Default Theme — accordion.js
 * Progressive enhancement for the FAQ accordion. No dependencies, no framework.
 *
 * Without JS: every panel is visible (content is fully readable).
 * With JS: panels collapse, the trigger gets aria-expanded, and clicking toggles
 * the matching panel. With data-allow-multiple absent, opening one closes others.
 * Triggers are native <button>s, so keyboard (Enter/Space) and focus work for free.
 */
(function () {
    'use strict';

    function init(root) {
        var allowMultiple = root.hasAttribute('data-allow-multiple');
        var triggers = Array.prototype.slice.call(root.querySelectorAll('.accordion__trigger'));

        triggers.forEach(function (trigger) {
            var panel = document.getElementById(trigger.getAttribute('aria-controls'));

            if (!panel) {
                return;
            }

            // Collapse by default once JS is available.
            trigger.setAttribute('aria-expanded', 'false');
            panel.hidden = true;

            trigger.addEventListener('click', function () {
                var isOpen = trigger.getAttribute('aria-expanded') === 'true';

                if (!allowMultiple && !isOpen) {
                    triggers.forEach(function (other) {
                        if (other === trigger) {
                            return;
                        }
                        var otherPanel = document.getElementById(other.getAttribute('aria-controls'));
                        other.setAttribute('aria-expanded', 'false');
                        if (otherPanel) {
                            otherPanel.hidden = true;
                        }
                    });
                }

                trigger.setAttribute('aria-expanded', String(!isOpen));
                panel.hidden = isOpen;
            });
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
        Array.prototype.forEach.call(document.querySelectorAll('[data-accordion]'), init);
    });
})();
