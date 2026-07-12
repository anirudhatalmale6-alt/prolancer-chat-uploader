/**
 * Chat extras — ProLancer child theme
 * ----------------------------------------------------------------------------
 * Three small things that all hang off the chat screen:
 *
 *   1. Enter sends the message; Shift+Enter starts a new line.
 *   2. A real tooltip on attachments, in place of the browser's title bubble.
 *   3. An online/offline dot on the other person's avatar.
 *
 * No dependency. One file, because none of the three is big enough to be worth
 * its own request.
 */
(function (window, document) {
    'use strict';

    var CFG = window.PCU_CHAT || {};

    // The chat's send button. It is an <a>, not a <button> — the plugin's own
    // handler (and ours, in realtime-chat.js) is bound to these classes.
    var SEND = '.send-service-message, .send-project-message, .send-message';

    function sendButtonFor(field) {
        var form = field.closest('form');

        return form ? form.querySelector(SEND) : null;
    }

    // ------------------------------------------------------- 1. Enter to send

    /**
     * Enter sends, Shift+Enter breaks the line.
     *
     * Bound on keydown, not keypress: keypress is deprecated and does not fire
     * for every layout. The default is only prevented for a bare Enter, so
     * Shift+Enter falls through to the browser and inserts the newline itself —
     * no need to splice one in by hand, and the undo history stays intact.
     *
     * IME guard: while composing Japanese/Chinese/Korean text, Enter COMMITS the
     * candidate word and must not send. `isComposing` is exactly that signal,
     * and keyCode 229 is the older browsers' version of it.
     */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }

        var field = e.target;
        if (!field.matches || !field.matches('textarea[name="message"]')) { return; }

        if (e.isComposing || e.keyCode === 229) { return; }

        var button = sendButtonFor(field);
        if (!button) { return; }

        e.preventDefault();

        // Don't fire a second send while the first is still in flight — the
        // send handler marks the button while it works.
        if (button.classList.contains('sending')) { return; }

        // Nothing to send: no text and no attachments.
        var form = field.closest('form');
        var attached = form && form.querySelector('.attachment-id');

        if (!field.value.trim() && !(attached && attached.value)) { return; }

        button.click();
    });

    // ------------------------------------------------------------ 2. Tooltips

    /**
     * The browser's title bubble cannot be styled, cannot hold two lines, and
     * appears after a delay you do not control. So the markup carries data-tip
     * instead of title, and this draws it.
     *
     * ONE element, reused — not one per attachment.
     */
    var tip = null;

    function tipEl() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'pcu-tip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        return tip;
    }

    function showTip(host) {
        var text = host.getAttribute('data-tip');
        if (!text) { return; }

        var el = tipEl();

        // textContent, then let CSS honour the newlines (white-space: pre-line).
        // Building the lines as HTML would put an attacker-supplied FILENAME into
        // innerHTML, which is exactly how you get script into a chat.
        el.textContent = text;
        el.classList.add('is-open');

        // Above the thumbnail, centred — unless that would run off the top of
        // the window, in which case flip below it.
        var box = host.getBoundingClientRect();
        var mine = el.getBoundingClientRect();
        var gap = 8;

        var above = box.top - mine.height - gap > 4;
        var top = above ? box.top - mine.height - gap : box.bottom + gap;
        var left = box.left + (box.width / 2) - (mine.width / 2);

        // Keep it on screen sideways too.
        left = Math.max(8, Math.min(left, window.innerWidth - mine.width - 8));

        el.classList.toggle('is-below', !above);
        el.style.top = (top + window.scrollY) + 'px';
        el.style.left = (left + window.scrollX) + 'px';
    }

    function hideTip() {
        if (tip) { tip.classList.remove('is-open'); }
    }

    // Delegated, so attachments that arrive over Pusher get tooltips too.
    document.addEventListener('mouseover', function (e) {
        var host = e.target.closest && e.target.closest('[data-tip]');
        if (host) { showTip(host); }
    });

    document.addEventListener('mouseout', function (e) {
        var host = e.target.closest && e.target.closest('[data-tip]');
        if (host) { hideTip(); }
    });

    // A tooltip pinned to a thumbnail that just scrolled away is nonsense.
    document.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);

    // ---------------------------------------------------------- 3. Online dot

    /**
     * The other person's avatar gets a dot: green if they were doing something
     * on the site in the last couple of minutes, grey otherwise.
     *
     * Only THEIR avatar — your own name and photo are hidden on your own
     * messages (you know who you are), so every avatar left in a thread is
     * theirs.
     *
     * Two screens, one piece of code. The order chat is a single conversation on
     * the page. The dashboard inbox is several at once — one tab per person, plus
     * a contact list down the side — so a conversation is found, not assumed, and
     * each one carries its own person's id.
     *
     * A conversation = whatever element holds a send button. On the inbox that is
     * the Bootstrap tab pane; on the order chat there is only one, so it is the
     * document.
     */
    function conversations() {
        var found = [];

        [].forEach.call(document.querySelectorAll(SEND + '[data-receiver-id]'), function (button) {
            var id = button.getAttribute('data-receiver-id');
            if (!id) { return; }

            found.push({
                id: id,
                root: button.closest('.tab-pane') || document,
                // The inbox's contact list sits OUTSIDE the thread it opens. The
                // tab and its pane are linked by the pane's id, so the tab can be
                // found from here and given the same dot.
                tab: tabFor(button.closest('.tab-pane'))
            });
        });

        return found;
    }

    /** The contact-list entry that opens this thread, if there is one. */
    function tabFor(pane) {
        if (!pane || !pane.id) { return null; }

        return document.querySelector('[data-bs-target="#' + pane.id + '"]');
    }

    function avatarsIn(root) {
        // Their messages only. Yours are hidden by the stylesheet, and marking a
        // hidden avatar would put a dot on a photo nobody can see.
        return root.querySelectorAll('.chat-list:not(.message_sender) .col-3 img');
    }

    /**
     * Wrap an avatar so the dot has something to sit on, once.
     *
     * The wrapper is inline-block at the image's own size, so nothing moves.
     */
    function wrapAvatar(img) {
        if (!img || img.parentNode.classList.contains('pcu-avatar')) { return; }

        var wrap = document.createElement('span');
        wrap.className = 'pcu-avatar';

        // MOVE THE IMAGE'S MARGIN ONTO THE WRAPPER.
        //
        // The dot is positioned as a percentage of the wrapper, on the assumption
        // that the wrapper IS the photo. But a shrink-to-fit box takes the width
        // of its child's MARGIN box — and the theme puts a right margin on the
        // contact-list avatar to space it from the name. So the wrapper came out
        // wider than the photo, the percentages were measured against that extra
        // width, and the dot floated off to the right of the avatar entirely.
        //
        // Hand the margin to the wrapper and zero it on the image: the spacing
        // the theme wanted is unchanged, and the wrapper is now exactly the photo.
        var cs = window.getComputedStyle(img);

        wrap.style.marginTop = cs.marginTop;
        wrap.style.marginRight = cs.marginRight;
        wrap.style.marginBottom = cs.marginBottom;
        wrap.style.marginLeft = cs.marginLeft;

        img.parentNode.insertBefore(wrap, img);
        wrap.appendChild(img);

        img.style.margin = '0';

        var dot = document.createElement('span');
        dot.className = 'pcu-dot';
        wrap.appendChild(dot);
    }

    function markAvatars(chats) {
        chats.forEach(function (chat) {
            [].forEach.call(avatarsIn(chat.root), wrapAvatar);

            if (chat.tab) { wrapAvatar(chat.tab.querySelector('img')); }
        });
    }

    /**
     * @param {Object} chat   One conversation.
     * @param {boolean} online Are they about?
     */
    function paint(chat, online) {
        var wraps = [].slice.call(chat.root.querySelectorAll('.pcu-avatar'));

        if (chat.tab) {
            wraps = wraps.concat([].slice.call(chat.tab.querySelectorAll('.pcu-avatar')));
        }

        wraps.forEach(function (wrap) {
            wrap.classList.toggle('is-online', !!online);
            wrap.classList.toggle('is-offline', !online);

            // data-tip, NOT title: the same styled tooltip the attachments use.
            // The browser's own bubble cannot be styled and arrives on its own
            // schedule. Put it on the WRAPPER, not the dot — a 10px dot is a
            // hard thing to hover deliberately; the avatar is not.
            wrap.setAttribute('data-tip', online ? 'Online' : 'Offline');
        });
    }

    function ping(chats) {
        var body = new URLSearchParams();
        body.set('action', 'pcu_presence');
        body.set('nonce', CFG.nonce);
        body.set('profile_ids', chats.map(function (c) { return c.id; }).join(','));

        return window.fetch(CFG.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (!r || !r.success) { return; }

                chats.forEach(function (chat) {
                    paint(chat, !!r.data.online[chat.id]);
                });
            })
            .catch(function () { /* a dropped ping is not worth a broken chat */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!CFG.ajaxUrl || !window.fetch) { return; }

        var chats = conversations();
        if (!chats.length) { return; }        // no composer: a read-only thread

        markAvatars(chats);
        ping(chats);

        var timer = window.setInterval(function () {
            // Don't hold a hidden tab online. The stamp keeps them "online" for
            // the rest of the window, then they go grey — which is the truth.
            if (document.hidden) { return; }
            ping(chats);
        }, CFG.everyMs || 45000);

        // Coming back to the tab should update immediately, not in 45 seconds.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { ping(chats); }
        });

        // A message arriving means the sender is demonstrably online — and gives
        // us a fresh avatar to mark up.
        document.addEventListener('pcu:appended', function () {
            markAvatars(chats);
            ping(chats);
        });

        window.addEventListener('beforeunload', function () {
            window.clearInterval(timer);
        });
    });

}(window, document));
