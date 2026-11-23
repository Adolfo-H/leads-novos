{{-- EC_INBOUND_V22_STYLE: escopo proprio; sem herdar tabela antiga nem cores invisiveis. --}}
@once
<style>
.ec-v22 {
    --ecv-bg: #ffffff;
    --ecv-bg-soft: #f6f9fc;
    --ecv-bg-hover: #f0f7f8;
    --ecv-border: #d8e5ed;
    --ecv-text: #142c3f;
    --ecv-muted: #536b7a;
    --ecv-link: #087d78;
    --ecv-teal-soft: #dff6f1;
    --ecv-teal-text: #08625b;
    --ecv-amber-soft: #fff1d9;
    --ecv-amber-text: #8d540b;
    --ecv-blue-soft: #e8f1ff;
    --ecv-blue-text: #265fa7;
    --ecv-shadow: 0 8px 28px rgba(17, 48, 75, .045);
    color: var(--ecv-text) !important;
    border: 1px solid var(--ecv-border);
    background: var(--ecv-bg);
    border-radius: 16px;
    padding: clamp(16px, 2vw, 26px);
    box-shadow: var(--ecv-shadow);
    margin: 18px 0;
    min-height: 0;
    width: 100%;
    box-sizing: border-box;
}
.dark .ec-v22,
[data-theme="dark"] .ec-v22 {
    --ecv-bg: #132433;
    --ecv-bg-soft: #192f40;
    --ecv-bg-hover: #173d45;
    --ecv-border: #335066;
    --ecv-text: #ecf5fa;
    --ecv-muted: #b0c4cf;
    --ecv-link: #66e2d1;
    --ecv-teal-soft: #134540;
    --ecv-teal-text: #9aeee0;
    --ecv-amber-soft: #4b351d;
    --ecv-amber-text: #ffcf90;
    --ecv-blue-soft: #203d60;
    --ecv-blue-text: #a6c8f6;
    --ecv-shadow: none;
}
.ec-v22 *, .ec-v22 *::before, .ec-v22 *::after { box-sizing: border-box; }
.ec-v22 .ec-v22-head { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px; padding-bottom:18px; border-bottom:1px solid var(--ecv-border); }
.ec-v22 .ec-v22-overline { font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--ecv-link)!important; margin:0 0 6px; }
.ec-v22 .ec-v22-title { font-size:clamp(18px, 1.8vw, 22px); line-height:1.25; letter-spacing:-.02em; font-weight:800; margin:0 0 7px; color:var(--ecv-text)!important; }
.ec-v22 .ec-v22-description { font-size:13px; line-height:1.55; margin:0; color:var(--ecv-muted)!important; max-width:760px; }
.ec-v22 .ec-v22-count { display:flex; align-items:baseline; gap:8px; border:1px solid var(--ecv-border); border-radius:12px; padding:10px 15px; background:var(--ecv-bg-soft); color:var(--ecv-muted)!important; font-size:12px; font-weight:600; white-space:nowrap; }
.ec-v22 .ec-v22-count strong { font-size:22px; font-weight:800; color:var(--ecv-text)!important; font-variant-numeric:tabular-nums; }
.ec-v22 .ec-v22-hint { display:flex; align-items:flex-start; gap:10px; margin:16px 0; padding:11px 14px; font-size:12px; line-height:1.55; color:var(--ecv-muted)!important; background:var(--ecv-bg-soft); border-radius:11px; }
.ec-v22 .ec-v22-hint-mark { display:inline-grid; place-items:center; flex:none; border-radius:50%; width:20px; height:20px; font-weight:800; background:var(--ecv-teal-soft); color:var(--ecv-teal-text)!important; }
.ec-v22 .ec-v22-feed { display:grid; gap:11px; margin-top:16px; }
.ec-v22 .ec-v22-item { display:grid; grid-template-columns:42px minmax(0, 1fr); gap:14px; background:var(--ecv-bg); border:1px solid var(--ecv-border); border-radius:13px; padding:17px; box-shadow:0 2px 6px rgba(17,48,75,.025); min-width:0; }
.ec-v22 .ec-v22-item:focus-within { outline:2px solid var(--ecv-link); outline-offset:2px; }
.ec-v22 .ec-v22-avatar { display:grid; place-items:center; width:42px; height:42px; border-radius:12px; font-size:16px; font-weight:800; color:var(--ecv-blue-text)!important; background:var(--ecv-blue-soft); }
.ec-v22 .ec-v22-body { min-width:0; }
.ec-v22 .ec-v22-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
.ec-v22 .ec-v22-pill { display:inline-flex; align-items:center; border-radius:100px; padding:5px 10px; font-size:11px; font-weight:800; line-height:1.2; border:1px solid transparent; }
.ec-v22 .ec-v22-pill--review { background:var(--ecv-amber-soft); color:var(--ecv-amber-text)!important; }
.ec-v22 .ec-v22-pill--address { background:var(--ecv-blue-soft); color:var(--ecv-blue-text)!important; }
.ec-v22 .ec-v22-pill--auto { background:var(--ecv-teal-soft); color:var(--ecv-teal-text)!important; }
.ec-v22 .ec-v22-timestamp { font-size:11px; color:var(--ecv-muted)!important; font-variant-numeric:tabular-nums; }
.ec-v22 .ec-v22-subject { color:var(--ecv-text)!important; font-size:15px; font-weight:750; line-height:1.45; margin:0 0 4px; overflow-wrap:anywhere; }
.ec-v22 .ec-v22-email { color:var(--ecv-muted)!important; font-size:13px; line-height:1.5; margin:0 0 10px; overflow-wrap:anywhere; }
.ec-v22 .ec-v22-foot { display:flex; align-items:center; flex-wrap:wrap; gap:10px 17px; font-size:12px; line-height:1.55; }
.ec-v22 .ec-v22-foot a { color:var(--ecv-link)!important; font-weight:750; text-decoration:underline; text-underline-offset:3px; }
.ec-v22 .ec-v22-foot a:hover { opacity:.83; }
.ec-v22 .ec-v22-foot span { color:var(--ecv-muted)!important; }
.ec-v22 .ec-v22-actions { display:flex; flex-wrap:wrap; gap:9px; align-items:center; margin-top:14px; }
.ec-v22 .ec-v22-button { display:inline-flex; justify-content:center; align-items:center; gap:7px; min-height:36px; padding:8px 14px; color:white!important; background:#087b75!important; border:1px solid #087b75!important; border-radius:9px; font-size:12px; font-weight:750; cursor:pointer; transition:background .15s ease, border-color .15s ease; text-decoration:none; }
.ec-v22 .ec-v22-button:hover { background:#08645e!important; border-color:#08645e!important; }
.ec-v22 .ec-v22-button:focus-visible { outline:3px solid #4ecab9; outline-offset:2px; }
.ec-v22 .ec-v22-button:disabled { cursor:wait; opacity:.65; }
.ec-v22 .ec-v22-button--quiet { background:transparent!important; color:var(--ecv-text)!important; border-color:var(--ecv-border)!important; }
.ec-v22 .ec-v22-button--quiet:hover { background:var(--ecv-bg-soft)!important; }
.ec-v22 .ec-v22-review { border:1px solid var(--ecv-border); border-left:3px solid #d58a28; border-radius:9px; margin-top:13px; padding:15px; background:var(--ecv-bg-soft); }
.ec-v22 .ec-v22-review strong { display:block; color:var(--ecv-text)!important; font-size:13px; margin-bottom:5px; }
.ec-v22 .ec-v22-review p { color:var(--ecv-muted)!important; font-size:12px; line-height:1.55; margin:0; }
.ec-v22 .ec-v22-empty { padding:25px; border-radius:11px; border:1px dashed var(--ecv-border); background:var(--ecv-bg-soft); }
.ec-v22 .ec-v22-empty strong { display:block; color:var(--ecv-text)!important; margin-bottom:5px; }
.ec-v22 .ec-v22-empty p { color:var(--ecv-muted)!important; font-size:13px; margin:0; }
.ec-v22 .ec-v22-divider { height:1px; background:var(--ecv-border); margin:15px 0; }
.ec-v22 .ec-v22-alert { display:block; font-size:13px; margin:10px 0; padding:12px 14px; border-radius:9px; background:var(--ecv-teal-soft); color:var(--ecv-teal-text)!important; }
.ec-v22 .ec-v22-alert--warn { background:var(--ecv-amber-soft); color:var(--ecv-amber-text)!important; }
.ec-v22 .ec-v22-page { margin-top:18px; color:var(--ecv-text)!important; }
.ec-v22 .ec-v22-muted { color:var(--ecv-muted)!important; }
.ec-v22 .ec-v22-more { font-size:12px; color:var(--ecv-muted)!important; margin:12px 0 0; }
.ec-v22 .ec-v22-kicker { font-size:12px; font-weight:800; color:var(--ecv-amber-text)!important; margin:0 0 5px; text-transform:uppercase; letter-spacing:.055em; }
.ec-v22.ec-v22--unlinked { border-color:var(--ecv-border); }
.ec-v22.ec-v22--unlinked .ec-v22-head { padding-bottom:13px; }
.ec-v22 .ec-v22-inline-id { font-size:11px; font-weight:650; color:var(--ecv-muted)!important; }
@media (max-width:700px) {
    .ec-v22 { padding:15px; border-radius:12px; }
    .ec-v22 .ec-v22-head { gap:12px; }
    .ec-v22 .ec-v22-count { padding:7px 12px; }
    .ec-v22 .ec-v22-item { grid-template-columns:34px minmax(0,1fr); padding:12px; gap:10px; }
    .ec-v22 .ec-v22-avatar { width:34px; height:34px; border-radius:9px; }
    .ec-v22 .ec-v22-subject { font-size:14px; }
    .ec-v22 .ec-v22-actions { width:100%; }
}
</style>
@endonce
