/**
 * Chat emoji picker — ProLancer child theme
 * ----------------------------------------------------------------------------
 * A popup next to the attach icon: category tabs across the top, a scrolling
 * grid below, and a "Frequently Used" row that learns from what gets picked.
 *
 * No dependency, no sprite sheet, no web font — the emoji are real characters
 * drawn by the system font, so there is nothing extra to download and they look
 * native on every platform. The character data is generated from the official
 * Unicode list (see tools/gen_emoji.py) into assets/emoji-data.json.
 *
 * Nothing about the picker touches page load. The data is FETCHED and the grid
 * is BUILT on first open — ~1,900 nodes and 11 KB of characters is real work,
 * and a chat where nobody opens the picker must not pay for any of it. The
 * fetch is warmed on hover, so by the time the click lands it is usually there.
 */
(function (window, document) {
    'use strict';

    var CFG = window.PCU_EMOJI || {};
    var RECENT_KEY = 'pcu-emoji-recent';
    var RECENT_MAX = 24;

    var loading = null;                      // the in-flight (or settled) fetch

    /**
     * Fetch the emoji once, however many composers or clicks ask for it.
     *
     * JSON rather than a text file: response.json() is decoded as UTF-8 by
     * spec, whereas a .txt served without an explicit charset gets read as
     * Latin-1 by some hosts and every emoji arrives as mojibake.
     */
    function load() {
        if (!loading) {
            loading = window.fetch(CFG.url, { credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { throw new Error(r.status); }
                    return r.json();
                })
                .catch(function () {
                    loading = null;          // let a later click try again
                    return [];
                });
        }
        return loading;
    }

    // One icon per tab, in the order the groups arrive. Line art, so they
    // inherit currentColor and match the composer's other icons.
    var TAB_ICONS = {
        'Frequently Used':  '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'Smileys & People': '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.2 4.2 0 0 0 7 0"/><path d="M9 9.5h.01M15 9.5h.01"/>',
        'Animals & Nature': '<path d="M5 11a2 2 0 1 1 2-3.4"/><path d="M19 11a2 2 0 1 0-2-3.4"/><path d="M12 20c-3.3 0-5.5-2-5.5-4.6C6.5 12 9 9.5 12 9.5s5.5 2.5 5.5 5.9C17.5 18 15.3 20 12 20z"/><path d="M10 15h.01M14 15h.01"/>',
        'Food & Drink':     '<path d="M12 21a7 7 0 0 0 7-7c0-3-2-6-7-6s-7 3-7 6a7 7 0 0 0 7 7z"/><path d="M12 8c0-2 .8-3.5 2.5-5"/>',
        'Activities':       '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18"/><path d="M5.6 5.6c3 2.4 9.8 2.4 12.8 0M5.6 18.4c3-2.4 9.8-2.4 12.8 0"/>',
        'Travel & Places':  '<path d="M4 16V9.5A2.5 2.5 0 0 1 6.5 7h11A2.5 2.5 0 0 1 20 9.5V16"/><path d="M3 16h18v2H3z"/><circle cx="7.5" cy="19" r="1.4"/><circle cx="16.5" cy="19" r="1.4"/><path d="M4 11h16"/>',
        'Objects':          '<path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.5.4.8 1 .8 1.6V16h5.4v-.5c0-.6.3-1.2.8-1.6A6 6 0 0 0 12 3z"/>',
        'Symbols':          '<path d="M4 8h6M7 8v9"/><path d="M14 17l4-9 4 9M15.2 14h5.6"/>',
        'Flags':            '<path d="M5 21V4"/><path d="M5 5h11l-2 3.5L16 12H5"/>'
    };

    function svg(paths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
               'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    }

    /**
     * One emoji button.
     *
     * The character is written to BOTH the button's text and a data-emoji
     * attribute, and every read goes through the attribute.
     *
     * WordPress ships wp-emoji-release.js, which rewrites emoji characters in
     * the page as <img class="emoji"> (Twemoji) whenever the browser cannot
     * draw them itself — Windows cannot draw flag emoji, so this fires for most
     * Windows users, and it fires on our buttons too because it watches the DOM
     * for new nodes. Once it has run the button holds an <img> and no text at
     * all, so button.textContent is EMPTY and inserting it inserts nothing.
     *
     * The attribute survives that rewrite. Leave WordPress to it — swapping in
     * an image is the only way flags render on Windows at all.
     */
    function cell(emoji) {
        return '<button type="button" class="pcu-emoji" tabindex="-1" data-emoji="' +
               emoji + '">' + emoji + '</button>';
    }

    function charOf(button) {
        return button.getAttribute('data-emoji') || button.textContent;
    }

    /**
     * Split a group's character data into individual emoji.
     *
     * NOT a plain split(''): an emoji is routinely several code units — a
     * surrogate pair, a ZWJ sequence, a variation selector — and splitting on
     * code units would shred them. Intl.Segmenter groups by grapheme, which is
     * exactly "one thing the user sees". Array.from() at least keeps surrogate
     * pairs intact where Segmenter is missing (older Safari).
     */
    var segmenter = window.Intl && Intl.Segmenter
        ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
        : null;

    function split(str) {
        if (!segmenter) { return Array.from(str); }

        var out = [];
        var it = segmenter.segment(str)[Symbol.iterator]();
        for (var s = it.next(); !s.done; s = it.next()) {
            out.push(s.value.segment);
        }
        return out;
    }

    // ------------------------------------------------------------- recents

    function readRecent() {
        try {
            var raw = window.localStorage.getItem(RECENT_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];                       // private mode, or a corrupt entry
        }
    }

    function pushRecent(emoji) {
        var list = readRecent().filter(function (e) { return e !== emoji; });
        list.unshift(emoji);
        list = list.slice(0, RECENT_MAX);

        try {
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(list));
        } catch (e) {
            /* nothing we can do, and nothing worth breaking the picker over */
        }
        return list;
    }

    // -------------------------------------------------------------- insert

    /**
     * Drop the emoji in at the caret, not at the end — someone who clicked back
     * into the middle of a sentence means to put it there.
     */
    function insertAtCaret(field, text) {
        var start = field.selectionStart;
        var end   = field.selectionEnd;

        if (start === null || start === undefined) {          // no selection API
            field.value += text;
        } else {
            field.value = field.value.slice(0, start) + text + field.value.slice(end);
            field.selectionStart = field.selectionEnd = start + text.length;
        }

        field.focus();
        // The composer's send button watches for input to enable itself.
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // --------------------------------------------------------------- build

    /**
     * The message box this button belongs to.
     *
     * Climbing to the nearest ancestor that CONTAINS a textarea, rather than
     * assuming a <form>: the composer is a form on the order screens but not
     * everywhere, and an assumption that silently returns null would leave the
     * button dead with nothing in the console to say why.
     */
    function fieldFor(btn) {
        for (var node = btn; node && node !== document.body; node = node.parentNode) {
            var found = node.querySelector &&
                       (node.querySelector('textarea[name="message"]') || node.querySelector('textarea'));
            if (found) { return found; }
        }
        return null;
    }

    function init(btn) {
        var wrap  = btn.closest('.pcu-emoji-wrap') || btn.parentNode;
        var field = fieldFor(btn);
        if (!field) { return; }

        var pop     = null;              // built on first open
        var scroll  = null;
        var built   = false;
        var recentGrid = null;

        function build(GROUPS) {
            pop = document.createElement('div');
            pop.className = 'pcu-emoji-pop';
            pop.setAttribute('role', 'dialog');
            pop.setAttribute('aria-label', 'Emoji');

            var tabs = document.createElement('div');
            tabs.className = 'pcu-emoji-tabs';

            var body = document.createElement('div');
            body.className = 'pcu-emoji-body pcu-scroll';

            var track = document.createElement('div');
            track.className = 'pcu-sb-track is-idle';
            track.setAttribute('aria-hidden', 'true');
            var thumb = document.createElement('div');
            thumb.className = 'pcu-sb-thumb';
            track.appendChild(thumb);

            var sections = [];
            var all = [{ name: 'Frequently Used', emoji: '' }].concat(GROUPS);

            all.forEach(function (group, i) {
                var tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'pcu-emoji-tab' + (i === 0 ? ' is-active' : '');
                tab.title = group.name;
                tab.setAttribute('aria-label', group.name);
                tab.innerHTML = svg(TAB_ICONS[group.name] || TAB_ICONS.Symbols);
                tabs.appendChild(tab);

                var section = document.createElement('div');
                section.className = 'pcu-emoji-section';

                var head = document.createElement('div');
                head.className = 'pcu-emoji-head';
                head.textContent = group.name;
                section.appendChild(head);

                var grid = document.createElement('div');
                grid.className = 'pcu-emoji-grid';

                // One string of HTML rather than 1,900 appendChild calls: the
                // browser parses it in one pass, which is the difference
                // between a snappy first open and a visible stall.
                grid.innerHTML = split(group.emoji).map(cell).join('');

                section.appendChild(grid);
                body.appendChild(section);

                sections.push({ tab: tab, section: section, grid: grid, name: group.name });
                if (i === 0) { recentGrid = grid; }

                tab.addEventListener('click', function () {
                    // scrollIntoView would also scroll the PAGE; set scrollTop.
                    body.scrollTop = section.offsetTop - body.firstChild.offsetTop;
                });
            });

            renderRecent();

            var scroller = document.createElement('div');
            scroller.className = 'pcu-emoji-scroll-wrap';
            scroller.appendChild(body);
            scroller.appendChild(track);

            pop.appendChild(tabs);
            pop.appendChild(scroller);
            wrap.appendChild(pop);          // positioned against .pcu-emoji-wrap

            scroll = window.PCU.scrollbar(body, track, thumb);

            // Highlight the tab whose section is under the top of the viewport.
            body.addEventListener('scroll', function () {
                var top = body.scrollTop + 4;
                var active = sections[0];

                sections.forEach(function (s) {
                    if (s.section.offsetTop - body.firstChild.offsetTop <= top) { active = s; }
                });
                sections.forEach(function (s) {
                    s.tab.classList.toggle('is-active', s === active);
                });
            }, { passive: true });

            // One listener for every emoji, however many there are.
            body.addEventListener('click', function (e) {
                var hit = e.target.closest('.pcu-emoji');
                if (!hit) { return; }

                var emoji = charOf(hit);
                if (!emoji) { return; }

                insertAtCaret(field, emoji);
                pushRecent(emoji);
                renderRecent();
            });

            built = true;
        }

        function renderRecent() {
            if (!recentGrid) { return; }

            var list = readRecent();
            recentGrid.parentNode.classList.toggle('is-empty', list.length === 0);
            recentGrid.innerHTML = list.map(cell).join('');
        }

        /**
         * Keep the popup on screen.
         *
         * It hangs off the button, and the button sits mid-row — so on a phone
         * the popup's right edge runs past the viewport. Nudge it back by
         * however much it overhangs. Measured rather than guessed at in a media
         * query, because where the button lands depends on the composer, which
         * belongs to the theme and can change.
         */
        function clamp() {
            var margin = 12;

            pop.style.left = '0px';                       // reset before measuring
            var box = pop.getBoundingClientRect();
            var shift = 0;

            if (box.right > window.innerWidth - margin) {
                shift = (window.innerWidth - margin) - box.right;
            }
            if (box.left + shift < margin) {              // and not off the LEFT
                shift = margin - box.left;
            }

            pop.style.left = shift + 'px';
        }

        function open() {
            load().then(function (groups) {
                if (!groups.length) { return; }     // fetch failed; nothing to show
                if (!built) { build(groups); }

                pop.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
                clamp();
                scroll.update();

                document.addEventListener('mousedown', outside);
                document.addEventListener('keydown', onKey);
                window.addEventListener('resize', clamp);
            });
        }

        function close() {
            if (!pop) { return; }

            pop.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');

            document.removeEventListener('mousedown', outside);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', clamp);
        }

        function isOpen() {
            return pop && pop.classList.contains('is-open');
        }

        function outside(e) {
            if (!pop.contains(e.target) && !btn.contains(e.target)) { close(); }
        }

        function onKey(e) {
            if (e.key === 'Escape') { close(); field.focus(); }
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isOpen()) { close(); } else { open(); }
        });

        // Warm the fetch on the way to the click, so the popup opens instantly.
        btn.addEventListener('mouseenter', load, { once: true });
        btn.addEventListener('focus', load, { once: true });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!CFG.url || !window.fetch) { return; }
        [].forEach.call(document.querySelectorAll('.pcu-emoji-btn'), init);
    });

}(window, document));
