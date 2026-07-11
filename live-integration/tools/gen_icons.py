#!/usr/bin/env python3
"""
Draw the file-type icons for chat attachments.

One PNG per extension, named after it — zip.png, docx.png — plus generic.png for
everything with no icon of its own. That naming IS the lookup: PHP checks whether
assets/icons/<ext>.png exists and falls back to generic.png, so adding a type
later means dropping in a file, and no code changes.

The client said he will replace these with his own artwork, so they are
deliberately plain: same silhouette, colour-coded by family, extension on the
face. Replacing one means matching the filename and nothing else.

Run:  python3 tools/gen_icons.py
"""
import os

from PIL import Image, ImageDraw, ImageFont

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'icons')

SIZE = 128          # 84px tile on a 2x screen, with room to spare
FOLD = 30           # the folded corner

# Colour per family, not per extension — a spreadsheet is green whether it is
# .xlsx or .csv, which is the convention every OS file manager already uses.
#
# NOTHING FROM pcu_blocked_extensions() BELONGS HERE. php, js, html, exe, apk,
# svg… are refused at upload, so they can never appear in a chat: an icon for
# one would advertise a type the chat will not accept.
FAMILIES = [
    ('#e5252a', ['pdf']),
    ('#2b579a', ['doc', 'docx', 'rtf', 'odt', 'pages', 'wpd']),
    ('#217346', ['xls', 'xlsx', 'csv', 'tsv', 'ods', 'numbers']),
    ('#d24726', ['ppt', 'pptx', 'odp', 'key']),
    ('#f0a500', ['zip', 'rar', '7z', 'gz', 'tar', 'bz2', 'xz', 'iso']),
    ('#7d4cdb', ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'mid']),
    ('#0f9dd7', ['txt', 'md', 'log', 'ini']),
    ('#00a39c', ['css', 'json', 'yml', 'yaml', 'sql']),
    ('#e0367a', ['psd', 'xcf', 'ai', 'eps', 'indd', 'fig', 'sketch', 'xd']),
    ('#455a64', ['ttf', 'otf', 'woff', 'woff2']),
    ('#8d6e63', ['epub', 'mobi', 'azw3']),
    ('#8b95a9', ['generic']),
]


def font(px):
    for path in ('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                 '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf'):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


def draw(ext, colour):
    img = Image.new('RGBA', (SIZE, SIZE), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    pad = 12
    x0, y0, x1, y1 = pad, 6, SIZE - pad, SIZE - 6

    # Sheet of paper with the top-right corner turned down.
    d.rounded_rectangle([x0, y0, x1, y1], radius=8, fill='#ffffff',
                        outline='#dfe3ec', width=2)
    d.polygon([(x1 - FOLD, y0), (x1, y0 + FOLD), (x1 - FOLD, y0 + FOLD)],
              fill='#eef1f6')
    d.line([(x1 - FOLD, y0), (x1 - FOLD, y0 + FOLD), (x1, y0 + FOLD)],
           fill='#dfe3ec', width=2)

    # Coloured band carrying the extension — the part you actually read.
    band_h = 34
    band_y = y1 - band_h - 14
    d.rounded_rectangle([x0 - 2, band_y, x1 - 14, band_y + band_h],
                        radius=5, fill=colour)

    label = '' if ext == 'generic' else ext.upper()
    if label:
        band_x0, band_x1 = x0 - 2, x1 - 14

        # Shrink until it fits the band — NUMBERS and SKETCH are long enough to
        # overflow at the size PDF is drawn at.
        px = 20
        while px > 9:
            f = font(px)
            box = d.textbbox((0, 0), label, font=f)
            if box[2] - box[0] <= (band_x1 - band_x0) - 12:
                break
            px -= 1

        d.text(((band_x0 + band_x1) / 2 - (box[2] - box[0]) / 2,
                band_y + band_h / 2 - (box[3] - box[1]) / 2 - box[1]),
               label, font=f, fill='#ffffff')

    return img


os.makedirs(OUT, exist_ok=True)

made = []
for colour, exts in FAMILIES:
    for ext in exts:
        draw(ext, colour).save(os.path.join(OUT, ext + '.png'))
        made.append(ext)

print('%d icons -> %s' % (len(made), os.path.normpath(OUT)))
print('  ' + ' '.join(sorted(made)))
