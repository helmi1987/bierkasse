/*
 * Live page refresh and undo expiry (no websockets, CSP friendly).
 *
 * 1. Undo expiry: every element with data-expires="<epoch ms>" (undo buttons on the dashboard) is
 *    removed when its window ends, measured in server time (<meta name="server-now">) so a wrong
 *    client clock does not matter. A [data-undo-group] without items left (list + hint) goes too.
 *    The server still checks every undo.
 *
 * 2. Live refresh (only when <body data-live="<fingerprint url>">): every 10 seconds, while the tab is
 *    visible, the cheap fingerprint is fetched. Only when it differs from the shown page's
 *    (data-live-fp), or every 5 minutes, the current URL is fetched again (header X-Live-Refresh) and
 *    the changed direct children of <main> plus the top navigation (badges) are swapped in.
 *    A child is never swapped while a field in it has focus or was changed by the user (dirty), while one of its
 *    forms is being sent or a sortable list has unsaved changes; the open state of <details> and the
 *    scroll position are kept. Flash messages (data-live-keep) stay as they are.
 */
(function () {
    'use strict';

    var INTERVAL = 10000;
    var FORCE = 300000;

    var body = document.body;
    var offset = 0; // server time - client time (ms)

    function readServerTime(doc) {
        var meta = doc.querySelector('meta[name="server-now"]');
        var now = meta ? parseInt(meta.getAttribute('content'), 10) : NaN;
        if (!isNaN(now)) {
            offset = now - Date.now();
        }
    }

    // ---- 1. Undo expiry -------------------------------------------------------------------------

    var expiryTimer = null;

    function expireNow() {
        var serverNow = Date.now() + offset;
        var left = 0;
        document.querySelectorAll('[data-expires]').forEach(function (el) {
            var at = parseInt(el.getAttribute('data-expires'), 10);
            if (isNaN(at)) {
                return;
            }
            if (at <= serverNow) {
                var group = el.closest('[data-undo-group]');
                el.parentNode.removeChild(el);
                if (group && !group.querySelector('[data-expires]')) {
                    group.parentNode.removeChild(group);
                }
            } else {
                left++;
            }
        });
        return left;
    }

    function watchExpiry() {
        if (expiryTimer !== null) {
            return;
        }
        if (expireNow() > 0) {
            expiryTimer = window.setInterval(function () {
                if (expireNow() === 0) {
                    window.clearInterval(expiryTimer);
                    expiryTimer = null;
                }
            }, 1000);
        }
    }

    readServerTime(document);
    watchExpiry();

    // ---- 2. Live refresh ------------------------------------------------------------------------

    var fpUrl = body.getAttribute('data-live');
    var main = document.getElementById('main');
    if (!fpUrl || !main || !window.fetch || !window.DOMParser) {
        return;
    }

    var fp = body.getAttribute('data-live-fp') || '';
    var lastRender = Date.now();
    var timer = null;
    var busy = false;
    var stopped = false;
    var pausedUntil = 0;
    // Parts not swapped because the user was busy; re-render as soon as one of them is free again.
    var staleCheck = null;

    // Server HTML of each main child as last rendered (form tokens masked), to swap only what changed.
    // Captured before app.js runs (this script comes first), so it is still the server's markup.
    // Masking is done on a DOM clone, so it does not depend on the attribute order in the markup.
    var TOKEN_SELECTOR = 'input[name="form_token"], input[name="_token"]';

    function normalize(el, inner) {
        var copy = el.cloneNode(true);
        var inputs = Array.prototype.slice.call(copy.querySelectorAll(TOKEN_SELECTOR));
        if (copy.matches && copy.matches(TOKEN_SELECTOR)) {
            inputs.push(copy);
        }
        inputs.forEach(function (input) { input.setAttribute('value', ''); });
        return inner ? copy.innerHTML : copy.outerHTML;
    }

    function isKeep(el) {
        return el.hasAttribute('data-live-keep');
    }

    function contentChildren(root) {
        return Array.prototype.filter.call(root.children, function (el) { return !isKeep(el); });
    }

    var serverHtml = contentChildren(main).map(function (el) { return normalize(el); });

    // Dirty = the user typed/changed something (real input events only). Values that scripts put into
    // fields (e.g. amount suggestions) do not count. Cleared when the form is sent or reset.
    var FIELD_SELECTOR = 'input, textarea, select';

    document.addEventListener('input', markDirty, true);
    document.addEventListener('change', markDirty, true);
    document.addEventListener('reset', function (e) {
        if (e.target && e.target.querySelectorAll) {
            e.target.querySelectorAll('[data-live-dirty]').forEach(function (f) { f.removeAttribute('data-live-dirty'); });
        }
    }, true);

    function markDirty(e) {
        var field = e.target;
        if (!e.isTrusted || !field || !field.matches || !field.matches(FIELD_SELECTOR)) {
            return;
        }
        var type = (field.type || '').toLowerCase();
        if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset') {
            return;
        }
        field.setAttribute('data-live-dirty', '');
    }

    function isBlocked(el) {
        var active = document.activeElement;
        if (active && active !== body && el.contains(active) && active.matches('input, textarea, select, [contenteditable]')) {
            return true;
        }
        return !!el.querySelector('form[data-submitted], [data-live-dirty]') || el.matches('[data-live-dirty]');
    }

    // Key of a <details>: its summary text (plus the n-th occurrence of that text).
    function detailsKeys(root) {
        var seen = {};
        var keys = [];
        root.querySelectorAll('details').forEach(function (d) {
            var summary = d.querySelector('summary');
            var text = (d.id || '') + '|' + (summary ? summary.textContent.replace(/\s+/g, ' ').trim() : '');
            seen[text] = (seen[text] || 0) + 1;
            keys.push({ el: d, key: text + '#' + seen[text] });
        });
        return keys;
    }

    // Copy the fresh form tokens (and CSRF token) into a part that is not swapped, so its forms stay valid
    // even when older single-use tokens drop out of the session. Forms are matched by identity (method +
    // action + n-th occurrence of that pair); a form without a counterpart is left untouched.
    function formKeys(root) {
        var forms = root.matches('form') ? [root] : Array.prototype.slice.call(root.querySelectorAll('form'));
        var seen = {};
        return forms.map(function (form) {
            var base = (form.getAttribute('method') || 'get').toLowerCase() + '|' + (form.getAttribute('action') || '');
            seen[base] = (seen[base] || 0) + 1;
            return { form: form, key: base + '#' + seen[base] };
        });
    }

    function syncTokens(oldEl, newEl) {
        var fresh = {};
        formKeys(newEl).forEach(function (f) { fresh[f.key] = f.form; });
        formKeys(oldEl).forEach(function (f) {
            var other = fresh[f.key];
            if (!other) {
                return;
            }
            ['form_token', '_token'].forEach(function (name) {
                var a = f.form.querySelector('input[name="' + name + '"]');
                var b = other.querySelector('input[name="' + name + '"]');
                if (a && b) {
                    a.value = b.value;
                    a.defaultValue = b.value;
                }
            });
        });
    }

    function replace(oldEl, newEl) {
        var open = {};
        detailsKeys(oldEl).forEach(function (d) { open[d.key] = d.el.open; });
        var scrolls = Array.prototype.map.call(oldEl.querySelectorAll('.table-wrap'), function (w) { return w.scrollLeft; });

        detailsKeys(newEl).forEach(function (d) {
            if (Object.prototype.hasOwnProperty.call(open, d.key)) {
                d.el.open = open[d.key];
            }
        });
        var adopted = document.importNode(newEl, true);
        oldEl.parentNode.replaceChild(adopted, oldEl);
        adopted.querySelectorAll('.table-wrap').forEach(function (w, i) {
            if (scrolls[i]) {
                w.scrollLeft = scrolls[i];
            }
        });
        if (window.BierkasseUi) {
            window.BierkasseUi.init(adopted);
        }
    }

    function apply(doc) {
        var newMain = doc.getElementById('main');
        if (!newMain) {
            return;
        }
        var x = window.scrollX;
        var y = window.scrollY;

        // Top navigation (Kassier/Backend badges); it holds no user input.
        var oldNav = document.querySelector('.topbar nav');
        var newNav = doc.querySelector('.topbar nav');
        if (oldNav && newNav && normalize(oldNav, true) !== normalize(newNav, true) && !isBlocked(oldNav)) {
            oldNav.parentNode.replaceChild(document.importNode(newNav, true), oldNav);
        }

        var oldKids = contentChildren(main);
        var newKids = contentChildren(newMain);
        var newHtml = newKids.map(function (el) { return normalize(el); });
        var sameShape = oldKids.length === newKids.length && oldKids.every(function (el, i) {
            return el.tagName === newKids[i].tagName;
        });

        var skipped = [];
        if (sameShape) {
            oldKids.forEach(function (el, i) {
                if (newHtml[i] === serverHtml[i]) {
                    syncTokens(el, newKids[i]);
                } else if (isBlocked(el)) {
                    syncTokens(el, newKids[i]);
                    newHtml[i] = serverHtml[i]; // still the old markup on screen
                    skipped.push(el);
                } else {
                    replace(el, newKids[i]);
                }
            });
            serverHtml = newHtml;
        } else if (!oldKids.some(isBlocked)) {
            // Different structure (e.g. a card appeared): swap all content, flash messages stay on top.
            var open = {};
            detailsKeys(main).forEach(function (d) { open[d.key] = d.el.open; });
            detailsKeys(newMain).forEach(function (d) {
                if (Object.prototype.hasOwnProperty.call(open, d.key)) {
                    d.el.open = open[d.key];
                }
            });
            oldKids.forEach(function (el) { el.parentNode.removeChild(el); });
            newKids.forEach(function (el) {
                var adopted = document.importNode(el, true);
                main.appendChild(adopted);
                if (window.BierkasseUi) {
                    window.BierkasseUi.init(adopted);
                }
            });
            serverHtml = newHtml;
        } else {
            // Wait until the user is done with all of it (tokens of the shown forms stay as they are).
            staleCheck = function () { return !contentChildren(main).some(isBlocked); };
            return;
        }

        if (window.scrollX !== x || window.scrollY !== y) {
            window.scrollTo(x, y);
        }
        watchExpiry();
        staleCheck = skipped.length === 0 ? null : function () {
            return skipped.some(function (el) { return el.isConnected && !isBlocked(el); });
        };
    }

    function stop(reload) {
        stopped = true;
        window.clearTimeout(timer);
        if (reload) {
            // Logged out, maintenance or redirected: load the page once (shows login/maintenance page).
            window.location.reload();
        }
    }

    function refresh() {
        return window.fetch(window.location.href, {
            headers: { 'X-Live-Refresh': '1', Accept: 'text/html' },
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (response) {
            if (response.status === 401 || response.status === 503 || response.redirected) {
                stop(true);
                return null;
            }
            if (response.status !== 200 || (response.headers.get('Content-Type') || '').indexOf('text/html') === -1) {
                return null;
            }
            return response.text();
        }).then(function (html) {
            if (html === null || stopped || Date.now() < pausedUntil) {
                return;
            }
            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (!doc.body || !doc.body.hasAttribute('data-live')) {
                return;
            }
            apply(doc);
            fp = doc.body.getAttribute('data-live-fp') || fp;
            lastRender = Date.now();
            readServerTime(doc);
        });
    }

    function schedule() {
        window.clearTimeout(timer);
        if (!stopped && !document.hidden) {
            timer = window.setTimeout(tick, INTERVAL);
        }
    }

    function tick() {
        if (stopped || document.hidden) {
            return;
        }
        if (busy || Date.now() < pausedUntil) {
            schedule();
            return;
        }
        busy = true;
        window.fetch(fpUrl, {
            headers: { 'X-Live-Refresh': '1', Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (response) {
            if (response.status === 401 || response.status === 503) {
                stop(true);
                return null;
            }
            return response.ok && !response.redirected ? response.json() : null;
        }).then(function (data) {
            if (!data || stopped) {
                return;
            }
            // Re-render when data changed, when a skipped part can now be swapped, or now and then
            // (time based content such as day statistics).
            var retry = staleCheck !== null && staleCheck();
            if (data.fp !== fp || retry || Date.now() - lastRender > FORCE) {
                return refresh();
            }
        }).catch(function () { /* offline or bad response: try again later */ }).then(function () {
            busy = false;
            schedule();
        });
    }

    // A form being sent leaves the page: no refresh meanwhile (would also take its flash message).
    document.addEventListener('submit', function (e) {
        if (!e.defaultPrevented) {
            pausedUntil = Date.now() + 15000;
        }
    });
    window.addEventListener('pagehide', function () { window.clearTimeout(timer); });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            pausedUntil = 0;
            tick();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearTimeout(timer);
        } else {
            tick();
            // The undo timers may have been throttled in the background.
            expireNow();
        }
    });

    schedule();
})();
