{{--
    CORE-H-Z1-REMEDIATION-R1 H-N4 (WCAG 1.3.1 / 4.1.2; practice for 3.3.1): associate a server
    validation error with the field it belongs to.

    Root cause (vendor, Filament 5.6.6 field-wrapper.blade.php): the error text is rendered as a
    [data-validation-error] element with no id, and the control gets neither aria-invalid nor
    aria-describedby, so assistive technology announces the field without its error.

    Integration (no vendor edit): after every Livewire morph (and once at start) each Filament field
    wrapper (.fi-fo-field) that shows its own [data-validation-error] is resolved to its control through
    the wrapper's <label for> - the association Filament already declares - and only when that control
    is a native input, textarea or select inside the same wrapper. The error element keeps an id it
    already has, or gets a stable id derived from the control id; the control gets aria-invalid="true"
    plus that id appended to its existing aria-describedby tokens. When the error is gone, only what
    this script added is removed.
    File uploads resolve through FilePond's own <label for> on its browse input.
    CORE-H-Z1-REMEDIATION-R2 adds Filament's JavaScript select (its trigger button). Those are the only
    non-native widgets that can enter a server invalid state from the Core Admin UI (doc r2/03 lists the
    rich editor, key-value, tags, repeater and markdown fields and why they cannot). Other widgets are
    left untouched. The server markup is authoritative on every morph; this re-applies after it.
    No event is intercepted. Emitted once before </body> for signed-in admin pages only.
--}}
<script data-tn-admin-field-errors>
(function () {
    if (window.__tnAdminFieldErrors) {
        return;
    }
    window.__tnAdminFieldErrors = true;

    var SUFFIX = '-tn-error';
    var marked = new Set();

    function tokens(el) {
        var value = el.getAttribute('aria-describedby');
        return value ? value.split(/\s+/).filter(Boolean) : [];
    }

    // Returns { control, key }: the element that receives focus for the field, and the id the error id is
    // derived from.
    function controlFor(wrapper) {
        var labels = wrapper.querySelectorAll('label[for]');
        var fieldId = null;
        for (var i = 0; i < labels.length; i++) {
            if (labels[i].closest('.fi-fo-field') !== wrapper) {
                continue;
            }
            var control = document.getElementById(labels[i].getAttribute('for'));
            if (control && wrapper.contains(control) && control.matches('input:not([type=hidden]), textarea, select')) {
                return { control: control, key: control.id };
            }
            fieldId = fieldId || labels[i].getAttribute('for');
        }
        // CORE-H-Z1-REMEDIATION-R2 (H-N4 residual): Filament's JavaScript select (searchable, multiple,
        // or native(false)) has no element with the label's id; its focusable control is the trigger
        // button the select script creates inside its wire:ignore region (the A14 / O-N10 integration
        // names it after the label). The error id is derived from the label's `for` value.
        var triggers = wrapper.querySelectorAll('.fi-select-input-btn');
        for (var j = 0; j < triggers.length; j++) {
            if (triggers[j].closest('.fi-fo-field') === wrapper && fieldId) {
                return { control: triggers[j], key: fieldId };
            }
        }
        return null;
    }

    function unmark(control) {
        var errorId = control.getAttribute('data-tn-error-ref') || control.id + SUFFIX;
        var rest = tokens(control).filter(function (token) { return token !== errorId; });
        control.removeAttribute('data-tn-error-ref');
        if (rest.length) {
            control.setAttribute('aria-describedby', rest.join(' '));
        } else {
            control.removeAttribute('aria-describedby');
        }
        control.removeAttribute('aria-invalid');
        marked.delete(control);
    }

    function mark(control, key, error) {
        // An id the error element already carries (e.g. from a future Filament release) is kept and used.
        var errorId = error.id || key + SUFFIX;
        if (! error.id) {
            error.id = errorId;
        }
        control.setAttribute('data-tn-error-ref', errorId);
        var list = tokens(control);
        if (list.indexOf(errorId) === -1) {
            list.push(errorId);
            control.setAttribute('aria-describedby', list.join(' '));
        }
        if (control.getAttribute('aria-invalid') !== 'true') {
            control.setAttribute('aria-invalid', 'true');
        }
        marked.add(control);
    }

    function sync() {
        var current = new Set();
        var errors = document.querySelectorAll('.fi-fo-field [data-validation-error]');
        for (var i = 0; i < errors.length; i++) {
            var wrapper = errors[i].closest('.fi-fo-field');
            var found = wrapper ? controlFor(wrapper) : null;
            if (found && found.key && ! current.has(found.control)) {
                mark(found.control, found.key, errors[i]);
                current.add(found.control);
            }
        }
        marked.forEach(function (control) {
            if (! current.has(control)) {
                if (control.isConnected) {
                    unmark(control);
                } else {
                    marked.delete(control);
                }
            }
        });
    }

    function hookLivewire(Livewire) {
        if (! Livewire || typeof Livewire.hook !== 'function') {
            return false;
        }
        Livewire.hook('morphed', sync);
        // CORE-H-Z1-REMEDIATION-R2: Filament re-renders schema parts (e.g. an action dialog's form after a
        // validation error) through its own partial renderer (support/resources/js/partials.js), which
        // calls Alpine.morph directly, so Livewire's `morphed` hook does not fire for them. Livewire's public
        // message interceptor does; the handler runs on the next task, after Filament's microtask morph.
        if (typeof Livewire.interceptMessage === 'function') {
            Livewire.interceptMessage(function (message) {
                message.onSuccess(function () {
                    window.setTimeout(sync, 0);
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
    sync();
})();
</script>
