/**
 * websocket_client.js — Minimal WebSocket notification client for CHED
 * Reconnect: jittered exponential backoff, one socket per page.
 */
(function () {
    'use strict';
    if (!window.CHED) window.CHED = {};
    const CHED = window.CHED;
    if (CHED.WebSocketClient) return; // header included twice: keep one socket

    const WS_SCHEME = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
    // WS_PATH set (e.g. "/ws" on Render): same-origin, proxied by Apache.
    // Otherwise connect to a dedicated host:port (local XAMPP setup).
    const WS_HOST = window.WS_PATH
        ? WS_SCHEME + window.location.host + window.WS_PATH
        : WS_SCHEME + (window.WS_HOST || '127.0.0.1') + ':' + (window.WS_PORT || '8080');

    let ws = null;
    let reconnectTimer = null;
    let reconnectDelay = 1000;
    let sessionExpired = false;
    const maxReconnectDelay = 30000;
    const seenNotificationIds = new Set();

    function scheduleReconnect() {
        if (sessionExpired) return; // logged out: stop until the page reloads
        clearTimeout(reconnectTimer);
        // Jitter stops many open tabs from retrying in lockstep after a restart.
        reconnectTimer = setTimeout(connect, reconnectDelay + Math.random() * 1000);
        reconnectDelay = Math.min(reconnectDelay * 2, maxReconnectDelay);
    }

    function connect() {
        if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) {
            return; // already connected/connecting: no duplicate sockets
        }
        try {
            ws = new WebSocket(WS_HOST);
            const sock = ws;
            sock.onopen = function () {
                reconnectDelay = 1000;
                // Always fetch a fresh ticket: tickets are single-use, so reusing
                // the page-rendered one after a reconnect is rejected as a replay.
                getTicket().then(function (res) {
                    if (res.expired) sessionExpired = true;
                    // Connection may have dropped while the ticket was fetched.
                    if (res.ticket && sock.readyState === WebSocket.OPEN) {
                        sock.send(JSON.stringify({ action: 'auth', ticket: res.ticket }));
                    } else {
                        try { sock.close(); } catch (e) {}
                    }
                });
            };
            sock.onmessage = function (event) {
                try {
                    const msg = JSON.parse(event.data);
                    if (msg.type === 'auth_ok') {
                        // Authenticated
                        return;
                    }
                    if (msg.type === 'auth_fail') {
                        sock.close();
                        return;
                    }
                    if (msg.type === 'notification' && msg.notification) {
                        const n = msg.notification;
                        if (seenNotificationIds.has(n.id)) return;
                        seenNotificationIds.add(n.id);
                        updateNotificationUI(n);
                    }
                } catch (e) {
                    // ignore invalid messages
                }
            };
            sock.onerror = function () {
                // silent
            };
            sock.onclose = function () {
                if (ws !== sock) return; // stale socket from an earlier attempt
                ws = null;
                scheduleReconnect();
            };
        } catch (e) {
            ws = null;
            scheduleReconnect();
        }
    }

    // A ticket is valid exactly once; ws_ticket.php always hands out a fresh one.
    function getTicket() {
        return fetch('ws_ticket.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (r) {
                if (r.status === 401) return { expired: true };
                return r.ok ? r.json() : {};
            })
            .then(function (d) { return { ticket: (d && d.ticket) || null, expired: !!(d && d.expired) }; })
            .catch(function () { return {}; });
    }

    // Keep every open tab on the server-authoritative unread count.
    const syncChannel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('ched-notif-count') : null;
    if (syncChannel) {
        syncChannel.onmessage = function (e) {
            if (e.data && typeof e.data.count === 'number') setNotifCount(e.data.count);
        };
    }

    function updateNotificationUI(n) {
        prependNotifPanelItem(n);
        // Don't guess "+1": fetch the same authoritative unread count that
        // notifications.php renders, so the badge can never drift from it.
        fetch('notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'action=get_notifications&last_id=' + encodeURIComponent(n.id)
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.success) setCount(parseInt(d.unread_count, 10) || 0); })
            .catch(function () {});
    }

    // Paint locally, then tell the other tabs.
    function setCount(count) {
        count = Math.max(0, parseInt(count, 10) || 0);
        setNotifCount(count);
        if (syncChannel) syncChannel.postMessage({ count: count });
    }

    // Single place that paints the unread count: bell badge, page badge, tab count.
    function setNotifCount(count) {
        const badge = document.getElementById('notifCountBadge');
        if (count > 0) {
            const text = count > 99 ? '99+' : String(count);
            if (badge) {
                badge.textContent = text;
                badge.style.display = '';
            } else {
                const btn = document.getElementById('notifBtn');
                if (btn) {
                    const newBadge = document.createElement('span');
                    newBadge.id = 'notifCountBadge';
                    newBadge.className = 'notif-count';
                    newBadge.textContent = text;
                    btn.appendChild(newBadge);
                }
            }
        } else if (badge) {
            badge.remove();
        }

        const cardBadge = document.querySelector('.card-header .badge');
        if (cardBadge && cardBadge.textContent.indexOf('unread') !== -1) {
            cardBadge.textContent = count + ' unread';
            cardBadge.className = count > 0 ? 'badge badge-submitted' : 'badge badge-pending';
        }
        const tabUnread = document.getElementById('tabCountUnread');
        if (tabUnread) tabUnread.textContent = String(count);
    }

    // Mirrors the .np-item markup rendered by includes/header.php (#notifList).
    function prependNotifPanelItem(n) {
        const list = document.getElementById('notifList');
        if (!list || list.querySelector('.np-item[data-notification-id="' + n.id + '"]')) return;
        const empty = list.querySelector('.np-empty');
        if (empty) empty.remove();

        const type = ['success', 'danger', 'warning'].indexOf(n.type) !== -1 ? n.type : 'info';
        const icon = type === 'success'
            ? '<path d="m5 13 4 4L19 7"/>'
            : type === 'danger' ? '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'
            : type === 'warning' ? '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'
            : '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>';

        const item = document.createElement('a');
        item.href = 'notifications.php';
        item.className = 'np-item unread';
        item.dataset.unread = '1';
        item.dataset.notificationId = n.id;
        item.innerHTML =
            '<span class="np-icon ' + type + '"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + icon + '</svg></span>' +
            '<div class="np-content">' +
                '<div class="np-item-title">' + escapeHtml(n.title || '') + '</div>' +
                '<div class="np-item-msg">' + escapeHtml((n.message || '').slice(0, 70)) + '</div>' +
                '<div class="np-item-time">Just now</div>' +
            '</div>';
        list.insertBefore(item, list.firstElementChild);

        const tabAll = document.getElementById('tabCountAll');
        if (tabAll) tabAll.textContent = list.querySelectorAll('.np-item').length;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    // Start connection after DOM ready if meta ticket exists; otherwise delay slightly
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(connect, 500);
    });
    CHED.WebSocketClient = { connect: connect, setCount: setCount };
})();
