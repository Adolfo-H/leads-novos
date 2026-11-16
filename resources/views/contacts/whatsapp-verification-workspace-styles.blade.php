<style>
/* EC_WA_UNIFIED_V7 — visual claro original do Prospector ExportControl. */
.wpr.wpr-v7{color:#133c51}
.wpr.wpr-v7 .wpr-heading{margin-bottom:19px}
.wpr.wpr-v7 .wpr-heading h1{font-size:30px;letter-spacing:-.035em}
.wpr .wa7-dashboard{display:flex;flex-direction:column;gap:14px;margin-bottom:18px}
.wpr .wa7-overview,.wpr .wa7-operation,.wpr .wa7-recent{background:#fff;border:1px solid #d9e7eb;border-radius:13px;padding:21px 23px;box-shadow:0 4px 18px rgba(15,51,70,.025)}
.wpr .wa7-overview{background:linear-gradient(115deg,#f3fbf9 0%,#fff 65%);border-top:3px solid #20a99d}
.wpr .wa7-intro,.wpr .wa7-operation-header,.wpr .wa7-recent-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}
.wpr .wa7-eyebrow{display:block;font-size:10px;color:#018b81;font-weight:800;letter-spacing:.1em;margin-bottom:7px}
.wpr .wa7-dashboard h2{font-size:19px;line-height:1.25;letter-spacing:-.02em;color:#0c3449;font-weight:780;margin:0 0 5px}
.wpr .wa7-dashboard h3{font-size:14px;font-weight:780;color:#173d50;margin:0}
.wpr .wa7-intro p,.wpr .wa7-operation-header p,.wpr .wa7-recent-head p{font-size:12px;color:#648095;max-width:700px;line-height:1.6;margin:4px 0 0}
.wpr .wa7-intro p strong{color:#146f66}
.wpr .wa7-index-note{white-space:nowrap;text-align:right;color:#305d71;font-weight:700;font-size:12px;padding-top:8px}
.wpr .wa7-index-note small{display:block;color:#7893a3;font-size:11px;font-weight:500;margin-top:5px}
.wpr .wa7-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:20px 0 15px}
.wpr .wa7-metric{background:#fff;border:1px solid #dfebef;border-radius:9px;display:flex;align-items:flex-start;flex-direction:column;padding:15px 16px;text-align:left;min-width:0;cursor:pointer;transition:border-color .15s,background .15s,transform .15s}
.wpr .wa7-metric:hover{background:#f6fcfa;border-color:#84d5c9;transform:translateY(-1px)}
.wpr .wa7-metric span{font-size:11px;font-weight:650;color:#658293}
.wpr .wa7-metric strong{font-weight:790;font-size:30px;letter-spacing:-.035em;color:#10394d;line-height:1.22;margin:6px 0}
.wpr .wa7-metric small{font-size:10px;color:#8299a5;line-height:1.3}
.wpr .wa7-metric-good{background:#ecfaf4;border-color:#caecdb}
.wpr .wa7-metric-good strong{color:#068a64}
.wpr .wa7-metric-accent{background:#f2fbf9;border-color:#b3e6dc}
.wpr .wa7-metric-accent strong{color:#048d83}
.wpr .wa7-completion{margin-top:4px}
.wpr .wa7-progress-label{display:flex;justify-content:space-between;gap:12px;align-items:center;font-size:11px;color:#698596;margin-bottom:7px}
.wpr .wa7-progress-label strong{color:#0a897d}
.wpr .wa7-progress-label span:last-child{font-size:10px;color:#8aa1ac}
.wpr .wa7-progress{background:#e3f0ee;height:7px;border-radius:100px;overflow:hidden}
.wpr .wa7-progress span{display:block;height:100%;background:#27b4a4;border-radius:100px}
.wpr .wa7-online-count{display:inline-flex;border-radius:100px;background:#f1f8f8;border:1px solid #d4e7e6;color:#308078;padding:7px 11px;font-size:11px;font-weight:750;white-space:nowrap}
.wpr .wa7-operation-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:13px;margin-top:17px}
.wpr .wa7-operation-card{padding:18px;border:1px solid #dfebef;border-radius:10px;background:#fdfefe;min-width:0}
.wpr .wa7-card-title{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}
.wpr .wa7-muted,.wpr .wa7-small-help{font-size:10px;color:#8298a4;line-height:1.5}
.wpr .wa7-agent-list{border:1px solid #e4ecf0;border-radius:9px;overflow:hidden;background:white}
.wpr .wa7-agent{padding:11px 13px;border-bottom:1px solid #edf1f4}
.wpr .wa7-agent:last-child{border-bottom:0}
.wpr .wa7-agent-row{display:flex;justify-content:space-between;align-items:center;gap:9px}
.wpr .wa7-agent-identity{display:flex;gap:9px;align-items:center;min-width:0}
.wpr .wa7-agent-identity strong,.wpr .wa7-agent-identity small{display:block}
.wpr .wa7-agent-identity strong{font-size:12px;color:#143d51}
.wpr .wa7-agent-identity small{font-size:10px;color:#819aaa;margin-top:2px}
.wpr .wa7-agent-symbol{width:31px;height:31px;flex:0 0 31px;display:grid;place-items:center;color:#078e80;font-size:12px;font-weight:800;border-radius:9px;background:#e5f6f1;border:1px solid #cae8df}
.wpr .wa7-presence{border-radius:100px;padding:5px 9px;white-space:nowrap;font-size:10px;font-weight:700;background:#f2f5f6;color:#7b919d;border:1px solid #e0e8ed}
.wpr .wa7-presence.wa7-on{background:#e9f8ef;color:#198252;border-color:#c3e9d1}
.wpr .wa7-last-run{display:block;margin:5px 0 0 40px;font-size:10px;color:#8a9da8}
.wpr .wa7-batch-progress{font-size:11px;color:#0c796f;margin:7px 0 0 40px;font-weight:700}
.wpr .wa7-batch-progress small{display:block;color:#738e9e;font-size:10px;margin-top:3px}
.wpr .wa7-stop{padding:2px 0 0;margin:2px 0 0 40px;border:0;background:none;text-decoration:underline;font-size:10px;color:#a46b40;cursor:pointer}
.wpr .wa7-now-row{display:flex;align-items:flex-end;gap:10px;margin-top:14px}
.wpr .wa7-input-label{display:flex;flex-direction:column;gap:7px;min-width:120px;color:#658393;font-size:11px;font-weight:650}
.wpr .wa7-input-label select,.wpr .wa7-input-label input{height:41px;background:white;color:#153e51;border:1px solid #d5e5eb;border-radius:8px;font-size:12px;padding:0 12px;width:100%;max-width:100%}
.wpr .wa7-primary{flex:1;min-height:41px;border:1px solid #048b7e;color:#fff!important;background:#028f83;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;padding:9px 12px;white-space:normal}
.wpr .wa7-primary:hover:not(:disabled){background:#037b73}
.wpr .wa7-primary:disabled{background:#e4eeee;border-color:#dae5e6;color:#91a2a7!important;cursor:not-allowed}
.wpr .wa7-small-help{display:block;margin-top:10px}
.wpr .wa7-account-shortcuts{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:9px}
.wpr .wa7-account-shortcuts span{font-size:10px;color:#7a94a3}
.wpr .wa7-account-shortcuts button{font-size:10px;padding:5px 10px;color:#1c7480;border:1px solid #d1e2e5;border-radius:6px;background:#fff;cursor:pointer}
.wpr .wa7-account-shortcuts button:disabled{opacity:.45;cursor:not-allowed}
.wpr .wa7-plan-state{border-radius:100px;padding:5px 8px;color:#678393;background:#eff4f6;border:1px solid #dce7eb;font-size:10px;font-weight:700}
.wpr .wa7-plan-state.is-on{color:#0d7e60;background:#e8f8ef;border-color:#bce9d3}
.wpr .wa7-daily-figures{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0}
.wpr .wa7-daily-figures>div{border:1px solid #e2ecf0;border-radius:8px;padding:10px;background:#fff}
.wpr .wa7-daily-figures small{color:#77909e;font-size:10px;display:block}
.wpr .wa7-daily-figures strong{font-size:22px;color:#12394b;display:block;margin-top:3px;font-weight:780}
.wpr .wa7-daily-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:13px}
.wpr .wa7-toggle{display:flex;align-items:flex-start;gap:10px;background:#f1f9f7;border:1px solid #d7eae5;border-radius:8px;padding:12px;margin:12px 0;cursor:pointer}
.wpr .wa7-toggle input{margin-top:3px;width:16px;height:16px;accent-color:#058c7c}
.wpr .wa7-toggle strong,.wpr .wa7-toggle small{display:block}
.wpr .wa7-toggle strong{font-size:11px;color:#165369}
.wpr .wa7-toggle small{font-size:10px;color:#748e9b;line-height:1.5;margin-top:4px}
.wpr .wa7-secondary{color:#0c8078;background:#fff;border:1px solid #9dd8ce;padding:10px 14px;border-radius:7px;font-size:11px;font-weight:800;cursor:pointer}
.wpr .wa7-secondary:hover{background:#eaf8f4}
.wpr .wa7-success,.wpr .wa7-error{padding:11px 12px;border-radius:8px;margin-top:11px;font-size:12px}
.wpr .wa7-success{background:#eaf9f2;border:1px solid #bfe8d6;color:#0e7555}
.wpr .wa7-error{background:#fff4ee;border:1px solid #eed5c8;color:#a15139}
.wpr .wa7-recent-head{align-items:center;margin-bottom:12px}
.wpr .wa7-recent-head button{background:none;border:0;color:#048779;font-size:11px;font-weight:750;cursor:pointer;white-space:nowrap}
.wpr .wa7-result-list{border:1px solid #e2ebef;border-radius:9px;overflow:hidden}
.wpr .wa7-result{width:100%;display:flex;align-items:center;text-align:left;gap:15px;border:0;border-bottom:1px solid #ecf1f3;background:#fff;padding:10px 13px;cursor:pointer}
.wpr .wa7-result:last-child{border-bottom:0}
.wpr .wa7-result:hover{background:#f3fbf9}
.wpr .wa7-result-phone{flex:1;min-width:0}
.wpr .wa7-result-phone strong{display:block;font-size:12px;color:#12384d}
.wpr .wa7-result-phone small,.wpr .wa7-result-time small{display:block;font-size:10px;color:#839cac;margin-top:2px}
.wpr .wa7-result-pill{padding:6px 9px;white-space:nowrap;border:1px solid #e0e8ed;border-radius:100px;background:#f6f8fa;color:#638092;font-size:10px;font-weight:720}
.wpr .wa7-pill-confirmed{color:#09805e;background:#e8f8f0;border-color:#bde8d1}
.wpr .wa7-pill-not_found{color:#95641e;background:#fff8ea;border-color:#eee1c2}
.wpr .wa7-pill-inconclusive,.wpr .wa7-pill-error{color:#a45d45;background:#fff4f0;border-color:#f0d5cb}
.wpr .wa7-result-time{font-size:11px;color:#728e9f;text-align:right;white-space:nowrap}
.wpr .wa7-chevron{color:#90aab7;font-size:18px}
.wpr .wa7-empty{font-size:12px;color:#8aa0ac;padding:18px;text-align:center}
.wpr .wa7-phone-proof{background:#f6f9fb;color:#58798d;border:1px solid #e2eaf0;border-radius:9px;padding:12px;margin:12px 0}
.wpr .wa7-phone-proof-good{background:#eaf9f1;color:#217859;border-color:#bfebd5}
.wpr .wa7-phone-proof-warn{background:#fff9ef;color:#956b35;border-color:#f1e2c1}
.wpr .wa7-phone-proof strong{display:block;font-size:12px}
.wpr .wa7-phone-proof p{font-size:11px;line-height:1.55;margin:6px 0}
.wpr .wa7-phone-proof small{font-size:10px}
.wpr.wpr-v7 .wpr-filters{grid-template-columns:minmax(240px,2fr) repeat(3,minmax(118px,1fr)) minmax(125px,auto) auto;align-items:end}
.wpr .wa7-more-filter{position:relative;min-height:40px;align-self:end}
.wpr .wa7-more-filter summary{border:1px solid #d7e5ea;padding:11px 12px;border-radius:8px;white-space:nowrap;cursor:pointer;color:#487487;font-size:11px;font-weight:750;list-style:none;background:#fff}
.wpr .wa7-more-filter[open] summary{border-color:#97d9cd;background:#f0fbf8}
.wpr .wa7-more-filter label{position:absolute;z-index:5;right:0;top:44px;width:220px;padding:11px;border-radius:8px;background:#fff;border:1px solid #dce9ed;box-shadow:0 10px 25px rgba(15,45,65,.12)}
.wpr .wa7-more-filter label span{font-size:11px;color:#6d899a;display:block;margin-bottom:7px}
.wpr .wa7-more-filter select{min-height:38px;width:100%;font-size:11px;padding:7px 9px;border:1px solid #dce9ed;border-radius:7px;background:#fff;color:#23485b}
.wpr .wa7-active-filter{font-size:11px;background:#e7f7f3;color:#107769;padding:8px 11px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.wpr .wa7-active-filter button{border:0;background:none;color:#086b62;font-weight:750;cursor:pointer}
.wpr .wa7-list-meta{color:#13917f;font-size:10px;font-weight:700;margin-top:3px}
@media(max-width:1250px){.wpr .wa7-operation-grid{grid-template-columns:1fr}.wpr.wpr-v7 .wpr-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.wpr .wa7-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:740px){.wpr .wa7-intro,.wpr .wa7-operation-header,.wpr .wa7-recent-head{flex-direction:column;gap:9px}.wpr .wa7-index-note{text-align:left}.wpr .wa7-overview,.wpr .wa7-operation,.wpr .wa7-recent{padding:15px}.wpr .wa7-metrics{gap:8px}.wpr .wa7-metric{padding:12px}.wpr .wa7-metric strong{font-size:26px}.wpr .wa7-now-row{flex-direction:column;align-items:stretch}.wpr .wa7-progress-label span:last-child{display:none}.wpr.wpr-v7 .wpr-filters{grid-template-columns:1fr 1fr}.wpr .wa7-result{flex-wrap:wrap;gap:8px}.wpr .wa7-result-time{min-width:80px}.wpr .wa7-daily-fields{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.wpr.wpr-v7 .wpr-filters{grid-template-columns:1fr}.wpr .wa7-metrics{grid-template-columns:1fr 1fr}.wpr .wa7-result-phone{flex-basis:100%}}
</style>
