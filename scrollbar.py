#!/usr/bin/env python3
"""Verify the custom Dhonu-style scrollbar: renders, tracks, drags."""
import os
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
FIX = os.path.join(HERE, 'fixtures')
FILES = [os.path.join(FIX, f) for f in
         ['mockup-homepage.png', 'logo-draft.png', 'banner-v2.png',
          'wireframe.png', 'spec-sheet.pdf']]

with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_page(viewport={'width': 1280, 'height': 800})
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)))

    page.goto('http://127.0.0.1:8811/index.html')
    page.wait_for_timeout(400)
    page.click('.pcu-attach-btn')
    page.wait_for_timeout(400)

    # No files yet -> nothing to scroll -> thumb parked
    idle = page.locator('.pcu-sb-track').evaluate("t => t.classList.contains('is-idle')")
    print('Empty modal — thumb hidden (is-idle):', idle)

    page.set_input_files('.pcu-input', FILES)
    page.wait_for_timeout(1200)

    def state():
        return page.evaluate("""() => {
            const s = document.querySelector('.pcu-modal-body');
            const t = document.querySelector('.pcu-sb-track');
            const th = document.querySelector('.pcu-sb-thumb');
            const cs = getComputedStyle(th);
            return {
                idle: t.classList.contains('is-idle'),
                trackH: t.clientHeight, thumbH: th.offsetHeight,
                thumbTop: parseFloat(th.style.top || 0),
                scrollTop: Math.round(s.scrollTop),
                maxScroll: s.scrollHeight - s.clientHeight,
                width: cs.width, radius: cs.borderRadius,
                bg: cs.backgroundColor, right: cs.right,
            };
        }""")

    s = state()
    print('With 5 files — overflowing, thumb shown:', not s['idle'])
    print('  thumb: width=%s radius=%s bg=%s right=%s' % (s['width'], s['radius'], s['bg'], s['right']))
    print('  Dhonu spec: width=6px radius=7px bg=rgb(162,173,183) right=2px')
    print('  track=%dpx thumb=%dpx  top=%d (at scrollTop=%d)'
          % (s['trackH'], s['thumbH'], s['thumbTop'], s['scrollTop']))

    # Scroll to the bottom — thumb must land exactly at the end of the track
    page.evaluate("document.querySelector('.pcu-modal-body').scrollTop = 99999")
    page.wait_for_timeout(300)
    s2 = state()
    end = s2['trackH'] - s2['thumbH']
    print('  scrolled to bottom: thumbTop=%d, track end=%d -> aligned: %s'
          % (s2['thumbTop'], end, abs(s2['thumbTop'] - end) <= 1))
    page.screenshot(path=os.path.join(HERE, 'shots', '09-scrollbar.png'))
    print('  shot: 09-scrollbar.png')

    # Drag the thumb back up to the top
    box = page.locator('.pcu-sb-thumb').bounding_box()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.mouse.down()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] - 400, steps=12)
    page.mouse.up()
    page.wait_for_timeout(300)
    s3 = state()
    print('  after dragging thumb up: scrollTop=%d (was %d) -> drag works: %s'
          % (s3['scrollTop'], s2['scrollTop'], s3['scrollTop'] < s2['scrollTop']))

    print('\nJS errors:', errs if errs else 'none')
    b.close()
