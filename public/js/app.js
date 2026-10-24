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

    // Elements in root (root itself included) matching the selector.
    function all(root, selector) {
        var list = Array.prototype.slice.call(root.querySelectorAll(selector));
        if (root.matches && root.matches(selector)) {
            list.unshift(root);
        }
        return list;
    }

    // Marks a colleague as pinned/unpinned in all colleague lists and re-sorts them in place
    // (favourites first, then the server order data-pos). Moving the <li> keeps the checkbox state.
    function setPinned(userId, pinned) {
        document.querySelectorAll('[data-pin="' + userId + '"]').forEach(function (b) {
            var li = b.closest('li');
            b.setAttribute('aria-pressed', pinned ? 'true' : 'false');
            b.textContent = pinned ? '\u2605' : '\u2606';
            var label = pinned ? b.dataset.labelUnpin : b.dataset.labelPin;
            b.setAttribute('aria-label', label);
            b.title = label;
            if (li) {
                li.classList.toggle('is-fav', pinned);
            }
        });
        document.querySelectorAll('.round-list').forEach(function (list) {
            var items = Array.prototype.slice.call(list.children);
            items.sort(function (a, b) {
                var fa = a.classList.contains('is-fav') ? 0 : 1;
                var fb = b.classList.contains('is-fav') ? 0 : 1;
                return fa - fb || (parseInt(a.dataset.pos, 10) || 0) - (parseInt(b.dataset.pos, 10) || 0);
            });
            items.forEach(function (li) { list.appendChild(li); });
        });
    }

    // Widgets bound to elements; run once for the page and again for content swapped in by the live
    // refresh (public/js/live.js calls window.BierkasseUi.init(newElement)).
    function initUi(root) {
        // Quantity stepper for "Bier abstreichen" (works without JS as a number input).
        all(root, '[data-stepper]').forEach(function (stepper) {
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
                    var current = parseInt(input.value, 10);
                    var value = (isNaN(current) ? min : current) + parseInt(btn.dataset.step, 10);
                    input.value = String(Math.min(max, Math.max(min, value)));
                    render();
                    // Let other widgets (e.g. the round total) see the new value.
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
            input.addEventListener('input', render);
            render();
        });

        // "Runde spendieren": search filter for the colleague list and live total (beers and amount).
        all(root, 'form[data-round]').forEach(function (form) {
            var price = parseInt(form.dataset.price || '0', 10);
            var max = parseInt(form.dataset.max || '30', 10);
            var count = form.querySelector('[data-round-count]');
            var amount = form.querySelector('[data-round-amount]');
            var submit = form.querySelector('[data-round-submit]');
            var submitSelf = form.querySelector('[data-round-submit-self]');
            var extra = form.querySelector('input[name=extra]');
            var filter = form.querySelector('[data-round-filter]');
            var render = function () {
                var n = form.querySelectorAll('input[name="recipients[]"]:checked').length;
                var e = extra ? parseInt(extra.value, 10) : 0;
                n += isNaN(e) ? 0 : Math.max(0, e);
                var rappen = n * price;
                count.textContent = n === 1 ? form.dataset.one : form.dataset.many.replace(':count', String(n));
                amount.textContent = 'CHF ' + Math.floor(rappen / 100) + '.' + String(rappen % 100).padStart(2, '0');
                // Two buttons: the round alone and "inkl. mir" (one beer more for the giver).
                submit.textContent = submit.dataset.label.replace(':count', String(n));
                submit.disabled = n < 1 || n > max;
                if (submitSelf) {
                    submitSelf.textContent = submitSelf.dataset.label.replace(':count', String(n + 1));
                    // A round only for oneself makes no sense: needs at least one other beer.
                    submitSelf.disabled = n < 1 || n + 1 > max;
                }
            };
            form.addEventListener('change', render);
            form.addEventListener('input', function (e) {
                if (e.target !== filter) {
                    render();
                }
            });
            if (filter) {
                filter.hidden = false;
                filter.addEventListener('input', function () {
                    var q = filter.value.trim().toLowerCase();
                    form.querySelectorAll('.round-list li').forEach(function (li) {
                        // Checked colleagues stay visible.
                        var checked = li.querySelector('input:checked');
                        li.hidden = q !== '' && !checked && li.dataset.name.indexOf(q) === -1;
                    });
                });
                // Enter in the search field must not send the form.
                filter.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });
            }
            render();
        });

        // "Bier verschenken": search filter and live total (recipients × beers per person).
        all(root, 'form[data-gift]').forEach(function (form) {
            var price = parseInt(form.dataset.price || '0', 10);
            var count = form.querySelector('[data-gift-count]');
            var amount = form.querySelector('[data-gift-amount]');
            var submit = form.querySelector('[data-gift-submit]');
            var qty = form.querySelector('input[name=gift_quantity]');
            var filter = form.querySelector('[data-round-filter]');
            var render = function () {
                var q = parseInt(qty.value, 10);
                var people = form.querySelectorAll('input[name="gift_recipients[]"]:checked').length;
                var n = people * (isNaN(q) ? 1 : Math.max(1, q));
                var rappen = n * price;
                count.textContent = n === 1 ? form.dataset.one : form.dataset.many.replace(':count', String(n));
                amount.textContent = 'CHF ' + Math.floor(rappen / 100) + '.' + String(rappen % 100).padStart(2, '0');
                submit.disabled = people < 1;
            };
            form.addEventListener('change', render);
            form.addEventListener('input', function (e) {
                if (e.target !== filter) {
                    render();
                }
            });
            if (filter) {
                filter.hidden = false;
                filter.addEventListener('input', function () {
                    var term = filter.value.trim().toLowerCase();
                    form.querySelectorAll('.round-list li').forEach(function (li) {
                        var checked = li.querySelector('input:checked');
                        li.hidden = term !== '' && !checked && li.dataset.name.indexOf(term) === -1;
                    });
                });
                filter.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });
            }
            render();
        });

        // Favourites: pin/unpin a colleague (buttons of the "Runde spendieren" and "Bier verschenken" lists).
        // POST via fetch so the checkbox selection stays; the lists are re-sorted in place (favourites first,
        // the rest in server order = data-pos). Without JavaScript the button submits #favorites-form normally.
        all(root, '[data-pin]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var form = btn.form;
                if (!form || !window.fetch || !window.FormData) {
                    return;
                }
                e.preventDefault();
                if (btn.disabled) {
                    return;
                }
                btn.disabled = true;
                window.fetch(btn.formAction, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                }).then(function (response) {
                    if (response.status === 419 || response.status === 401) {
                        // Session/CSRF expired: reload so the user lands on a fresh page or the login.
                        window.location.reload();
                        return null;
                    }
                    if (!response.ok) {
                        throw new Error('pin failed');
                    }
                    return response.json();
                }).then(function (data) {
                    if (data) {
                        setPinned(btn.dataset.pin, !!data.pinned, btn);
                    }
                }).catch(function () {
                    // Fall back to the normal form post (reloads the page).
                    form.action = btn.formAction;
                    form.submit();
                }).then(function () {
                    btn.disabled = false;
                    btn.focus({ preventScroll: true });
                });
            });
        });

        // Copy buttons: data-copy="#selector" (text of that element) or data-copy-text="value".
        all(root, '[data-copy], [data-copy-text]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var source = btn.dataset.copy ? document.querySelector(btn.dataset.copy) : null;
                var text = btn.dataset.copyText || (source ? source.textContent.trim() : '');
                if (!text || !navigator.clipboard) {
                    return;
                }
                navigator.clipboard.writeText(text).then(function () {
                    var label = btn.textContent;
                    btn.textContent = btn.dataset.copied || '✓';
                    window.setTimeout(function () { btn.textContent = label; }, 1500);
                });
            });
        });

        // Share buttons: data-share="#selector" (URL as text content). Native share sheet where available,
        // otherwise the URL is copied to the clipboard.
        all(root, '[data-share]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var source = document.querySelector(btn.dataset.share);
                if (!source) {
                    return;
                }
                var url = source.textContent.trim();
                if (navigator.share) {
                    navigator.share({ title: btn.dataset.shareTitle || '', text: btn.dataset.shareText || '', url: url })
                        .catch(function () { /* cancelled */ });
                    return;
                }
                if (!navigator.clipboard) {
                    return;
                }
                navigator.clipboard.writeText(url).then(function () {
                    var label = btn.textContent;
                    btn.textContent = btn.dataset.copied || '✓';
                    window.setTimeout(function () { btn.textContent = label; }, 1500);
                });
            });
        });

        // Deposit: proposal buttons fill the amount; below the field the amount is shown as beers.
        all(root, '[data-beers]').forEach(function (input) {
            var out = document.querySelector(input.dataset.beers);
            var price = parseInt(input.dataset.price || '0', 10);
            var debt = parseInt(input.dataset.debt || '0', 10);
            var buttons = document.querySelectorAll('[data-fill="#' + input.id + '"]');
            var render = function () {
                var value = input.value.replace(/[\s']/g, '').replace(',', '.');
                var rappen = /^\d{1,6}(\.\d{1,2})?$/.test(value) ? Math.round(parseFloat(value) * 100) : NaN;
                buttons.forEach(function (btn) { btn.classList.toggle('active', btn.dataset.value === input.value.trim()); });
                if (!out || !price || isNaN(rappen) || rappen <= debt) {
                    if (out) { out.textContent = ''; }
                    return;
                }
                var beers = Math.floor((rappen - debt) / price);
                out.textContent = (debt > 0 ? input.dataset.textDebt : input.dataset.text).replace(':count', String(beers));
            };
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    input.value = btn.dataset.value;
                    render();
                });
            });
            input.addEventListener('input', render);
            render();
        });

        // Checkboxes that save immediately; their fallback submit button is hidden.
        all(root, '[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () { el.form.requestSubmit(); });
        });
        all(root, '[data-hide-js]').forEach(function (el) { el.hidden = true; });

        // Print button.
        all(root, '[data-print]').forEach(function (btn) {
            btn.addEventListener('click', function () { window.print(); });
        });

        // PNG download of an SVG image: data-png-download="<svg url>", data-png-filename, data-png-error="#selector",
        // optional data-png-width/-height in px. Renders the (same-origin) SVG onto a white canvas, default A4 at
        // 300 dpi (2480 x 3508 px).
        all(root, '[data-png-download]').forEach(function (btn) {
            var errorBox = btn.dataset.pngError ? document.querySelector(btn.dataset.pngError) : null;
            var fail = function () {
                btn.disabled = false;
                if (errorBox) {
                    errorBox.hidden = false;
                }
            };
            var save = function (url) {
                var link = document.createElement('a');
                link.href = url;
                link.download = btn.dataset.pngFilename || 'image.png';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            };
            btn.addEventListener('click', function () {
                if (errorBox) {
                    errorBox.hidden = true;
                }
                btn.disabled = true;
                var img = new Image();
                img.onload = function () {
                    try {
                        var canvas = document.createElement('canvas');
                        canvas.width = parseInt(btn.dataset.pngWidth || '2480', 10);
                        canvas.height = parseInt(btn.dataset.pngHeight || '3508', 10);
                        var ctx = canvas.getContext('2d');
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                        if (canvas.toBlob) {
                            canvas.toBlob(function (blob) {
                                if (!blob) {
                                    fail();
                                    return;
                                }
                                var url = URL.createObjectURL(blob);
                                save(url);
                                // Revoke later: Safari starts the download asynchronously.
                                window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
                                btn.disabled = false;
                            }, 'image/png');
                        } else {
                            save(canvas.toDataURL('image/png'));
                            btn.disabled = false;
                        }
                    } catch (err) {
                        fail();
                    }
                };
                img.onerror = fail;
                img.src = btn.dataset.pngDownload;
            });
        });

        // Horizontally scrolling menus (backend subnav, tabs): keep the scroll position across page
        // loads instead of jumping back to the start, and make sure the active entry is visible.
        all(root, '.subnav, .tabs').forEach(function (nav, i) {
            var key = 'navscroll:' + (nav.getAttribute('aria-label') || nav.className + i);
            try {
                var saved = window.sessionStorage.getItem(key);
                if (saved !== null) {
                    nav.scrollLeft = parseInt(saved, 10) || 0;
                }
            } catch (err) { /* storage blocked */ }
            var active = nav.querySelector('a.active');
            if (active) {
                var left = active.offsetLeft - nav.offsetLeft;
                if (left < nav.scrollLeft || left + active.offsetWidth > nav.scrollLeft + nav.clientWidth) {
                    nav.scrollLeft = Math.max(0, left - (nav.clientWidth - active.offsetWidth) / 2);
                }
            }
            var store = function () {
                try {
                    window.sessionStorage.setItem(key, String(Math.round(nav.scrollLeft)));
                } catch (err) { /* storage blocked */ }
            };
            nav.addEventListener('scroll', store, { passive: true });
            nav.addEventListener('click', store);
        });

        // Sortable lists: drag and drop, or the ↑/↓ buttons (data-move).
        all(root, '[data-sortable]').forEach(function (list) {
            var dragged = null;
            list.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-move]');
                if (!btn) {
                    return;
                }
                var item = btn.closest('li');
                if (btn.dataset.move === '-1' && item.previousElementSibling) {
                    list.insertBefore(item, item.previousElementSibling);
                } else if (btn.dataset.move === '1' && item.nextElementSibling) {
                    list.insertBefore(item.nextElementSibling, item);
                }
                btn.focus();
                // Unsaved order: the live refresh must not swap this list.
                list.setAttribute('data-live-dirty', '');
            });
            list.addEventListener('dragstart', function (e) {
                dragged = e.target.closest('li');
                if (dragged) {
                    dragged.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                }
            });
            list.addEventListener('dragend', function () {
                if (dragged) {
                    dragged.classList.remove('dragging');
                }
                dragged = null;
            });
            list.addEventListener('dragover', function (e) {
                var over = e.target.closest('li');
                if (!dragged || !over || over === dragged || over.parentNode !== list) {
                    return;
                }
                e.preventDefault();
                var rect = over.getBoundingClientRect();
                list.insertBefore(dragged, e.clientY > rect.top + rect.height / 2 ? over.nextSibling : over);
                list.setAttribute('data-live-dirty', '');
            });
        });
    }

    initUi(document);
    window.BierkasseUi = { init: initUi };

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
