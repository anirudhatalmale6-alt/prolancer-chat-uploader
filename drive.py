#!/usr/bin/env python3
"""Drive the uploader end-to-end and screenshot every step."""
import os
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.path.join(HERE, 'shots')
FIX = os.path.join(HERE, 'fixtures')
os.makedirs(SHOTS, exist_ok=True)

FILES = [os.path.join(FIX, f) for f in
         ['mockup-homepage.png', 'logo-draft.png', 'banner-v2.png',
          'wireframe.png', 'spec-sheet.pdf']]


def shot(page, name):
    page.screenshot(path=os.path.join(SHOTS, name))
    print('shot:', name)


with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_page(viewport={'width': 1280, 'height': 800})
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append('console.' + m.type + ': ' + m.text)
            if m.type == 'error' else None)

    page.goto('http://127.0.0.1:8811/index.html')
    page.wait_for_timeout(500)
    shot(page, '01-composer-icon.png')

    # 1. Icon opens the modal
    page.click('.pcu-attach-btn')
    page.wait_for_timeout(700)
    shot(page, '02-modal-empty-upload-dimmed.png')

    upload_disabled_before = page.is_disabled('.pcu-btn-upload')
    print('Upload disabled with 0 files:', upload_disabled_before)

    # 2. Modal must NOT close on backdrop click or ESC
    page.mouse.click(20, 20)          # click outside the dialog
    page.wait_for_timeout(400)
    still_open_backdrop = page.is_visible('.pcu-modal.show')
    page.keyboard.press('Escape')
    page.wait_for_timeout(400)
    still_open_esc = page.is_visible('.pcu-modal.show')
    print('Open after backdrop click:', still_open_backdrop, '| after ESC:', still_open_esc)

    # 3. Attach files
    # Dropzone appends its hidden multi-file input to <body>
    page.set_input_files('body > input[type=file]', FILES)
    page.wait_for_timeout(1200)
    shot(page, '03-files-attached-thumbnails.png')

    upload_enabled_after = not page.is_disabled('.pcu-btn-upload')
    rows = page.locator('.pcu-file-row').count()
    print('Rows:', rows, '| Upload enabled with files:', upload_enabled_after)

    # 4. Body must scroll, not grow
    box = page.locator('.pcu-modal-body').bounding_box()
    metrics = page.evaluate("""() => {
        const b = document.querySelector('.pcu-modal-body');
        return {clientH: b.clientHeight, scrollH: b.scrollHeight,
                scrollable: b.scrollHeight > b.clientHeight};
    }""")
    print('Modal body height:', round(box['height']), '| metrics:', metrics)

    page.evaluate("document.querySelector('.pcu-modal-body').scrollTop = 9999")
    page.wait_for_timeout(300)
    shot(page, '04-scrolled-fixed-height.png')

    # 5. Remove one file
    page.locator('.pcu-file-remove').first.click()
    page.wait_for_timeout(400)
    print('Rows after remove:', page.locator('.pcu-file-row').count())

    # 6. Upload — spinner shows, then files land in the chat, no success dialog
    page.locator('.pcu-modal-body').evaluate("b => b.scrollTop = 0")
    page.click('.pcu-btn-upload')
    page.wait_for_timeout(350)
    shot(page, '05-uploading-spinner.png')

    page.wait_for_selector('.pcu-chat-attachments', timeout=15000)
    page.wait_for_timeout(900)
    modal_closed = not page.is_visible('.pcu-modal.show')
    thumbs = page.locator('.pcu-chat-thumb').count()
    print('Modal auto-closed after upload:', modal_closed, '| chat thumbs:', thumbs)
    shot(page, '06-uploaded-into-chat.png')

    print('\nJS errors:', errors if errors else 'none')
    b.close()
