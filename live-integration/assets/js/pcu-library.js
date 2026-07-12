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

        /** A blank row, ready to type into. */
        function blankRow() {
            var tr = document.createElement('tr');

            var check = el('td', 'pcu-lib-check');
            check.appendChild(el('input'));
            check.firstChild.type = 'checkbox';
            check.firstChild.className = 'pcu-lib-row-check';

            var main = document.createElement('td');
            var title = document.createElement('input');
            title.type = 'text';
            title.className = 'form-control pcu-lib-title';
            title.placeholder = isExtras ? 'Name of extra' : 'FAQ name';
            main.appendChild(title);

            if (!isExtras) {
                var desc = document.createElement('textarea');
                desc.className = 'form-control pcu-lib-desc';
                desc.placeholder = 'Answer';
                main.appendChild(desc);
            }

            tr.appendChild(check);
            tr.appendChild(main);

            if (isExtras) {
                var priceCell = el('td', 'pcu-lib-price');
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
                priceCell.appendChild(group);
                tr.appendChild(priceCell);
            }

            var actions = el('td', 'pcu-lib-actions',
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

        // Remove one row, or every ticked row.
        lib.addEventListener('click', function (e) {
            if (e.target.classList.contains('pcu-lib-delete')) {
                var tr = e.target.closest('tr');
                if (tr) { tr.parentNode.removeChild(tr); }
                syncRemoveButton();
            }
        });

        removeBtn.addEventListener('click', function () {
            rows().forEach(function (tr) {
                var c = tr.querySelector('.pcu-lib-row-check');
                if (c && c.checked) { tr.parentNode.removeChild(tr); }
            });
            if (allBox) { allBox.checked = false; allBox.indeterminate = false; }
            syncRemoveButton();
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

        lib.querySelector('.pcu-lib-save').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            status.textContent = 'Saving…';
            status.className = 'pcu-lib-status';

            var body = new URLSearchParams();
            body.set('action', 'pcu_library_save');
            body.set('nonce', CFG.nonce);
            body.set('which', which);
            body.set('rows', JSON.stringify(collect()));

            window.fetch(CFG.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;

                    if (!res || !res.success) {
                        throw new Error('save failed');
                    }

                    status.textContent = res.data.message;
                    status.className = 'pcu-lib-status is-ok';

                    // Rows the server accepted now carry their stored ids, so a
                    // second save updates them instead of creating duplicates.
                    var stored = res.data.rows || [];
                    rows().forEach(function (tr, i) {
                        if (stored[i]) { tr.setAttribute('data-id', stored[i].id); }
                    });
                })
                .catch(function () {
                    btn.disabled = false;
                    status.textContent = 'Sorry — that could not be saved. Please try again.';
                    status.className = 'pcu-lib-status is-error';
                });
        });

        syncRemoveButton();
    }

    // ================================================ the Create Service form

    /**
     * Drop a tick-list of the seller's library above each of the two sections,
     * so they can pull rows in rather than retyping them. Typing a brand-new one
     * still works exactly as it did — this only adds.
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

    document.addEventListener('DOMContentLoaded', function () {
        var extrasWrap = document.querySelector('.additional-services');
        var faqWrap = document.querySelector('.faqs');

        if (!extrasWrap && !faqWrap) { return; }   // not the service form

        picker(extrasWrap, CFG.extras, true, function (row, label) {
            var tr = el('div', 'row mb-4');
            tr.innerHTML =
                '<div class="col-sm-1"><i class="fa fa-bars"></i></div>' +
                '<div class="col-sm-10 my-auto">' +
                '<input type="text" name="additional_service_title[]" class="form-control">' +
                '<textarea name="additional_service_description[]" class="form-control"></textarea>' +
                '<div class="input-group mb-3"><span class="input-group-text"></span>' +
                '<input type="text" inputmode="decimal" data-num="1" name="additional_service_price[]" class="form-control mb-0">' +
                '</div></div>' +
                '<div class="col-sm-1"><i class="fas fa-trash"></i></div>';

            // textContent/value, never innerHTML — the title is the seller's own
            // text and must never be able to become markup.
            tr.querySelector('input[name="additional_service_title[]"]').value = row.title;
            tr.querySelector('textarea').value = row.description || '';
            tr.querySelector('.input-group-text').textContent = CFG.currency || '$';
            tr.querySelector('input[name="additional_service_price[]"]').value = row.price || '';

            extrasWrap.appendChild(tr);
            label.pcuRow = tr;
        });

        picker(faqWrap, CFG.faqs, false, function (row, label) {
            var tr = el('div', 'row mb-4');
            tr.innerHTML =
                '<div class="col-sm-1"><i class="fa fa-bars"></i></div>' +
                '<div class="col-sm-10 my-auto">' +
                '<input type="text" name="faq_title[]" class="form-control">' +
                '<textarea name="faq_description[]" class="form-control"></textarea>' +
                '</div>' +
                '<div class="col-sm-1"><i class="fas fa-trash"></i></div>';

            tr.querySelector('input[name="faq_title[]"]').value = row.title;
            tr.querySelector('textarea').value = row.description || '';

            faqWrap.appendChild(tr);
            label.pcuRow = tr;
        });
    });

}(window, document));
