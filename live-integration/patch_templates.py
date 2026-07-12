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

# Profile edit pages. Their profile-PICTURE dropzone is swapped for the round
# camera control (pcu_avatar_uploader). The cover-image dropzone is left as it
# is. Handled on its own below, not through the chat edits above.
PROFILE = {
    'seller/profile.php': ('$seller_id', 'seller_profile_attachment'),
    'buyer/profile.php':  ('$buyer_id',  'buyer_profile_attachment'),
}

# Forms whose dropdowns open with a placeholder <option> that has NO value.
# An option with no value submits its own TEXT, so an untouched dropdown posts
# the literal word "Category" / "Delivery Time". The plugin casts that to (int)
# — which is 0 — and calls wp_set_post_terms(..., false), a REPLACE. The real
# term is destroyed. Give the placeholders value="" so they send nothing.
# (inc/pcu-service-form.php is the second half: it refuses to act on a taxonomy
# value that is not a term id, whatever gets sent.)
SELECT_FORMS = [
    'seller/create-service.php',
    'buyer/create-project.php',
]

# The rich-text editor binds to `#editor` (plugin.js: $('#editor').richText()).
# Take the id away and it never attaches — the textarea stays a plain textarea,
# with no fighting the plugin and no editor left half-initialised.
# Create Service and the Profile pages only; the client did not ask for the
# project form, so its editor is left alone.
PLAIN_TEXTAREA = [
    'seller/create-service.php',
    'seller/profile.php',
    'buyer/profile.php',
]

# Price / revision fields. type="number" draws the spinner arrows the client does
# not want. text + inputmode="decimal" keeps the numeric keypad on a phone and
# still allows paste; pcu-num.js is what actually keeps non-numbers out.
NUMERIC_FIELDS = [
    'seller/create-service.php',
]

# Split Create Service into a 4-step wizard.
WIZARD = [
    'seller/create-service.php',
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

# --- 404: the doorway for the screens we added ---
#
# The plugin routes ?fed=... through a hard-coded if/elseif with no hook to
# register a new screen. But its final `else` loads dashboard/404.php through
# locate_template(), so the CHILD THEME's copy runs for any ?fed= it does not
# know. Prepend a doorway: answer for our screens, else fall through to the real
# "not found". No plugin file is touched.

DOORWAY = """<?php
/**
 * Dashboard 404 -- and the doorway for the screens the child theme adds.
 *
 * Generated by patch_templates.py. See inc/pcu-library.php for why this file is
 * the hook we do not otherwise have.
 */

$pcu_screen = function_exists( 'pcu_library_screen' ) ? pcu_library_screen() : '';

if ( $pcu_screen && is_user_logged_in() ) {
	pcu_library_render( $pcu_screen );
	return;
}
?>
"""

src = os.path.join(SRC, '404.php')
code = open(src, encoding='utf-8').read()
dst = os.path.join(OUT, '404.php')
os.makedirs(os.path.dirname(dst), exist_ok=True)
open(dst, 'w', encoding='utf-8').write(DOORWAY + code)
print('%-42s doorway added' % '404.php')

# --- sidebar: Extras and FAQ under the Services menu ---

# Anchor on "Completed Services" — the LAST item in the Services submenu — so
# Extras and FAQ come after it. The client wants them at the bottom of the menu,
# not sitting between "Create a service" and the service lists.
SIDEBAR_ANCHOR = """<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=completed-services"><?php echo esc_html__( 'Completed Services', 'prolancer' ); ?></a></li>"""

SIDEBAR_ADD = SIDEBAR_ANCHOR + """
					<?php if($visit_as == 'seller'){ ?>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=extras"><?php echo esc_html__( 'Extras', 'prolancer' ); ?></a></li>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=faqs"><?php echo esc_html__( 'FAQ', 'prolancer' ); ?></a></li>
					<?php } ?>"""

src = os.path.join(SRC, 'sidebar.php')
code = open(src, encoding='utf-8').read()

if SIDEBAR_ANCHOR not in code:
    fails.append('sidebar.php: the "Create a service" item was not found')
else:
    code = code.replace(SIDEBAR_ANCHOR, SIDEBAR_ADD, 1)

if 'fed=extras' not in code or 'fed=faqs' not in code:
    fails.append('sidebar.php: Extras/FAQ items not added')

dst = os.path.join(OUT, 'sidebar.php')
open(dst, 'w', encoding='utf-8').write(code)
print('%-42s Extras + FAQ added (sellers only)' % 'sidebar.php')

# --- dashboard forms: one pass per file, from the PRISTINE plugin template ---
#
# Each of these files needs SEVERAL edits. Running them as separate passes that
# each re-read the previous pass's OUTPUT is not idempotent: a second run finds
# the edits already applied, matches nothing, and reports a false failure.
#
# So build a pipeline per file and apply it once, always starting from SRC. The
# script can be re-run any number of times and always produces the same result —
# which is the whole point of generating these rather than hand-editing them.


def fix_placeholders(rel, code):
    """A placeholder <option> with no value submits its own TEXT. See
    inc/pcu-service-form.php for what that then destroys."""
    code, n = re.subn(
        r'<option>(\s*<\?php\s+echo\s+esc_html__\(.*?\?>\s*)</option>',
        r'<option value="">\1</option>', code, flags=re.DOTALL)

    if n == 0:
        fails.append('%s: no valueless placeholder <option> found' % rel)
    if re.search(r'<option>\s*<\?php\s+echo\s+esc_html__', code):
        fails.append('%s: a valueless placeholder survived' % rel)

    return code, 'placeholders:%d' % n


def plain_textarea(rel, code):
    """The rich-text editor binds to #editor. Remove the id and it never
    attaches — nothing to fight, nothing left half-initialised."""
    code, n = re.subn(r'<textarea\s+id="editor"\s+', '<textarea ', code)

    if n == 0:
        fails.append('%s: id="editor" NOT FOUND' % rel)
    if 'id="editor"' in code:
        fails.append('%s: an id="editor" survived' % rel)

    return code, 'plain-textarea:%d' % n


def numeric_fields(rel, code):
    """No spinner arrows, numbers only.

    data-num, NOT a class: these inputs already carry class="form-control mb-0",
    and a second class attribute is a DUPLICATE that the browser silently drops
    — which would have stripped the theme's styling off the field."""
    code, n = re.subn(r'type="number"',
                      'type="text" inputmode="decimal" data-num="1"', code)

    if n == 0:
        fails.append('%s: no type="number" found' % rel)
    if 'type="number"' in code:
        fails.append('%s: a type="number" survived' % rel)

    return code, 'numeric:%d' % n


# The whole <div class="col-md-12"> wrapping the profile-PICTURE dropzone. It has
# nested <div class="progress"><div class="progress-bar">, so a plain
# `.*?</div></div>` stops inside it. Anchor the end with a lookahead to the NEXT
# col-md-12 (the cover image), which is zero-width and leaves that block alone.
PROFILE_BLOCK = re.compile(
    r'<div class="col-md-12">\s*<div class="dropzone.*?profile_attachment'
    r'.*?</div>\s*</div>\s*(?=<div class="col-md-12")',
    re.DOTALL,
)


def profile_uploader(rel, code):
    """Swap the big "Choose a Profile Picture" dropzone for the round camera
    control. The cover-image dropzone is left exactly as it is."""
    id_var, meta_key = PROFILE[rel]

    replacement = ('<div class="col-md-12">\n\t\t\t\t\t\t'
                   "<?php pcu_avatar_uploader( %s, '%s' ); ?>"
                   '\n\t\t\t\t\t</div>' % (id_var, meta_key))

    code, n = PROFILE_BLOCK.subn(replacement, code, count=1)

    if n == 0:
        fails.append('%s: profile-picture dropzone NOT FOUND' % rel)
    if '_cover_attachment' not in code:
        fails.append('%s: the cover dropzone was wrongly removed' % rel)

    return code, 'avatar-uploader:%d' % n


# The four steps, in order. Each entry is (title, the marker the step STARTS at).
# The markers are the opening line of the first column in that step — matched
# exactly, so if a plugin update moves them the build fails loudly instead of
# silently producing a wizard with the wrong fields in the wrong step.
WIZARD_STEPS = [
    ("About service", None),                       # from the top of the row
    ("Media",         '<div class="col-md-12 mb-4">\n                <label><?php echo esc_html__(\'Featured Images\',\'prolancer\'); ?></label>'),
    ("Pricing",       '<!-- The packages section remains exactly as you had it -->'),
    ("FAQ",           '<div class="col-md-12 mb-5">\n                <h4 class="mb-4"><?php echo esc_html__( "FAQ", \'prolancer\' ); ?></h4>'),
]


def wizard(rel, code):
    """Group the form's columns into four steps.

    Done in the MARKUP, not at runtime. Restructuring the form with JS after load
    would show the whole thing and then collapse it -- the page would visibly
    jump, which is the client's rule 2 ("no shaking or shifting elements on
    load"). Built server-side, the wizard is correct before the first paint.

    Each step is a full-width column wrapping its own row, so the Bootstrap grid
    stays valid: .row > .col-12 > .row > .col-md-*.
    """
    # Find where each step begins.
    cuts = []
    for title, marker in WIZARD_STEPS:
        if marker is None:
            cuts.append((title, None))
            continue

        at = code.find(marker)
        if at == -1:
            fails.append('%s: wizard marker for "%s" NOT FOUND' % (rel, title))
            return code, 'wizard:FAILED'
        cuts.append((title, at))

    open_row = '<div class="row">'
    start = code.find(open_row)
    if start == -1:
        fails.append('%s: the form row was not found' % rel)
        return code, 'wizard:FAILED'
    start += len(open_row)

    # The row closes just before the form does.
    end = code.rfind('</div>', 0, code.find('</form>'))
    if end == -1:
        fails.append('%s: the end of the form row was not found' % rel)
        return code, 'wizard:FAILED'

    bounds = [start] + [c for _, c in cuts[1:]] + [end]

    pieces = []
    for i, (title, _) in enumerate(cuts):
        body = code[bounds[i]:bounds[i + 1]]
        # Steps 2+ come down ALREADY HIDDEN. If they arrived visible and JS hid
        # them after load, the browser would paint the whole form and then
        # collapse it — a visible jump on every page load, which is exactly the
        # client's rule 2. The `hidden` attribute is honoured before any script
        # runs, so there is nothing to see.
        hide = '' if i == 0 else ' hidden'

        pieces.append(
            # col-lg-9 pairs with the stepper's col-lg-3 so the two sit side by
            # side on a wide screen and stack on a narrow one. col-12 alone made
            # the pane full width, which pushed it below the stepper.
            '\n            <div class="pcu-step col-12 col-lg-9" data-step="%d" data-title="%s"%s>'
            '\n              <div class="row">%s</div>'
            '\n            </div>\n' % (i + 1, title, hide, body)
        )

    code = code[:start] + ''.join(pieces) + code[end:]

    # The stepper itself is printed by PHP, so its numbers and titles come from
    # one place rather than being duplicated in the markup.
    code = code.replace(open_row, open_row + '\n            <?php pcu_wizard_nav(); ?>', 1)

    # The submit button sits in the last step; the wizard adds Previous/Next.
    code = code.replace('</form>', '  <?php pcu_wizard_controls(); ?>\n    </form>', 1)

    return code, 'wizard:4 steps'


PIPELINE = {}

for rel in WIZARD:
    PIPELINE.setdefault(rel, []).append(wizard)
for rel in SELECT_FORMS:
    PIPELINE.setdefault(rel, []).append(fix_placeholders)
for rel in PLAIN_TEXTAREA:
    PIPELINE.setdefault(rel, []).append(plain_textarea)
for rel in NUMERIC_FIELDS:
    PIPELINE.setdefault(rel, []).append(numeric_fields)
for rel in PROFILE:
    PIPELINE.setdefault(rel, []).append(profile_uploader)

for rel, edits in PIPELINE.items():
    src = os.path.join(SRC, rel)

    if not os.path.exists(src):
        fails.append('%s: MISSING' % rel)
        continue

    code = open(src, encoding='utf-8').read()
    notes = []

    for edit in edits:
        code, note = edit(rel, code)
        notes.append(note)

    dst = os.path.join(OUT, rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'w', encoding='utf-8').write(code)

    print('%-42s %s' % (rel, '  '.join(notes)))

if fails:
    print('\nFAILED:')
    for f in fails:
        print('  -', f)
    sys.exit(1)

print('\nAll templates patched.')
