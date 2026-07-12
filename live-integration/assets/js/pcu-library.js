/**
 * Extras & FAQ library.
 * ----------------------------------------------------------------------------
 * Two jobs, one file:
 *
 *   1. On the library screens (Services -> Extras / FAQ): add, edit, tick,
 *      remove and save the seller's own list.
 *   2. On Create Service: show that library as a tick-list, so they can pull
 *      rows in instead of retyping them — while still being able to type brand
 *      new ones, which is exactly how the form already worked.
 *
 * No jQuery.
 */
(function (window, document) {
    'use strict';

    var CFG = window.PCU_LIB || {};

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html !== undefined) { n.innerHTML = html; }
        return n;
    }

    // ==================================================== the library screens

    var lib = document.querySelector('.pcu-lib');

    if (lib) {
        var which = lib.getAttribute('data-library');
        var isExtras = which === 'extras';
        var tbody = lib.querySelector('.pcu-lib-rows');
        var status = lib.querySelector('.pcu-lib-status');
        var allBox = lib.querySelector('.pcu-lib-all');
        var removeBtn = lib.querySelector('.pcu-lib-remove');

        /**
         * Put a row back into view mode, showing what was saved.
         *
         * The view text is written with textContent — never innerHTML. The title
         * is the seller's own typing, and the one place it must never be able to
         * become markup is the page that shows it back to them.
         */
        function toView(tr) {
            var title = tr.querySelector('.pcu-lib-title');
            var vTitle = tr.querySelector('.pcu-lib-view-title');

            if (vTitle && title) { vTitle.textContent = title.value; }

            if (isExtras) {
                var price = tr.querySelector('.pcu-lib-price-input');
                var vPrice = tr.querySelector('.pcu-lib-view-price');
                if (vPrice && price) {
                    vPrice.textContent = (CFG.currency || '$') + price.value;
                }
            } else {
                var desc = tr.querySelector('.pcu-lib-desc');
                var vDesc = tr.querySelector('.pcu-lib-view-desc');
                if (vDesc && desc) { vDesc.textContent = desc.value; }
            }

            tr.classList.remove('is-editing');
        }

        /**
         * A blank row, ready to type into.
         *
         * Built with the same two states as a saved row (a view and an edit
         * block) so that after it is saved it can simply flip to view like any
         * other — rather than being a special case that stays a form for ever.
         * It just starts in edit mode, because there is nothing to show yet.
         */
        function blankRow() {
            var tr = document.createElement('tr');
            tr.className = 'is-editing';

            var check = el('td', 'pcu-lib-check');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'pcu-lib-row-check';
            check.appendChild(cb);

            var main = document.createElement('td');

            var view = el('div', 'pcu-lib-view');
            view.appendChild(el('span', 'pcu-lib-view-title'));
            if (!isExtras) { view.appendChild(el('span', 'pcu-lib-view-desc')); }
            main.appendChild(view);

            var edit = el('div', 'pcu-lib-edit');
            var title = document.createElement('input');
            title.type = 'text';
            title.className = 'form-control pcu-lib-title';
            title.placeholder = isExtras ? 'Name of extra' : 'FAQ name';
            edit.appendChild(title);

            if (!isExtras) {
                var desc = document.createElement('textarea');
                desc.className = 'form-control pcu-lib-desc';
                desc.placeholder = 'Answer';
                edit.appendChild(desc);
            }
            main.appendChild(edit);

            tr.appendChild(check);
            tr.appendChild(main);

            if (isExtras) {
                var priceCell = el('td', 'pcu-lib-price');

                var pView = el('div', 'pcu-lib-view');
                pView.appendChild(el('span', 'pcu-lib-view-price'));
                priceCell.appendChild(pView);

                var pEdit = el('div', 'pcu-lib-edit');
                var group = el('div', 'input-group');
                var sym = el('span', 'input-group-text');
                sym.textContent = CFG.currency || '$';

                var price = document.createElement('input');
                price.type = 'text';
                price.setAttribute('inputmode', 'decimal');
                price.setAttribute('data-num', '1');
                price.className = 'form-control mb-0 pcu-lib-price-input';
                price.placeholder = 'Price';

                group.appendChild(sym);
                group.appendChild(price);
                pEdit.appendChild(group);
                priceCell.appendChild(pEdit);

                tr.appendChild(priceCell);
            }

            var actions = el('td', 'pcu-lib-actions',
                '<i class="fas fa-pen pcu-lib-edit-btn" role="button" tabindex="0" aria-label="Edit"></i>' +
                '<i class="fas fa-trash pcu-lib-delete" role="button" tabindex="0" aria-label="Remove"></i>');
            tr.appendChild(actions);

            return tr;
        }

        function rows() {
            return [].slice.call(tbody.querySelectorAll('tr[data-id], tr:not(.pcu-lib-empty)'))
                .filter(function (tr) { return !tr.classList.contains('pcu-lib-empty'); });
        }

        function dropEmptyNotice() {
            var empty = tbody.querySelector('.pcu-lib-empty');
            if (empty) { tbody.removeChild(empty); }
        }

        function syncRemoveButton() {
            var any = tbody.querySelector('.pcu-lib-row-check:checked');
            removeBtn.disabled = !any;

            // The select-all box reflects the rows, rather than pretending.
            var all = rows();
            var ticked = all.filter(function (tr) {
                var c = tr.querySelector('.pcu-lib-row-check');
                return c && c.checked;
            });

            if (allBox) {
                allBox.checked = all.length > 0 && ticked.length === all.length;
                allBox.indeterminate = ticked.length > 0 && ticked.length < all.length;
            }
        }

        lib.querySelector('.pcu-lib-add').addEventListener('click', function (e) {
            e.preventDefault();
            dropEmptyNotice();

            var tr = blankRow();
            tbody.appendChild(tr);
            tr.querySelector('.pcu-lib-title').focus();
            syncRemoveButton();
        });

        // Select all / none.
        if (allBox) {
            allBox.addEventListener('change', function () {
                rows().forEach(function (tr) {
                    var c = tr.querySelector('.pcu-lib-row-check');
                    if (c) { c.checked = allBox.checked; }
                });
                syncRemoveButton();
            });
        }

        lib.addEventListener('change', function (e) {
            if (e.target.classList.contains('pcu-lib-row-check')) { syncRemoveButton(); }
        });

        // Remove one row, or edit one row.
        lib.addEventListener('click', function (e) {
            if (e.target.classList.contains('pcu-lib-delete')) {
                var tr = e.target.closest('tr');
                if (tr) { tr.parentNode.removeChild(tr); }
                syncRemoveButton();

                // Removed means removed — write it now. Waiting for Save is what
                // let a deleted FAQ turn up on Create a Service.
                persist('Removed.', false);
                return;
            }

            // The pencil: turn this row into fields, or put it back if it is
            // already open. Only this row — the rest stay as records.
            if (e.target.classList.contains('pcu-lib-edit-btn')) {
                var row = e.target.closest('tr');
                if (!row) { return; }

                if (row.classList.contains('is-editing')) {
                    toView(row);
                } else {
                    row.classList.add('is-editing');
                    var field = row.querySelector('.pcu-lib-title');
                    if (field) { field.focus(); }
                }
            }
        });

        removeBtn.addEventListener('click', function () {
            rows().forEach(function (tr) {
                var c = tr.querySelector('.pcu-lib-row-check');
                if (c && c.checked) { tr.parentNode.removeChild(tr); }
            });
            if (allBox) { allBox.checked = false; allBox.indeterminate = false; }
            syncRemoveButton();

            persist('Removed.', false);   // same as the bin: removed is removed
        });

        function collect() {
            return rows().map(function (tr) {
                var title = tr.querySelector('.pcu-lib-title');
                var row = {
                    id: tr.getAttribute('data-id') || '',
                    title: title ? title.value : ''
                };

                if (isExtras) {
                    var p = tr.querySelector('.pcu-lib-price-input');
                    row.price = p ? p.value : '';
                } else {
                    var d = tr.querySelector('.pcu-lib-desc');
                    row.description = d ? d.value : '';
                }

                return row;
            }).filter(function (r) { return r.title.trim() !== ''; });
        }

        var saveBtn = lib.querySelector('.pcu-lib-save');

        /**
         * Write the table to the server.
         *
         * DELETING PERSISTS IMMEDIATELY, and that is the point of this being a
         * function rather than a click handler.
         *
         * The client removed an FAQ, went to Create a Service, and it was still
         * there. His library on the server still held it: the bin had only taken
         * the row off the screen, and the change was waiting on a Save he had no
         * reason to expect. A list you manage is not a form you draft — removing
         * something from it should remove it, full stop.
         *
         * So a removal writes straight away. Save is still there for edits and
         * for new rows, and does the same thing.
         *
         * @param {string} done  What to say when it worked.
         * @param {boolean} toViewAfter  Flip the rows back to text (a real save).
         */
        function persist(done, toViewAfter) {
            saveBtn.disabled = true;
            status.textContent = 'Saving…';
            status.className = 'pcu-lib-status';

            var body = new URLSearchParams();
            body.set('action', 'pcu_library_save');
            body.set('nonce', CFG.nonce);
            body.set('which', which);
            body.set('rows', JSON.stringify(collect()));

            return window.fetch(CFG.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    saveBtn.disabled = false;

                    if (!res || !res.success) {
                        throw new Error('save failed');
                    }

                    status.textContent = done || res.data.message;
                    status.className = 'pcu-lib-status is-ok';

                    // Rows the server accepted now carry their stored ids, so a
                    // second save updates them instead of creating duplicates.
                    var stored = res.data.rows || [];

                    rows().forEach(function (tr, i) {
                        if (stored[i]) { tr.setAttribute('data-id', stored[i].id); }

                        // Saved: it is a record now, not a form.
                        if (toViewAfter) { toView(tr); }
                    });
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    status.textContent = 'Sorry — that could not be saved. Please try again.';
                    status.className = 'pcu-lib-status is-error';
                });
        }

        saveBtn.addEventListener('click', function () {
            persist(null, true);
        });

        syncRemoveButton();
    }

    // ================================================ the Create Service form

    /**
     * Drop a tick-list of the seller's library above each of the two sections,
     * so they can pull rows in rather than retyping them. Typing a brand-new one
     * still works exactly as it did — this only adds.
     *
     * TICKING DOES NOT OPEN ANYTHING.
     *
     * It used to append a full editable block — title, description and price
     * boxes — for every item ticked. That was wrong twice over: the seller has
     * already written those words in their library, so the form was asking them
     * to look at them again; and ticking four extras buried the rest of the page
     * under four big blocks.
     *
     * The tick IS the inclusion. What it adds is HIDDEN inputs carrying exactly
     * what the plugin's save expects (additional_service_title[] and friends), so
     * the service saves the same as if they had been typed — with nothing to look
     * at. Unticking takes them away again.
     */
    function picker(container, list, isExtras, addRow) {
        if (!container || !list || !list.length) { return; }

        var box = el('div', 'pcu-pick');

        var head = el('div', 'pcu-pick-head');
        head.appendChild(el('strong', null,
            isExtras ? 'Your saved extras' : 'Your saved FAQs'));

        var hint = el('span', 'pcu-pick-hint', 'Tick to add to this service');
        head.appendChild(hint);
        box.appendChild(head);

        var listEl = el('div', 'pcu-pick-list');

        list.forEach(function (row) {
            var label = el('label', 'pcu-pick-item');

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = row.id;

            var text = el('span', 'pcu-pick-text');
            text.textContent = row.title;

            label.appendChild(cb);
            label.appendChild(text);

            if (isExtras && row.price) {
                var price = el('span', 'pcu-pick-price');
                price.textContent = (CFG.currency || '$') + row.price;
                label.appendChild(price);
            }

            cb.addEventListener('change', function () {
                if (cb.checked) {
                    addRow(row, label);
                } else if (label.pcuRow && label.pcuRow.parentNode) {
                    // Untick removes the row it added — but only that one, and
                    // only if the seller has not since edited it away.
                    label.pcuRow.parentNode.removeChild(label.pcuRow);
                    label.pcuRow = null;
                }
            });

            listEl.appendChild(label);
        });

        box.appendChild(listEl);
        container.parentNode.insertBefore(box, container);
    }

    /**
     * One hidden field. .value, never innerHTML — the text is the seller's own.
     */
    function hidden(name, value) {
        var i = document.createElement('input');
        i.type = 'hidden';
        i.name = name;
        i.value = value || '';
        return i;
    }

    /**
     * The hidden inputs for one ticked library item.
     *
     * They go in their own <div>, appended to the SAME container the typed-in
     * blocks live in. The plugin's save reads these as parallel arrays
     * (title[i], description[i], price[i]), and the browser serialises them in
     * DOM order — so as long as every block contributes exactly one of each
     * name, which it does, the three arrays stay lined up whether the block was
     * typed by hand or ticked from the library.
     */
    function hiddenGroup(row, isExtras) {
        var box = el('div', 'pcu-pick-hidden');

        if (isExtras) {
            box.appendChild(hidden('additional_service_title[]', row.title));
            box.appendChild(hidden('additional_service_description[]', row.description));
            box.appendChild(hidden('additional_service_price[]', row.price));
        } else {
            box.appendChild(hidden('faq_title[]', row.title));
            box.appendChild(hidden('faq_description[]', row.description));
        }

        return box;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var extrasWrap = document.querySelector('.additional-services');
        var faqWrap = document.querySelector('.faqs');

        if (!extrasWrap && !faqWrap) { return; }   // not the service form

        picker(extrasWrap, CFG.extras, true, function (row, label) {
            var box = hiddenGroup(row, true);
            extrasWrap.appendChild(box);
            label.pcuRow = box;
        });

        picker(faqWrap, CFG.faqs, false, function (row, label) {
            var box = hiddenGroup(row, false);
            faqWrap.appendChild(box);
            label.pcuRow = box;
        });
    });

}(window, document));
