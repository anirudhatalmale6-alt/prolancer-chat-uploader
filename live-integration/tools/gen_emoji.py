#!/usr/bin/env python3
"""
Build the emoji dataset for the chat picker from the official Unicode list.

Source: https://unicode.org/Public/emoji/15.1/emoji-test.txt

Only fully-qualified emoji are kept, and skin-tone variants are dropped — the
picker shows one base emoji per character, which is what every chat client does
and what keeps the file small enough to ship inline (no second request, no
sprite sheet, no CDN).

Output is JSON — [{"name": "…", "emoji": "😀😃😄…"}] — fetched by chat-emoji.js
the first time the picker is opened. JSON specifically, not a text or JS file,
because response.json() is decoded as UTF-8 by spec: a .txt served without an
explicit charset would be read as Latin-1 by some servers and every emoji would
arrive as mojibake.
"""
import json
import re
import sys

SRC = sys.argv[1] if len(sys.argv) > 1 else 'emoji-test.txt'
OUT = sys.argv[2] if len(sys.argv) > 2 else 'emoji-data.json'

# Unicode's ten groups, folded into the eight tabs the client asked for.
# "Component" is skin tones and hair colours on their own — not pickable.
GROUPS = {
    'Smileys & Emotion': 'Smileys & People',
    'People & Body':     'Smileys & People',
    'Animals & Nature':  'Animals & Nature',
    'Food & Drink':      'Food & Drink',
    'Activities':        'Activities',
    'Travel & Places':   'Travel & Places',
    'Objects':           'Objects',
    'Symbols':           'Symbols',
    'Flags':             'Flags',
}
ORDER = ['Smileys & People', 'Animals & Nature', 'Food & Drink', 'Activities',
         'Travel & Places', 'Objects', 'Symbols', 'Flags']

SKIN_TONES = {0x1F3FB, 0x1F3FC, 0x1F3FD, 0x1F3FE, 0x1F3FF}

out = {name: [] for name in ORDER}
seen = set()
group = None

for line in open(SRC, encoding='utf-8'):
    if line.startswith('# group:'):
        group = GROUPS.get(line.split(':', 1)[1].strip())
        continue
    if not group or line.startswith('#') or not line.strip():
        continue

    m = re.match(r'^([0-9A-F ]+?)\s*;\s*fully-qualified', line)
    if not m:
        continue

    points = [int(c, 16) for c in m.group(1).split()]
    if SKIN_TONES.intersection(points):
        continue

    emoji = ''.join(chr(c) for c in points)
    if emoji in seen:
        continue

    seen.add(emoji)
    out[group].append(emoji)

groups = [{'name': name, 'emoji': ''.join(out[name])} for name in ORDER if out[name]]

with open(OUT, 'w', encoding='utf-8') as fh:
    json.dump(groups, fh, ensure_ascii=False, separators=(',', ':'))

total = sum(len(v) for v in out.values())
size = sum(len(e.encode('utf-8')) for v in out.values() for e in v)
for name in ORDER:
    print('%-18s %4d' % (name, len(out[name])))
print('%-18s %4d emoji, %.1f KB of character data' % ('TOTAL', total, size / 1024))
