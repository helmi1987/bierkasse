/* Small UI helpers (no inline handlers, CSP friendly). */
(function () {
    'use strict';

    // Confirmation for forms and buttons with data-confirm.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var submitter = e.submitter;
        var text = (submitter && submitter.dataset.confirm) || form.dataset.confirm;
        if (text && !window.confirm(text)) {
            e.preventDefault();
        }
    }, true);

    // Against double submits: a form is sent only once; its buttons are disabled afterwards
    // (asynchronously, so the clicked button's name/value is still submitted).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented) {
            return;
        }
        if (form.dataset.submitted === '1') {
            e.preventDefault();
            return;
        }
        form.dataset.submitted = '1';
        window.setTimeout(function () {
            form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (btn) {
                btn.disabled = true;
            });
        }, 0);
    });

    // Back/forward cache: re-enable forms that were submitted before.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitted]').forEach(function (form) {
            delete form.dataset.submitted;
            form.querySelectorAll('button[disabled]').forEach(function (btn) { btn.disabled = false; });
        });
    });

    // Quantity stepper for "Bier abstreichen" (works without JS as a number input).
    document.querySelectorAll('[data-stepper]').forEach(function (stepper) {
        var input = stepper.querySelector('input[type=number]');
        var form = stepper.closest('form');
        var total = form ? form.querySelector('[data-total]') : null;
        var price = input ? parseInt(input.dataset.price || '0', 10) : 0;
        var min = input ? parseInt(input.min || '1', 10) : 1;
        var max = input ? parseInt(input.max || '10', 10) : 10;
        var render = function () {
            if (!total) {
                return;
            }
            var qty = Math.min(max, Math.max(min, parseInt(input.value || '1', 10) || 1));
            var rappen = qty * price;
            total.textContent = 'CHF ' + Math.floor(rappen / 100) + '.' + String(rappen % 100).padStart(2, '0');
        };
        stepper.querySelectorAll('[data-step]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var value = (parseInt(input.value || '1', 10) || 1) + parseInt(btn.dataset.step, 10);
                input.value = String(Math.min(max, Math.max(min, value)));
                render();
            });
        });
        input.addEventListener('input', render);
        render();
    });

    // Copy buttons: data-copy="#selector".
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var source = document.querySelector(btn.dataset.copy);
            if (!source || !navigator.clipboard) {
                return;
            }
            navigator.clipboard.writeText(source.textContent.trim()).then(function () {
                var label = btn.textContent;
                btn.textContent = btn.dataset.copied || '✓';
                window.setTimeout(function () { btn.textContent = label; }, 1500);
            });
        });
    });

    // Checkboxes that save immediately; their fallback submit button is hidden.
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.requestSubmit(); });
    });
    document.querySelectorAll('[data-hide-js]').forEach(function (el) { el.hidden = true; });

    // Print button.
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    // PWA install hint: native prompt where the browser offers one (Chrome/Edge/Samsung on Android and
    // desktop), instructions for iOS Safari. Hidden when running installed or dismissed (30 days).
    (function () {
        var hint = document.querySelector('[data-install-hint]');
        if (!hint) {
            return;
        }
        var KEY = 'installHintDismissed';
        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        var dismissed = false;
        try {
            dismissed = Date.now() - parseInt(window.localStorage.getItem(KEY) || '0', 10) < 30 * 864e5;
        } catch (err) { /* storage blocked: show the hint */ }
        if (standalone || dismissed) {
            return;
        }
        var show = function (variant) {
            hint.querySelectorAll('[data-install-variant]').forEach(function (el) {
                el.hidden = el.dataset.installVariant !== variant;
            });
            hint.hidden = false;
        };
        var deferred = null;
        var button = hint.querySelector('[data-install-button]');
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferred = e;
            button.hidden = false;
            show('prompt');
        });
        button.addEventListener('click', function () {
            if (!deferred) {
                return;
            }
            deferred.prompt();
            deferred.userChoice.finally(function () {
                deferred = null;
                hint.hidden = true;
            });
        });
        window.addEventListener('appinstalled', function () { hint.hidden = true; });
        hint.querySelector('[data-install-dismiss]').addEventListener('click', function () {
            hint.hidden = true;
            try {
                window.localStorage.setItem(KEY, String(Date.now()));
            } catch (err) { /* ignore */ }
        });
        var ua = window.navigator.userAgent;
        var ios = /iPhone|iPad|iPod/.test(ua) || (ua.indexOf('Macintosh') !== -1 && navigator.maxTouchPoints > 1);
        if (ios) {
            show('ios');
        } else if (/Android/.test(ua) && /Firefox/.test(ua)) {
            show('other');
        }
    })();

    // PWA: register the (network-only) service worker.
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    }
})();
