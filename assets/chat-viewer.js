/**
 * Chat media viewer — ProLancer child theme
 * ----------------------------------------------------------------------------
 * Click any attachment in a chat and it opens full screen, with:
 *
 *   · the position in the set ("3 / 7") top left
 *   · download and close top right
 *   · arrows either side, and the left/right keys, stepping through EVERY file
 *     in that conversation — not just the ones in the message you clicked
 *   · video played in place, with the browser's own controls
 *   · a file tile for anything with no preview (pdf, zip, docx…)
 *
 * Built natively rather than on Viewer.js: Viewer.js is ~35 KB plus a
 * stylesheet, and still would not play video or drive a save dialogue, which
 * are two of the five things asked for.
 *
 * The overlay is created once, on first open, and reused. Nothing is added to
 * the DOM at page load, so an unused viewer costs nothing.
 */
(function (window, document) {
    'use strict';

    var CFG = window.PCU_VIEWER || {};

    var ICON = {
        close: '<path d="M18 6 6 18M6 6l12 12"/>',
        down:  '<path d="M12 4v12"/><path d="M7 12l5 5 5-5"/><path d="M5 20h14"/>',
        left:  '<path d="M15 5 8 12l7 7"/>',
        right: '<path d="M9 5l7 7-7 7"/>',
        file:  '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>'
    };

    function svg(paths, cls) {
        return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
               'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
               paths + '</svg>';
    }

    var ui = null;      // the overlay, built lazily
    var items = [];     // every attachment in the chat we opened from
    var at = 0;

    // -------------------------------------------------------------- download

    /**
     * Save, rather than navigate.
     *
     * A plain <a download> is enough while the file sits on this domain, but
     * WordPress can be configured to serve uploads from a CDN or S3, and a
     * cross-origin `download` is silently ignored — the browser navigates to
     * the file instead, and for a video or a PDF it just plays or renders it.
     * Fetching to a blob first means the save dialogue always opens.
     */
    function download(item) {
        var a = document.createElement('a');

        function go(href, revoke) {
            a.href = href;
            a.download = item.file || 'download';
            document.body.appendChild(a);
            a.click();
            a.remove();
            if (revoke) { window.setTimeout(function () { URL.revokeObjectURL(href); }, 1000); }
        }

        if (!window.fetch) { go(item.url, false); return; }

        ui.root.classList.add('is-downloading');

        window.fetch(item.url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { throw new Error(r.status); }
                return r.blob();
            })
            .then(function (blob) { go(URL.createObjectURL(blob), true); })
            .catch(function () { go(item.url, false); })   // fall back to a direct hit
            .then(function () { ui.root.classList.remove('is-downloading'); });
    }

    // ----------------------------------------------------------------- build

    function build() {
        var root = document.createElement('div');
        root.className = 'pcu-viewer';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Attachment viewer');

        root.innerHTML =
            '<div class="pcu-viewer-bar">' +
                '<span class="pcu-viewer-count"></span>' +
                '<div class="pcu-viewer-actions">' +
                    '<button type="button" class="pcu-viewer-btn pcu-viewer-download" aria-label="Download">' + svg(ICON.down) + '</button>' +
                    '<button type="button" class="pcu-viewer-btn pcu-viewer-close" aria-label="Close">' + svg(ICON.close) + '</button>' +
                '</div>' +
            '</div>' +
            '<button type="button" class="pcu-viewer-nav pcu-viewer-prev" aria-label="Previous">' + svg(ICON.left) + '</button>' +
            '<button type="button" class="pcu-viewer-nav pcu-viewer-next" aria-label="Next">' + svg(ICON.right) + '</button>' +
            '<div class="pcu-viewer-stage"></div>' +
            '<div class="pcu-viewer-loader" aria-hidden="true">' +
                (CFG.spinner ? '<img src="' + CFG.spinner + '" alt="">' : '') +
            '</div>' +
            '<div class="pcu-viewer-caption"></div>';

        document.body.appendChild(root);

        ui = {
            root:    root,
            stage:   root.querySelector('.pcu-viewer-stage'),
            count:   root.querySelector('.pcu-viewer-count'),
            caption: root.querySelector('.pcu-viewer-caption'),
            prev:    root.querySelector('.pcu-viewer-prev'),
            next:    root.querySelector('.pcu-viewer-next')
        };

        ui.prev.addEventListener('click', function () { step(-1); });
        ui.next.addEventListener('click', function () { step(1); });
        root.querySelector('.pcu-viewer-close').addEventListener('click', close);
        root.querySelector('.pcu-viewer-download').addEventListener('click', function () {
            download(items[at]);
        });

        // Click the backdrop to close — but not a click that lands on the media
        // itself, or on a video's controls.
        root.addEventListener('click', function (e) {
            if (e.target === root || e.target === ui.stage) { close(); }
        });
    }

    // ----------------------------------------------------------------- render

    /**
     * The site's own spinner, over whatever is loading.
     *
     * The browser draws its OWN loading spinner inside a <video>'s default
     * controls, and it is not ours and cannot be styled. So the controls are
     * withheld until the video can actually play — no controls, no browser
     * spinner — and ours shows in the meantime. The moment it is playable the
     * controls go on and ours comes off.
     */
    function loading(on) {
        ui.root.classList.toggle('is-loading', !!on);
    }

    function show(i) {
        at = (i + items.length) % items.length;      // wrap at both ends
        var item = items[at];

        // Drop the previous <video> rather than leave it buffering off-screen.
        ui.stage.innerHTML = '';
        loading(false);

        var node;
        if (item.kind === 'image') {
            node = document.createElement('img');
            node.alt = item.file;                    // the filename, not the tooltip

            loading(true);
            node.addEventListener('load', function () { loading(false); });
            node.addEventListener('error', function () { loading(false); });
            node.src = item.url;                     // set src AFTER the listeners
        } else if (item.kind === 'video' || item.kind === 'audio') {
            node = document.createElement(item.kind);
            node.src = item.url;
            node.preload = 'metadata';

            // The frame we already generated. Without it the viewer is a black
            // box until the first frame decodes.
            if (item.poster) { node.poster = item.poster; }

            loading(true);

            node.addEventListener('canplay', function () {
                loading(false);
                node.controls = true;                // …and only now, its controls
            });

            // A file the browser cannot decode must not spin forever.
            node.addEventListener('error', function () {
                loading(false);
                node.controls = true;
            });

            // Browsers refuse to autoplay anything with sound until the user has
            // interacted with the page, and the refusal is a REJECTED PROMISE,
            // not an exception — ignore it and the video just sits there looking
            // broken. So: try with sound, and if that is blocked, fall back to
            // muted playback, which is always allowed. The native controls are
            // right there for the user to unmute.
            var media = node;
            var play = media.play();

            if (play && play.catch) {
                play.catch(function () {
                    media.muted = true;
                    var retry = media.play();
                    if (retry && retry.catch) {
                        retry.catch(function () { /* leave it paused, controls are showing */ });
                    }
                });
            }
        } else {
            // No preview possible. Show the SAME type icon the chat tile shows,
            // so a PDF looks like a PDF in both places.
            node = document.createElement('div');
            node.className = 'pcu-viewer-file';

            if (item.icon) {
                var art = document.createElement('img');
                art.src = item.icon;
                art.alt = '';
                node.appendChild(art);
            } else {
                node.innerHTML = svg(ICON.file);
            }

            var label = document.createElement('span');
            label.textContent = (item.file.split('.').pop() || 'file').toUpperCase();
            node.appendChild(label);
        }

        node.className = (node.className ? node.className + ' ' : '') + 'pcu-viewer-media';
        ui.stage.appendChild(node);

        ui.count.textContent = (at + 1) + ' / ' + items.length;
        ui.caption.textContent = item.file || item.name;

        // One file: nothing to step to, so don't offer.
        var many = items.length > 1;
        ui.prev.hidden = !many;
        ui.next.hidden = !many;
    }

    function step(by) {
        if (items.length > 1) { show(at + by); }
    }

    // ------------------------------------------------------------- open/close

    var lastFocus = null;
    var prevOverflow = '';

    function open(list, index) {
        if (!ui) { build(); }

        items = list;
        lastFocus = document.activeElement;

        // Freeze the page behind the overlay, exactly as the upload modal does.
        prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        show(index);
        ui.root.classList.add('is-open');
        document.addEventListener('keydown', onKey);
    }

    function close() {
        ui.root.classList.remove('is-open');
        ui.stage.innerHTML = '';                  // stop any playing video
        document.body.style.overflow = prevOverflow;
        document.removeEventListener('keydown', onKey);

        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    function onKey(e) {
        if (e.key === 'Escape')     { close();   }
        if (e.key === 'ArrowLeft')  { step(-1);  }
        if (e.key === 'ArrowRight') { step(1);   }
    }

    // ------------------------------------------------------------------ wiring

    function read(a) {
        return {
            url:    a.getAttribute('href'),
            kind:   a.getAttribute('data-kind') || 'file',
            file:   a.getAttribute('data-file') || '',
            name:   a.getAttribute('data-tip') || '',
            poster: a.getAttribute('data-poster') || '',
            icon:   a.getAttribute('data-icon') || ''
        };
    }

    /**
     * Delegated, so attachments that arrive over Pusher — after this script has
     * run — open in the viewer too, with no rebinding.
     */
    document.addEventListener('click', function (e) {
        var hit = e.target.closest && e.target.closest('.pcu-chat-thumb');
        if (!hit) { return; }

        // Let a modified click through: ctrl/cmd-click still opens a new tab.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }

        e.preventDefault();

        // "All files in the current chat" — every attachment in this
        // conversation, in the order they were sent, starting at the one
        // clicked. Fall back to the message if the chat wrapper ever moves.
        var chat = hit.closest('.chat-box') || hit.closest('.pcu-chat-attachments');
        var all  = [].slice.call(chat.querySelectorAll('.pcu-chat-thumb'));

        open(all.map(read), all.indexOf(hit));
    });

}(window, document));
