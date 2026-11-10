// Client-side WebSocket reconnect checks. Run: node tests/ws_client_test.js
'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const source = fs.readFileSync(__dirname + '/../includes/websocket_client.js', 'utf8');

function makeHarness() {
    let now = 0, nextId = 1, timers = [];
    let respond401 = false;
    const sockets = [];

    class FakeWebSocket {
        constructor(url) {
            this.url = url;
            this.readyState = FakeWebSocket.CONNECTING;
            this.sent = [];
            sockets.push(this);
        }
        send(data) { this.sent.push(data); }
        close() {
            if (this.readyState === FakeWebSocket.CLOSED) return;
            this.readyState = FakeWebSocket.CLOSED;
            if (this.onclose) this.onclose();
        }
    }
    FakeWebSocket.CONNECTING = 0;
    FakeWebSocket.OPEN = 1;
    FakeWebSocket.CLOSING = 2;
    FakeWebSocket.CLOSED = 3;

    const sandbox = {
        console,
        location: { protocol: 'http:', host: '127.0.0.1' },
        document: { addEventListener() {}, querySelector() { return null; }, getElementById() { return null; } },
        WebSocket: FakeWebSocket,
        setTimeout(fn, ms) { timers.push({ id: nextId, fn, at: now + ms }); return nextId++; },
        clearTimeout(id) { timers = timers.filter(t => t.id !== id); },
        setInterval() { return 0; },
        clearInterval() {},
        Math: Object.create(Math),
        fetch() {
            return Promise.resolve(respond401
                ? { status: 401, ok: false }
                : { status: 200, ok: true, json: () => Promise.resolve({ ticket: 'fresh-ticket' }) });
        },
    };
    sandbox.window = sandbox;
    sandbox.Math.random = () => 0.5;
    sandbox.setTimeout = function (fn, ms) { timers.push({ id: nextId, fn, at: now + ms }); return nextId++; };

    vm.runInNewContext(source, sandbox, { filename: 'websocket_client.js' });
    const client = sandbox.CHED.WebSocketClient;

    return {
        client, sockets, timers: () => timers,
        set401(v) { respond401 = v; },
        fireNextTimer() { const t = timers.shift(); assert(t, 'expected a pending reconnect timer'); t.fn(); },
        pending() { return timers.length; },
        resetTimers() { timers = []; },
    };
}

const tick = () => new Promise(r => setImmediate(r));

(async function () {
    const h = makeHarness();

    h.client.connect();
    assert.strictEqual(h.sockets.length, 1, 'first connect creates one socket');
    h.client.connect();
    assert.strictEqual(h.sockets.length, 1, 'second connect does not duplicate the socket');
    console.log('PASS duplicate connection guard');

    const s1 = h.sockets[0];
    s1.readyState = 1;
    s1.onopen();
    await tick();
    assert.strictEqual(s1.sent.length, 1, 'ticket is sent after open');
    assert.strictEqual(JSON.parse(s1.sent[0]).action, 'auth', 'auth action sent');
    assert.ok(JSON.parse(s1.sent[0]).ticket, 'fresh ticket fetched');
    console.log('PASS auth uses a fresh fetched ticket');

    s1.close();
    assert.strictEqual(h.pending(), 1, 'disconnect schedules exactly one reconnect');
    const firstAt = h.timers()[0].at;
    assert.ok(firstAt >= 1000 && firstAt <= 2000, 'first retry is 1s + jitter, got ' + firstAt);
    console.log('PASS jittered reconnect scheduled');

    h.fireNextTimer();
    assert.strictEqual(h.sockets.length, 2, 'reconnect attempted');
    const s2 = h.sockets[1];
    s2.close();
    const secondAt = h.timers()[0].at;
    assert.ok(secondAt >= 2000 && secondAt <= 3000, 'backoff doubles, got ' + secondAt);
    console.log('PASS exponential backoff doubles');

    h.fireNextTimer();
    const s3 = h.sockets[2];
    h.set401(true);
    s3.readyState = 1;
    s3.onopen();
    await tick();
    assert.strictEqual(s3.readyState, 3, 'closed after 401');
    assert.strictEqual(h.pending(), 0, 'no reconnect after session expiry');
    console.log('PASS stops retrying when the session is gone');

    console.log('ALL PASS');
})().catch(function (e) {
    console.error('FAIL:', e.message);
    process.exit(1);
});
