<?php
// Direct-access guard: include-only file. Refuse a direct web request.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
/* ═══════════════════════════════════════════════════════════════
   SECTION 1 · PHP BOOTSTRAP
   Guard + close the layout wrappers opened in header.php.
   ═══════════════════════════════════════════════════════════════ */
if (!defined('CHED_APP')) exit;
?>
</div> <!-- .content-area -->
</main> <!-- .main-content -->


<!-- ═══════════════════════════════════════════════════════════════
     SECTION 2 · GLOBAL SCRIPTS
     ═══════════════════════════════════════════════════════════════ -->
<script src="includes/sidebar.js?v=20261013" defer></script>
<script src="includes/csp-events.js" defer></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>



<!-- ── 3.1  MODAL SHELL ─────────────────────────────────────── -->
<div id="universalDocumentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="docViewerTitle">
  <div class="modal-content doc-viewer-modal-content">

    <!-- ── 3.2  HEADER: title + toolbar + close ─────────────── -->
    <div class="modal-header doc-viewer-header">
      <div class="doc-viewer-title-group">
        <span class="doc-viewer-icon" aria-hidden="true">
          <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
        </span>
        <div class="doc-viewer-titles">
          <h3 id="docViewerTitle">Document Preview</h3>
          <span id="docViewerSubtitle" class="doc-viewer-subtitle">Preparing…</span>
        </div>
      </div>

      <!-- ── 3.3  TOOLBARS ──────────────────────────────────── -->
      <div class="doc-viewer-toolbar" id="docViewerToolbar" role="toolbar" aria-label="Document view controls">

        <!-- 3.3.a  PDF toolbar -->
        <div id="pdfToolbar" class="pdf-toolbar tool-group">
          <button id="pdfPrev" class="btn btn-sm btn-viewer" title="Previous Page" aria-label="Previous page">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
          </button>
          <span class="pdf-page-info" aria-live="polite"><span id="pdfPageNum">1</span><span class="page-sep">/</span><span id="pdfPageCount">?</span></span>
          <button id="pdfNext" class="btn btn-sm btn-viewer" title="Next Page" aria-label="Next page">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
          </button>
          <span class="toolbar-divider" aria-hidden="true"></span>
          <button id="pdfZoomOut" class="btn btn-sm btn-viewer" title="Zoom Out" aria-label="Zoom out">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
          </button>
          <span id="pdfZoomScale" class="zoom-label" aria-live="polite">100%</span>
          <button id="pdfZoomIn" class="btn btn-sm btn-viewer" title="Zoom In" aria-label="Zoom in">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
          <button id="pdfFitWidth" class="btn btn-sm btn-viewer" title="Fit Width" aria-label="Fit to width">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>
          </button>
        </div>

        <!-- 3.3.b  Image toolbar -->
        <div id="imageToolbar" class="pdf-toolbar tool-group">
          <button id="imgZoomOut" class="btn btn-sm btn-viewer" title="Zoom Out" aria-label="Zoom out">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
          </button>
          <span id="imgZoomScale" class="zoom-label" aria-live="polite">100%</span>
          <button id="imgZoomIn" class="btn btn-sm btn-viewer" title="Zoom In" aria-label="Zoom in">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
          <span class="toolbar-divider" aria-hidden="true"></span>
          <button id="imgFitWidth" class="btn btn-sm btn-viewer" title="Fit Width" aria-label="Fit to width">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>
          </button>
          <button id="imgReset" class="btn btn-sm btn-viewer" title="Reset Zoom" aria-label="Reset zoom and pan">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          </button>
        </div>

        <!-- 3.3.c  Office toolbar -->
        <div id="officeToolbar" class="pdf-toolbar tool-group">
          <button id="officeZoomOut" class="btn btn-sm btn-viewer" title="Zoom Out" aria-label="Zoom out">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
          </button>
          <span id="officeZoomScale" class="zoom-label" aria-live="polite">100%</span>
          <button id="officeZoomIn" class="btn btn-sm btn-viewer" title="Zoom In" aria-label="Zoom in">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
          <span class="toolbar-divider" aria-hidden="true"></span>
          <button id="officeFitWidth" class="btn btn-sm btn-viewer" title="Fit Width" aria-label="Fit to width">
            <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>
          </button>
        </div>

        <!-- 3.3.d  Common: fullscreen + download -->
        <button id="fullscreenBtn" class="btn btn-sm btn-viewer btn-standalone" title="Full Screen" aria-label="Toggle full screen" aria-pressed="false">
          <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
        </button>
        <a id="docDownloadBtn" href="#" class="btn btn-sm btn-primary btn-download" download>
          <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          <span class="btn-label">Download</span>
        </a>
      </div>

      <button class="close-btn doc-viewer-close" data-modal="universalDocumentModal" title="Close" aria-label="Close preview">&times;</button>
    </div>

    <!-- ── 3.4  BODY: one stage visible at a time ────────────── -->
    <div class="modal-body doc-viewer-body" id="docViewerBody">

      <!-- 3.4.a  Loading -->
      <div id="docLoading" class="doc-loading">
        <div class="doc-loading-spinner"></div>
        <div class="doc-loading-text">Opening…</div>
      </div>

      <!-- 3.4.b  PDF canvas -->
      <div id="pdfContainer" class="pdf-container">
        <canvas id="pdfCanvas"></canvas>
      </div>

      <!-- 3.4.c  Office iframe -->
      <div id="officeContainer" class="office-container">
        <iframe id="docxHtmlIframe" class="office-iframe" title="Document Preview"></iframe>
      </div>

      <!-- 3.4.d  Error card -->
      <div id="docErrorMsg" class="doc-error-msg">
        <div class="doc-state-icon doc-state-icon--error">
          <svg class="dv-ico dv-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
        </div>
        <h4 id="docErrorTitle">Unable to Load Document</h4>
        <p id="docErrorText">The file could not be loaded. It may be missing or inaccessible.</p>
        <a id="docErrorDownload" href="#" class="doc-cta">
          <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Download Instead
        </a>
      </div>

      <!-- 3.4.e  Unsupported card -->
      <div id="unsupportedMsg" class="doc-unsupported-msg">
        <div class="doc-state-icon doc-state-icon--neutral">
          <svg class="dv-ico dv-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
        </div>
        <h4>Preview Not Available</h4>
        <p>This file type can't be previewed inline. Download it to view the contents.</p>
        <a id="unsupportedDownload" href="#" class="doc-cta" target="_blank">
          <svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Download File
        </a>
      </div>

      <!-- 3.4.f  Image viewer -->
      <div id="imageContainer" class="image-container">
        <img id="docViewerImg" src="" alt="Document image">
      </div>

    </div>
  </div>
</div>

<style>
/* ── 4.1  THEME TOKENS (change colors here) ──────────────────── */
#universalDocumentModal{
  --dv-bg:#ffffff; --dv-surface:#f8fafc; --dv-surface-2:#eef2f7;
  --dv-border:#e3e8ef; --dv-text:#0f172a; --dv-muted:#64748b;
  --dv-primary:#2563eb; --dv-danger:#ef4444;
  --dv-radius:16px; --dv-canvas:#15171d;
}

/* Shared SVG icon sizing */
.dv-ico{width:14px;height:14px;display:block}
.dv-ico-lg{width:26px;height:26px}

/* ── 4.2  BACKDROP & SHELL ──────────────────────────────────── */
.modal{display:none;position:fixed;z-index:10000;left:0;top:0;width:100%;height:100%;overflow:hidden;
  background-color:rgba(8,11,18,.62);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
.modal.show{display:flex;align-items:center;justify-content:center}
#universalDocumentModal.show{animation:dvFade .18s ease-out both}
@keyframes dvFade{from{opacity:0}to{opacity:1}}

.doc-viewer-modal-content{
  max-width:95%!important;width:1200px!important;height:92vh!important;margin:4vh auto!important;
  display:flex;flex-direction:column;border-radius:var(--dv-radius)!important;overflow:hidden;
  background:var(--dv-bg);border:1px solid rgba(255,255,255,.08);
  box-shadow:0 32px 80px -12px rgba(0,0,0,.55),0 0 0 1px rgba(0,0,0,.05);
  animation:dvRise .26s cubic-bezier(.22,1,.36,1) both}
@keyframes dvRise{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
.modal.fullscreen .doc-viewer-modal-content{
  max-width:100%!important;width:100%!important;height:100%!important;
  margin:0!important;border-radius:0!important;border:0}

/* ── 4.3  HEADER & TITLE ────────────────────────────────────── */
.doc-viewer-header{
  padding:.65rem .85rem!important;display:flex!important;align-items:center;gap:.75rem;
  background:linear-gradient(180deg,#fff 0%,var(--dv-surface) 100%)!important;
  border-bottom:1px solid var(--dv-border)!important;flex-shrink:0;min-height:58px}
.doc-viewer-title-group{display:flex;align-items:center;gap:.65rem;min-width:0;flex-shrink:1}
.doc-viewer-icon{
  width:34px;height:34px;flex-shrink:0;display:grid;place-items:center;border-radius:10px;
  background:linear-gradient(135deg,#dbeafe,#e0e7ff);color:var(--dv-primary);font-size:.95rem}
.doc-viewer-icon .dv-ico{width:16px;height:16px}
.doc-viewer-titles{display:flex;flex-direction:column;min-width:0;line-height:1.25}
.doc-viewer-header h3{
  font-size:.9rem!important;font-weight:650!important;color:var(--dv-text)!important;margin:0!important;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px}
.doc-viewer-subtitle{
  font-size:.68rem;font-weight:500;letter-spacing:.05em;text-transform:uppercase;color:var(--dv-muted);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px}

/* ── 4.4  TOOLBAR & BUTTONS ─────────────────────────────────── */
.doc-viewer-toolbar{display:none;align-items:center;gap:8px;margin-left:auto;flex-wrap:wrap;justify-content:flex-end}
.doc-viewer-toolbar.active{display:flex}
.tool-group{display:none;align-items:center;gap:2px;padding:3px;border-radius:11px;
  background:var(--dv-surface-2);border:1px solid var(--dv-border)}
.pdf-toolbar.active{display:inline-flex}
.toolbar-divider{width:1px;height:18px;background:#d6dee9;margin:0 3px;flex-shrink:0}

.btn-viewer{
  border:1px solid transparent!important;background:transparent!important;color:var(--dv-text)!important;
  border-radius:8px!important;width:30px;height:30px;padding:0!important;font-size:.76rem;
  display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
  transition:background .14s ease,color .14s ease,box-shadow .14s ease,transform .08s ease}
.btn-viewer:hover{background:#fff!important;color:var(--dv-primary)!important;box-shadow:0 1px 3px rgba(15,23,42,.14)}
.btn-viewer:active{transform:scale(.93)}
.btn-viewer:focus-visible{outline:2px solid var(--dv-primary);outline-offset:2px}
.btn-standalone{width:34px!important;height:34px!important;background:#fff!important;
  border:1px solid var(--dv-border)!important;border-radius:10px!important;color:var(--dv-muted)!important}
.btn-standalone:hover{color:var(--dv-primary)!important;border-color:#bfdbfe!important;
  background:#f0f7ff!important;box-shadow:0 2px 8px rgba(37,99,235,.12)}

.pdf-page-info{display:inline-flex;align-items:center;gap:3px;font-size:.74rem;font-weight:650;
  font-variant-numeric:tabular-nums;color:var(--dv-text);background:#fff;border-radius:7px;padding:4px 9px;
  min-width:62px;justify-content:center;box-shadow:inset 0 0 0 1px rgba(15,23,42,.06)}
.page-sep{color:#cbd5e1;font-weight:400}
.zoom-label{font-size:.72rem;font-weight:700;font-variant-numeric:tabular-nums;color:var(--dv-muted);
  min-width:42px;text-align:center;letter-spacing:-.01em}

.btn-download{display:inline-flex;align-items:center;gap:.4rem;
  background:linear-gradient(135deg,var(--dv-primary),#4f46e5)!important;border:none!important;
  color:#fff!important;border-radius:10px!important;font-size:.78rem!important;font-weight:600!important;
  padding:.42rem .8rem!important;line-height:1.1;text-decoration:none;
  box-shadow:0 1px 2px rgba(37,99,235,.35);
  transition:filter .14s ease,box-shadow .14s ease,transform .08s ease}
.btn-download:hover{filter:brightness(1.08);box-shadow:0 4px 14px rgba(37,99,235,.38)}
.btn-download:active{transform:scale(.97)}

.doc-viewer-close{flex-shrink:0;margin-left:2px;font-size:1.5rem!important;line-height:1;
  width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:10px;
  cursor:pointer;color:var(--dv-muted);background:transparent;border:1px solid transparent;
  transition:background .14s ease,color .14s ease,border-color .14s ease}
.doc-viewer-close:hover{background:#fef2f2;color:var(--dv-danger);border-color:#fecaca}
.doc-viewer-close:focus-visible{outline:2px solid var(--dv-primary);outline-offset:2px}

/* ── 4.5  BODY & SCROLLBARS ─────────────────────────────────── */
.doc-viewer-body{flex:1!important;overflow:auto;padding:0!important;position:relative;
  display:flex;justify-content:center;align-items:flex-start;
  background:radial-gradient(1200px 520px at 50% -12%,#2b2f3a 0%,transparent 62%),var(--dv-canvas)}

.doc-viewer-body::-webkit-scrollbar,.pdf-container::-webkit-scrollbar,
.office-container::-webkit-scrollbar,.image-container::-webkit-scrollbar{width:12px;height:12px}
.doc-viewer-body::-webkit-scrollbar-track,.pdf-container::-webkit-scrollbar-track,
.office-container::-webkit-scrollbar-track,.image-container::-webkit-scrollbar-track{background:transparent}
.doc-viewer-body::-webkit-scrollbar-thumb,.pdf-container::-webkit-scrollbar-thumb,
.office-container::-webkit-scrollbar-thumb,.image-container::-webkit-scrollbar-thumb{
  background:rgba(255,255,255,.16);border:3px solid transparent;background-clip:content-box;border-radius:999px}
.doc-viewer-body::-webkit-scrollbar-thumb:hover,.pdf-container::-webkit-scrollbar-thumb:hover,
.office-container::-webkit-scrollbar-thumb:hover,.image-container::-webkit-scrollbar-thumb:hover{
  background:rgba(255,255,255,.3);background-clip:content-box}

/* ── 4.6  LOADING SPINNER ───────────────────────────────────── */
.doc-loading{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#fff;
  text-align:center;z-index:5;pointer-events:none}
.doc-loading-spinner{width:24px;height:24px;margin:0 auto 8px;
  border:2px solid rgba(255,255,255,.12);border-top-color:#60a5fa;border-radius:50%;
  animation:docSpin .5s linear infinite;box-shadow:0 0 12px rgba(96,165,250,.15)}
@keyframes docSpin{to{transform:rotate(360deg)}}
.doc-loading-text{font-size:.8rem;font-weight:500;letter-spacing:.02em;color:rgba(255,255,255,.7)}

/* ── 4.7  PDF / OFFICE / IMAGE STAGES ───────────────────────── */
.pdf-container{display:none;width:100%;height:100%;overflow:auto;text-align:center}
.pdf-container canvas{display:block;margin:24px auto;border-radius:2px;
  box-shadow:0 10px 40px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04)}

.office-container{display:none;width:100%;height:100%;overflow:auto;position:relative;
  text-align:center;background:#fff}
.office-iframe{width:100%;height:100%;border:none;transform-origin:0 0;
  transition:transform .1s ease;background:#fff}

.image-container{display:none;position:relative;width:100%;height:100%;overflow:auto;text-align:center}
#docViewerImg{max-width:none;cursor:grab;user-select:none;-webkit-user-select:none;
  transform-origin:0 0;transition:transform .1s ease;border-radius:3px;
  box-shadow:0 12px 44px rgba(0,0,0,.6)}
#docViewerImg:active{cursor:grabbing}

/* ── 4.8  ERROR / UNSUPPORTED CARDS ─────────────────────────── */
.doc-error-msg,.doc-unsupported-msg{
  display:none;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
  width:max-content;max-width:min(92%,430px);padding:2.4rem 2.6rem;text-align:center;color:#fff;
  border-radius:18px;background:rgba(26,29,37,.92);border:1px solid rgba(255,255,255,.09);
  box-shadow:0 28px 70px rgba(0,0,0,.55);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.doc-state-icon{width:58px;height:58px;margin:0 auto 16px;border-radius:50%;
  display:grid;place-items:center}
.doc-state-icon--error{background:rgba(239,68,68,.16);color:#f87171;box-shadow:0 0 0 1px rgba(239,68,68,.22)}
.doc-state-icon--neutral{background:rgba(148,163,184,.16);color:#cbd5e1;box-shadow:0 0 0 1px rgba(148,163,184,.22)}
.doc-error-msg h4,.doc-unsupported-msg h4{color:#fff;font-size:1.02rem;font-weight:650;margin:0 0 .5rem}
.doc-error-msg p,.doc-unsupported-msg p{color:rgba(255,255,255,.6);font-size:.84rem;line-height:1.55;margin:0 0 1.4rem}
.doc-cta{display:inline-flex;align-items:center;gap:.5rem;padding:.62rem 1.15rem;border-radius:10px;
  font-size:.82rem;font-weight:600;color:#fff!important;text-decoration:none;
  background:linear-gradient(135deg,var(--dv-primary),#4f46e5)!important;border:none!important;
  box-shadow:0 4px 16px rgba(37,99,235,.35);transition:filter .14s ease,transform .08s ease}
.doc-cta:hover{filter:brightness(1.09)}
.doc-cta:active{transform:scale(.97)}

/* ── 4.9  RESPONSIVE & REDUCED MOTION ───────────────────────── */
@media (max-width:1024px){
  .doc-viewer-header h3{max-width:180px}
  .doc-viewer-subtitle{max-width:180px}
}
@media (max-width:768px){
  .doc-viewer-modal-content{width:100%!important;max-width:100%!important;
    height:100vh!important;height:100dvh!important;margin:0!important;border-radius:0!important}
  .doc-viewer-header{flex-wrap:wrap;gap:.5rem;padding:.6rem .65rem!important;min-height:0}
  .doc-viewer-title-group{flex:1 1 100%;order:1}
  .doc-viewer-header h3{max-width:none;font-size:.85rem!important}
  .doc-viewer-subtitle{max-width:none}
  .doc-viewer-close{order:2;position:absolute;top:.6rem;right:.6rem}
  .doc-viewer-toolbar{order:3;flex:1 1 100%;margin-left:0;justify-content:flex-start;
    gap:6px;overflow-x:auto;padding-bottom:2px;scrollbar-width:none}
  .doc-viewer-toolbar::-webkit-scrollbar{display:none}
  .btn-viewer{width:34px;height:34px}
  .btn-standalone{width:36px!important;height:36px!important}
  .btn-download{padding:.5rem .7rem!important}
  .btn-download .btn-label{display:none}
  .pdf-page-info{font-size:.7rem;min-width:56px;padding:4px 7px}
  .zoom-label{min-width:36px;font-size:.68rem}
  .doc-error-msg,.doc-unsupported-msg{padding:1.8rem 1.5rem}
}
@media (prefers-reduced-motion:reduce){
  #universalDocumentModal.show,.doc-viewer-modal-content{animation:none!important}
  .doc-loading-spinner{animation-duration:1.6s}
  #docViewerImg,.office-iframe{transition:none!important}
}
</style>


<!-- ═══════════════════════════════════════════════════════════════
     SECTION 5 · DOCUMENT VIEWER — SCRIPT
     ═══════════════════════════════════════════════════════════════ -->
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function () {

  /* ── 5.1  DOM REFERENCES ───────────────────────────────────── */
  // Shell & title
  const modal      = document.getElementById('universalDocumentModal');
  const titleEl    = document.getElementById('docViewerTitle');
  const subtitleEl = document.getElementById('docViewerSubtitle');

  // Stage containers
  const loadingEl       = document.getElementById('docLoading');
  const pdfContainer    = document.getElementById('pdfContainer');
  const pdfCanvas       = document.getElementById('pdfCanvas');
  const ctx             = pdfCanvas ? pdfCanvas.getContext('2d') : null;
  const officeContainer = document.getElementById('officeContainer');
  const officeIframe    = document.getElementById('docxHtmlIframe');
  const imageContainer  = document.getElementById('imageContainer');
  const docImg          = document.getElementById('docViewerImg');

  // State cards
  const errorMsg       = document.getElementById('docErrorMsg');
  const unsupportedMsg = document.getElementById('unsupportedMsg');

  // Chrome
  const toolbar       = document.getElementById('docViewerToolbar');
  const downloadBtn   = document.getElementById('docDownloadBtn');
  const fullscreenBtn = document.getElementById('fullscreenBtn');

  // Toolbar groups + buttons
  const pdfToolbar    = document.getElementById('pdfToolbar');
  const imageToolbar  = document.getElementById('imageToolbar');
  const officeToolbar = document.getElementById('officeToolbar');
  const pdfZoomIn     = document.getElementById('pdfZoomIn');
  const pdfZoomOut    = document.getElementById('pdfZoomOut');
  const pdfFitWidth   = document.getElementById('pdfFitWidth');
  const pdfPrev       = document.getElementById('pdfPrev');
  const pdfNext       = document.getElementById('pdfNext');
  const pageNumSpan   = document.getElementById('pdfPageNum');
  const pageCountSpan = document.getElementById('pdfPageCount');
  const pdfZoomScaleSpan    = document.getElementById('pdfZoomScale');
  const imgZoomIn     = document.getElementById('imgZoomIn');
  const imgZoomOut    = document.getElementById('imgZoomOut');
  const imgFitWidth   = document.getElementById('imgFitWidth');
  const imgReset      = document.getElementById('imgReset');
  const imgZoomScaleSpan    = document.getElementById('imgZoomScale');
  const officeZoomIn  = document.getElementById('officeZoomIn');
  const officeZoomOut = document.getElementById('officeZoomOut');
  const officeFitWidth= document.getElementById('officeFitWidth');
  const officeZoomScaleSpan = document.getElementById('officeZoomScale');

  /* ── 5.2  STATE VARIABLES ──────────────────────────────────── */
  let pdfDoc       = null;
  let pageNum      = 1;
  let currentScale = 1.2;
  let imgScale     = 1;
  let imgX = 0, imgY = 0;
  let isDragging   = false;
  let dragStart    = { x: 0, y: 0 };
  let officeScale  = 1;
  let currentType  = null;   // 'pdf' | 'image' | 'office'
  let lastFocused  = null;
  let currentImageLoadHandler  = null;
  let currentImageErrorHandler = null;
  let currentLoadingTask = null; // PDF loading task (for cancellation)
  let currentDocId       = null; // guards against stale loads

  /* ── 5.3  FOCUS TRAP & CLOSE PATH ──────────────────────────── */
  const FOCUSABLE = 'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

  function trapFocus(e){
    const items = Array.from(modal.querySelectorAll(FOCUSABLE)).filter(el => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
      e.preventDefault(); last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault(); first.focus();
    }
  }
  function openFocus(){
    lastFocused = document.activeElement;
    const closeBtn = modal.querySelector('.doc-viewer-close') || modal.querySelector(FOCUSABLE);
    if (closeBtn) closeBtn.focus();
  }
  function closeViewer(){
    modal.classList.remove('show');
    resetViewer();
    if (lastFocused && document.contains(lastFocused)) lastFocused.focus();
    lastFocused = null;
  }

  /* ── 5.4  HELPERS (reset / error / unsupported) ────────────── */
  function resetViewer(){
    currentDocId = null;
    if (currentLoadingTask) { try { currentLoadingTask.destroy(); } catch(e){} currentLoadingTask = null; }

    loadingEl.style.display = 'block';
    pdfContainer.style.display = 'none';
    if (pdfCanvas) { const p = pdfCanvas.getContext('2d'); if (p) p.clearRect(0,0,pdfCanvas.width,pdfCanvas.height); }
    if (officeContainer) { officeContainer.style.display = 'none'; officeIframe.src = ''; }
    errorMsg.style.display = 'none';
    unsupportedMsg.style.display = 'none';
    imageContainer.style.display = 'none';

    if (docImg) {
      if (currentImageLoadHandler)  docImg.removeEventListener('load',  currentImageLoadHandler);
      if (currentImageErrorHandler) docImg.removeEventListener('error', currentImageErrorHandler);
      docImg.removeAttribute('src');
      docImg.removeAttribute('srcset');
    }

    toolbar.classList.remove('active');
    pdfToolbar.classList.remove('active');
    imageToolbar.classList.remove('active');
    officeToolbar.classList.remove('active');

    pdfDoc = null; pageNum = 1;
    currentScale = 1.2; imgScale = 1; imgX = imgY = 0; officeScale = 1;
    currentType = null;

    if (pdfZoomScaleSpan)    pdfZoomScaleSpan.textContent    = '100%';
    if (imgZoomScaleSpan)    imgZoomScaleSpan.textContent    = '100%';
    if (officeZoomScaleSpan) officeZoomScaleSpan.textContent = '100%';
    if (docImg)       docImg.style.transform       = 'scale(1) translate(0px, 0px)';
    if (officeIframe) officeIframe.style.transform = 'scale(1)';

    // Remove any stray image nodes we appended in fallback mode
    document.querySelectorAll('#docViewerBody img:not(#docViewerImg)').forEach(n => n.remove());
  }

  function showError(title, text, downloadUrl){
    loadingEl.style.display = 'none';
    errorMsg.style.display = 'block';
    document.getElementById('docErrorTitle').textContent = title;
    document.getElementById('docErrorText').textContent  = text;
    const dl = document.getElementById('docErrorDownload');
    dl.href = downloadUrl || '#';
    dl.style.display = downloadUrl ? 'inline-flex' : 'none';
  }
  function showUnsupported(downloadUrl){
    loadingEl.style.display = 'none';
    unsupportedMsg.style.display = 'block';
    document.getElementById('unsupportedDownload').href = downloadUrl || '#';
  }

  /* ── 5.5  PDF RENDER + LOADER ──────────────────────────────── */
  // [SELF-HOST] For fully offline use: drop pdf.min.js and pdf.worker.min.js
  //             into includes/ and update the two paths below.
  const PDFJS_LIB_URL    = 'offline/pdf.min.js';
  const PDFJS_WORKER_URL = 'offline/pdf.worker.min.js';

  function ensurePdfLib(cb){
    if (typeof pdfjsLib !== 'undefined') return cb();
    const s = document.createElement('script');
    s.src = PDFJS_LIB_URL;
    s.onload  = cb;
    s.onerror = () => showError('PDF Library Not Loaded', 'The PDF viewer library could not be loaded.');
    document.head.appendChild(s);
  }

  function renderPDFPage(num, expectedDocId){
    if (!pdfDoc || !ctx) return;
    if (expectedDocId && currentDocId !== expectedDocId) return;

    pdfDoc.getPage(num).then(page => {
      if (expectedDocId && currentDocId !== expectedDocId) return;
      const viewport = page.getViewport({ scale: currentScale });
      pdfCanvas.height = viewport.height;
      pdfCanvas.width  = viewport.width;
      page.render({ canvasContext: ctx, viewport: viewport }).promise.then(() => {
        if (expectedDocId && currentDocId !== expectedDocId) return;
        loadingEl.style.display = 'none';
        pdfContainer.style.display = 'block';
        if (pageNumSpan) pageNumSpan.textContent = num;
        if (pdfZoomScaleSpan) pdfZoomScaleSpan.textContent = Math.round(currentScale * 100) + '%';
      });
    }).catch(() => {
      if (expectedDocId && currentDocId !== expectedDocId) return;
      showError('Page Render Error', 'Could not render page.');
    });
  }

  function loadPDF(pdfUrl, expectedDocId){
    ensurePdfLib(function(){
      if (expectedDocId !== currentDocId) return;
      if (currentLoadingTask) { try { currentLoadingTask.destroy(); } catch(e){} }
      pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_URL;
      loadingEl.style.display = 'block';
      currentLoadingTask = pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true });
      currentLoadingTask.promise
        .then(pdf => {
          if (currentDocId !== expectedDocId) { try { pdf.destroy(); } catch(e){} return; }
          pdfDoc = pdf;
          currentLoadingTask = null;
          if (pageCountSpan) pageCountSpan.textContent = pdf.numPages;
          pageNum = 1;
          renderPDFPage(pageNum, expectedDocId);
        })
        .catch(err => {
          currentLoadingTask = null;
          if (expectedDocId !== currentDocId) return;
          if (err && err.name !== 'RenderingCancelledException' && err.name !== 'GlobalWorkerSpecificationError') {
            showError('PDF Load Failed', 'The PDF file could not be loaded.');
          }
        });
    });
  }

  /* ── 5.6  IMAGE ZOOM / PAN ─────────────────────────────────── */
  function applyImageTransform(){
    if (!docImg) return;
    docImg.style.transform = 'scale(' + imgScale + ') translate(' + imgX + 'px, ' + imgY + 'px)';
    if (imgZoomScaleSpan) imgZoomScaleSpan.textContent = Math.round(imgScale * 100) + '%';
  }
  function resetImage(){ imgScale = 1; imgX = imgY = 0; applyImageTransform(); }
  function fitImageToWidth(){
    if (!docImg || !docImg.complete || !docImg.naturalWidth) return;
    const cw = imageContainer.clientWidth - 20;
    imgScale = cw / docImg.naturalWidth;
    imgX = imgY = 0;
    applyImageTransform();
  }

  function setupImageEvents(){
    if (!docImg) return;
    docImg.removeEventListener('wheel', onImageWheel);
    docImg.removeEventListener('mousedown', onImageMouseDown);
    window.removeEventListener('mousemove', onImageMouseMove);
    window.removeEventListener('mouseup', onImageMouseUp);
    docImg.addEventListener('wheel', onImageWheel, { passive: false });
    docImg.addEventListener('mousedown', onImageMouseDown);
    window.addEventListener('mousemove', onImageMouseMove);
    window.addEventListener('mouseup', onImageMouseUp);
  }
  function onImageWheel(e){
    e.preventDefault();
    const d = e.deltaY > 0 ? -0.1 : 0.1;
    imgScale = Math.min(5, Math.max(0.5, imgScale + d));
    applyImageTransform();
  }
  function onImageMouseDown(e){
    isDragging = true;
    dragStart.x = e.clientX - imgX;
    dragStart.y = e.clientY - imgY;
    docImg.style.cursor = 'grabbing';
  }
  function onImageMouseMove(e){
    if (!isDragging) return;
    imgX = e.clientX - dragStart.x;
    imgY = e.clientY - dragStart.y;
    applyImageTransform();
  }
  function onImageMouseUp(){
    isDragging = false;
    if (docImg) docImg.style.cursor = 'grab';
  }

  /* ── 5.7  OFFICE IFRAME ZOOM ───────────────────────────────── */
  function applyOfficeTransform(){
    if (!officeIframe) return;
    officeIframe.style.transform = 'scale(' + officeScale + ')';
    officeIframe.style.width  = (100 / officeScale) + '%';
    officeIframe.style.height = (100 / officeScale) + '%';
    if (officeZoomScaleSpan) officeZoomScaleSpan.textContent = Math.round(officeScale * 100) + '%';
  }
  function resetOffice(){ officeScale = 1; applyOfficeTransform(); }
  function fitOfficeToWidth(){ officeScale = 1; applyOfficeTransform(); }

  /* ── 5.8  TYPE DETECTION & DISPATCHER ──────────────────────── */
  function detectAndOpen(docId, title, source, downloadUrl, extension, previewUrl){
    const expectedDocId = docId;
    const knownExts = ['pdf','jpg','jpeg','png','gif','webp','doc','docx','xls','xlsx','ppt','pptx'];
    if (extension && knownExts.indexOf(extension) !== -1) {
      handleByExtension(extension, previewUrl, downloadUrl, expectedDocId);
      return;
    }
    fetch(previewUrl, { method: 'HEAD' })
      .then(res => {
        const ct = res.headers.get('Content-Type') || '';
        if (currentDocId !== expectedDocId) return;
        if (ct.indexOf('application/pdf') !== -1)        handleByExtension('pdf',     previewUrl, downloadUrl, expectedDocId);
        else if (ct.indexOf('image/') !== -1)            handleByExtension('image',   previewUrl, downloadUrl, expectedDocId);
        else if (ct.indexOf('text/html') !== -1)         handleByExtension('html',    previewUrl, downloadUrl, expectedDocId);
        else showUnsupported(downloadUrl);
      })
      .catch(() => showUnsupported(downloadUrl));
  }

  function handleByExtension(type, previewUrl, downloadUrl, expectedDocId){
    if (currentDocId !== expectedDocId) return;
    currentType = type;

    const cb  = '_t=' + Date.now() + '_' + Math.random().toString(36).substring(2);
    const url = previewUrl + (previewUrl.indexOf('?') !== -1 ? '&' : '?') + cb;

    // ── PDF ────────────────────────────────────────────────────
    if (type === 'pdf') {
      if (currentDocId !== expectedDocId) return;
      toolbar.classList.add('active');
      pdfToolbar.classList.add('active');
      loadPDF(url, expectedDocId);
      return;
    }

    // ── PowerPoint (returns PDF via preview.php) ────────────────
    if (type === 'ppt' || type === 'pptx') {
      type = 'pdf';
      if (currentDocId !== expectedDocId) return;
      toolbar.classList.add('active');
      pdfToolbar.classList.add('active');
      loadPDF(url, expectedDocId);
      return;
    }

    // ── Image ──────────────────────────────────────────────────
    if (type === 'image' || ['jpg','jpeg','png','gif','webp'].indexOf(type) !== -1) {
      if (currentDocId !== expectedDocId) return;
      toolbar.classList.add('active');
      imageToolbar.classList.add('active');

      if (imageContainer && docImg) {
        imageContainer.style.display = 'block';
        setupImageEvents();

        docImg.removeAttribute('src');
        docImg.removeAttribute('srcset');
        if (currentImageLoadHandler)  docImg.removeEventListener('load',  currentImageLoadHandler);
        if (currentImageErrorHandler) docImg.removeEventListener('error', currentImageErrorHandler);

        currentImageLoadHandler = function(){
          if (currentDocId !== expectedDocId) return;
          if (this.complete && this.naturalWidth > 0) {
            loadingEl.style.display = 'none';
            resetImage();
          }
        };
        currentImageErrorHandler = function(){
          if (currentDocId !== expectedDocId) return;
          const src = this.src || '';
          if (src && src.indexOf('about:') !== 0 && src.indexOf('data:') !== 0 && src !== window.location.href) {
            if (this.complete === false || this.naturalWidth === 0) {
              showError('Image Load Failed', 'The image could not be loaded.', downloadUrl);
            }
          }
        };
        docImg.addEventListener('load',  currentImageLoadHandler);
        docImg.addEventListener('error', currentImageErrorHandler);

        if (currentDocId !== expectedDocId) return;
        docImg.src = url;
      } else {
        // Fallback node
        const img = document.createElement('img');
        img.style.cssText = 'max-width:100%;border-radius:3px;box-shadow:0 12px 44px rgba(0,0,0,.6);margin:24px auto;display:block;';
        img.onload  = () => { loadingEl.style.display = 'none'; };
        img.onerror = () => showError('Image Load Failed', 'The image could not be loaded.', downloadUrl);
        img.src = url;
        document.getElementById('docViewerBody').appendChild(img);
      }
      return;
    }

    // ── Office (doc/docx/xls/xlsx/html) ────────────────────────
    if (type === 'html' || ['doc','docx','xls','xlsx'].indexOf(type) !== -1) {
      if (currentDocId !== expectedDocId) return;
      toolbar.classList.add('active');
      officeToolbar.classList.add('active');
      if (currentDocId !== expectedDocId) return;

      if (officeContainer && officeIframe) {
        officeContainer.style.display = 'block';
        officeIframe.onload  = () => {
          if (currentDocId !== expectedDocId) return;
          loadingEl.style.display = 'none';
          resetOffice();
        };
        officeIframe.onerror = () => {
          if (currentDocId !== expectedDocId) return;
          showError('Preview Failed', 'Could not load document preview.', downloadUrl);
        };
        officeIframe.src = url;
      } else {
        showError('Preview Not Available', 'Your browser cannot display this document inline.', downloadUrl);
      }
      return;
    }

    // ── Fallback ───────────────────────────────────────────────
    showUnsupported(downloadUrl);
  }

  /* ── 5.9  PUBLIC ENTRY POINT ───────────────────────────────── */
  function openDocumentViewer(docId, title, source, extension){
    resetViewer();
    currentDocId = docId;
    titleEl.textContent = title || 'Document Preview';
    if (subtitleEl) {
      subtitleEl.textContent = extension ? (extension.toUpperCase() + ' document') : 'Document';
    }
    modal.classList.add('show');
    openFocus();

    const previewUrl  = 'preview.php?id=' + encodeURIComponent(docId) + '&source=' + encodeURIComponent(source);
    const downloadUrl = 'download_document.php?id=' + encodeURIComponent(docId) + '&source=' + encodeURIComponent(source);
    downloadBtn.href = downloadUrl;

    detectAndOpen(docId, title, source, downloadUrl, extension, previewUrl);
  }

  /* ── 5.10  TOOLBAR BUTTON BINDINGS ─────────────────────────── */
  // PDF
  if (pdfPrev)     pdfPrev.addEventListener('click',     () => { if (pdfDoc && pageNum > 1)                { pageNum--; renderPDFPage(pageNum); } });
  if (pdfNext)     pdfNext.addEventListener('click',     () => { if (pdfDoc && pageNum < pdfDoc.numPages)  { pageNum++; renderPDFPage(pageNum); } });
  if (pdfZoomIn)   pdfZoomIn.addEventListener('click',   () => { if (currentScale < 3.0) { currentScale += 0.2; renderPDFPage(pageNum); } });
  if (pdfZoomOut)  pdfZoomOut.addEventListener('click',  () => { if (currentScale > 0.4) { currentScale -= 0.2; renderPDFPage(pageNum); } });
  if (pdfFitWidth) pdfFitWidth.addEventListener('click', () => {
    if (!pdfDoc) return;
    pdfDoc.getPage(pageNum).then(page => {
      const vp = page.getViewport({ scale: 1 });
      const cw = document.getElementById('docViewerBody').clientWidth - 40;
      currentScale = cw / vp.width;
      renderPDFPage(pageNum);
    });
  });

  // Image
  if (imgZoomIn)   imgZoomIn.addEventListener('click',   () => { imgScale = Math.min(5, imgScale + 0.2); applyImageTransform(); });
  if (imgZoomOut)  imgZoomOut.addEventListener('click',  () => { imgScale = Math.max(0.5, imgScale - 0.2); applyImageTransform(); });
  if (imgFitWidth) imgFitWidth.addEventListener('click', fitImageToWidth);
  if (imgReset)    imgReset.addEventListener('click',    resetImage);

  // Office
  if (officeZoomIn)   officeZoomIn.addEventListener('click',   () => { officeScale = Math.min(3, officeScale + 0.2); applyOfficeTransform(); });
  if (officeZoomOut)  officeZoomOut.addEventListener('click',  () => { officeScale = Math.max(0.5, officeScale - 0.2); applyOfficeTransform(); });
  if (officeFitWidth) officeFitWidth.addEventListener('click', fitOfficeToWidth);

  // Fullscreen
  if (fullscreenBtn) fullscreenBtn.addEventListener('click', () => {
    modal.classList.toggle('fullscreen');
    const on = modal.classList.contains('fullscreen');
    fullscreenBtn.innerHTML = on
      ? '<svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/></svg>'
      : '<svg class="dv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>';
    fullscreenBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    fullscreenBtn.title = on ? 'Exit Full Screen' : 'Full Screen';
    if (currentType === 'pdf')    pdfFitWidth   && pdfFitWidth.click();
    else if (currentType === 'image')  fitImageToWidth();
    else if (currentType === 'office') fitOfficeToWidth();
  });

  /* ── 5.11  CLICK DELEGATION (.view-btn / .view-document-modal) */
  document.addEventListener('click', function(e){
    const trigger = e.target.closest('.view-btn, .view-document-modal');
    if (!trigger) return;

    let extension = trigger.dataset.extension || '';
    if (!extension && trigger.dataset.filename) {
      const parts = trigger.dataset.filename.split('.');
      extension = parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    if (trigger.dataset.id) {
      e.preventDefault();
      const docId  = trigger.dataset.id;
      const source = trigger.dataset.type  || 'document';
      const title  = trigger.dataset.title || 'Document Preview';
      openDocumentViewer(docId, title, source, extension);
      return;
    }

  });

  /* ── 5.12  KEYBOARD SHORTCUTS ──────────────────────────────── */
  document.addEventListener('keydown', function(e){
    if (!modal.classList.contains('show')) return;
    if (e.key === 'Escape') closeViewer();
    else if (e.key === 'Tab') trapFocus(e);
    else if (pdfDoc) {
      if (e.key === 'ArrowLeft')  { e.preventDefault(); if (pageNum > 1)               { pageNum--; renderPDFPage(pageNum); } }
      if (e.key === 'ArrowRight') { e.preventDefault(); if (pageNum < pdfDoc.numPages) { pageNum++; renderPDFPage(pageNum); } }
    }
  });

  /* ── 5.13  MODAL CLOSE BINDINGS ────────────────────────────── */
  document.querySelectorAll('.close-btn[data-modal="universalDocumentModal"]').forEach(function(btn){
    btn.addEventListener('click', closeViewer);
  });
  modal.addEventListener('click', function(e){
    if (e.target === modal) closeViewer();
  });

});
</script>


<!-- ═══════════════════════════════════════════════════════════════
     SECTION 6 · DEEP-LINK ROW HIGHLIGHT  (?highlight=<source>:<id>)
     ═══════════════════════════════════════════════════════════════ -->
<script nonce="<?= csp_nonce() ?>">
(function () {
  var h = new URLSearchParams(location.search).get('highlight');
  if (!h || !/^[a-z_]+:\d+$/.test(h)) return;

  var s = document.createElement('style');
  s.textContent = '.row-highlight{outline:2px solid #2563eb;outline-offset:-2px;' +
                  'background:#eff6ff;box-shadow:0 0 0 4px rgba(37,99,235,.14)}';
  document.head.appendChild(s);

  var scrolled = false;
  function find() {
    var row = document.querySelector('[data-row-id="' + h + '"]');
    if (!row) {
      var p = h.split(':');
      var b = document.querySelector('.view-btn[data-id="' + p[1] + '"][data-type="' + p[0] + '"]');
      if (b) row = b.closest('.content-item, .file-card, .folder-item-card');
    }
    return row;
  }
  var row = find();
  if (row) { row.classList.add('row-highlight'); row.scrollIntoView({ block: 'center' }); }
  setTimeout(function () {
    document.querySelectorAll('.row-highlight').forEach(function (r) { r.classList.remove('row-highlight'); });
  }, 3000);
})();
</script>
</body>
</html>