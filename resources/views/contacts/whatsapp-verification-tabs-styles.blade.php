<style>
/* EC_WA_VISUAL_V12 — visual por tarefa, sem alterar estilos globais. */
.wpr.wa12{--wa12-teal:#008d82;--wa12-ink:#10364b;--wa12-muted:#627f91;--wa12-border:#dce8ec;--wa12-soft:#f7fbfb;color:var(--wa12-ink)}
.wpr.wa12 [x-cloak]{display:none!important}
.wpr.wa12 .wpr-heading{margin-bottom:17px}
.wpr.wa12 .wpr-heading h1{font-size:26px;letter-spacing:-.035em}
.wpr.wa12 .wpr-heading p{max-width:780px}
.wpr.wa12 .wa12-overview{background:#fff;border:1px solid var(--wa12-border);border-top:3px solid #14ad9e;border-radius:12px;padding:19px 20px 15px;margin:0 0 16px;box-shadow:0 3px 16px rgba(19,60,73,.035)}
.wpr.wa12 .wa12-overview-head{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:17px}
.wpr.wa12 .wa12-eyebrow{font-size:10px;letter-spacing:.1em;color:var(--wa12-teal);font-weight:800}
.wpr.wa12 .wa12-overview h2{font-size:20px;letter-spacing:-.02em;font-weight:800;color:var(--wa12-ink);margin:5px 0 4px}
.wpr.wa12 .wa12-overview-head p{margin:0;font-size:12px;line-height:1.5;color:var(--wa12-muted)}
.wpr.wa12 .wa12-progress-info{display:flex;gap:8px;align-items:center;border-radius:10px;background:#edf9f5;border:1px solid #caebe0;padding:10px 13px;flex:0 0 200px}
.wpr.wa12 .wa12-progress-info strong{font-size:25px;font-weight:850;color:var(--wa12-teal);letter-spacing:-.04em}
.wpr.wa12 .wa12-progress-info span{font-size:10px;color:#527e73;line-height:1.35}
.wpr.wa12 .wa12-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.wpr.wa12 .wa12-metric{display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;gap:5px;min-width:0;text-align:left;padding:15px 15px 13px;min-height:104px;background:#fff;border:1px solid var(--wa12-border);border-radius:9px;cursor:pointer;color:var(--wa12-ink);transition:border-color .15s,background-color .15s,box-shadow .15s}
.wpr.wa12 .wa12-metric:hover,.wpr.wa12 .wa12-metric:focus-visible{background:#f4fcfa;border-color:#41b8a9;box-shadow:0 0 0 2px rgba(11,152,137,.08)}
.wpr.wa12 .wa12-metric-green{background:#edf9f5;border-color:#bee8d9}
.wpr.wa12 .wa12-metric span{font-size:11px;font-weight:700;color:#547487}
.wpr.wa12 .wa12-metric strong{font-size:29px;line-height:1.1;font-weight:800;letter-spacing:-.045em;color:#12384d}
.wpr.wa12 .wa12-metric-green strong{color:#008c7b}
.wpr.wa12 .wa12-metric small{font-size:10px;color:#7c95a1;line-height:1.4}
.wpr.wa12 .wa12-overview-bottom{margin-top:14px}
.wpr.wa12 .wa12-overview-track{height:5px;background:#e3efee;overflow:hidden;border-radius:20px}
.wpr.wa12 .wa12-overview-track>span{display:block;background:#24b2a2;height:100%;border-radius:20px;transition:width .3s}
.wpr.wa12 .wa12-overview-meta{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px;color:#76909f;font-size:10px}
.wpr.wa12 .wa12-overview-meta>b,.wpr.wa12 .wa12-overview-meta b{color:#235266}
.wpr.wa12 .wa12-overview-meta>span+span:before{content:"·";margin-right:12px;color:#b4c9d0}
.wpr.wa12 .wa12-info-details{margin-left:auto;position:relative;max-width:100%}
.wpr.wa12 .wa12-info-details summary{cursor:pointer;color:#0a8c80;font-size:10px;font-weight:750}
.wpr.wa12 .wa12-info-details p{max-width:560px;font-size:11px;line-height:1.6;color:#5b798a;background:#f1f9f7;border:1px solid #d9eae7;border-radius:8px;padding:10px;margin:7px 0 0}
.wpr.wa12 .wa12-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;border:1px solid var(--wa12-border);background:#f5faf9;padding:6px;border-radius:11px;margin-bottom:16px}
.wpr.wa12 .wa12-tabs button{display:flex;align-items:center;gap:11px;text-align:left;min-width:0;padding:12px 13px;border:1px solid transparent;border-radius:8px;background:transparent;cursor:pointer;color:#5f7e8f}
.wpr.wa12 .wa12-tabs button:hover{background:#fff;border-color:#d7e7e7}
.wpr.wa12 .wa12-tabs button.is-active{background:#fff;border-color:#b9dcd5;box-shadow:0 2px 7px rgba(20,80,70,.06);color:#123a4d}
.wpr.wa12 .wa12-tab-icon{width:29px;height:29px;display:flex;align-items:center;justify-content:center;background:#e1eeef;color:#4a7e87;font-weight:800;border-radius:8px;flex:0 0 29px;font-size:10px}
.wpr.wa12 .wa12-tabs button.is-active .wa12-tab-icon{background:#def5ed;color:#008779}
.wpr.wa12 .wa12-tabs button strong{display:block;font-size:12px;line-height:1.35;font-weight:800}
.wpr.wa12 .wa12-tabs button small{display:block;font-size:10px;line-height:1.4;color:#839aa5;margin-top:2px}
.wpr.wa12 .wa12-pane{animation:wa12Enter .15s ease-out;min-width:0}
@keyframes wa12Enter{from{opacity:.65;transform:translateY(3px)}to{opacity:1;transform:translateY(0)}}
.wpr.wa12 .wa12-pane-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:3px 0 13px}
.wpr.wa12 .wa12-pane-heading h2{font-size:16px;font-weight:800;color:#163b4e;margin:0 0 3px}
.wpr.wa12 .wa12-pane-heading p{font-size:11px;color:#738f9e;margin:0}
.wpr.wa12 .wa12-pane-heading .wa12-primary{background:#008f83;color:#fff;border:1px solid #008f83;border-radius:8px;padding:10px 14px;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap}
.wpr.wa12 .wa12-pane-heading .wa12-primary:hover{background:#047c72}
.wpr.wa12 .wa8-directory-head{margin:0 0 10px;padding:14px 16px;border:1px solid var(--wa12-border);background:#fff;border-radius:10px}
.wpr.wa12 .wa8-directory-head strong{font-size:14px}
.wpr.wa12 .wa8-directory-head small{font-size:11px}
.wpr.wa12 .wa12-type-shortcuts{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.wpr.wa12 .wa12-type-shortcuts button{border:1px solid #dce8ec;border-radius:7px;padding:7px 10px;background:#fff;color:#517587;font-size:11px;font-weight:700;cursor:pointer}
.wpr.wa12 .wa12-type-shortcuts button:hover{border-color:#18a99a;background:#f0fbf7;color:#078677}
.wpr.wa12 .wa12-hint{font-size:10px;color:#7d98a3;line-height:1.6;margin:-1px 0 12px}
.wpr.wa12 .wpr-filters.wa12-filters{display:grid;grid-template-columns:minmax(220px,2fr) repeat(3,minmax(115px,1fr)) auto;column-gap:10px;row-gap:9px;align-items:end;padding:15px;border-radius:10px;background:#fff;border:1px solid var(--wa12-border);margin-bottom:13px}
.wpr.wa12 .wa12-filters>label{min-width:0}.wpr.wa12 .wa12-filters>label span{display:block;color:#56798a;font-size:10px;font-weight:700;margin-bottom:5px}
.wpr.wa12 .wa12-filters input,.wpr.wa12 .wa12-filters select{height:39px;min-height:39px;background:#fff;border:1px solid #d5e4e9;border-radius:7px;font-size:12px;color:#12394c;width:100%;padding:8px 10px;box-sizing:border-box}
.wpr.wa12 .wa12-filters input:focus,.wpr.wa12 .wa12-filters select:focus{border-color:#18a69a;outline:2px solid #def4ee;outline-offset:0}
.wpr.wa12 .wa12-filters>.wa12-clear{height:39px;min-height:39px;padding:0 14px;margin:0;background:#fff;border:1px solid #d5e4e9;border-radius:7px;font-size:11px;color:#456b7d;white-space:nowrap}
.wpr.wa12 .wa12-advanced{grid-column:1/-1;border:0;margin:0;padding:0}
.wpr.wa12 .wa12-advanced summary{display:inline-flex;align-items:center;cursor:pointer;color:#008e80;font-size:11px;font-weight:750;padding:4px 0;gap:5px}
.wpr.wa12 .wa12-advanced-grid{display:grid;grid-template-columns:repeat(2,minmax(0,220px));gap:10px;margin-top:8px;padding-top:9px;border-top:1px solid #e6eff1}
.wpr.wa12 .wa12-advanced-grid label{display:flex;flex-direction:column;gap:5px;color:#668697;font-size:10px;font-weight:700}
.wpr.wa12 .wa12-advanced-grid select{width:100%}
.wpr.wa12 .wpr-workspace{display:grid;grid-template-columns:minmax(0,1.07fr) minmax(0,.93fr);align-items:start;gap:13px}
.wpr.wa12 .wpr-list-panel,.wpr.wa12 .wpr-detail-panel{border:1px solid #dce8ec;border-radius:11px;background:#fff;box-shadow:0 2px 10px rgba(20,65,78,.025)}
.wpr.wa12 .wpr-panel-header{padding:14px 15px;background:#f8fbfc;border-bottom:1px solid #e5eef0}
.wpr.wa12 .wpr-panel-header h2{font-size:14px}
.wpr.wa12 .wpr-number-list{max-height:580px;overflow-y:auto;overscroll-behavior:contain}
.wpr.wa12 .wpr-number{min-height:62px;padding:10px 13px}
.wpr.wa12 .wpr-number.is-active{background:#eaf7f2;border-left:3px solid #0ea697}
.wpr.wa12 .wpr-number-content strong{font-size:12px}
.wpr.wa12 .wpr-number-content small{font-size:10px;color:#829ba7}
.wpr.wa12 .wpr-detail-panel{position:sticky;top:15px;max-height:calc(100vh - 125px);overflow-y:auto}
.wpr.wa12 .wpr-detail-panel h2{font-size:21px;letter-spacing:-.02em}
.wpr.wa12 .wpr-action-panel{background:#f5fbf9;border:1px solid #d6e9e4;border-radius:10px}
.wpr.wa12 .wpr-linked,.wpr.wa12 .wpr-history{margin-top:14px}
.wpr.wa12 .wa11-block{margin:0 0 15px;border-top:0}
.wpr.wa12 .wa11-heading{border-top:0;padding:18px 20px 10px}
.wpr.wa12 .wa11-primary{background:#fff}
.wpr.wa12 .wa11-primary-top small{max-width:690px}
.wpr.wa12 .wa7-operation-block{margin-top:0}
.wpr.wa12 .wa7-overview,.wpr.wa12 .wa8-directory-intro,.wpr.wa12 .wa11-shortcut{display:none!important}
.wpr.wa12 .wa12-pane-operation .wa7-recent{margin-top:14px}
.wpr.wa12 .wa12-pane-operation .wa7-operation-card{border-radius:10px}
@media(max-width:1100px){.wpr.wa12 .wa12-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.wpr.wa12 .wpr-filters.wa12-filters{grid-template-columns:minmax(220px,1.6fr) repeat(2,minmax(125px,1fr))}.wpr.wa12 .wa12-filters>.wa12-clear{grid-column:auto}.wpr.wa12 .wpr-workspace{grid-template-columns:1fr}.wpr.wa12 .wpr-detail-panel{position:static;max-height:none;overflow:visible}}
@media(max-width:740px){.wpr.wa12 .wa12-overview{padding:14px}.wpr.wa12 .wa12-overview-head{flex-direction:column;align-items:flex-start}.wpr.wa12 .wa12-progress-info{flex:initial;width:100%}.wpr.wa12 .wa12-tabs{grid-template-columns:1fr;gap:3px}.wpr.wa12 .wa12-tabs button{padding:8px 10px}.wpr.wa12 .wpr-filters.wa12-filters{grid-template-columns:1fr 1fr}.wpr.wa12 .wa12-filters .wpr-search{grid-column:1/-1}.wpr.wa12 .wa12-advanced-grid{grid-template-columns:1fr 1fr}.wpr.wa12 .wa12-overview-meta{align-items:flex-start}.wpr.wa12 .wa12-info-details{margin-left:0}.wpr.wa12 .wa12-pane-heading{align-items:flex-start;flex-direction:column}}
@media(max-width:480px){.wpr.wa12 .wa12-metrics{gap:7px}.wpr.wa12 .wa12-metric{padding:11px 10px}.wpr.wa12 .wa12-metric strong{font-size:23px}.wpr.wa12 .wpr-filters.wa12-filters{grid-template-columns:1fr}.wpr.wa12 .wa12-filters .wpr-search{grid-column:auto}.wpr.wa12 .wa12-advanced-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.wpr.wa12 .wa12-pane{animation:none}}
</style>
