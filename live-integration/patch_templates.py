#!/usr/bin/env python3
"""
Generate child-theme template overrides from the ProLancer plugin templates.

Three surgical edits per template — everything else is copied byte-for-byte, so
a plugin update can be re-patched by re-running this rather than hand-merging:

  1. The single-attachment "Download" block becomes pcu_render_attachments(),
     which renders EVERY id in the comma-separated attachment_id column.

  2. The attach icon + modal is printed right after the plugin's file input.
     That input stays in the page (CSS hides it) because it carries the post_id
     and nonce the upload endpoint needs.

  3. esc_html() becomes pcu_message_text(). esc_html() leaves existing HTML
     entities alone, so a message typed as "&#xb6;" rendered as a pilcrow after
     a reload — the stored chat disagreed with the live one.

  4. The avatars are wrapped in links to the author's public profile page. The
     client wants the avatar without the link, so the <a> is unwrapped to its
     contents. The nav tabs, the download link and the send button are left
     alone — only the author/permalink profile links go.
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
# The inbox chat: no composer, no attachments — but it shows stored messages, so
# it needs the escaping fix like every other list.
TEXT_ONLY = [
    'message/message.php',
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

# How the plugin echoes a stored message, with and without inner spaces.
MESSAGE_ECHO = re.compile(
    r'esc_html\(\s*\$message->message\s*\)'
)

# An avatar wrapped in a link to its owner's public profile page. Two forms:
# the inbox uses get_author_posts_url(), the order chat uses get_the_permalink().
# The anchor holds an <img> and/or a name and NO nested anchor, so `.*?</a>`
# stops at its own close tag. Replaced by its own contents — avatar, no link.
PROFILE_LINK = re.compile(
    r'<a\s+href="<\?php\s+echo\s+esc_url\(\s*'
    r'(?:get_author_posts_url|get_the_permalink)\('
    r'.*?\?>"'                    # up to the end of the PHP echo — args may nest parens
    r'[^>]*\btarget="_blank"[^>]*>(.*?)</a>',
    re.DOTALL,
)

fails = []

for rel in COMPOSER + RENDER_ONLY + TEXT_ONLY:
    src = os.path.join(SRC, rel)
    if not os.path.exists(src):
        fails.append('%s: MISSING' % rel)
        continue

    code = open(src, encoding='utf-8').read()
    orig = code

    # --- edit 1: multi-attachment rendering ---
    n_attach = 0
    if rel not in TEXT_ONLY:
        code, n_attach = ATTACH_BLOCK.subn(
            '<?php pcu_render_attachments( $message->attachment_id ); ?>', code)

    # --- edit 3: show a message exactly as it was typed ---
    code, n_echo = MESSAGE_ECHO.subn('pcu_message_text( $message->message )', code)

    # --- edit 4: avatar without the profile-page link ---
    code, n_plink = PROFILE_LINK.subn(lambda m: m.group(1), code)

    # --- edit 2: attach icon + modal after the file input ---
    n_input = 0
    if rel in COMPOSER:
        def add_button(m):
            global n_input
            n_input += 1
            tag = m.group(0)

            # Plugin bug (theirs, not ours): this one template forgets
            # data-post-id, so its uploads land unattached to the project.
            # $project_id is already in scope here — just pass it.
            if 'data-post-id' not in tag:
                tag = tag[:-1].rstrip() + \
                    ' data-post-id="<?php echo esc_attr($project_id); ?>">'

            return tag + '\n    <?php pcu_attach_button(); ?>'

        code = FILE_INPUT.sub(add_button, code, count=1)

    # Guard rails: if a pattern stopped matching (e.g. plugin update changed the
    # markup), fail loudly instead of silently shipping an unpatched template.
    if n_attach == 0 and rel not in TEXT_ONLY:
        fails.append('%s: attachment block NOT FOUND' % rel)
    if rel in COMPOSER and n_input == 0:
        fails.append('%s: file input NOT FOUND' % rel)
    if n_echo == 0:
        fails.append('%s: message echo NOT FOUND' % rel)
    if n_plink == 0:
        fails.append('%s: profile link NOT FOUND' % rel)
    if 'get_author_posts_url' in code or 'get_the_permalink($sender_id)' in code.replace(' ', ''):
        # A profile link survived edit 4 — the pattern missed one.
        if re.search(r'<a[^>]*(?:get_author_posts_url|get_the_permalink)', code):
            fails.append('%s: a profile link survived' % rel)
    if 'esc_html($message->message)' in code.replace(' ', ''):
        fails.append('%s: a raw esc_html() message echo survived' % rel)
    if code == orig:
        fails.append('%s: nothing changed' % rel)

    # PHP lint does NOT catch a mangled HTML tag — the file stays valid PHP even
    # if the call was spliced into the middle of an <input>. So check the markup:
    # the inserted call must sit on its own line, outside any tag.
    for line in code.splitlines():
        if 'pcu_attach_button' in line and line.strip() != '<?php pcu_attach_button(); ?>':
            fails.append('%s: button spliced INSIDE a tag -> %s' % (rel, line.strip()[:60]))

    # The JS reads post_id and nonce off that input, so every composer template
    # must carry both — including the one where the plugin omitted data-post-id.
    if rel in COMPOSER:
        tag = FILE_INPUT.search(code)
        tag = tag.group(0) if tag else ''

        for attr in ('data-post-id', 'data-nonce'):
            if attr not in tag:
                fails.append('%s: file input is missing %s' % (rel, attr))

    dst = os.path.join(OUT, rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'w', encoding='utf-8').write(code)

    print('%-42s attachments:%d  composer:%d  messages:%d' % (rel, n_attach, n_input, n_echo))

if fails:
    print('\nFAILED:')
    for f in fails:
        print('  -', f)
    sys.exit(1)

print('\nAll templates patched.')
