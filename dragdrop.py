#!/usr/bin/env python3
"""Exercise the native drag-and-drop that replaced Dropzone."""
from playwright.sync_api import sync_playwright

with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_page(viewport={'width': 1280, 'height': 800})
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)))

    page.goto('http://127.0.0.1:8811/index.html')
    page.wait_for_timeout(400)
    page.click('.pcu-attach-btn')
    page.wait_for_timeout(400)

    # Build a real DataTransfer with files and fire the drag sequence.
    page.evaluate("""() => {
        window.__mkdt = (files) => {
            const dt = new DataTransfer();
            for (const [name, type, body] of files) {
                dt.items.add(new File([body], name, {type}));
            }
            return dt;
        };
    }""")

    def fire(evt, files=None):
        page.evaluate(
            """([evt, files]) => {
                const dz = document.querySelector('.pcu-dropzone');
                const dt = files ? window.__mkdt(files) : new DataTransfer();
                dz.dispatchEvent(new DragEvent(evt, {
                    bubbles: true, cancelable: true, dataTransfer: dt
                }));
            }""", [evt, files])

    # 1x1 transparent PNG bytes, as a plain array the page can turn into a File
    png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    page.evaluate("""(b64) => {
        const bin = atob(b64);
        const arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        window.__png = arr;
    }""", png)

    # --- dragenter highlights the zone ---
    fire('dragenter')
    page.wait_for_timeout(200)
    hl = page.locator('.pcu-dropzone').evaluate("d => d.classList.contains('is-dragover')")
    print('dragenter  -> zone highlighted:', hl)

    # --- dragleave clears it ---
    fire('dragleave')
    page.wait_for_timeout(200)
    hl2 = page.locator('.pcu-dropzone').evaluate("d => d.classList.contains('is-dragover')")
    print('dragleave  -> highlight cleared:', not hl2)

    # --- drop two files: one allowed image, one rejected .exe ---
    page.evaluate("""() => {
        const dz = document.querySelector('.pcu-dropzone');
        const dt = new DataTransfer();
        dt.items.add(new File([window.__png], 'dropped-photo.png', {type: 'image/png'}));
        dt.items.add(new File(['x'], 'virus.exe', {type: 'application/x-msdownload'}));
        dz.dispatchEvent(new DragEvent('drop', {
            bubbles: true, cancelable: true, dataTransfer: dt
        }));
    }""")
    page.wait_for_timeout(900)

    rows = page.locator('.pcu-file-row').count()
    errored = page.locator('.pcu-file-row.is-error').count()
    names = page.locator('.pcu-file-name').all_inner_texts()
    err_txt = page.locator('.pcu-file-error').all_inner_texts()
    hl3 = page.locator('.pcu-dropzone').evaluate("d => d.classList.contains('is-dragover')")
    has_thumb = page.locator('.pcu-file-row').first.locator('img').count()

    print('drop       -> rows:', rows, names)
    print('             highlight cleared after drop:', not hl3)
    print('             image got a real thumbnail:', has_thumb == 1)
    print('             rejected rows:', errored, [e for e in err_txt if e])

    # The rejected file must not be uploadable, the good one must be
    disabled = page.is_disabled('.pcu-btn-upload')
    print('             Upload enabled (1 valid file):', not disabled)

    page.screenshot(path='shots/10-dragdrop.png')
    print('\nJS errors:', errs if errs else 'none')
    b.close()
