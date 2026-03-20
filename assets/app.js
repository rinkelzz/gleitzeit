'use strict';

// ── Live duration counter ─────────────────────────────────
function formatHM(totalSeconds) {
    const s = Math.max(0, totalSeconds);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return `${h}:${String(m).padStart(2, '0')} h`;
}

function startLiveTimer() {
    // Header live duration (check-in card)
    const liveEl = document.getElementById('live-duration');
    if (liveEl) {
        const since = parseInt(liveEl.dataset.since, 10);
        const tick = () => {
            liveEl.textContent = formatHM(Math.floor(Date.now() / 1000) - since);
        };
        tick();
        setInterval(tick, 30_000);
    }

    // Today's worked counter
    const todayEl = document.getElementById('today-worked');
    if (todayEl && todayEl.dataset.open === '1') {
        const since   = parseInt(todayEl.dataset.since, 10);
        const base    = parseInt(todayEl.dataset.seconds, 10);
        const started = Math.floor(Date.now() / 1000);
        const tick = () => {
            const elapsed = Math.floor(Date.now() / 1000) - started;
            todayEl.textContent = formatHM(base + elapsed);
        };
        tick();
        setInterval(tick, 30_000);
    }
}

// ── File drop label ───────────────────────────────────────
function initFileDrop() {
    const input = document.getElementById('csvfile');
    const label = document.getElementById('fileName');
    if (!input || !label) return;
    input.addEventListener('change', () => {
        label.textContent = input.files[0]?.name ?? 'Maximal 2 MB';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    startLiveTimer();
    initFileDrop();
});
