/*
 * Server time and undo expiry on every page; on Livewire pages the idle rule, the "Neue Version" bar and
 * scroll anchoring (no websockets, CSP friendly).
 *
 * 1. Undo expiry: every element with data-expires="<epoch ms>" (undo buttons on the dashboard) is
 *    removed when its window ends, measured in server time (<meta name="server-now">) so a wrong
 *    client clock does not matter. A [data-undo-group] without items left (list + hint) goes too.
 *    The server still checks every undo.
 *
 * 2. Livewire pages (<body data-livewire-page>, since 1.20.0): the components refresh their data themselves
 *    (wire:poll.10s.visible). After IDLE_LIMIT (30 minutes) without user activity (trusted pointerdown,
 *    keydown, touchstart, wheel, scroll, input) Livewire's poll actions are cancelled (Livewire.interceptAction),
 *    so the session can expire; the next poll after an activity runs again. A component that finds newer CSS/JS
 *    on the server makes this script show the "Neue Version" bar; component updates keep the scroll position.
 *    The old full-page live refresh (fingerprint polling, swapping the children of <main>) is gone since 1.37.0.
 */
(function () {
    'use strict';

    // No polling after this long without user activity, so the server session can expire (SESSION_LIFETIME).
    var IDLE_LIMIT = 30 * 60 * 1000;

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

    // The undo timers may have been throttled in the background.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            expireNow();
        }
    });

    // ---- 2. Livewire pages: idle stop of wire:poll -------------------------------------------------

    if (body.hasAttribute('data-livewire-page')) {
        var lwLastActivity = Date.now();

        // Poll actions (wire:poll) are cancelled after IDLE_LIMIT without activity; user actions still run.
        var lwRegister = function () {
            if (!window.Livewire || typeof window.Livewire.interceptAction !== 'function' || lwRegister.done) {
                return;
            }
            lwRegister.done = true;
            window.Livewire.interceptAction(function (ctx) {
                var action = ctx.action;
                if (action && action.metadata && action.metadata.type === 'poll' && Date.now() - lwLastActivity > IDLE_LIMIT) {
                    action.cancel();
                }
            });
        };
        lwRegister();
        document.addEventListener('livewire:init', lwRegister);

        ['pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll', 'input'].forEach(function (name) {
            document.addEventListener(name, function (e) {
                if (e.isTrusted) {
                    lwLastActivity = Date.now();
                }
            }, { capture: true, passive: true });
        });

        // ---- Deploy while the page is open (1.34.0) --------------------------------------------
        // A component found newer CSS/JS on the server (App\Livewire\Concerns\WatchesAssets dispatches
        // "assets-changed"): a bar offers to reload. Automatic reload never mid-use: only after a minute
        // without activity, or when the tab comes back into view; in both cases with no focused field,
        // nothing typed since the page was loaded and no form being sent.
        var lwDirty = false;
        document.addEventListener('input', function (e) {
            if (e.isTrusted) {
                lwDirty = true;
            }
        }, { capture: true, passive: true });

        function canReload() {
            var active = document.activeElement;
            var inField = !!active && active !== body && active.matches('input, textarea, select, [contenteditable]');
            return !lwDirty && !inField && !document.querySelector('form[data-submitted]');
        }

        var updateShown = false;
        function showUpdate() {
            if (updateShown) {
                return;
            }
            updateShown = true;
            var bar = document.createElement('div');
            bar.className = 'alert info update-bar';
            bar.setAttribute('role', 'status');
            bar.setAttribute('data-testid', 'update-bar');
            var text = document.createElement('span');
            text.textContent = body.getAttribute('data-update-text') || '';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn small';
            button.textContent = body.getAttribute('data-update-button') || 'Reload';
            button.addEventListener('click', function () {
                window.location.reload();
            });
            bar.appendChild(text);
            bar.appendChild(button);
            var main = document.getElementById('main') || body;
            main.insertBefore(bar, main.firstChild);

            var check = function (idleMs) {
                if (!document.hidden && Date.now() - lwLastActivity > idleMs && canReload()) {
                    window.location.reload();
                }
            };
            window.setInterval(function () { check(60000); }, 5000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    check(0);
                }
            });
        }

        var lwUpdateHook = function () {
            if (!window.Livewire || typeof window.Livewire.on !== 'function' || lwUpdateHook.done) {
                return;
            }
            lwUpdateHook.done = true;
            window.Livewire.on('assets-changed', showUpdate);
        };
        lwUpdateHook();
        document.addEventListener('livewire:init', lwUpdateHook);

        // ---- Scroll anchoring for component updates (1.34.0) ---------------------------------
        // Chrome keeps the visible content in place when markup above it changes height (scroll anchoring),
        // Safari does not: a morphed island above the viewport would shift the page. Before each morph the
        // first visible element outside the morphed part is remembered; afterwards the page is scrolled by
        // the distance it moved (nothing happens where the browser anchors itself).
        var topbar = document.querySelector('.topbar');
        var anchor = null;
        var anchorTop = 0;
        function visibleTop() {
            return topbar ? topbar.getBoundingClientRect().bottom : 0;
        }
        function rememberAnchor(isInsideChange) {
            anchor = null;
            var limit = visibleTop();
            var candidates = document.querySelectorAll('#main section, #main .card, #main form, #main h2, #main li, #main p');
            for (var i = 0; i < candidates.length; i++) {
                var rect = candidates[i].getBoundingClientRect();
                if (rect.bottom > limit && rect.height > 0 && !isInsideChange(candidates[i])) {
                    anchor = candidates[i];
                    anchorTop = rect.top;
                    return;
                }
            }
        }
        // Inside an island = between its start and end comment nodes.
        function withinIsland(el, startNode, endNode) {
            return !!(startNode.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)
                && !!(el.compareDocumentPosition(endNode) & Node.DOCUMENT_POSITION_FOLLOWING);
        }
        function restoreAnchor() {
            if (!anchor || !anchor.isConnected) {
                return;
            }
            var delta = anchor.getBoundingClientRect().top - anchorTop;
            if (Math.abs(delta) >= 1) {
                window.scrollBy(0, delta);
            }
            anchor = null;
        }
        var lwScrollHook = function () {
            if (!window.Livewire || typeof window.Livewire.hook !== 'function' || lwScrollHook.done) {
                return;
            }
            lwScrollHook.done = true;
            window.Livewire.hook('morph', function (ctx) {
                rememberAnchor(function (el) { return !!ctx.el && ctx.el.contains(el); });
            });
            window.Livewire.hook('morphed', restoreAnchor);
            window.Livewire.hook('island.morph', function (ctx) {
                rememberAnchor(function (el) { return withinIsland(el, ctx.startNode, ctx.endNode); });
            });
            window.Livewire.hook('island.morphed', restoreAnchor);
        };
        lwScrollHook();
        document.addEventListener('livewire:init', lwScrollHook);
    }
})();
