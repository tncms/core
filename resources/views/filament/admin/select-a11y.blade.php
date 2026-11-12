{{--
    CORE-A14-FINAL-CLOSURE-R1, A14 (WCAG 4.1.2, axe nested-interactive). Filament's multiple select
    draws its value badges, each with an interactive "Remove <label>" <button>, inside the trigger
    <button class="fi-select-input-btn">. This Core Alpine directive runs only on selects carrying
    the Core hook `x-tn-select-a11y` (added to every Filament Select by AdminPanelProvider::boot()
    through Filament's public configureUsing() / extraAlpineAttributes() APIs) and, once per
    component instance:
      - moves Filament's value container (select.selectedDisplay) out of the trigger so it becomes
        the trigger's next sibling inside Filament's own container. Filament keeps the same element
        reference, so its badge, search, add, remove, clear, disable and destroy code is unchanged
        (Filament already places the single-select "Clear selection" button there);
      - names the trigger after the field <label> (aria-labelledby) and describes it with the
        selected values (aria-describedby); marks it aria-haspopup=listbox / aria-controls;
      - after a badge is removed, returns focus to the next remaining "Remove" button, or to the
        trigger, if Filament left focus on <body>.
    CORE-H-Z1-REMEDIATION-R1, H-C1: the field <label> lives outside Filament's wire:ignore region, so
    every Livewire re-render (e.g. a server-side validation error) morphs it back to the server HTML,
    which has no id. The trigger's aria-labelledby then pointed at a missing id and the trigger lost
    its name. Each detached select is therefore kept in a registry (removed by Alpine's cleanup) and
    re-linked - idempotently - after Livewire's public `morphed` / `island.morphed` hooks, only for
    selects inside the morphed component. One pair of hooks is registered per page.
    CORE-H-Z1-REMEDIATION-R1, H-N9 (WCAG 2.4.3): Escape on an open select inside a modal closed the whole
    modal and dropped focus to <body>. Filament's select closes its own dropdown on Escape without stopping
    propagation, and its guard (`select.dropdown.isActive && $event.stopPropagation()`) can never fire:
    `dropdown` is the panel element (no isActive), and by the time the event bubbles to that guard the
    dropdown is already closed. So the modal's window-level Escape handler always ran. For every Filament
    select (single or multiple) the directive records, in the capture phase on the select's own root
    element, whether its dropdown was open, and in the bubble phase on the same element stops the event
    only if it was. The select itself still handles the key (closes, keeps focus on the trigger); a second
    Escape reaches the modal as normal. Nothing outside the select's root is listened to.
    Single selects contain no nested control, so nothing is moved; since CORE-H-Z1-REMEDIATION-R2 (O-N10)
    their trigger is named by the field label as well (see nameSingle). No HTML is parsed or written,
    no global DOM API is patched, nothing is hidden from assistive technology, and listeners are
    attached to the component's own value container and removed by Alpine's cleanup.
    CORE-H-Z1-PRE-RERUN-RESIDUAL-CLOSURE-R1, L-10 (WCAG 1.3.1 / 4.1.2, axe aria-required-children /
    aria-required-parent / list / aria-input-field-name): Filament puts role="listbox" on the dropdown panel,
    but the options are <li role="option"> inside a plain <ul> (an implicit list) and, when searchable, next to
    the search <input>; the listbox had no name. Browsers exposed each option as a list item with no selected
    state. See structureListbox: the panel becomes a group, the persistent options <ul> Filament keeps in
    select.optionsList becomes the named listbox (aria-multiselectable moves with it), and option groups get
    group semantics. Filament re-renders the options inside that <ul> on open, search and option loads through
    the instance's renderOptions(); that one instance method is wrapped (and restored on cleanup) so the roles
    are re-applied after each render. Filament selects options by class and tag, never by role.
    CORE-H-Z1-REMEDIATION-R3, ER-5 (WCAG 3.1.2): a Core language select (Settings, the content "Language"
    fields) lists native language names - text in another language than the interface. Core puts the codes
    whose label is a native name in data-tn-option-lang on the select root; langParts then sets lang=<code>
    on each Filament option whose data-value is one of them (after every renderOptions(), like L-10), and on
    the single select's shown value: the instance's addSingleSelectionDisplay() is wrapped the same way
    (restored on cleanup) and marks the label it has just added. Values are only ever compared with that
    list, never parsed or written as HTML.
    If Filament's internals no longer match, nothing is moved and the element is marked
    data-tn-select-a11y="contract-mismatch" (tests pin the vendor files this relies on).
--}}
<script data-tn-admin-select-a11y>
(function () {
    if (window.__tnAdminSelectA11y) {
        return;
    }
    window.__tnAdminSelectA11y = true;

    var REMOVE = '.fi-badge-delete-btn';

    function mismatch(el) {
        el.setAttribute('data-tn-select-a11y', 'contract-mismatch');
        if (window.console) {
            window.console.warn('TN CMS A14: Filament select internals changed; the nested-interactive fix was not applied.');
        }
    }

    function labelIdFor(el, fieldId) {
        var wrapper = fieldId ? el.closest('[data-field-wrapper]') : null;
        if (! wrapper) {
            return null;
        }
        var label = Array.prototype.find.call(wrapper.querySelectorAll('label'), function (candidate) {
            return candidate.getAttribute('for') === fieldId;
        });
        if (! label) {
            return null;
        }
        if (! label.id) {
            if (document.getElementById(fieldId + '-label')) {
                return null;
            }
            label.id = fieldId + '-label';
        }

        return label.id;
    }

    var links = new Set();

    // CORE-H-Z1-REMEDIATION-R2: aria-describedby is shared with the H-N4 field-errors integration (it
    // appends the error id), so only this script's own token is added, never the whole value replaced.
    function addDescribedBy(trigger, id) {
        var list = (trigger.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (list.indexOf(id) === -1) {
            list.unshift(id);
            trigger.setAttribute('aria-describedby', list.join(' '));
        }
    }

    function relink(link) {
        if (! link.values.id) {
            link.values.id = link.valuesId;
        }
        var labelId = labelIdFor(link.el, link.fieldId);
        // Without a field label (none exists) a single select keeps Filament's own name from its content.
        if (! labelId && ! link.multiple) {
            return;
        }
        var labelledBy = labelId || link.values.id;
        if (link.trigger.getAttribute('aria-labelledby') !== labelledBy) {
            link.trigger.setAttribute('aria-labelledby', labelledBy);
        }
        if (labelId) {
            addDescribedBy(link.trigger, link.values.id);
        }
    }

    function onMorphed(payload) {
        var root = payload && (payload.el || (payload.component && payload.component.el));
        links.forEach(function (link) {
            if (link.trigger.isConnected && (! root || root.contains(link.el))) {
                relink(link);
            }
        });
    }

    function hookLivewire(Livewire) {
        if (! Livewire || typeof Livewire.hook !== 'function') {
            return false;
        }
        Livewire.hook('morphed', onMorphed);
        Livewire.hook('island.morphed', onMorphed);
        // CORE-H-Z1-REMEDIATION-R2: Filament re-renders schema parts (e.g. an action dialog's form after a
        // validation error) through its own partial renderer (support/resources/js/partials.js), which
        // calls Alpine.morph directly, so Livewire's `morphed` hook does not fire for them. Livewire's public
        // message interceptor does; the handler runs on the next task, after Filament's microtask morph.
        if (typeof Livewire.interceptMessage === 'function') {
            Livewire.interceptMessage(function (message) {
                message.onSuccess(function () {
                    window.setTimeout(function () { onMorphed(null); }, 0);
                });
            });
        }

        return true;
    }

    function guardEscape(el, utilities) {
        var wasOpen = false;
        var onCapture = function (event) {
            if (event.key === 'Escape') {
                var data = window.Alpine.$data(el);
                wasOpen = !! (data && data.select && data.select.isOpen);
            }
        };
        var onBubble = function (event) {
            if (event.key === 'Escape' && wasOpen) {
                wasOpen = false;
                event.stopPropagation();
            }
        };
        el.addEventListener('keydown', onCapture, true);
        el.addEventListener('keydown', onBubble, false);
        utilities.cleanup(function () {
            el.removeEventListener('keydown', onCapture, true);
            el.removeEventListener('keydown', onBubble, false);
        });
    }

    // CORE-H-Z1-REMEDIATION-R2, O-N10 (WCAG 4.1.2 / 2.4.6 / 3.3.2): a single select's trigger has no
    // name source but its own content, so it was named by the placeholder ("Select an option") or by the
    // chosen value - never by the field label, whose `for` points at an id no element carries. The trigger
    // is named by the label (aria-labelledby) and described by Filament's value container, which stays
    // inside it and shows the chosen option or the placeholder (aria-describedby), as A14 does for
    // multiple selects. Nothing is moved. The same registry re-links it after every morph (H-C1).
    function nameSingle(el, select, fieldId, utilities) {
        var trigger = select.selectButton;
        var values = select.selectedDisplay;
        if (! trigger || ! values || ! select.dropdownId) {
            mismatch(el);
            return;
        }
        if (values.parentNode !== trigger) {
            mismatch(el);
            return;
        }
        var link = { el: el, trigger: trigger, values: values, fieldId: fieldId, valuesId: select.dropdownId + '-values', multiple: false };
        relink(link);
        links.add(link);
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-controls', select.dropdownId);
        utilities.cleanup(function () {
            links.delete(link);
        });
    }

    // L-10: see the header. Idempotent: every write is skipped when the value is already there.
    function setAttr(node, name, value) {
        if (node.getAttribute(name) !== value) {
            node.setAttribute(name, value);
        }
    }

    // ER-5: see the header. Returns the option pass for structureListbox, or null for other selects.
    function langParts(el, select, utilities) {
        var codes = (el.getAttribute('data-tn-option-lang') || '').split(/\s+/).filter(Boolean);
        if (! codes.length) {
            return null;
        }
        var mark = function (node, value) {
            if (codes.indexOf(value) !== -1) {
                setAttr(node, 'lang', value);
            } else if (node.hasAttribute('lang')) {
                node.removeAttribute('lang');
            }
        };
        var shown = function (target) {
            var labels = target ? target.querySelectorAll('.fi-select-input-value-label') : [];
            if (labels.length && ! select.isMultiple) {
                mark(labels[labels.length - 1], select.state === null || select.state === undefined ? '' : String(select.state));
            }
        };
        var display = select.addSingleSelectionDisplay;
        if (typeof display === 'function' && ! display.__tnLang && ! select.isMultiple) {
            var wrappedDisplay = function (label, target) {
                var result = display.apply(this, arguments);
                shown(target || select.selectedDisplay);
                return result;
            };
            wrappedDisplay.__tnLang = true;
            select.addSingleSelectionDisplay = wrappedDisplay;
            utilities.cleanup(function () {
                if (select.addSingleSelectionDisplay === wrappedDisplay) {
                    select.addSingleSelectionDisplay = display;
                }
            });
        }
        shown(select.selectedDisplay);
        return function (list) {
            var options = list.querySelectorAll('li[data-value]');
            for (var i = 0; i < options.length; i++) {
                mark(options[i], options[i].getAttribute('data-value'));
            }
        };
    }

    function structureListbox(el, select, fieldId, utilities) {
        var dropdown = select.dropdown;
        var list = select.optionsList;
        var original = select.renderOptions;
        var role = dropdown ? dropdown.getAttribute('role') : null;
        if (original && original.__tnListbox) {
            return; // this instance is already wrapped (directive re-initialised on the same select)
        }
        if (! list || list.tagName !== 'UL' || typeof original !== 'function' || ! select.dropdownId || (role !== 'listbox' && role !== 'group')) {
            mismatch(el);
            return;
        }
        var listId = select.dropdownId + '-listbox';
        var langOptions = langParts(el, select, utilities);
        var apply = function () {
            if (langOptions) {
                langOptions(list);
            }
            setAttr(dropdown, 'role', 'group');
            setAttr(list, 'role', 'listbox');
            if (! list.id) {
                list.id = listId;
            }
            if (select.isMultiple) {
                dropdown.removeAttribute('aria-multiselectable');
                setAttr(list, 'aria-multiselectable', 'true');
            }
            var labelId = labelIdFor(el, fieldId);
            if (labelId) {
                setAttr(list, 'aria-labelledby', labelId);
            }
            // Grouped options: <li class="fi-select-input-option-group"> holds a header and a <ul> of options;
            // ungrouped options next to groups sit in their own <ul>. HTML allows role=group on <ul>, not on <li>,
            // so the inner <ul> is the group (named by its header) and the <li> wrapper is presentational.
            for (var i = 0; i < list.children.length; i++) {
                var child = list.children[i];
                if (child.tagName === 'UL') {
                    setAttr(child, 'role', 'none');
                } else if (child.classList.contains('fi-select-input-option-group')) {
                    setAttr(child, 'role', 'none');
                    var header = null;
                    var options = null;
                    for (var j = 0; j < child.children.length; j++) {
                        var part = child.children[j];
                        if (part.tagName === 'UL') {
                            options = part;
                        } else if (part.classList.contains('fi-dropdown-header')) {
                            header = part;
                        }
                    }
                    if (options) {
                        setAttr(options, 'role', 'group');
                        if (header) {
                            if (header.id !== listId + '-group-' + i) {
                                header.id = listId + '-group-' + i;
                            }
                            setAttr(options, 'aria-labelledby', header.id);
                        }
                    }
                }
            }
        };
        var wrapped = function () {
            var result = original.apply(this, arguments);
            apply();
            return result;
        };
        wrapped.__tnListbox = true;
        select.renderOptions = wrapped;
        apply();
        utilities.cleanup(function () {
            if (select.renderOptions === wrapped) {
                select.renderOptions = original;
            }
        });
    }

    function register(Alpine) {
        Alpine.directive('tn-select-a11y', function (el, directive, utilities) {
            var data = Alpine.$data(el);
            var select = data ? data.select : null;
            if (! select) {
                return;
            }
            guardEscape(el, utilities);
            structureListbox(el, select, directive.expression, utilities);
            if (! select.isMultiple) {
                nameSingle(el, select, directive.expression, utilities);
                return;
            }
            var container = select.container;
            var trigger = select.selectButton;
            var values = select.selectedDisplay;
            if (! container || ! trigger || ! values || ! select.dropdownId) {
                mismatch(el);
                return;
            }
            if (values.parentNode === container && container.classList.contains('tn-select-a11y-detached')) {
                return;
            }
            if (values.parentNode !== trigger || trigger.parentNode !== container) {
                mismatch(el);
                return;
            }

            container.insertBefore(values, trigger.nextSibling);
            container.classList.add('tn-select-a11y-detached');
            if (select.isReorderable) {
                container.classList.add('tn-select-a11y-reorderable');
            }

            var link = { el: el, trigger: trigger, values: values, fieldId: directive.expression, valuesId: select.dropdownId + '-values', multiple: true };
            relink(link);
            links.add(link);
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-controls', select.dropdownId);

            var onRemove = function (event) {
                var button = event.target instanceof Element ? event.target.closest(REMOVE) : null;
                if (! button || ! values.contains(button)) {
                    return;
                }
                if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                var index = Array.prototype.indexOf.call(values.querySelectorAll(REMOVE), button);
                window.setTimeout(function () {
                    var active = document.activeElement;
                    if (! trigger.isConnected || (active && active !== document.body && active.isConnected)) {
                        return;
                    }
                    var remaining = values.querySelectorAll(REMOVE);
                    var next = remaining.length ? remaining[Math.min(index, remaining.length - 1)] : trigger;
                    next.focus();
                }, 0);
            };
            values.addEventListener('click', onRemove, true);
            values.addEventListener('keydown', onRemove, true);

            utilities.cleanup(function () {
                links.delete(link);
                values.removeEventListener('click', onRemove, true);
                values.removeEventListener('keydown', onRemove, true);
            });
        });
    }

    if (! hookLivewire(window.Livewire)) {
        document.addEventListener('livewire:init', function () {
            if (! hookLivewire(window.Livewire) && window.console) {
                window.console.warn('TN CMS A14: Livewire.hook is unavailable; select names are not re-linked after re-renders.');
            }
        }, { once: true });
    }

    if (window.Alpine && typeof window.Alpine.directive === 'function') {
        register(window.Alpine);
    } else {
        document.addEventListener('alpine:init', function () {
            register(window.Alpine);
        });
    }
})();
</script>
