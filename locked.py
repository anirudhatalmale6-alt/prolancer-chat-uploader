#!/usr/bin/env python3
"""While uploading, the user must not be able to add files or remove rows."""
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
    page.wait_for_timeout(300)
    page.set_input_files('.pcu-input', FILES)
    page.wait_for_timeout(900)

    rows_before = page.locator('.pcu-file-row').count()
    page.click('.pcu-btn-upload')
    page.wait_for_timeout(150)          # mid-flight

    st = page.evaluate("""() => ({
        busy:        document.getElementById('pcu-uploader').classList.contains('pcu-busy'),
        browseDis:   document.querySelector('.pcu-dz-browse').disabled,
        inputDis:    document.querySelector('.pcu-input').disabled,
        closeDis:    document.querySelector('.pcu-btn-close').disabled,
        uploadDis:   document.querySelector('.pcu-btn-upload').disabled,
        removesDis:  [...document.querySelectorAll('.pcu-file-remove')].every(b => b.disabled),
        zoneEvents:  getComputedStyle(document.querySelector('.pcu-dropzone')).pointerEvents,
    })""")
    print('mid-upload state:')
    for k, v in st.items():
        print('  %-11s %s' % (k, v))

    # The real test: try to interfere. A forced click must NOT drop a row, and
    # Browse must NOT open a dialog. Dispatch straight through the DOM so a
    # disabled control can't just swallow the synthetic click at the driver level.
    page.evaluate("""() => {
        const b = document.querySelector('.pcu-file-remove');
        if (b) { b.click(); }
    }""")
    page.wait_for_timeout(150)
    rows_mid = page.locator('.pcu-file-row').count()
    print('\n  rows before=%d, after forced remove mid-upload=%d (want unchanged)'
          % (rows_before, rows_mid))

    opened = False
    try:
        with page.expect_file_chooser(timeout=800):
            page.evaluate("document.querySelector('.pcu-dz-browse').click()")
        opened = True
    except Exception:
        pass
    print('  Browse opened a dialog mid-upload:', opened, '(want False)')

    # And once it's done, everything must be usable again.
    page.wait_for_selector('.pcu-chat-attachments', timeout=15000)
    page.wait_for_timeout(600)
    after = page.evaluate("""() => ({
        busy:      document.getElementById('pcu-uploader').classList.contains('pcu-busy'),
        browseDis: document.querySelector('.pcu-dz-browse').disabled,
        inputDis:  document.querySelector('.pcu-input').disabled,
        closeDis:  document.querySelector('.pcu-btn-close').disabled,
    })""")
    print('\nafter upload — controls released:', after)
    print('chat thumbs:', page.locator('.pcu-chat-thumb').count())
    print('JS errors:', errs if errs else 'none')

    ok = (st['busy'] and st['browseDis'] and st['inputDis'] and st['closeDis']
          and st['removesDis'] and not opened and rows_mid == rows_before
          and not after['busy'] and not after['browseDis'] and not after['closeDis'])
    print('\n' + ('PASS: locked during upload, released after' if ok else 'FAIL'))
    if not ok:
        raise SystemExit(1)
    b.close()
