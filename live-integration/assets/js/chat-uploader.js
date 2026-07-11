/* ==========================================================================
   Prolancer — Chat attachment uploader
   --------------------------------------------------------------------------
   - Attach icon in the composer opens a modal (no inline "Choose File").
   - Drag-and-drop + browse, queued; nothing sends until "Upload" is pressed.
   - "Upload" is disabled until at least one file is queued.
   - Modal closes only via the explicit Close button.
   - Uploaded files are handed to the chat area; no "Successfully uploaded!".
   - One spinner, reused for every async action.
   - Scrollbar is drawn as a real element, matching Dhonu/SimpleBar.

   No Dropzone, no Bootstrap, no jQuery — nothing but this file.
   ========================================================================== */
(function (window, document) {
    'use strict';

    var CFG = window.PCU_CONFIG || {};

    var settings = {
        uploadUrl:     CFG.uploadUrl     || '/wp-admin/admin-ajax.php',
        action:        CFG.action        || 'prolancer_ajax_upload_message_attachment',
        maxFilesize:   CFG.maxFilesize   || 10,          // MB, per file
        maxFiles:      CFG.maxFiles      || 10,
        parallel:      CFG.parallel      || 3,           // concurrent uploads
        acceptedFiles: CFG.acceptedFiles || 'image/*,.pdf,.doc,.docx,.ppt,.pptx'
    };

    var MB = 1024 * 1024;
    var IMAGE_RE = /^image\//;
    var THUMB_PX = 88;

    // ---------------------------------------------------------------- helpers

    function bytes(n) {
        if (n < 1024) { return n + ' B'; }
        if (n < MB) { return (n / 1024).toFixed(1) + ' KB'; }
        return (n / MB).toFixed(1) + ' MB';
    }

    function ext(name) {
        var i = name.lastIndexOf('.');
        return i > -1 ? name.slice(i + 1) : 'file';
    }

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html !== undefined) { n.innerHTML = html; }
        return n;
    }

    var ICON_X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
                 'stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    /**
     * Does this file match the `accept` list?
     *
     * Handles both forms the list can take: a wildcard type ("image/*") and a
     * bare extension (".pdf"). Browsers are inconsistent about the MIME type
     * they report for office documents, so an extension match is enough.
     */
    function accepted(file, list) {
        if (!list) { return true; }

        var name = file.name.toLowerCase();
        var type = (file.type || '').toLowerCase();

        return list.split(',').some(function (rule) {
            rule = rule.trim().toLowerCase();
            if (!rule) { return false; }

            if (rule.charAt(0) === '.') {
                return name.slice(-rule.length) === rule;
            }
            if (rule.slice(-2) === '/*') {
                return type.indexOf(rule.slice(0, -1)) === 0;
            }
            return type === rule;
        });
    }

    /**
     * Shrink an image to a thumbnail data URL.
     *
     * Downscaling through a canvas rather than pointing an <img> at the full
     * file: a handful of multi-megapixel photos in the list would otherwise sit
     * in memory at full size purely to paint 44px rows.
     */
    function makeThumb(file, done) {
        if (!IMAGE_RE.test(file.type)) { done(null); return; }

        var reader = new FileReader();

        reader.onerror = function () { done(null); };
        reader.onload = function () {
            var img = new Image();

            img.onerror = function () { done(null); };
            img.onload = function () {
                var scale = Math.min(THUMB_PX / img.width, THUMB_PX / img.height, 1);
                var w = Math.max(Math.round(img.width * scale), 1);
                var h = Math.max(Math.round(img.height * scale), 1);

                var canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);

                try {
                    done(canvas.toDataURL('image/png'));
                } catch (e) {
                    done(null);   // tainted canvas — fall back to the glyph
                }
            };
            img.src = reader.result;
        };
        reader.readAsDataURL(file);
    }

    /**
     * Minimal modal. Deliberately has NO backdrop-click and NO ESC handler:
     * the only way out is the Close button, per spec.
     *
     * Locks body scroll by compensating for the scrollbar width, so opening the
     * modal does not shift the page underneath it.
     */
    function makeModal(node) {
        var prevPad = '';
        var prevOverflow = '';

        return {
            show: function () {
                var sbw = window.innerWidth - document.documentElement.clientWidth;
                prevPad = document.body.style.paddingRight;
                prevOverflow = document.body.style.overflow;
                if (sbw > 0) {
                    document.body.style.paddingRight = sbw + 'px';
                }
                document.body.style.overflow = 'hidden';

                node.classList.add('is-open');
                node.setAttribute('aria-hidden', 'false');
            },
            hide: function () {
                node.classList.remove('is-open');
                node.setAttribute('aria-hidden', 'true');
                document.body.style.paddingRight = prevPad;
                document.body.style.overflow = prevOverflow;
            }
        };
    }

    /**
     * Overlay scrollbar matching Dhonu's (SimpleBar's) look.
     *
     * The native bar is hidden in CSS and this draws a real element instead —
     * the only way to get one consistent style on every platform, since macOS
     * native scrollbars are invisible auto-hiding overlays. Dhonu ships
     * SimpleBar for exactly this reason.
     */
    function attachScrollbar(scroller, track, thumb) {
        var dragging = false;
        var dragStartY = 0;
        var dragStartScroll = 0;
        var hideTimer = null;

        function update() {
            var visibleH = scroller.clientHeight;
            var contentH = scroller.scrollHeight;

            if (contentH <= visibleH + 1) {      // nothing to scroll
                track.classList.add('is-idle');
                return;
            }
            track.classList.remove('is-idle');

            var trackH = track.clientHeight;
            var thumbH = Math.max(Math.round(trackH * (visibleH / contentH)), 10);

            // The thumb travels (track - thumb) while the content travels
            // (scrollHeight - viewport). Mapping between those two ranges is
            // what keeps the thumb in step with the content at both ends.
            var maxScroll = contentH - visibleH;
            var maxTop = trackH - thumbH;

            thumb.style.height = thumbH + 'px';
            thumb.style.top = (maxScroll > 0
                ? Math.round((scroller.scrollTop / maxScroll) * maxTop)
                : 0) + 'px';
        }

        function flash() {
            thumb.classList.add('is-visible');
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(function () {
                thumb.classList.remove('is-visible');
            }, 800);
        }

        scroller.addEventListener('scroll', function () {
            update();
            flash();
        }, { passive: true });

        // The scroller's box changes with the viewport; its CONTENT changes as
        // rows come and go — the caller calls update() for that.
        if (window.ResizeObserver) {
            new ResizeObserver(update).observe(scroller);
        }
        window.addEventListener('resize', update);

        thumb.addEventListener('mousedown', function (e) {
            e.preventDefault();                  // don't select text mid-drag
            dragging = true;
            dragStartY = e.clientY;
            dragStartScroll = scroller.scrollTop;
            thumb.classList.add('is-dragging');
            document.body.style.userSelect = 'none';
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) { return; }

            var maxTop = track.clientHeight - thumb.offsetHeight;
            if (maxTop <= 0) { return; }

            // Inverse of the mapping in update(): thumb pixels -> content pixels.
            var maxScroll = scroller.scrollHeight - scroller.clientHeight;
            scroller.scrollTop = dragStartScroll +
                ((e.clientY - dragStartY) / maxTop) * maxScroll;
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) { return; }
            dragging = false;
            thumb.classList.remove('is-dragging');
            document.body.style.userSelect = '';
        });

        return { update: update };
    }

    // ------------------------------------------------------------------ setup

    function init(root) {
        var uploading = false;
        var queue = [];                  // the staged files
        var seq = 0;

        // --- the plugin's existing composer ---------------------------------
        // The chat form, its hidden id-carrier, and the plugin's original file
        // input. That input stays in the DOM (CSS hides it) because it holds the
        // post_id + nonce the upload endpoint needs; nothing listens to its
        // change event any more, so the plugin's old uploader — and its
        // "Successfully uploaded!" popup — never fires.
        var form = document.querySelector('#send-service-message-form, #send-project-message-form');
        var legacyInput = document.getElementById('upload-message-attachments');
        var idField = form ? form.querySelector('.attachment-id') : null;

        var modalNode = root.querySelector('.pcu-modal');
        var dialog    = root.querySelector('.pcu-modal-dialog');
        var dropzone  = root.querySelector('.pcu-dropzone');
        var input     = root.querySelector('.pcu-input');
        var browse    = root.querySelector('.pcu-dz-browse');
        var listEl    = root.querySelector('.pcu-file-list');
        var body      = root.querySelector('.pcu-modal-body');
        var uploadBtn = root.querySelector('.pcu-btn-upload');
        var uploadTxt = uploadBtn.querySelector('.pcu-btn-label');
        var closeBtn  = root.querySelector('.pcu-btn-close');
        var attachBtn = document.querySelector('.pcu-attach-btn');
        var countEl   = attachBtn ? attachBtn.querySelector('.pcu-attach-count') : null;

        var modal = makeModal(modalNode);

        // Only the file list scrolls — NOT the modal body. The dropzone above it
        // must stay put instead of scrolling out of view as files are added.
        var scrollWrap = root.querySelector('.pcu-scroll-wrap');
        var listScroll = root.querySelector('.pcu-list-scroll');

        var sb = attachScrollbar(
            listScroll,
            root.querySelector('.pcu-sb-track'),
            root.querySelector('.pcu-sb-thumb')
        );

        // ------------------------------------------------------------ UI state

        function pending() {
            return queue.filter(function (it) { return !it.error && !it.done; });
        }

        function syncUi() {
            scrollWrap.classList.toggle('is-visible', queue.length > 0);
            uploadBtn.disabled = uploading || pending().length === 0;

            if (countEl) {
                var n = pending().length;
                countEl.textContent = n;
                countEl.classList.toggle('is-visible', n > 0);
            }
            sb.update();
        }

        // ---------------------------------------------------- adding files

        function addFiles(fileList) {
            Array.prototype.forEach.call(fileList, function (file) {
                var item = { id: ++seq, file: file, error: null, done: false };

                if (queue.length >= settings.maxFiles) {
                    item.error = 'Limit of ' + settings.maxFiles + ' files reached.';
                } else if (!accepted(file, settings.acceptedFiles)) {
                    item.error = 'That file type is not allowed.';
                } else if (file.size > settings.maxFilesize * MB) {
                    item.error = 'File is too large. Maximum is ' +
                        settings.maxFilesize + ' MB.';
                }

                queue.push(item);
                renderRow(item);

                if (!item.error) {
                    makeThumb(file, function (dataUrl) {
                        item.preview = dataUrl;
                        if (!dataUrl || !item.thumbEl) { return; }

                        var img = new Image();
                        img.src = dataUrl;
                        img.alt = '';
                        item.thumbEl.innerHTML = '';
                        item.thumbEl.appendChild(img);
                    });
                }
            });

            // Reset, so picking the same file twice in a row still fires change.
            input.value = '';

            listScroll.scrollTop = listScroll.scrollHeight;
            syncUi();
        }

        function renderRow(item) {
            var row = el('div', 'pcu-file-row' + (item.error ? ' is-error' : ''));

            var thumb = el('div', 'pcu-file-thumb');
            thumb.appendChild(el('span', 'pcu-ext', ext(item.file.name)));

            var meta = el('div', 'pcu-file-meta');
            var name = el('span', 'pcu-file-name');
            name.textContent = item.file.name;

            var size = el('span', 'pcu-file-size', bytes(item.file.size));
            var err  = el('span', 'pcu-file-error');
            err.textContent = item.error || '';

            var prog = el('div', 'pcu-file-progress', '<span></span>');

            meta.appendChild(name);
            meta.appendChild(size);
            meta.appendChild(err);
            meta.appendChild(prog);

            var remove = el('button', 'pcu-file-remove', ICON_X);
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove ' + item.file.name);
            remove.addEventListener('click', function () { removeItem(item); });

            row.appendChild(thumb);
            row.appendChild(meta);
            row.appendChild(remove);
            listEl.appendChild(row);

            item.row = row;
            item.thumbEl = thumb;
            item.errEl = err;
            item.bar = prog.querySelector('span');
        }

        function removeItem(item) {
            if (item.xhr) { item.xhr.abort(); }

            var i = queue.indexOf(item);
            if (i > -1) { queue.splice(i, 1); }

            if (item.row && item.row.parentNode) {
                item.row.parentNode.removeChild(item.row);
            }
            syncUi();
        }

        function fail(item, message) {
            item.error = message;
            item.row.classList.add('is-error');
            item.row.classList.remove('is-uploading');
            item.errEl.textContent = message;
        }

        // ------------------------------------------------------------- upload

        /**
         * Upload one file through the PLUGIN's own endpoint
         * (prolancer_ajax_upload_message_attachment).
         *
         * post_id and nonce are read straight off the plugin's file input, which
         * is still rendered (CSS keeps it hidden) precisely so we don't have to
         * duplicate them. Field name must be `attachment` — that is what the
         * endpoint's media_handle_upload() call looks for.
         *
         * Resolves either way; a failure marks the row rather than throwing.
         */
        function uploadOne(item) {
            return new Promise(function (resolve) {
                var form = new FormData();

                form.append('action', settings.action);
                form.append('nonce', legacyInput ? legacyInput.getAttribute('data-nonce') : '');
                form.append('post_id', legacyInput ? legacyInput.getAttribute('data-post-id') : '');
                form.append('attachment', item.file, item.file.name);

                var xhr = new XMLHttpRequest();
                item.xhr = xhr;
                item.row.classList.add('is-uploading');

                xhr.upload.addEventListener('progress', function (e) {
                    if (!e.lengthComputable) { return; }
                    item.bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
                });

                xhr.addEventListener('load', function () {
                    item.xhr = null;

                    var res = {};
                    try { res = JSON.parse(xhr.responseText); } catch (e) { /* noop */ }

                    // WordPress replies {success:bool, data:{…}}.
                    var payload = res.data || res;

                    if (xhr.status < 200 || xhr.status >= 300 || res.success === false) {
                        fail(item, (payload && payload.message) || 'Upload failed.');
                        resolve();
                        return;
                    }

                    // The plugin endpoint does `if ($attachment_id)` — and a
                    // WP_Error object is truthy, so a genuinely failed upload can
                    // come back reported as success with a non-numeric id. Check
                    // it really is an attachment id before trusting it.
                    var id = parseInt(payload.id, 10);

                    if (!id) {
                        fail(item, 'Upload failed on the server.');
                        resolve();
                        return;
                    }

                    item.done = true;
                    item.row.classList.remove('is-uploading');
                    item.uploaded = {
                        id:   id,
                        name: item.file.name,
                        type: item.file.type,
                        // Already-decoded preview, so the staged thumbnail paints
                        // immediately instead of popping in and shifting the row.
                        preview: item.preview || null
                    };
                    resolve();
                });

                xhr.addEventListener('error', function () {
                    item.xhr = null;
                    fail(item, 'Network error. Please try again.');
                    resolve();
                });

                xhr.addEventListener('abort', function () {
                    item.xhr = null;
                    resolve();
                });

                xhr.open('POST', settings.uploadUrl, true);
                xhr.send(form);
            });
        }

        /** Run the queue `settings.parallel` at a time. */
        function runQueue(items) {
            var next = 0;

            function worker() {
                if (next >= items.length) { return Promise.resolve(); }
                return uploadOne(items[next++]).then(worker);
            }

            var workers = [];
            for (var i = 0; i < Math.min(settings.parallel, items.length); i++) {
                workers.push(worker());
            }
            return Promise.all(workers);
        }

        function setUploading(on) {
            uploading = on;
            uploadBtn.disabled = on || pending().length === 0;
            uploadTxt.textContent = on ? 'Uploading…' : 'Upload';
            closeBtn.disabled = on;

            // THE site spinner: `.processing-loader` from the ProLancer plugin,
            // which overlays its own loader.gif. Reused rather than reimplemented
            // so that restyling the spinner later is a single change, in their
            // file, and this picks it up for free.
            dialog.classList.toggle('processing-loader', on);
        }

        uploadBtn.addEventListener('click', function () {
            var batch = pending();
            if (!batch.length) { return; }

            setUploading(true);

            runQueue(batch).then(function () {
                setUploading(false);

                var done = queue.filter(function (it) { return it.done; });
                if (!done.length) { return; }   // all failed — keep the modal up

                // Upload SENDS. The files go into the chat and out to the other
                // user immediately — not parked in the composer waiting for a
                // second click. No "Successfully uploaded!" dialog either.
                var ids = done.map(function (it) { return it.uploaded.id; });

                if (idField) {
                    // Several ids in one message: the column is varchar, and the
                    // plugin saves it with sanitize_text_field(), so a
                    // comma-separated list round-trips as-is.
                    idField.value = ids.join(',');
                }

                window.PCU.emit('pcu:uploaded', done.map(function (it) {
                    return it.uploaded;
                }));

                done.forEach(removeItem);
                syncUi();

                // Hand off to the normal send path, so the message is stored,
                // rendered in the chat and pushed live to the other side exactly
                // as a typed message would be. Nothing about sending is
                // reimplemented here.
                send();

                // Only close when everything went through; if some rows failed,
                // stay open so the user can see which ones and retry.
                if (!queue.length) { modal.hide(); }
            });
        });

        // --------------------------------------------------------- sending

        /**
         * Fire the composer's own Send button.
         *
         * The chat already has a send path — the plugin's handler, or the
         * realtime one that replaces it. Both read the attachment ids out of the
         * hidden .attachment-id field, store the message, render it and push it
         * to the other user. Clicking that button reuses all of it, instead of
         * duplicating the send logic (and its nonces) in here.
         */
        function send() {
            var sendBtn = form && form.querySelector(
                '.send-service-message, .send-project-message');

            if (!sendBtn) { return; }

            // A native click bubbles to the delegated jQuery handler just like a
            // real one, so whichever sender is active picks it up.
            sendBtn.click();
        }

        // ------------------------------------------------- drag, drop, browse

        input.addEventListener('change', function () {
            addFiles(input.files);
        });

        function openPicker(e) {
            if (e) { e.preventDefault(); }
            input.click();
        }

        // The input lives OUTSIDE the dropzone (see the markup). If it were a
        // child, the synthetic click from input.click() would bubble back up
        // into this very handler, which would call input.click() again — and on
        // that re-entrancy Chrome silently refuses to open the file dialog.
        // Belt and braces: stop its click escaping either way.
        input.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        dropzone.addEventListener('click', openPicker);

        if (browse) {
            browse.addEventListener('click', function (e) {
                e.stopPropagation();   // otherwise the dropzone handler fires too
                openPicker(e);
            });
        }

        // dragenter/dragover must BOTH be cancelled or the browser just opens
        // the file, and `dragleave` fires when moving onto a child element —
        // hence the counter rather than a bare boolean.
        var dragDepth = 0;

        dropzone.addEventListener('dragenter', function (e) {
            e.preventDefault();
            dragDepth++;
            dropzone.classList.add('is-dragover');
        });

        dropzone.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });

        dropzone.addEventListener('dragleave', function () {
            dragDepth = Math.max(dragDepth - 1, 0);
            if (!dragDepth) { dropzone.classList.remove('is-dragover'); }
        });

        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dragDepth = 0;
            dropzone.classList.remove('is-dragover');

            if (e.dataTransfer && e.dataTransfer.files.length) {
                addFiles(e.dataTransfer.files);
            }
        });

        // Dropping anywhere else on the page must not navigate away from it.
        ['dragover', 'drop'].forEach(function (evt) {
            window.addEventListener(evt, function (e) {
                if (!dropzone.contains(e.target)) { e.preventDefault(); }
            });
        });

        // -------------------------------------------------------------- modal

        if (attachBtn) {
            attachBtn.addEventListener('click', function () { modal.show(); });
        }

        closeBtn.addEventListener('click', function () {
            if (uploading) { return; }
            modal.hide();
        });

        syncUi();

        return { addFiles: addFiles, modal: modal };
    }

    // ------------------------------------------------------- tiny event bus

    var handlers = {};

    window.PCU = {
        on: function (evt, fn) {
            (handlers[evt] = handlers[evt] || []).push(fn);
        },
        emit: function (evt, payload) {
            (handlers[evt] || []).forEach(function (fn) { fn(payload); });
        },
        init: init
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('pcu-uploader');
        if (root) { init(root); }
    });

}(window, document));
