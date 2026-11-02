<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
// Shared success toast: top banner, hugs text, no backdrop, auto-dismiss.
// Set $toastMsg before including, or it falls back to $success / $flashMsg / $successMsg.
$toastMsg = $toastMsg ?? '';
if ($toastMsg === '') $toastMsg = $success ?? '';
if ($toastMsg === '') $toastMsg = $flashMsg ?? '';
if ($toastMsg === '') $toastMsg = $successMsg ?? '';
?>
<style>
.success-toast-modal { display: none; position: fixed; inset: 0; z-index: 3000; background: none;
    padding: 0; align-items: flex-start; justify-content: center; pointer-events: none; }
.success-toast-modal.active { display: flex; animation: successToastIn .2s ease; }
@keyframes successToastIn { from { opacity: 0; } to { opacity: 1; } }
.success-toast-modal .success-toast { pointer-events: auto; box-sizing: border-box; width: auto;
    max-width: calc(100vw - 2rem); margin-top: 16px; display: flex; align-items: center; gap: .55rem;
    padding: .7rem .75rem .7rem .9rem; border-radius: 10px; background: #f0fdf4;
    border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; color: #14532d;
    font-size: .88rem; font-weight: 600; line-height: 1.4;
    box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
    animation: successToastDrop .26s cubic-bezier(.2, .9, .3, 1); }
@keyframes successToastDrop { from { transform: translateY(-18px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.success-toast > svg { flex-shrink: 0; color: #16a34a; }
.success-toast .success-toast-x { margin-left: .35rem; flex-shrink: 0; border: 0; background: transparent;
    color: #4d7c5f; cursor: pointer; padding: .2rem; border-radius: 6px; display: flex; }
.success-toast .success-toast-x:hover { background: rgba(22, 163, 74, .12); color: #166534; }
</style>

<div id="successModal" class="success-toast-modal" aria-hidden="true">
    <div class="success-toast" role="status" aria-live="polite">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span id="successMsg">Done.</span>
        </button>
    </div>
</div>
<script nonce="<?= csp_nonce() ?>">
var __successToastTimer = null;
function __closeSuccessToast() {
    var m = document.getElementById('successModal');
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    clearTimeout(__successToastTimer);
}
<?php if ($toastMsg): ?>
document.getElementById('successMsg').textContent = <?= json_encode($toastMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
document.getElementById('successModal').classList.add('active');
document.getElementById('successModal').setAttribute('aria-hidden', 'false');
__successToastTimer = setTimeout(__closeSuccessToast, 2600);
<?php endif; ?>
</script>
