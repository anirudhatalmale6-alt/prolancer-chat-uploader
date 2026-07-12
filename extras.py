#!/usr/bin/env python3
"""
The five chat items: type icons, own name/photo hidden, Enter to send, the
tooltip, and the online dot.

Every check is a real interaction against markup shaped like the live templates.

Run:  python3 extras.py
"""
import http.server
import json
import urllib.parse
import os
import socketserver
import sys
import threading

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
PORT = 8999
URL = 'http://127.0.0.1:%d/index.html' % PORT

ok = True
online = {'value': True}          # what the fake presence endpoint reports


def check(label, cond, detail=''):
    global ok
    if not cond:
        ok = False
    print('  %s %s%s' % ('PASS' if cond else 'FAIL', label,
                         (' — ' + detail) if detail else ''))


class Handler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_POST(self):
        # Stand in for admin-ajax's pcu_presence. The real endpoint answers a
        # comma-separated `profile_ids` with a {id: bool} map, so mirror that:
        # every requested id reports the current online['value'].
        length = int(self.headers.get('Content-Length', 0))
        raw = self.rfile.read(length).decode() if length else ''
        params = urllib.parse.parse_qs(raw)
        ids = (params.get('profile_ids', [''])[0] or params.get('profile_id', [''])[0]).split(',')
        ids = [i for i in ids if i]
        state = {i: online['value'] for i in ids}
        body = json.dumps({'success': True, 'data': {'online': state}})
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body.encode())


class Server(socketserver.TCPServer):
    allow_reuse_address = True


def main():
    os.chdir(HERE)
    httpd = Server(('127.0.0.1', PORT), Handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={'width': 1100, 'height': 720})

        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))

        sent = []
        page.expose_function('__sent', lambda: sent.append(1))
        page.goto(URL)
        page.wait_for_load_state('networkidle')
        page.evaluate("document.querySelector('.send-service-message')"
                      ".addEventListener('click', () => window.__sent())")

        # ------------------------------------------------- 1. file-type icons
        print('\nFile-type icons')

        pdf = page.locator('.pcu-chat-thumb[data-file$=".pdf"]')
        zipf = page.locator('.pcu-chat-thumb[data-file$=".zip"]')

        check('a PDF shows the pdf icon',
              'pdf.png' in (pdf.locator('img').get_attribute('src') or ''),
              pdf.locator('img').get_attribute('src') or 'none')
        check('a ZIP shows the zip icon',
              'zip.png' in (zipf.locator('img').get_attribute('src') or ''))
        check('the old text tile is gone',
              page.locator('.pcu-chat-file').count() == 0)

        # The icon must be contained, not cropped like a photo.
        check('icon is contained, not cropped',
              pdf.locator('img').evaluate('n => getComputedStyle(n).objectFit') == 'contain')

        # Unknown types fall back to generic.png — the naming IS the lookup.
        check('generic.png exists for unknown types',
              os.path.exists(os.path.join(HERE, 'assets', 'icons', 'generic.png')))

        # ------------------------------------- 2. own name and photo removed
        print('\nYour own name and photo')

        mine = page.locator('.chat-list.message_sender')
        theirs = page.locator('.chat-list.message_receiver').first

        check('hidden on your own messages',
              not mine.locator('.col-3').is_visible())
        check('still shown on theirs',
              theirs.locator('.col-3').is_visible())

        # …and the message takes the width the column used to occupy.
        w_mine = mine.locator('.col-9').bounding_box()['width']
        w_row = mine.locator('.row').bounding_box()['width']
        check('your message takes the full width',
              abs(w_mine - w_row) < 2, '%.0f of %.0f' % (w_mine, w_row))

        # ---------------------------------------------------- 3. Enter to send
        print('\nEnter to send')

        box = page.locator('textarea[name="message"]')
        box.click()
        box.type('hello there')
        page.keyboard.press('Enter')
        page.wait_for_timeout(150)
        check('Enter sends', len(sent) == 1, '%d send(s)' % len(sent))

        box.fill('')
        box.click()
        box.type('line one')
        page.keyboard.press('Shift+Enter')
        box.type('line two')
        page.wait_for_timeout(100)
        val = box.input_value()
        check('Shift+Enter makes a new line and does NOT send',
              '\n' in val and len(sent) == 1, repr(val))

        box.fill('')
        page.keyboard.press('Enter')
        page.wait_for_timeout(100)
        check('Enter on an empty box sends nothing', len(sent) == 1)

        # ---------------------------------------------------------- 4. tooltip
        print('\nTooltip')

        check('no native title attribute left to fight with',
              page.locator('.pcu-chat-thumb[title]').count() == 0)

        tip = page.locator('.pcu-tip')
        pdf.hover()
        page.wait_for_timeout(250)

        check('tooltip appears on hover', tip.is_visible())
        text = tip.inner_text()
        check('it is multiline: name, then type and size',
              '\n' in text and 'Spec sheet, final.pdf' in text and 'PDF' in text,
              repr(text))

        # WordPress sanitises an upload's name — spaces become hyphens. Showing
        # that back to the reader is showing them plumbing; the post title keeps
        # the name they actually chose.
        check('the shown name has no hyphens from sanitising',
              '-' not in text.split('\n')[0], repr(text.split('\n')[0]))
        check('newline is honoured (white-space)',
              tip.evaluate('n => getComputedStyle(n).whiteSpace') == 'pre-line')
        check('tooltip never eats the hover it describes',
              tip.evaluate('n => getComputedStyle(n).pointerEvents') == 'none')

        # It must sit over the thumbnail, not somewhere random.
        tb, pb = tip.bounding_box(), pdf.bounding_box()
        check('anchored to the thumbnail',
              abs((tb['x'] + tb['width'] / 2) - (pb['x'] + pb['width'] / 2)) < 40)

        page.mouse.move(5, 5)
        page.wait_for_timeout(200)
        check('tooltip hides again', not tip.is_visible())

        # ------------------------------------------------------- 5. online dot
        print('\nOnline / offline dot')

        dots = page.locator('.pcu-avatar')
        check('a dot on each of THEIR avatars', dots.count() == 2, '%d' % dots.count())
        check('no dot on your own (hidden) avatar',
              page.locator('.chat-list.message_sender .pcu-avatar').count() == 0)

        # The avatar is a CIRCLE, so the dot must be inset along the diagonal —
        # pinned to the square box's corner it sat half on the photo, half off.
        # Measure to the dot's RIM, not its square bounding-box corner.
        # The client drew where he wants it: the dot's CENTRE sitting ON the
        # avatar's rim, at the bottom-right diagonal — straddling the edge, half
        # on the photo and half off. So measure the dot's centre against the
        # radius, not its outer edge.
        geo = page.evaluate("""() => {
          const w = document.querySelector('.pcu-avatar'), d = document.querySelector('.pcu-dot');
          const img = w.querySelector('img');
          const ib = img.getBoundingClientRect(), db = d.getBoundingClientRect();
          const cx = ib.x + ib.width/2, cy = ib.y + ib.height/2;
          const dx = db.x + db.width/2, dy = db.y + db.height/2;
          return {
            r: ib.width/2,
            dist: Math.hypot(dx-cx, dy-cy),
            angle: Math.atan2(dy-cy, dx-cx) * 180 / Math.PI
          };
        }""")
        check('dot centre sits ON the avatar rim',
              abs(geo['dist'] - geo['r']) < 1.0,
              'centre %.2fpx from middle, radius %.1fpx' % (geo['dist'], geo['r']))
        check('…at the bottom-right diagonal (45 deg)',
              abs(geo['angle'] - 45) < 2, '%.1f deg' % geo['angle'])

        check('green when the other user is online',
              dots.first.evaluate('n => n.classList.contains("is-online")'))
        colour = page.locator('.pcu-dot').first.evaluate(
            'n => getComputedStyle(n).backgroundColor')
        check('the dot is actually green', colour == 'rgb(18, 183, 106)', colour)

        # Flip the server's answer and let the next ping land.
        online['value'] = False
        page.evaluate("document.dispatchEvent(new CustomEvent('pcu:appended'))")
        page.wait_for_timeout(400)

        check('grey when they go offline',
              dots.first.evaluate('n => n.classList.contains("is-offline")'))
        colour = page.locator('.pcu-dot').first.evaluate(
            'n => getComputedStyle(n).backgroundColor')
        check('the dot is actually grey', colour == 'rgb(173, 181, 189)', colour)

        print('\nConsole')
        check('no JS errors', not errors, '; '.join(errors))

        # ------------------------------------------------------- screenshots
        online['value'] = True
        page.evaluate("document.dispatchEvent(new CustomEvent('pcu:appended'))")
        page.wait_for_timeout(400)
        pdf.hover()
        page.wait_for_timeout(250)
        page.locator('.chat-card').screenshot(path='../shots/chat-extras.png')

        browser.close()

    print('\n%s' % ('ALL PASS' if ok else 'FAILURES ABOVE'))
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
