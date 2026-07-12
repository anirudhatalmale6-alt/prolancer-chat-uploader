/**
 * Create Service — 4-step wizard.
 * ----------------------------------------------------------------------------
 * The steps and the panes are BOTH rendered server-side (see pcu_wizard_steps()
 * and patch_templates.py), so the form is already a wizard before the first
 * paint. This file only moves between them — it never rebuilds the form, which
 * is what would make the page jump on load.
 *
 * Rules, from the brief:
 *   - Previous is back, and you may move backwards and forwards freely.
 *   - You may not move FORWARD past a step that has an error. Backwards is
 *     always allowed: nobody should be trapped on a step they cannot fix.
 *   - Steps change instantly. No reload.
 *
 * No jQuery.
 */
(function (window, document) {
    'use strict';

    var form = document.getElementById('create-service-form');
    if (!form) { return; }

    var panes = [].slice.call(form.querySelectorAll('.pcu-step'));
    var items = [].slice.call(document.querySelectorAll('.pcu-step-item'));
    var prev = form.querySelector('.pcu-wiz-prev');
    var next = form.querySelector('.pcu-wiz-next');

    if (!panes.length || !prev || !next) { return; }

    var current = 1;
    var LAST = panes.length;

    // ------------------------------------------------------------- validation

    /**
     * What a step must have before you can move past it.
     *
     * Deliberately thin. The server is what decides whether a service is valid,
     * and duplicating its rules here would mean two sets to keep in step. This
     * only catches the things a seller would obviously want to be told about
     * BEFORE they get to the end and press Create.
     */
    var RULES = {
        1: [
            ['input[name="title"]', 'Please give your service a title.'],
            ['select[name="service_category"]', 'Please choose a category.']
        ],
        3: [
            ['input[name="package_price[]"]', 'Please set a price for at least one package.']
        ]
    };

    function fieldsIn(pane, selector) {
        return [].slice.call(pane.querySelectorAll(selector));
    }

    /**
     * Check a step. Returns '' when it is fine, or the first problem found.
     */
    function problemWith(step) {
        var rules = RULES[step];
        if (!rules) { return ''; }

        var pane = panes[step - 1];

        for (var i = 0; i < rules.length; i++) {
            var selector = rules[i][0];
            var message = rules[i][1];
            var found = fieldsIn(pane, selector);

            if (!found.length) { continue; }

            // A repeated field (package_price[]) passes if ANY of them is filled.
            var filled = found.some(function (el) {
                return String(el.value || '').trim() !== '';
            });

            if (!filled) {
                found[0].focus();
                return message;
            }
        }

        return '';
    }

    function say(message) {
        var box = form.querySelector('.pcu-wiz-error');

        if (!box) {
            box = document.createElement('p');
            box.className = 'pcu-wiz-error';
            box.setAttribute('role', 'alert');
            form.querySelector('.pcu-wizard-controls').appendChild(box);
        }

        box.textContent = message || '';
        box.hidden = !message;
    }

    // ----------------------------------------------------------------- moving

    function show(step) {
        panes.forEach(function (pane, i) {
            pane.hidden = (i + 1) !== step;
        });

        items.forEach(function (item, i) {
            var n = i + 1;
            item.classList.toggle('is-current', n === step);
            // A step you have already been past is "done" — it is what tells you
            // how far through you are.
            item.classList.toggle('is-done', n < step);
        });

        prev.hidden = step === 1;
        // The last step carries the plugin's own Create/Update button, so Next
        // has nothing left to do there.
        next.hidden = step === LAST;

        current = step;
        say('');
    }

    /**
     * Move to a step.
     *
     * Going BACKWARDS is always allowed. Going FORWARD checks every step between
     * here and there — otherwise clicking straight to step 4 from the stepper
     * would skip the checks on 1..3.
     */
    function go(step) {
        step = Math.max(1, Math.min(LAST, step));

        if (step > current) {
            for (var s = current; s < step; s++) {
                var problem = problemWith(s);

                if (problem) {
                    show(s);
                    say(problem);
                    return;
                }
            }
        }

        show(step);

        // Bring the top of the form into view — on a long step, the next one
        // would otherwise open halfway down.
        var top = form.getBoundingClientRect().top + window.scrollY - 90;
        window.scrollTo({ top: top < 0 ? 0 : top, behavior: 'smooth' });
    }

    next.addEventListener('click', function () { go(current + 1); });
    prev.addEventListener('click', function () { go(current - 1); });

    // The numbers themselves are clickable — that is what "move back and forth"
    // means in practice.
    items.forEach(function (item) {
        item.addEventListener('click', function () {
            go(parseInt(item.getAttribute('data-goto'), 10) || 1);
        });
    });

    /**
     * Enter must not submit the form from step 1.
     *
     * The form has no submit button of its own (the plugin uses an <a>), but a
     * bare Enter in a text input still tries to submit. In a wizard that means
     * creating a half-filled service from step one. Take Enter to mean "next".
     */
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') { return; }

        e.preventDefault();

        if (current < LAST) { go(current + 1); }
    });

    show(1);

}(window, document));
