/**
 * poller.js — Lightweight AJAX polling for live page data.
 * Usage:CHED.Poller.register('tasks', fetchFn, intervalMs)
 * 
 */
(function () {
    'use strict';

    const CHED = window.CHED = window.CHED || {};
    const tasks = {};
    let paused = false;

    /**
     * Register a polling task.
     * @param {string} id       Unique task name (e.g. 'tasks', 'submissions')
     * @param {Function} fetchFn  Async function that fetches and updates DOM
     * @param {number} intervalMs Polling interval in ms (default 30000)
     */
    function register(id, fetchFn, intervalMs = 30000) {
        unregister(id);
        tasks[id] = { fetchFn, intervalMs, timer: null };
        if (!paused) startTask(id);
    }

    function unregister(id) {
        if (tasks[id]) {
            clearInterval(tasks[id].timer);
            delete tasks[id];
        }
    }

    function startTask(id) {
        const t = tasks[id];
        if (!t) return;
        t.timer = setInterval(() => {
            if (!document.hidden) t.fetchFn();
        }, t.intervalMs);
    }

    function stopAll() {
        Object.keys(tasks).forEach(id => clearInterval(tasks[id].timer));
    }

    function resumeAll() {
        Object.keys(tasks).forEach(id => startTask(id));
    }

    // Pause polling when tab is hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            paused = true;
            stopAll();
        } else {
            paused = false;
            resumeAll();
            // Also trigger all tasks immediately on tab focus
            Object.keys(tasks).forEach(id => tasks[id].fetchFn());
        }
    });

    CHED.Poller = { register, unregister };
})();
