# Prolancer — Chat attachment uploader

Replaces the plain `Choose File` input in the Prolancer chat composer with an
attach **icon** that opens a **modal** holding a Dropzone-style uploader.

`prolancer-child/` is a drop-in **child theme** — the parent theme is never
touched, so it stays safe to update.

## Spec covered

| # | Requirement | Where |
|---|---|---|
| 1 | Dropzone tool (Dhonu "Dropzone" look) instead of `Choose File` | `assets/css/chat-uploader.css` |
| 2 | Attach **icon** in composer; click opens modal | `.pcu-attach-btn` |
| 3 | Modal closes **only** via the Close button (no backdrop, no ESC) | `makeModal()` — no such handlers exist |
| 4 | Modal height **fixed**, never grows; scrolls instead | `.pcu-modal-body { max-height: min(58vh, 460px) }` |
| 5 | Styled scrollbar | `.pcu-scroll` *(placeholder — awaiting client's style)* |
| 6 | `Upload` in footer, **dimmed until files attached** | `.pcu-btn-upload[disabled]` |
| 7 | Upload sends files **to the chat area** | `pcu:uploaded` event |
| 8 | **Thumbnails** for media files | `dz.on('thumbnail')` |
| 9 | No "Successfully uploaded!" dialog | removed by design |
| 10 | **One** spinner, reused for every async action | `.pcu-spinner` |

## Client rules — how each is met

| Rule | How |
|---|---|
| 1. No messy code | Namespaced `.pcu-`, one CSS + one JS file, documented, PHP passes `php -l` |
| 2. No shaking/shifting on load | **Measured CLS = 0.000** on load and while adding files. Every box has explicit dimensions; the modal is `display:none` until opened |
| 3. No page slow-down | Assets load **only on the chat screen** (`prolancer_child_is_chat_screen()`), both scripts `defer`, so nothing blocks render. Every other page is untouched |
| 4. No style-after-style | **One** stylesheet for the uploader. Bootstrap and `dropzone.min.css` are *not* loaded — every Dropzone visual is overridden, so shipping its CSS would only paint styles we replace. Child CSS declares the parent as a dependency so the order can never invert |
| 5. All work on child theme | Everything is in `prolancer-child/` |
| 6. Stays responsive | Verified at 390px and 768px: no horizontal overflow, buttons reachable. Full-bleed sheet on phones |

## Install

1. Copy `prolancer-child/` into `wp-content/themes/`.
2. Activate **Prolancer Child** (or merge the two PHP files into your existing child theme).
3. Confirm the chat-screen check in `prolancer_child_is_chat_screen()` matches
   the real messages page, then render the markup in the composer.

Uploads go through `admin-ajax.php` → `pcu_upload_attachment`, which checks the
nonce, requires `upload_files`, enforces the size cap and validates the **sniffed**
MIME type (never the browser-supplied one) before handing the file to
`media_handle_upload()`.

## Demo

```sh
python3 server.py       # http://127.0.0.1:8811
python3 drive.py        # functional walkthrough + screenshots
python3 rules.py        # CLS / asset / responsive checks
```

## Gotcha worth knowing

Dropzone with `autoProcessQueue: false` uploads only the first `parallelUploads`
batch and then **stalls** — it re-kicks its own queue only when
`autoProcessQueue` is `true`, so `queuecomplete` never fires and the remaining
files silently never send. The `complete` handler drives the queue manually.
