CHAT ATTACHMENT UPLOADER — install notes
========================================

Your site ALREADY has an active child theme (prolancer-child). This package is
ADDITIVE — it does not replace or overwrite anything you have.

Copy into wp-content/themes/prolancer-child/ :

    assets/css/chat-uploader.css
    assets/js/chat-uploader.js
    inc/chat-uploader.php

Then APPEND the contents of APPEND-TO-functions.php to the END of your existing
    wp-content/themes/prolancer-child/functions.php
(everything below the opening comment — do not paste a second <?php tag).

Nothing here re-enqueues the parent stylesheet: your child theme already does
that, and enqueuing it twice is exactly what causes styles to load one after
another.

One thing I still need to confirm on the live site: which page/template is the
chat screen. That is the pcu_is_chat_screen() function at the bottom of
APPEND-TO-functions.php — a one-line change once I can see the theme files.

Total weight on the chat page: ~10 KB gzipped (one CSS + one JS). No Dropzone,
no Bootstrap, no jQuery. Every other page on the site loads nothing at all.
