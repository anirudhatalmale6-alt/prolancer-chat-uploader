#!/usr/bin/env python3
"""Check the client's RULES: no layout shift, no extra stylesheets, responsive."""
import os
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.path.join(HERE, 'shots')
FIX = os.path.join(HERE, 'fixtures')
FILES = [os.path.join(FIX, f) for f in
         ['mockup-homepage.png', 'logo-draft.png', 'banner-v2.png',
          'wireframe.png', 'spec-sheet.pdf']]

URL = 'http://127.0.0.1:8811/index.html'

with sync_playwright() as p:
    b = p.chromium.launch()

    # ---- Rule 2: no shaking / shifting on load (Cumulative Layout Shift) ----
    page = b.new_page(viewport={'width': 1280, 'height': 800})
    page.add_init_script("""
        window.__cls = 0;
        new PerformanceObserver(list => {
            for (const e of list.getEntries()) {
                if (!e.hadRecentInput) { window.__cls += e.value; }
            }
        }).observe({type: 'layout-shift', buffered: true});
    """)
    page.goto(URL)
    page.wait_for_timeout(2500)
    cls = page.evaluate('window.__cls')
    print('Rule 2 — CLS on load: %.5f  (good < 0.1)' % cls)

    # ---- Rules 3 & 4: asset count / weight, no style-after-style ----
    assets = page.evaluate("""() => {
        const css = [...document.querySelectorAll('link[rel=stylesheet]')].map(l => l.getAttribute('href'));
        const js  = [...document.querySelectorAll('script[src]')].map(s => s.getAttribute('src'));
        return {css, js};
    }""")
    print('Rules 3/4 — stylesheets:', assets['css'])
    print('Rules 3/4 — scripts    :', assets['js'])

    # Bytes actually shipped for the uploader
    total = 0
    for f in ['assets/chat-uploader.css', 'assets/chat-uploader.js', 'vendor/dropzone.min.js']:
        n = os.path.getsize(os.path.join(HERE, f))
        total += n
        print('   %-32s %6.1f KB' % (f, n / 1024))
    print('   %-32s %6.1f KB' % ('TOTAL', total / 1024))

    # ---- Rule 2 again: shift when files are added to the list ----
    page.evaluate("window.__cls = 0")
    page.click('.pcu-attach-btn')
    page.wait_for_timeout(400)
    page.set_input_files('body > input[type=file]', FILES)
    page.wait_for_timeout(1500)
    cls_modal = page.evaluate('window.__cls')
    print('Rule 2 — CLS while adding 5 files: %.5f' % cls_modal)
    page.close()

    # ---- Rule 6: responsive ----
    for label, w, h in [('mobile', 390, 780), ('tablet', 768, 900)]:
        pg = b.new_page(viewport={'width': w, 'height': h})
        pg.goto(URL)
        pg.wait_for_timeout(400)
        pg.click('.pcu-attach-btn')
        pg.wait_for_timeout(400)
        pg.set_input_files('body > input[type=file]', FILES)
        pg.wait_for_timeout(1200)

        overflow = pg.evaluate("""() => ({
            bodyOverflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            docW: document.documentElement.scrollWidth,
            winW: window.innerWidth
        })""")
        btn = pg.locator('.pcu-btn-upload').bounding_box()
        vis = pg.is_visible('.pcu-btn-upload') and pg.is_visible('.pcu-btn-close')
        print('Rule 6 — %-6s %dpx: horizontal overflow=%s (doc %dpx vs win %dpx), '
              'buttons visible=%s, Upload w=%dpx'
              % (label, w, overflow['bodyOverflowX'], overflow['docW'],
                 overflow['winW'], vis, btn['width']))

        pg.screenshot(path=os.path.join(SHOTS, '07-responsive-%s.png' % label))
        print('   shot: 07-responsive-%s.png' % label)
        pg.close()

    b.close()
