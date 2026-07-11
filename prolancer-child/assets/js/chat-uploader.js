/* ==========================================================================
   Prolancer — Chat attachment uploader
   --------------------------------------------------------------------------
   - Attach icon in the composer opens a modal (no inline "Choose File").
   - Modal holds a Dropzone; queue is NOT auto-processed.
   - "Upload" is disabled until at least one file is queued.
   - Modal closes only via the explicit Close button.
   - Uploaded files are handed to the chat area; no "Successfully uploaded!".
   - One spinner class, reused for every async action.
   ========================================================================== */
(function (window, document) {
    'use strict';

    Dropzone.autoDiscover = false;

    var CFG = window.PCU_CONFIG || {};

    var settings = {
        uploadUrl:    CFG.uploadUrl    || 'upload.php',
        maxFilesize:  CFG.maxFilesize  || 10,          // MB, per file
        maxFiles:     CFG.maxFiles     || 10,
        acceptedFiles: CFG.acceptedFiles || 'image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.zip',
        params:       CFG.params       || {}           // nonce, thread id, etc.
    };

    var IMAGE_RE = /^image\//;

    // ---------------------------------------------------------------- helpers

    function bytes(n) {
        if (n < 1024) { return n + ' B'; }
        if (n < 1024 * 1024) { return (n / 1024).toFixed(1) + ' KB'; }
        return (n / 1024 / 1024).toFixed(1) + ' MB';
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
     * Pull a human-readable message out of whatever Dropzone hands us.
     *
     * Dropzone's own client-side rejections arrive as a plain string, while a
     * server rejection arrives as the parsed body. WordPress wraps AJAX errors
     * as {success:false, data:{error:"…"}}, so the message is one level deeper
     * than a bare {error:"…"}.
     */
    function readError(message) {
        if (!message) { return 'Upload failed.'; }
        if (typeof message === 'string') { return message; }
        if (message.data && message.data.error) { return message.data.error; }
        if (message.error) { return message.error; }
        if (message.message) { return message.message; }
        return 'Upload failed.';
    }

    /**
     * Minimal modal. Deliberately has NO backdrop-click and NO ESC handler:
     * the only way out is the Close button, per spec.
     *
     * Locks body scroll by compensating for the scrollbar width, so opening the
     * modal does not shift the page underneath it.
     */
    function makeModal(el) {
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

                el.classList.add('is-open');
                el.setAttribute('aria-hidden', 'false');
            },
            hide: function () {
                el.classList.remove('is-open');
                el.setAttribute('aria-hidden', 'true');
                document.body.style.paddingRight = prevPad;
                document.body.style.overflow = prevOverflow;
            },
            isOpen: function () {
                return el.classList.contains('is-open');
            }
        };
    }

    /**
     * Custom overlay scrollbar, matching Dhonu's (SimpleBar's) look.
     *
     * The native bar is hidden in CSS and this draws a real element instead —
     * the only way to get one consistent style on every platform, since macOS
     * native scrollbars are invisible overlays.
     *
     * @param {HTMLElement} scroller Element with overflow-y:auto.
     * @param {HTMLElement} track    Absolutely-positioned sibling of scroller.
     * @param {HTMLElement} thumb    Child of track.
     */
    function attachScrollbar(scroller, track, thumb) {
        var dragging = false;
        var dragStartY = 0;
        var dragStartScroll = 0;
        var hideTimer = null;

        function update() {
            var visibleH = scroller.clientHeight;
            var contentH = scroller.scrollHeight;

            // Nothing to scroll — park the thumb.
            if (contentH <= visibleH + 1) {
                track.classList.add('is-idle');
                return;
            }
            track.classList.remove('is-idle');

            var trackH = track.clientHeight;
            var ratio  = visibleH / contentH;
            var thumbH = Math.max(Math.round(trackH * ratio), 10); // min-height

            // The thumb travels the track minus its own height, while the
            // content travels scrollHeight minus one viewport. Mapping between
            // those two ranges is what keeps the thumb in step with the content
            // at both ends.
            var maxScroll = contentH - visibleH;
            var maxTop    = trackH - thumbH;
            var top       = maxScroll > 0
                ? Math.round((scroller.scrollTop / maxScroll) * maxTop)
                : 0;

            thumb.style.height = thumbH + 'px';
            thumb.style.top = top + 'px';
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

        // The scroller's own box changes with the viewport; its CONTENT changes
        // as file rows come and go. ResizeObserver on the scroller catches the
        // first, and the caller calls update() on add/remove for the second.
        if (window.ResizeObserver) {
            new ResizeObserver(update).observe(scroller);
        }
        window.addEventListener('resize', update);

        // --- drag the thumb ---
        thumb.addEventListener('mousedown', function (e) {
            e.preventDefault();          // don't select text while dragging
            dragging = true;
            dragStartY = e.clientY;
            dragStartScroll = scroller.scrollTop;
            thumb.classList.add('is-dragging');
            document.body.style.userSelect = 'none';
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) { return; }

            var trackH = track.clientHeight;
            var thumbH = thumb.offsetHeight;
            var maxTop = trackH - thumbH;
            if (maxTop <= 0) { return; }

            // Invert the same mapping used in update(): thumb pixels -> content
            // pixels, so a drag of the whole track scrolls the whole content.
            var maxScroll = scroller.scrollHeight - scroller.clientHeight;
            var delta = e.clientY - dragStartY;

            scroller.scrollTop = dragStartScroll + (delta / maxTop) * maxScroll;
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

        var modalEl   = root.querySelector('.pcu-modal');
        var listEl    = root.querySelector('.pcu-file-list');
        var uploadBtn = root.querySelector('.pcu-btn-upload');
        var uploadTxt = uploadBtn.querySelector('.pcu-btn-label');
        var spinner   = uploadBtn.querySelector('.pcu-spinner');
        var closeBtn  = root.querySelector('.pcu-btn-close');
        var attachBtn = document.querySelector('.pcu-attach-btn');
        var countEl   = attachBtn ? attachBtn.querySelector('.pcu-attach-count') : null;

        // Self-contained modal: no Bootstrap, no framework. Keeps the chat page
        // to ONE stylesheet and avoids a second JS bundle on every page load.
        // It closes only via the Close button — there is deliberately no
        // backdrop-click or ESC handler to remove.
        var modal = makeModal(modalEl);

        var dz = new Dropzone(root.querySelector('.pcu-dropzone'), {
            url: settings.uploadUrl,
            method: 'post',
            paramName: 'file',
            autoProcessQueue: false,          // wait for the Upload button
            uploadMultiple: false,
            parallelUploads: 3,
            maxFilesize: settings.maxFilesize,
            maxFiles: settings.maxFiles,
            acceptedFiles: settings.acceptedFiles,
            addRemoveLinks: false,
            createImageThumbnails: true,
            thumbnailWidth: 88,
            thumbnailHeight: 88,
            previewsContainer: false,         // we build our own rows
            clickable: [
                root.querySelector('.pcu-dropzone'),
                root.querySelector('.pcu-dz-browse')
            ],
            params: settings.params
        });

        // ------------------------------------------------------------ UI state

        function queued() {
            return dz.getFilesWithStatus(Dropzone.QUEUED)
                .concat(dz.getFilesWithStatus(Dropzone.ADDED));
        }

        function syncUi() {
            var n = dz.files.filter(function (f) {
                return f.status !== Dropzone.ERROR;
            }).length;

            listEl.classList.toggle('is-visible', dz.files.length > 0);
            uploadBtn.disabled = n === 0 || uploading;

            if (countEl) {
                countEl.textContent = n;
                countEl.classList.toggle('is-visible', n > 0);
            }
        }

        // ---------------------------------------------------- preview rows

        function buildRow(file) {
            var row = el('div', 'pcu-file-row');

            var thumb = el('div', 'pcu-file-thumb');
            thumb.appendChild(el('span', 'pcu-ext', ext(file.name)));

            var meta = el('div', 'pcu-file-meta');
            var name = el('span', 'pcu-file-name');
            name.textContent = file.name;
            var size = el('span', 'pcu-file-size', bytes(file.size));
            var err  = el('span', 'pcu-file-error');

            var prog = el('div', 'pcu-file-progress', '<span></span>');

            meta.appendChild(name);
            meta.appendChild(size);
            meta.appendChild(err);
            meta.appendChild(prog);

            var remove = el('button', 'pcu-file-remove', ICON_X);
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove ' + file.name);
            remove.addEventListener('click', function () {
                dz.removeFile(file);
            });

            row.appendChild(thumb);
            row.appendChild(meta);
            row.appendChild(remove);

            file._row   = row;
            file._thumb = thumb;
            file._err   = err;
            file._bar   = prog.querySelector('span');

            listEl.appendChild(row);
        }

        dz.on('addedfile', function (file) {
            buildRow(file);
            syncUi();
        });

        // Real thumbnail for media files; non-media keep the extension glyph.
        dz.on('thumbnail', function (file, dataUrl) {
            if (!file._thumb || !IMAGE_RE.test(file.type)) { return; }
            file._thumb.innerHTML = '';
            var img = new Image();
            img.src = dataUrl;
            img.alt = file.name;
            file._thumb.appendChild(img);
        });

        dz.on('removedfile', function (file) {
            if (file._row && file._row.parentNode) {
                file._row.parentNode.removeChild(file._row);
            }
            syncUi();
        });

        dz.on('error', function (file, message) {
            if (!file._row) { return; }
            file._row.classList.add('is-error');
            file._row.classList.remove('is-uploading');
            file._err.textContent = readError(message);
            syncUi();
        });

        dz.on('uploadprogress', function (file, pct) {
            if (file._bar) { file._bar.style.width = pct + '%'; }
        });

        dz.on('sending', function (file) {
            if (file._row) { file._row.classList.add('is-uploading'); }
        });

        // With autoProcessQueue:false Dropzone uploads one batch of
        // `parallelUploads` and then stops — it only re-kicks the queue when
        // autoProcessQueue is true. So we drive it ourselves, otherwise files
        // past the first batch never send and `queuecomplete` never fires.
        dz.on('complete', function () {
            if (uploading && dz.getQueuedFiles().length > 0) {
                dz.processQueue();
            }
        });

        // ------------------------------------------------------------- upload

        function setUploading(on) {
            uploading = on;
            uploadBtn.disabled = on || queued().length === 0;
            spinner.classList.toggle('is-hidden', !on);
            uploadTxt.textContent = on ? 'Uploading…' : 'Upload';
            closeBtn.disabled = on;
        }

        uploadBtn.addEventListener('click', function () {
            if (queued().length === 0) { return; }
            setUploading(true);
            dz.processQueue();
        });

        // Server responds with the stored file; hand it to the chat area.
        dz.on('success', function (file, response) {
            if (typeof response === 'string') {
                try { response = JSON.parse(response); } catch (e) { response = {}; }
            }
            // WordPress replies {success:true, data:{…}}; a plain endpoint replies
            // with the fields at the top level. Accept either.
            var d = (response && response.data) || response || {};

            file._uploaded = {
                name:  file.name,
                type:  file.type,
                url:   d.url   || null,
                thumb: d.thumb || null,   // WP-generated size, when available
                id:    d.id    || null,
                // Local preview, so the thumbnail is already painted and does
                // not pop in (and shift the chat) when the full image loads.
                preview: file.dataURL || null
            };
            if (file._row) {
                file._row.classList.remove('is-uploading');
            }
        });

        // Fires once the whole queue drains (success or error).
        dz.on('queuecomplete', function () {
            setUploading(false);

            var done = dz.files.filter(function (f) {
                return f.status === Dropzone.SUCCESS && f._uploaded;
            });

            if (!done.length) { return; }   // all failed — leave the modal open

            // NO "Successfully uploaded!" dialog. Files go straight to the chat.
            window.PCU.emit('pcu:uploaded', done.map(function (f) {
                return f._uploaded;
            }));

            done.forEach(function (f) { dz.removeFile(f); });

            // Only auto-close when everything succeeded; if some rows errored,
            // keep the modal up so the user can see which ones and retry.
            if (dz.files.length === 0) {
                modal.hide();
            }
            syncUi();
        });

        // -------------------------------------------------------------- modal

        if (attachBtn) {
            attachBtn.addEventListener('click', function () {
                modal.show();
            });
        }

        closeBtn.addEventListener('click', function () {
            if (uploading) { return; }
            modal.hide();
        });

        // Custom scrollbar on the modal body (see attachScrollbar).
        var body = root.querySelector('.pcu-modal-body');
        var sb = attachScrollbar(
            body,
            root.querySelector('.pcu-sb-track'),
            root.querySelector('.pcu-sb-thumb')
        );

        // Keep the newest row in view, and resize the thumb for the new content.
        dz.on('addedfile', function () {
            body.scrollTop = body.scrollHeight;
            sb.update();
        });

        dz.on('removedfile', function () {
            sb.update();
        });

        syncUi();
        sb.update();

        return { dz: dz, modal: modal };
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
