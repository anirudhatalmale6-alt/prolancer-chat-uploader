/**
 * Create / Edit Service — form behaviour.
 * ----------------------------------------------------------------------------
 *   1. Numbers only in the price/revision fields, and no spinner arrows.
 *   2. A working delete icon on every Additional Service block.
 *
 * No jQuery. Delegated, so blocks added after page load behave the same as the
 * ones that were there to begin with.
 */
(function (window, document) {
    'use strict';

    // --------------------------------------------------- 1. numeric fields

    /**
     * The fields carry data-num (the template swaps type="number" for
     * type="text", which is what removes the spinners). This is what keeps
     * non-numbers out — of typing AND of pasting, which is the half a plain
     * type="number" never handled anyway.
     *
     * Kept permissive on purpose: digits and ONE decimal point. Not a currency
     * parser — the server is what decides what a valid price is.
     */
    function clean(value) {
        // Strip everything that is not a digit or a dot.
        var v = String(value).replace(/[^\d.]/g, '');

        // Collapse extra dots: "1.2.3" -> "1.23"
        var parts = v.split('.');
        if (parts.length > 2) {
            v = parts.shift() + '.' + parts.join('');
        }

        return v;
    }

    function isNumField(el) {
        return el && el.hasAttribute && el.hasAttribute('data-num');
    }

    // `input` covers typing, pasting, drag-drop and autofill in one go — there
    // is no need to listen for each separately.
    document.addEventListener('input', function (e) {
        if (!isNumField(e.target)) { return; }

        var before = e.target.value;
        var after = clean(before);

        if (before === after) { return; }

        // Keep the caret where the user left it, rather than throwing it to the
        // end every time they type a stray character.
        var pos = e.target.selectionStart - (before.length - after.length);

        e.target.value = after;

        if (e.target.setSelectionRange) {
            try {
                e.target.setSelectionRange(pos, pos);
            } catch (err) { /* not all inputs support it */ }
        }
    });

    // ------------------------------------------ 2. delete an Additional Service

    /**
     * WHY THIS EXISTS
     *
     * The block the server sends back when you press "Add Extra Service" carries
     * a WordPress ADMIN icon:
     *
     *     <i class="dashicons dashicons-trash"></i>
     *
     * dashicons is not loaded on the front end, so it renders as nothing at all
     * — an invisible, unclickable element. The FAQ block sends a Font Awesome
     * icon instead, which IS loaded. That is the whole reason FAQ has a working
     * delete and Additional Service does not.
     *
     * Rather than patch the icon in after the fact, the row is removed by a
     * DELEGATED click on either icon. It works for blocks that were on the page
     * at load, blocks added by the plugin's own AJAX, and any added later —
     * without the plugin having to rebind anything.
     *
     * The stylesheet turns the dashicon into the same trash glyph FAQ uses, so
     * the two look identical, which is what the client asked for.
     */
    var DELETE_ICONS = [
        '.additional-services .fa-trash',
        '.additional-services .fa-trash-alt',
        '.additional-services .dashicons-trash',
        '.faqs .fa-trash',
        '.faqs .fa-trash-alt',
        '.faqs .dashicons-trash'
    ].join(',');

    // FAQ is covered too, even though the plugin already binds it. The plugin
    // rebinds its handler after each AJAX insert, so a delete only works if that
    // rebinding ran — this does not depend on it, and it means both lists behave
    // identically, which is what was asked for. If both handlers fire on the same
    // row the second is a no-op: the row has no parentNode by then.
    document.addEventListener('click', function (e) {
        var icon = e.target.closest(DELETE_ICONS);

        if (!icon) { return; }

        e.preventDefault();

        // .row is the block. Walk up to it rather than assuming a fixed depth —
        // the server-rendered block and the AJAX one nest differently.
        var row = icon.closest('.row');

        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }
    });

}(window, document));
