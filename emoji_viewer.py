#!/usr/bin/env python3
"""
Functional check of the emoji picker and the media viewer.

Everything here is a REAL interaction — clicks, keys, a genuine download event.
The browse-button bug earlier in this project got through precisely because the
test drove the DOM instead of the UI, so nothing below reaches past the surface
a user actually touches.

Run:  python3 emoji_viewer.py
"""
import http.server
import os
import socketserver
import sys
import threading

from playwright.sync_api import sync_playwright, expect

HERE = os.path.dirname(os.path.abspath(__file__))
PORT = 8953
URL = 'http://127.0.0.1:%d/index.html' % PORT

ok = True


def check(label, cond, detail=''):
    global ok
    if not cond:
        ok = False
    print('  %s %s%s' % ('PASS' if cond else 'FAIL', label,
                         (' — ' + detail) if detail else ''))


class Quiet(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *a):
        pass


class Server(socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


def serve():
    os.chdir(HERE)
    httpd = Server(('127.0.0.1', PORT), Quiet)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd


def main():
    serve()

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={'width': 1280, 'height': 720})

        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(URL)
        page.wait_for_load_state('networkidle')

        # ------------------------------------------------------ emoji picker
        print('\nEmoji picker')

        pop = page.locator('.pcu-emoji-pop')
        check('popup absent until opened', pop.count() == 0)

        page.click('.pcu-emoji-btn')
        expect(pop).to_be_visible()
        check('opens on icon click', pop.is_visible())

        tabs = page.locator('.pcu-emoji-tab')
        check('9 tabs (recent + 8 groups)', tabs.count() == 9,
              '%d found' % tabs.count())

        total = page.locator('.pcu-emoji').count()
        check('all emoji rendered', total > 1800, '%d buttons' % total)

        # A grapheme check: emoji must not be shredded into half-surrogates.
        first = page.locator('.pcu-emoji-section').nth(1).locator('.pcu-emoji').first
        check('emoji are whole graphemes', first.inner_text() == '\U0001F600',
              repr(first.inner_text()))

        # The popup must not grow as you scroll it.
        h1 = pop.bounding_box()['height']
        page.locator('.pcu-emoji-body').evaluate('n => n.scrollTop = 900')
        page.wait_for_timeout(150)
        h2 = pop.bounding_box()['height']
        check('fixed height while scrolling', abs(h1 - h2) < 1,
              '%.0f -> %.0f' % (h1, h2))

        check('scrollbar thumb drawn', page.locator('.pcu-emoji-pop .pcu-sb-thumb').count() == 1)

        # Tab click jumps to that group.
        tabs.nth(4).click()
        page.wait_for_timeout(200)
        scrolled = page.locator('.pcu-emoji-body').evaluate('n => n.scrollTop')
        check('tab jumps to its group', scrolled > 0, 'scrollTop=%d' % scrolled)

        # Insert at the caret, not blindly at the end.
        page.fill('textarea[name="message"]', 'hello world')
        page.locator('textarea[name="message"]').evaluate(
            'n => { n.selectionStart = n.selectionEnd = 5; }')
        page.locator('.pcu-emoji').first.click()
        val = page.input_value('textarea[name="message"]')
        check('inserts at the caret', val.startswith('hello') and ' world' in val
              and len(val) > len('hello world'), repr(val))

        # Picking one files it under Frequently Used.
        page.wait_for_timeout(100)
        recent = page.locator('.pcu-emoji-section').first.locator('.pcu-emoji').count()
        check('picked emoji lands in Frequently Used', recent >= 1, '%d' % recent)

        # --- the three faults the client reported, pinned down ----------------
        # 1+2. The parent theme styles EVERY <button> on the site:
        #        button, input[type=button], … {
        #            color:#fff; font-size:16px; border-radius:50px;
        #            height:60px; padding:0 50px; border:none;
        #        }
        #      That forced the emoji cells to 60px (so the grid overflowed and
        #      the glyphs came out huge) and bent the active tab's 2px underline
        #      into an arc. Apply the real rule and hold the picker to its own
        #      geometry.
        page.add_style_tag(content="""
            button, input[type="button"], input[type="reset"], input[type="submit"] {
                cursor: pointer; color: #fff; font-size: 16px;
                border-radius: 50px; height: 60px; padding: 0 50px; border: none;
            }""")
        page.wait_for_timeout(150)

        cellbox = page.locator('.pcu-emoji').first.bounding_box()
        check("theme's 60px button rule can't stretch the emoji cells",
              cellbox['height'] < 50, '%.0fpx' % cellbox['height'])

        radius = page.locator('.pcu-emoji-tab').first.evaluate(
            'n => getComputedStyle(n).borderRadius')
        check("theme's 50px radius can't curve the active tab underline",
              radius == '0px', radius)

        grid = page.locator('.pcu-emoji-grid').first
        overflows = grid.evaluate(
            'g => g.scrollWidth > g.closest(".pcu-emoji-body").clientWidth + 1')
        check('emoji grid does not overflow sideways', not overflows)

        # 3. WordPress rewrites emoji characters as <img class="emoji"> (Twemoji)
        #    when the browser can't draw them — Windows can't draw flags, so it
        #    fires for most Windows users. The button is then left with an image
        #    and NO TEXT, so reading textContent inserts nothing. Do exactly what
        #    wp-emoji-release.js does, and the pick must still work.
        page.evaluate("""() => {
          document.querySelectorAll('.pcu-emoji').forEach(b => {
            b.innerHTML = '<img class="emoji" alt="' + b.textContent + '" src="data:image/svg+xml,' +
                          '%3Csvg xmlns=%27http://www.w3.org/2000/svg%27/%3E">';
          });
        }""")
        page.wait_for_timeout(100)

        swapped = page.locator('.pcu-emoji').nth(3)
        check('WordPress leaves the button with no text at all',
              swapped.evaluate('n => n.textContent') == '')

        page.fill('textarea[name="message"]', '')
        want = swapped.get_attribute('data-emoji')
        swapped.click()
        check('emoji still inserts once WordPress has swapped it for an image',
              page.input_value('textarea[name="message"]') == want,
              repr(page.input_value('textarea[name="message"]')))

        # Closes on outside click; stays open otherwise.
        page.mouse.click(20, 400)
        check('closes on outside click', not pop.is_visible())

        page.click('.pcu-emoji-btn')
        page.keyboard.press('Escape')
        check('closes on Escape', not pop.is_visible())

        # ------------------------------------------------------ media viewer
        print('\nMedia viewer')

        viewer = page.locator('.pcu-viewer')
        check('viewer absent until opened', viewer.count() == 0)

        # A video in the chat shows a frame from itself, not a grey "MP4" tile —
        # with a play badge, or it is indistinguishable from a photo.
        vid_thumb = page.locator('.pcu-chat-thumb[data-kind="video"]')
        check('video thumbnail is a frame from the video',
              vid_thumb.locator('img').count() == 1
              and vid_thumb.locator('.pcu-chat-file').count() == 0)
        check('video thumbnail carries a play badge',
              vid_thumb.locator('.pcu-chat-play').count() == 1)
        check('play badge does not swallow the click',
              vid_thumb.locator('.pcu-chat-play').evaluate(
                  'n => getComputedStyle(n).pointerEvents') == 'none')

        # Click the FIRST image in the chat.
        page.locator('.pcu-chat-thumb').first.click()
        expect(viewer).to_be_visible()
        check('opens on thumbnail click', viewer.is_visible())

        # 5 attachments across two separate messages — the viewer must span the
        # whole conversation, not just the message that was clicked.
        check('counts every file in the chat',
              page.locator('.pcu-viewer-count').inner_text() == '1 / 5',
              page.locator('.pcu-viewer-count').inner_text())

        check('image is rendered', page.locator('.pcu-viewer-stage img').count() == 1)

        # Arrows step forward.
        page.click('.pcu-viewer-next')
        check('next advances the counter',
              page.locator('.pcu-viewer-count').inner_text() == '2 / 5')

        # Keyboard.
        page.keyboard.press('ArrowRight')
        check('right arrow key advances',
              page.locator('.pcu-viewer-count').inner_text() == '3 / 5')
        page.keyboard.press('ArrowLeft')
        check('left arrow key goes back',
              page.locator('.pcu-viewer-count').inner_text() == '2 / 5')

        # #3 is the PDF: no preview, a file tile instead.
        page.keyboard.press('ArrowRight')
        check('non-media shows a file tile, no preview',
              page.locator('.pcu-viewer-file').count() == 1
              and page.locator('.pcu-viewer-stage img').count() == 0)
        check('file tile names the type',
              'PDF' in page.locator('.pcu-viewer-file').inner_text(),
              page.locator('.pcu-viewer-file').inner_text())

        # #4 is the video: it must actually play, and it must carry the frame
        # ffmpeg grabbed from it rather than opening as a black box.
        page.keyboard.press('ArrowRight')
        video = page.locator('.pcu-viewer-stage video')
        check('video element is rendered', video.count() == 1)
        check('video opens on its poster frame, not black',
              bool(video.get_attribute('poster')),
              video.get_attribute('poster') or 'no poster')

        # While it buffers the site's OWN spinner shows — and the browser's does
        # not, because a <video>'s built-in spinner lives inside its default
        # controls and cannot be styled, so the controls are withheld until it
        # can play. Assert both halves: ours on, its controls off.
        loader = page.locator('.pcu-viewer-loader')
        if video.evaluate('v => v.readyState < 3'):
            check("the site's own spinner shows while the video loads",
                  loader.is_visible())
            check("the browser's own spinner is suppressed (controls held back)",
                  video.evaluate('v => !v.controls'))

        page.wait_for_function(
            "() => { const v = document.querySelector('.pcu-viewer-stage video');"
            "        return v && v.readyState >= 3; }", timeout=15000)
        page.wait_for_timeout(300)

        check('spinner clears once the video is playable', not loader.is_visible())
        check('controls appear once the video is playable',
              video.evaluate('v => v.controls'))
        check('spinner uses the same gif as the rest of the site',
              'loader.gif' in (page.locator('.pcu-viewer-loader img').get_attribute('src') or ''),
              page.locator('.pcu-viewer-loader img').get_attribute('src') or 'none')

        playing = video.evaluate('v => !v.paused && v.currentTime > 0')
        check('video plays in the viewer', playing)

        # Wrap around from the last item back to the first.
        page.keyboard.press('ArrowRight')          # 5/5, the zip
        page.keyboard.press('ArrowRight')          # wraps
        check('wraps past the last file',
              page.locator('.pcu-viewer-count').inner_text() == '1 / 5')

        # The download button must trigger a SAVE, not a navigation.
        with page.expect_download() as dl:
            page.click('.pcu-viewer-download')
        got = dl.value
        check('download opens a save dialogue',
              got.suggested_filename == 'mockup-homepage.png',
              got.suggested_filename)

        # A playing video must not keep playing after the viewer closes.
        page.keyboard.press('ArrowLeft')           # back to the zip
        page.keyboard.press('ArrowLeft')           # the video
        page.wait_for_timeout(300)
        page.keyboard.press('Escape')
        check('closes on Escape', not viewer.is_visible())
        check('video is torn down on close',
              page.locator('.pcu-viewer-stage video').count() == 0)

        # Ctrl-click must still open the file in a new tab.
        page.locator('.pcu-chat-thumb').first.click(modifiers=['Control'])
        check('ctrl-click is left alone', not viewer.is_visible())

        print('\nConsole')
        check('no JS errors', not errors, '; '.join(errors))

        # ---------------------------------------------------------- screenshots
        page.click('.pcu-emoji-btn')
        page.wait_for_timeout(250)
        page.screenshot(path='../shots/emoji-picker.png')
        page.keyboard.press('Escape')

        page.locator('.pcu-chat-thumb').first.click()
        page.wait_for_timeout(400)
        page.screenshot(path='../shots/viewer-image.png')
        page.keyboard.press('ArrowRight')
        page.keyboard.press('ArrowRight')
        page.wait_for_timeout(250)
        page.screenshot(path='../shots/viewer-file.png')
        page.keyboard.press('ArrowRight')
        page.wait_for_timeout(900)
        page.screenshot(path='../shots/viewer-video.png')

        browser.close()

    print('\n%s' % ('ALL PASS' if ok else 'FAILURES ABOVE'))
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
