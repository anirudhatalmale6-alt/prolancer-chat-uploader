# Prolancer — Chat attachment uploader

Replaces the plain `Choose File` input in the Prolancer chat composer with an
attach **icon** that opens a **modal** containing a Dropzone-style uploader.

## Spec covered

| # | Requirement | Where |
|---|---|---|
| 1 | Dropzone file tool (Dhonu "Dropzone" look) instead of `Choose File` | `assets/chat-uploader.css`, `index.html` |
| 2 | Attach **icon** in composer; click opens modal | `.pcu-attach-btn` |
| 3 | Modal closes **only** via the explicit Close button (no backdrop / no ESC) | `backdrop: 'static'`, `keyboard: false` |
| 4 | Modal height **fixed**, never grows; scrolls instead | `.pcu-modal-body { max-height: min(58vh, 460px); overflow-y: auto }` |
| 5 | Styled scrollbar | `.pcu-scroll` *(placeholder — swap for client's style)* |
| 6 | `Upload` button in footer, **dimmed until files are attached** | `.pcu-btn-upload[disabled]` |
| 7 | Upload sends the files **to the chat area** | `pcu:uploaded` event |
| 8 | **Thumbnails** for attached media files | `dz.on('thumbnail')` |
| 9 | No "Successfully uploaded!" dialog | removed by design |
| 10 | **One** spinner, reused for every async action | `.pcu-spinner` |

## Run the demo

```sh
python3 server.py       # http://127.0.0.1:8811
```

## Notes

- Dropzone runs with `autoProcessQueue: false` so nothing sends until **Upload**
  is pressed. Dropzone only re-kicks its own queue when `autoProcessQueue` is
  true, so the `complete` handler drives the queue manually — without it only
  the first `parallelUploads` batch would ever send.
- All CSS is namespaced `.pcu-` so nothing leaks into the parent theme.
- `window.PCU_CONFIG` carries the upload URL / nonce / limits, so the WordPress
  integration only has to point it at an `admin-ajax` or REST endpoint.
