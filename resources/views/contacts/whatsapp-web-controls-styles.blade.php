<style>
/* WA-V6: escopo restrito à Central WhatsApp, seguindo o tema claro do Prospector. */
.wpr .wa6-main,.wpr .wa6-recent{background:#fff;border:1px solid #dce7ec;border-radius:12px;padding:20px 22px;margin:14px 0;box-shadow:0 4px 24px rgba(9,42,64,.025);color:#15384c}
.wpr .wa6-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:15px}
.wpr .wa6-eyebrow{color:#009d90;letter-spacing:.1em;font-size:10px;font-weight:800}
.wpr .wa6-head h2{font-size:18px;line-height:1.3;margin:4px 0 5px;color:#0b344b;font-weight:750}
.wpr .wa6-head p,.wpr .wa6-agent-head p{font-size:12px;color:#7c98ab;margin:0;line-height:1.5}
.wpr .wa6-progress-chip{background:#e6f6f2;color:#008e7f;border:1px solid #b6e5d9;font-size:12px;padding:9px 12px;border-radius:999px;font-weight:700;white-space:nowrap}
.wpr .wa6-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px}
.wpr .wa6-metrics>div{border:1px solid #e4ebf0;border-radius:9px;padding:12px;min-width:0;background:#fbfdfe}
.wpr .wa6-metrics>div.wa6-highlight{background:#effbf7;border-color:#bce6dc}
.wpr .wa6-metrics small{display:block;color:#6e8a9d;font-size:11px;margin-bottom:6px}
.wpr .wa6-metrics strong{font-size:25px;color:#103950;display:block;line-height:1.2;font-weight:800}
.wpr .wa6-metrics .wa6-highlight strong{color:#008a7d}
.wpr .wa6-metrics span{font-size:10px;color:#8196a4;display:block;margin-top:5px}
.wpr .wa6-eligibility{color:#698595;font-size:11px;margin:10px 0 0}
.wpr .wa6-progress-bg,.wpr .wa6-batch-progress{height:6px;border-radius:999px;background:#e3eeed;overflow:hidden;margin-top:13px}
.wpr .wa6-progress-bg>div,.wpr .wa6-batch-progress>span{display:block;height:100%;background:#27b4a2;border-radius:999px;transition:width .25s}
.wpr .wa6-agent-head{margin:20px 0 11px;display:flex;justify-content:space-between;align-items:end;gap:15px}
.wpr .wa6-agent-head h3{font-size:14px;font-weight:750;margin:0 0 3px;color:#143a4e}
.wpr .wa6-limit{display:flex;align-items:center;gap:8px;font-size:11px;color:#6d889a;white-space:nowrap}
.wpr .wa6-limit select{border:1px solid #d4e0e6;border-radius:7px;padding:6px 25px 6px 10px;background:#fff;color:#163d51;font-size:12px;min-height:36px}
.wpr .wa6-agents{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.wpr .wa6-agent{border:1px solid #dce8ed;border-radius:10px;padding:15px;background:#fcfefe;min-width:0}
.wpr .wa6-agent-title{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.wpr .wa6-agent-id{display:flex;gap:10px;align-items:center}
.wpr .wa6-device-icon{width:34px;height:34px;display:grid;place-items:center;color:#047e74;background:#dff4ee;border:1px solid #bce6dc;border-radius:9px;font-weight:800}
.wpr .wa6-agent-id strong,.wpr .wa6-agent-id small{display:block}
.wpr .wa6-agent-id strong{font-size:13px;color:#14384e}
.wpr .wa6-agent-id small{font-size:10px;color:#8a9eaa;margin-top:2px}
.wpr .wa6-state{font-size:11px;border-radius:999px;padding:5px 8px;border:1px solid #e2e9ed;color:#6a8193;background:#f3f7f9;font-weight:700;white-space:nowrap}
.wpr .wa6-state.is-connected{background:#ecfaf2;border-color:#bde5cd;color:#16845b}
.wpr .wa6-state-dot{width:6px;height:6px;background:currentColor;border-radius:50%;display:inline-block;margin-right:5px}
.wpr .wa6-start{display:block;width:100%;padding:11px 12px;border-radius:8px;border:1px solid #008d80;background:#008d80;color:#fff!important;font-size:12px;font-weight:750;margin-top:13px;cursor:pointer}
.wpr .wa6-start:hover:not(:disabled){background:#007b71}
.wpr .wa6-start:disabled{background:#e7f0f1;border-color:#d7e1e5;color:#75909c!important;cursor:not-allowed}
.wpr .wa6-running,.wpr .wa6-last-batch,.wpr .wa6-idle{font-size:11px;color:#698496;margin:12px 0 0;min-height:22px}
.wpr .wa6-current{display:block;margin-top:6px;font-weight:700;color:#0b806f;font-size:11px}
.wpr .wa6-account-phone{font-weight:700;color:#168079!important}
.wpr .wa6-running strong{color:#0f384b;margin-right:8px}
.wpr .wa6-running .wa6-batch-progress{margin-top:7px}
.wpr .wa6-cancel{color:#9c503e;font-size:11px;cursor:pointer;background:none;border:0;text-decoration:underline;margin-top:9px;padding:0}
.wpr .wa6-agent-hint,.wpr .wa6-agent-error{display:block;font-size:10px;color:#8a9cab;margin-top:9px;line-height:1.4}
.wpr .wa6-agent-error{color:#b65345}
.wpr .wa6-notice{margin-top:11px;padding:10px;border-radius:7px;color:#0b7368;background:#e9f9f4;border:1px solid #b8e2d7;font-size:12px}
.wpr .wa6-notice-error{color:#a3503f;border-color:#f0d6cc;background:#fff6f1}
.wpr .wa6-disclaimer{font-size:11px;color:#869eac;margin:13px 0 0;line-height:1.5}
.wpr .wa6-text-action{background:transparent;border:0;color:#008c81;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap}
.wpr .wa6-recent-list{border:1px solid #e5edf0;border-radius:9px;overflow:hidden}
.wpr .wa6-recent-row{display:flex;align-items:center;text-align:left;width:100%;border:0;border-bottom:1px solid #e6edf0;padding:11px 13px;gap:12px;background:#fff;cursor:pointer}
.wpr .wa6-recent-row:last-child{border-bottom:0}
.wpr .wa6-recent-row:hover{background:#f1faf8}
.wpr .wa6-recent-phone{flex:1;min-width:0}
.wpr .wa6-recent-phone strong{font-size:12px;color:#133a50;display:block}
.wpr .wa6-recent-phone small,.wpr .wa6-recent-meta small{font-size:10px;color:#87a0ae;display:block;margin-top:2px}
.wpr .wa6-recent-meta{font-size:11px;color:#56788a;min-width:110px;text-align:right}
.wpr .wa6-probe-badge{padding:5px 9px;border-radius:999px;font-weight:750;font-size:10px;white-space:nowrap;border:1px solid #dfe9ed;background:#f4f7f9;color:#637f91}
.wpr .wa6-probe-found{background:#e8f8f0;border-color:#c1e7d2;color:#167f59}
.wpr .wa6-probe-not_found{background:#fff7e9;border-color:#f1deb8;color:#9c6827}
.wpr .wa6-probe-inconclusive,.wpr .wa6-probe-error{background:#fff3f1;color:#ad5646;border-color:#f1d7cd}
.wpr .wa6-chevron{color:#99b0bc;font-size:18px}
.wpr .wa6-empty{padding:25px;text-align:center;color:#8da1ae;font-size:12px;background:#f8fbfc;border-radius:8px}
.wpr.wpr-v2 .wpr-filters{grid-template-columns:minmax(200px,2fr) repeat(4,minmax(125px,1fr)) auto}
.wpr .wa6-filter-label{display:flex;flex-direction:column;gap:6px;min-width:145px}
.wpr .wa6-filter-label span{font-size:11px;color:#66879a}
.wpr .wa6-filter-label select{min-height:38px;border:1px solid #d9e5eb;border-radius:7px;background:#fff;color:#143b50;padding:6px 12px;font-size:12px}
@media(max-width:1100px){.wpr .wa6-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.wpr.wpr-v2 .wpr-filters{grid-template-columns:minmax(180px,2fr) repeat(2,minmax(120px,1fr))}}
@media(max-width:680px){.wpr.wpr-v2 .wpr-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.wpr .wa6-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.wpr .wa6-agents{grid-template-columns:1fr}.wpr .wa6-head{align-items:flex-start;flex-wrap:wrap}.wpr .wa6-agent-head{align-items:start;flex-wrap:wrap}.wpr .wa6-recent-meta{min-width:0}.wpr .wa6-recent-row{gap:7px;flex-wrap:wrap}}
</style>
