/*
 * CHED Compliance - CSP event delegation.
 *
 * Inline event-handler attributes (onclick=, onchange=, ...) are NOT authorised
 * by a script nonce and are blocked once 'unsafe-inline' is removed from
 * script-src. This file replaces them with data-csp-* hooks handled by a single
 * delegated listener:
 *
 *   data-csp-action="fnName"          -> call window.fnName(...)
 *   data-csp-args='[..json..]'        -> arguments passed to window.fnName
 *   data-csp-el                       -> append the element as the last argument
 *   data-csp-event                    -> append the event object as the last argument
 *   data-csp-stop                     -> stopPropagation() and do nothing else
 *   data-csp-href="url"               -> navigate (click), ignoring interactive children
 *   data-csp-submit                   -> submit the closest form (change)
 *   data-csp-nav="prefix"             -> navigate to prefix + encodeURIComponent(value) (change)
 *
 * A private namespace is used so the pre-existing `data-action`/`data-href`
 * hooks (CSS selectors and page-specific JS) are left untouched.
 *
 * The page's own inline <script> (nonce-authorised) still defines the functions;
 * they are global, so window.fnName resolves here.
 */
(function () {
    'use strict';

    function splitArgs(el) {
        var raw = el.getAttribute('data-csp-args');
        if (!raw) { return []; }
        try {
            var v = JSON.parse(raw);
            return Array.isArray(v) ? v : [v];
        } catch (e) {
            if (window.console) { console.warn('[csp-events] bad data-csp-args:', raw); }
            return [];
        }
    }

    function run(el, ev) {
        var name = el.getAttribute('data-csp-action');
        if (!name) { return; }
        var fn = window[name];
        if (typeof fn !== 'function') {
            if (window.console) { console.warn('[csp-events] missing handler:', name); }
            return;
        }
        var args = splitArgs(el);
        if (el.hasAttribute('data-csp-el')) { args.push(el); }
        if (el.hasAttribute('data-csp-event')) { args.push(ev); }
        fn.apply(el, args);
    }

    document.addEventListener('click', function (ev) {
        var stop = ev.target.closest('[data-csp-stop]');
        if (stop) { ev.stopPropagation(); return; }
        var act = ev.target.closest('[data-csp-action]');
        if (act) { run(act, ev); return; }
        var link = ev.target.closest('[data-csp-href]');
        if (link) {
            if (ev.target.closest('button,a,input,select,textarea,label,form')) { return; }
            var href = link.getAttribute('data-csp-href');
            if (href) { window.location.href = href; }
        }
    }, false);

    document.addEventListener('change', function (ev) {
        if (ev.target.closest && ev.target.closest('[data-csp-stop]')) { return; }
        var nav = ev.target.closest('[data-csp-nav]');
        if (nav) {
            window.location.href = nav.getAttribute('data-csp-nav') + encodeURIComponent(nav.value);
            return;
        }
        var sub = ev.target.closest('[data-csp-submit]');
        if (sub) {
            var form = sub.form || sub.closest('form');
            if (form) { form.submit(); }
            return;
        }
        var act = ev.target.closest('[data-csp-action]');
        if (act) { run(act, ev); }
    }, false);
})();
