{{--
    CORE-H-Z1-REMEDIATION-R1 H-N6 (WCAG 1.3.1 / 4.1.2, axe `label`): name the key and value inputs of
    Filament's KeyValue field.

    Root cause (vendor, Filament 5.6.6 key-value.blade.php): each row renders two text inputs with no
    label, no id and (unless the field sets one) an empty placeholder; the column headers "Key" /
    "Value" are <th scope="col"> elements, which do not name form controls.

    Integration (no vendor edit): every KeyValue gets the Core Alpine hook `x-tn-key-value-a11y`
    through Filament's public KeyValue::configureUsing() + extraAlpineAttributes() API (boot() in
    AdminPanelProvider). This directive gives the two column headers a generated id and points each
    row's inputs at their header with aria-labelledby, so the visible header text is the name. Rows
    are rendered by Alpine (x-for) inside Filament's wire:ignore region, so Livewire never morphs
    them; the directive re-applies through an Alpine effect whenever the rows change (add,
    delete, state sync). No listener, no observer, no other attribute. Emitted once before </body>
    for signed-in admin pages only.
--}}
<script data-tn-admin-key-value-a11y>
(function () {
    if (window.__tnAdminKeyValueA11y) {
        return;
    }
    window.__tnAdminKeyValueA11y = true;

    var counter = 0;

    function columns(table) {
        return Array.prototype.filter.call(table.querySelectorAll('thead th[scope="col"]'), function (th) {
            return ! th.classList.contains('fi-has-action');
        });
    }

    function label(table, heads) {
        Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
            var cells = Array.prototype.filter.call(row.children, function (cell) {
                return ! cell.classList.contains('fi-has-action');
            });
            cells.forEach(function (cell, index) {
                var input = cell.querySelector('input.fi-input');
                if (input && heads[index] && input.getAttribute('aria-labelledby') !== heads[index].id) {
                    input.setAttribute('aria-labelledby', heads[index].id);
                }
            });
        });
    }

    function register(Alpine) {
        Alpine.directive('tn-key-value-a11y', function (el, directive, utilities) {
            var table = el.querySelector('table.fi-fo-key-value-table');
            var heads = table ? columns(table) : [];
            if (heads.length !== 2) {
                el.setAttribute('data-tn-key-value-a11y', 'contract-mismatch');
                if (window.console) {
                    window.console.warn('TN CMS H-N6: Filament key-value internals changed; the input names were not applied.');
                }
                return;
            }
            var prefix = 'tn-key-value-' + (++counter);
            heads[0].id = heads[0].id || prefix + '-key';
            heads[1].id = heads[1].id || prefix + '-value';

            utilities.effect(function () {
                var data = Alpine.$data(el);
                // Reading the rows subscribes this effect to rows being added, removed or replaced
                // (a state sync that swaps the array keeps the count but may re-render the inputs).
                if (data && data.rows) {
                    data.rows.forEach(function () {});
                }
                Alpine.nextTick(function () {
                    if (table.isConnected) {
                        label(table, heads);
                    }
                });
            });
        });
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
