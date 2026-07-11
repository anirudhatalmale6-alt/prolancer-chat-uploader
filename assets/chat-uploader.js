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

        // Modal must NOT close on backdrop click or ESC — only the Close button.
        var modal = new bootstrap.Modal(modalEl, {
            backdrop: 'static',
            keyboard: false
        });

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
            file._err.textContent = typeof message === 'string'
                ? message
                : (message && message.error) || 'Upload failed.';
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
            file._uploaded = {
                name: file.name,
                type: file.type,
                url:  (response && (response.url || (response.data && response.data.url))) || null,
                id:   (response && (response.id  || (response.data && response.data.id)))  || null,
                // Local preview so the thumbnail shows instantly even before the
                // server URL round-trips.
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

        // Keep the scroll position at the newest row as files are added.
        var body = root.querySelector('.pcu-modal-body');
        dz.on('addedfile', function () {
            body.scrollTop = body.scrollHeight;
        });

        syncUi();

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
