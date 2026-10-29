/**
 * websocket_client.js — Minimal WebSocket notification client for CHED
 * shortcut: basic reconnect with exponential backoff; upgrade to jittered backoff and circuit breaker.
 */
(function () {
    'use strict';
    if (!window.CHED) window.CHED = {};
    const CHED = window.CHED;

    const WS_HOST = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') + ((window.WS_HOST || 'localhost') + ':' + (window.WS_PORT || '8080'));
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '';

    let ws = null;
    let reconnectTimer = null;
    let reconnectDelay = 1000;
    const maxReconnectDelay = 30000;
    let reconnectCount = 0;
    const maxReconnectAttempts = 20; // shortcut: hard cap; upgrade to circuit breaker with health-check endpoint.
    const seenNotificationIds = new Set();

    function connect() {
        if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) {
            return; // shortcut: avoid duplicate connections; upgrade to connection manager.
        }
        if (reconnectCount >= maxReconnectAttempts) {
            return; // shortcut: hard stop after max retries; upgrade to circuit breaker with health-check endpoint.
        }
        reconnectCount++;
        try {
            ws = new WebSocket(WS_HOST);
            ws.onopen = function () {
                reconnectDelay = 1000;
                reconnectCount = 0;
                // Get ticket from server-rendered meta or fetch endpoint? For minimal: use meta tag injected by PHP if available.
                // shortcut: ticket delivered via meta tag rendered by PHP; upgrade to server endpoint for dynamic ticket refresh.
                const metaTicket = document.querySelector('meta[name="ws-ticket"]');
                if (metaTicket && metaTicket.content) {
                    ws.send(JSON.stringify({ action: 'auth', ticket: metaTicket.content }));
                }
            };
            ws.onmessage = function (event) {
                try {
                    const msg = JSON.parse(event.data);
                    if (msg.type === 'auth_ok') {
                        // Authenticated
                        return;
                    }
                    if (msg.type === 'auth_fail') {
                        ws.close();
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
            ws.onerror = function () {
                // silent
            };
            ws.onclose = function () {
                reconnectTimer = setTimeout(connect, reconnectDelay);
                reconnectDelay = Math.min(reconnectDelay * 2, maxReconnectDelay);
            };
        } catch (e) {
            reconnectTimer = setTimeout(connect, reconnectDelay);
            reconnectDelay = Math.min(reconnectDelay * 2, maxReconnectDelay);
        }
    }

    function updateNotificationUI(n) {
        // Update badge count
        const badge = document.getElementById('notifCountBadge');
        let currentCount = badge ? parseInt(badge.textContent || '0', 10) : 0;
        if (isNaN(currentCount)) currentCount = 0;
        const newCount = currentCount + 1;

        if (badge) {
            badge.textContent = newCount > 99 ? '99+' : newCount;
            badge.style.display = '';
        } else {
            const btn = document.getElementById('notifBtn');
            if (btn) {
                const newBadge = document.createElement('span');
                newBadge.id = 'notifCountBadge';
                newBadge.className = 'notif-count';
                newBadge.textContent = newCount > 99 ? '99+' : newCount;
                btn.appendChild(newBadge);
            }
        }

        // Prepend to dropdown if visible
        const dropdown = document.getElementById('notifDropdown');
        if (dropdown) {
            const list = dropdown.querySelector('.notif-list') || dropdown.querySelector('.flex.flex-col');
            if (list) {
                prependDropdownItem(list, n);
            }
        }
    }

    function prependDropdownItem(container, n) {
        const item = document.createElement('a');
        item.href = 'notifications.php';
        item.className = 'notif-item unread';
        item.setAttribute('data-notification-id', n.id);
        item.innerHTML = '<span class="notif-icon info"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span>' +
            '<div class="notif-content"><div class="notif-title">' + escapeHtml(n.title || '') + '</div>' +
            '<div class="notif-msg">' + escapeHtml((n.message || '').slice(0, 70)) + '</div>' +
            '<div class="notif-time">Just now</div></div>';
        const first = container.querySelector('.notif-item');
        if (first) {
            container.insertBefore(item, first);
        } else {
            container.appendChild(item);
        }
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
    CHED.WebSocketClient = { connect: connect };
})();
