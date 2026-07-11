#!/usr/bin/env python3
"""
The test I was missing.

Every earlier test used set_input_files(), which writes to the <input> directly
and NEVER clicks anything — so it could not possibly catch a Browse button that
fails to open the file dialog. This one clicks for real and asserts the browser
actually raises a filechooser.
"""
import os
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
URL = 'http://127.0.0.1:8811/index.html'


def expect_chooser(page, do_click, label):
    """True if the click actually made the browser open a file dialog."""
    try:
        with page.expect_file_chooser(timeout=3000) as fc:
            do_click()
        chooser = fc.value
        print('  %-28s file dialog OPENED (multiple=%s)' % (label, chooser.is_multiple()))
        return True
    except Exception:
        print('  %-28s NO FILE DIALOG  <-- broken' % label)
        return False


with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_page(viewport={'width': 1280, 'height': 800})
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)))

    page.goto(URL)
    page.wait_for_timeout(400)
    page.click('.pcu-attach-btn')
    page.wait_for_timeout(400)

    ok_browse = expect_chooser(
        page, lambda: page.click('.pcu-dz-browse'), 'Browse files button')

    # Reopen cleanly and try the dropzone body itself
    page.keyboard.press('Escape')
    ok_zone = expect_chooser(
        page, lambda: page.click('.pcu-dz-title'), 'Dropzone click')

    print('\nJS errors:', errs if errs else 'none')

    if not (ok_browse and ok_zone):
        raise SystemExit('FAIL: file dialog did not open')

    print('PASS: both open the file dialog')
    b.close()
