#!/usr/bin/env python3
"""
Generate child-theme template overrides from the ProLancer plugin templates.

Two surgical edits per template — everything else is copied byte-for-byte, so a
plugin update can be re-patched by re-running this rather than hand-merging:

  1. The single-attachment "Download" block becomes pcu_render_attachments(),
     which renders EVERY id in the comma-separated attachment_id column.

  2. The attach icon + modal is printed right after the plugin's file input.
     That input stays in the page (CSS hides it) because it carries the post_id
     and nonce the upload endpoint needs.
"""
import os
import re
import sys

SRC = '/var/lib/freelancer/projects/40574808/site/prolancer-element/templates/dashboard'
OUT = '/var/lib/freelancer/projects/40574808/build/prolancer-templates/dashboard'

# Templates with a chat composer AND a message list
COMPOSER = [
    'buyer/ongoing-service-details.php',
    'seller/ongoing-service-details.php',
    'buyer/ongoing-project-details.php',
    'seller/ongoing-project-details.php',
]
# Read-only message lists (no composer) — still must render multiple attachments
RENDER_ONLY = [
    'buyer/completed-service-details.php',
    'seller/completed-service-details.php',
    'buyer/completed-project-details.php',
    'seller/completed-project-details.php',
]

# The plugin's single-attachment download block, in all its whitespace variants.
ATTACH_BLOCK = re.compile(
    r'<\?php\s+if\s*\(\s*\$message->attachment_id\s*\)\s*\{\s*\?>.*?<\?php\s*\}\s*\?>',
    re.DOTALL,
)

# The plugin's file input. Attributes span lines AND contain PHP blocks whose
# closing `?>` ends in '>' — so a lazy `.*?>` stops INSIDE the tag, at the first
# `?>` it meets, and splices the button into the middle of the markup.
# Consume whole PHP blocks explicitly, and otherwise any char that isn't '>'.
FILE_INPUT = re.compile(
    r'<input\s+id="upload-message-attachments"(?:<\?php.*?\?>|[^>])*>',
    re.DOTALL,
)

fails = []

for rel in COMPOSER + RENDER_ONLY:
    src = os.path.join(SRC, rel)
    if not os.path.exists(src):
        fails.append('%s: MISSING' % rel)
        continue

    code = open(src, encoding='utf-8').read()
    orig = code

    # --- edit 1: multi-attachment rendering ---
    code, n_attach = ATTACH_BLOCK.subn(
        '<?php pcu_render_attachments( $message->attachment_id ); ?>', code)

    # --- edit 2: attach icon + modal after the file input ---
    n_input = 0
    if rel in COMPOSER:
        def add_button(m):
            global n_input
            n_input += 1
            return m.group(0) + '\n    <?php pcu_attach_button(); ?>'
        code = FILE_INPUT.sub(add_button, code, count=1)

    # Guard rails: if a pattern stopped matching (e.g. plugin update changed the
    # markup), fail loudly instead of silently shipping an unpatched template.
    if n_attach == 0:
        fails.append('%s: attachment block NOT FOUND' % rel)
    if rel in COMPOSER and n_input == 0:
        fails.append('%s: file input NOT FOUND' % rel)
    if code == orig:
        fails.append('%s: nothing changed' % rel)

    # PHP lint does NOT catch a mangled HTML tag — the file stays valid PHP even
    # if the call was spliced into the middle of an <input>. So check the markup:
    # the inserted call must sit on its own line, outside any tag.
    for line in code.splitlines():
        if 'pcu_attach_button' in line and line.strip() != '<?php pcu_attach_button(); ?>':
            fails.append('%s: button spliced INSIDE a tag -> %s' % (rel, line.strip()[:60]))

    # And the input the JS reads post_id/nonce from must still be intact.
    if rel in COMPOSER:
        for attr in ('data-post-id', 'data-nonce'):
            if attr not in code and attr in orig:
                fails.append('%s: %s lost from the file input' % (rel, attr))

    dst = os.path.join(OUT, rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'w', encoding='utf-8').write(code)

    print('%-42s attachments:%d  composer:%d' % (rel, n_attach, n_input))

if fails:
    print('\nFAILED:')
    for f in fails:
        print('  -', f)
    sys.exit(1)

print('\nAll templates patched.')
