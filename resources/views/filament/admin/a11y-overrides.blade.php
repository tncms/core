{{--
    Core admin accessibility overrides (CORE-RELEASE-BLOCKERS-R1-CLOSURE). Emitted once in <head>
    through Filament's public PanelsRenderHook::HEAD_END. No vendor file is edited; every rule is
    scoped to a Core class or to one Filament state, so nothing else in the panel changes.

    A11 (WCAG 2.4.3 / 2.4.7): below Filament's lg breakpoint (64rem) the CLOSED main sidebar is
        translated off screen but stays in the Tab order (~30 invisible stops). visibility:hidden
        removes it from the Tab order and the accessibility tree only while it is closed; Filament's
        own `transition-property: all` keeps it visible until the slide-out finishes. At 64rem and
        above, and whenever the sidebar is open, Filament's behaviour is unchanged.
    A5 (WCAG 1.4.3 + 1.4.1): Core's own text links. Amber-600 measured 3.2:1; Amber-700 on white
        and Amber-400 on the dark panel meet 4.5:1 (doc 05). The underline is the non-colour cue.
    A6 follow-up (WCAG 1.4.3): the "Not published" placeholder in Core's Published-at cells. Filament
        draws placeholders in gray-400 (2.62:1 light) / gray-500 (dark); gray-600 / gray-400 meet 4.5:1.

    CORE-ADMIN-A11Y-R2 (CORE-REMAINING-DEFECTS-REMEDIATION-R1):
    A10 (WCAG 2.5.8): the select "Clear selection" button was a 16x16 hit area. It becomes at least
        24x24 CSS px around the same centre (Filament places it 2rem from the end edge; a 1.5rem box
        at 1.75rem keeps the icon where it was) and gets a visible keyboard focus outline.
    A13 (WCAG 2.4.7): sidebar items, group triggers and the notifications button showed focus only
        as the hover background. A 2px outline, inset so the sidebar never clips it, is added.
    V-2 (WCAG 2.4.7): Filament links (dashboard Documentation / GitHub) showed focus only as the
        hover underline. A 2px outline is added on :focus-visible, so focus differs from hover.
    A10b (WCAG 2.5.8, found in the R2 audit): a multi-select value badge's "Remove ..." button was
        20x20. It gets a 24x24 minimum; Filament's own negative block margin keeps the badge height.
    A5b (WCAG 1.4.3, found in the R2 audit): multi-select value badges use Filament's 600 text shade
        (Amber-600 on Amber-50 measured 3.09:1). In light mode Core uses the 700 shade of the same
        badge colour; dark mode keeps Filament's own 200 shade.
    V-1 (WCAG 2.4.11): Filament's sticky top bar is 4rem tall at every width; with no scroll
        padding the browser could scroll a focused control under it (400% zoom). The padding is in
        rem, so it scales with zoom and text size together with the bar.

    CORE-A14-FINAL-CLOSURE-R1:
    A14 (WCAG 4.1.2): select-a11y.blade.php moves a multiple select's value container out of the
        trigger button and marks Filament's container .tn-select-a11y-detached. Only behind that
        marker: the trigger covers the field (absolute, inset 0) and keeps Filament's chevron; the
        values sit above it with Filament's own padding and type size and let pointer events fall
        through to the trigger, except the "Remove" buttons (and the badges of a reorderable
        select, so dragging keeps working). Without the marker Filament's layout is unchanged.

    CORE-H-Z1-REMEDIATION-R1:
    H-N5 (WCAG 1.4.3): Filament's danger buttons (Delete, Delete selected, dialog confirm) draw white
        14px / 500 text - normal text - on danger-600 (4.77:1) and lighten to danger-500 on hover
        (3.81:1, light and dark). Only for a danger button whose hover text stays white, the hover
        background uses the 700 shade of the same palette instead, which darkens on hover like
        Filament's other states and keeps white text above 4.5:1. Default, focus and every other
        colour are unchanged.

    CORE-H-Z1-REMEDIATION-R3:
    ER-1 (WCAG 1.4.11): Filament draws the boundary of text inputs, selects, textareas
        (.fi-input-wrp) and unchecked checkboxes as a 1px ring of gray-950 at 10% (light, 1.5:1) /
        white at 20% (dark, 1.9:1), and the off switch track as gray-200 (1.27:1). Enabled controls get
        a gray-500 (light) / gray-400 (dark) ring, and the off track becomes gray-500 (light) or gets a
        gray-400 border (dark), so the boundary and the knob position reach 3:1. Focus, invalid,
        checked and disabled states keep Filament's own colours (disabled controls are exempt).
    ER-2 (WCAG 2.4.7): Filament moves keyboard focus onto a select option with the arrow keys but
        marks it only with a gray-50 background (1.04:1), and the user-menu theme buttons set
        outline: none. Both get the 2px primary outline used by the other Core focus rings, on
        :focus-visible only, so a mouse hover or the selected value is not drawn as focus.
    M6 (WCAG 2.1.1 / 2.4.7, CORE-40-H-Z1-M5-M6-CLOSURE-R1): the XML Sitemap page's horizontally
        scrolling table containers become a Tab stop only while they overflow (see that view). When
        focused they get the same 2px primary outline, inset so the container's own overflow does
        not clip it.
--}}
<style data-tn-admin-a11y>
@media not all and (min-width: 64rem) {
    .fi-sidebar.fi-main-sidebar:not(.fi-sidebar-open) { visibility: hidden; }
}
.tn-admin-link,
.tn-admin-link:visited {
    color: var(--primary-700);
    font-weight: 500;
    text-decoration-line: underline;
    text-underline-offset: 2px;
}
.tn-admin-link:hover { color: var(--primary-800); text-decoration-thickness: 2px; }
.tn-admin-link:focus-visible { outline: 2px solid var(--primary-700); outline-offset: 2px; border-radius: 2px; }
.dark .tn-admin-link,
.dark .tn-admin-link:visited { color: var(--primary-400); }
.dark .tn-admin-link:hover { color: var(--primary-300); }
.dark .tn-admin-link:focus-visible { outline-color: var(--primary-400); }
.tn-admin-placeholder .fi-ta-placeholder { color: var(--gray-600); }
.dark .tn-admin-placeholder .fi-ta-placeholder { color: var(--gray-400); }
html { scroll-padding-top: 4.5rem; }
.fi-select-input .fi-select-input-value-remove-btn {
    min-width: 1.5rem;
    min-height: 1.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    inset-inline-end: 1.75rem;
    border-radius: 0.375rem;
}
.fi-select-input .fi-select-input-value-remove-btn:focus-visible { outline: 2px solid var(--primary-700); outline-offset: 0; }
.dark .fi-select-input .fi-select-input-value-remove-btn:focus-visible { outline-color: var(--primary-400); }
.fi-sidebar .fi-sidebar-item-btn:focus-visible,
.fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:focus-visible,
.fi-sidebar .fi-sidebar-database-notifications-btn:focus-visible { outline: 2px solid var(--primary-700); outline-offset: -2px; }
.dark .fi-sidebar .fi-sidebar-item-btn:focus-visible,
.dark .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:focus-visible,
.dark .fi-sidebar .fi-sidebar-database-notifications-btn:focus-visible { outline-color: var(--primary-400); }
.fi-select-input-value-badges-ctn .fi-badge-delete-btn {
    min-width: 1.5rem;
    min-height: 1.5rem;
}
.fi-select-input-value-badges-ctn .fi-badge-delete-btn:focus-visible { outline: 2px solid var(--primary-700); outline-offset: 0; border-radius: 0.25rem; }
.dark .fi-select-input-value-badges-ctn .fi-badge-delete-btn:focus-visible { outline-color: var(--primary-400); }
html:not(.dark) .fi-select-input-value-badges-ctn .fi-badge.fi-text-color-600 { --text: var(--color-700); }
.fi-link:focus-visible { outline: 2px solid var(--primary-700); outline-offset: 2px; border-radius: 0.25rem; }
.dark .fi-link:focus-visible { outline-color: var(--primary-400); }
.fi-select-input-ctn.tn-select-a11y-detached > .fi-select-input-btn { position: absolute; inset: 0; }
.fi-select-input-ctn.tn-select-a11y-detached > .fi-select-input-value-ctn {
    position: relative;
    min-height: 2.25rem;
    padding-block: 0.375rem;
    padding-inline: 0.75rem 2rem;
    font-size: 0.875rem;
    line-height: 1.5rem;
    pointer-events: none;
}
.fi-select-input-ctn.tn-select-a11y-detached > .fi-select-input-value-ctn .fi-badge-delete-btn { pointer-events: auto; }
.fi-select-input-ctn.tn-select-a11y-reorderable > .fi-select-input-value-ctn .fi-select-input-value-badges-ctn { pointer-events: auto; }
.fi-select-input-ctn.tn-select-a11y-detached > .fi-select-input-btn.fi-disabled + .fi-select-input-value-ctn { opacity: 0.7; }
.fi-btn.fi-color-danger.hover\:fi-bg-color-500.hover\:fi-text-color-0 { --hover-bg: var(--color-700); }
.fi-btn.fi-color-danger.dark\:hover\:fi-bg-color-500.dark\:hover\:fi-text-color-0 { --dark-hover-bg: var(--color-700); }
.fi-input-wrp:not(.fi-disabled):not(.fi-invalid),
.fi-checkbox-input:not(:checked):not(:disabled):not(:focus):not(.fi-invalid) { --tw-ring-color: var(--gray-500); }
.dark .fi-input-wrp:not(.fi-disabled):not(.fi-invalid),
.dark .fi-checkbox-input:not(:checked):not(:disabled):not(:focus):not(.fi-invalid) { --tw-ring-color: var(--gray-400); }
html:not(.dark) .fi-toggle.fi-toggle-off:not(:disabled) { background-color: var(--gray-500); }
.dark .fi-toggle.fi-toggle-off:not(:disabled) { border-color: var(--gray-400); }
.fi-select-input-option:focus-visible,
.fi-theme-switcher-btn:focus-visible { outline: 2px solid var(--primary-700); outline-offset: -2px; }
.dark .fi-select-input-option:focus-visible,
.dark .fi-theme-switcher-btn:focus-visible { outline-color: var(--primary-400); }
.tn-scroll-region:focus-visible { outline: 2px solid var(--primary-700); outline-offset: -2px; border-radius: 0.25rem; }
.dark .tn-scroll-region:focus-visible { outline-color: var(--primary-400); }
</style>
