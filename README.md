# Prolancer — Chat attachment uploader

Replaces the plain `Choose File` input in the Prolancer chat composer with an
attach **icon** that opens a **modal** holding a drag-and-drop uploader.

**No dependencies.** No Dropzone, no Bootstrap, no jQuery — one CSS file and one
JS file, **~10 KB gzipped**, loaded only on the chat page.

## Spec covered

| # | Requirement | Where |
|---|---|---|
| 1 | Dropzone-style tool (Dhonu look) instead of `Choose File` | `chat-uploader.css` |
| 2 | Attach **icon** in composer; click opens modal | `.pcu-attach-btn` |
| 3 | Modal closes **only** via Close button (no backdrop, no ESC) | `makeModal()` — no such handlers exist |
| 4 | Modal height **fixed**, never grows; scrolls instead | `.pcu-scroll-wrap { max-height: min(58vh, 460px) }` |
| 5 | Styled scrollbar, matching Dhonu | `attachScrollbar()` |
| 6 | `Upload` in footer, **dimmed until files attached** | `.pcu-btn-upload[disabled]` |
| 7 | Upload sends files **to the chat area** | `pcu:uploaded` event |
| 8 | **Thumbnails** for media files | `makeThumb()` |
| 9 | No "Successfully uploaded!" dialog | removed by design |
| 10 | **One** spinner, reused for every action | `.pcu-spinner` |

## Client rules — how each is met

| Rule | How |
|---|---|
| 1. No messy code | Namespaced `.pcu-`, one CSS + one JS, documented, PHP passes `php -l` |
| 2. No shaking/shifting | **Measured CLS = 0.000** on load and while adding files |
| 3. No slow-down | Chat page only; `defer`; zero dependencies (~10 KB gz, down from ~45 KB) |
| 4. No style-after-style | **One** stylesheet, and the parent sheet is *not* re-enqueued (the existing child theme already does that — doing it twice is what causes the flash) |
| 5. Child theme only | Additive package for the existing `prolancer-child`; parent untouched |
| 6. Responsive | Verified 390px + 768px: no horizontal overflow, buttons reachable |

## Scrollbar — why it is drawn, not native

Dhonu uses **SimpleBar**, and its values are matched exactly (11px track, 6px
thumb, `#a2adb7` @ 50%, 7px radius — taken from Dhonu's `_simplebar.scss`).

It is drawn as a real element rather than styled natively because a native
scrollbar cannot look the same on every platform: on macOS it is an auto-hiding
overlay, so a Mac user would never see the style at all. That is the same reason
Dhonu ships SimpleBar. This does it in ~60 lines, with no library.

Two traps worth recording:
- Chrome **ignores `::-webkit-scrollbar` entirely** if `scrollbar-width` or
  `scrollbar-color` is set on the same element.
- **Headless Chromium never renders classic scrollbars**, so a native one cannot
  be screenshotted or asserted in a browser test. A drawn one can.

## Install

See `chat-uploader-package/README.txt`. The site already has an active
`prolancer-child` theme, so the package is additive — it overwrites nothing.

## Demo & tests

```sh
python3 server.py       # http://127.0.0.1:8811
python3 drive.py        # full walkthrough + screenshots
python3 dragdrop.py     # native drag-and-drop, incl. rejecting a .exe
python3 scrollbar.py    # scrollbar renders, tracks, drags
python3 rules.py        # CLS / asset count / responsive
```
