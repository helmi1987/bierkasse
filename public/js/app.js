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

    // PWA: register the (network-only) service worker.
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    }
})();
