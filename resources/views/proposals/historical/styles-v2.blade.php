<style>
/* Scoped to the history screens. No Tailwind compilation needed. */
.ecp-history {--ec-ink:#102e44;--ec-muted:#607487;--ec-bg:#ffffff;--ec-soft:#f4f8fa;--ec-line:#dce7ed;--ec-teal:#007e73;--ec-teal-bg:#eaf7f4;--ec-amber:#925a12;--ec-amber-bg:#fff5e4;--ec-blue:#265a87;--ec-blue-bg:#eaf2fa; color:var(--ec-ink);font:inherit;line-height:1.5;display:grid;gap:20px;width:100%;min-width:0;padding:4px 0 24px;box-sizing:border-box;}
.dark .ecp-history {--ec-ink:#e4eff4;--ec-muted:#a8bdca;--ec-bg:#172a38;--ec-soft:#12222f;--ec-line:#354b5a;--ec-teal:#70d9c7;--ec-teal-bg:#193e3c;--ec-amber:#f2ca87;--ec-amber-bg:#48351e;--ec-blue:#b5d8fb;--ec-blue-bg:#233c53;}
.ecp-history * {box-sizing:border-box;}
.ecp-history h2,.ecp-history h3,.ecp-history p {margin:0;}
.ecp-history h2 {font-size:18px;font-weight:700;line-height:1.35;letter-spacing:-.015em;}
.ecp-history h3 {font-size:14px;font-weight:700;line-height:1.4;}
.ecp-history .ecp-sub {display:block;color:var(--ec-muted);font-size:12px;line-height:1.5;margin-top:3px;overflow-wrap:anywhere;}
.ecp-history .ecp-label {display:block;color:var(--ec-muted);font-size:12px;font-weight:600;margin-bottom:5px;}
.ecp-history .ecp-kpis {display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;}
.ecp-history .ecp-kpi {display:block;border:1px solid var(--ec-line);border-radius:12px;background:var(--ec-bg);padding:16px;text-decoration:none;color:var(--ec-ink);min-width:0;}
.ecp-history .ecp-kpi:hover,.ecp-history .ecp-kpi-active {border-color:var(--ec-teal);}
.ecp-history .ecp-kpi strong {display:block;font-size:29px;font-weight:750;line-height:1.2;letter-spacing:-.03em;font-variant-numeric:tabular-nums;margin:6px 0;}
.ecp-history .ecp-kpi-accepted {background:var(--ec-teal-bg);}
.ecp-history .ecp-panel {border:1px solid var(--ec-line);border-radius:12px;background:var(--ec-bg);padding:20px;min-width:0;}
.ecp-history .ecp-head {display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.ecp-history .ecp-btn {display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;border:1px solid var(--ec-line);border-radius:8px;padding:8px 12px;background:var(--ec-bg);color:var(--ec-ink);font-size:13px;font-weight:650;text-decoration:none;line-height:1.35;cursor:pointer;white-space:nowrap;}
.ecp-history .ecp-btn:hover {border-color:var(--ec-teal);}
.ecp-history .ecp-btn-primary {background:#00877b;color:#fff;border-color:#00877b;}
.ecp-history .ecp-link,.ecp-history .ecp-action a {color:var(--ec-teal);text-decoration:none;font-weight:650;font-size:13px;}
.ecp-history .ecp-link:hover,.ecp-history .ecp-action a:hover {text-decoration:underline;}
.ecp-history .ecp-filter {display:grid;grid-template-columns:minmax(200px,2.1fr) minmax(135px,1fr) minmax(140px,1fr) minmax(75px,.55fr) auto;align-items:end;gap:10px;margin-bottom:18px;}
.ecp-history .ecp-filter label {display:block;min-width:0;font-size:12px;font-weight:600;color:var(--ec-muted);}
.ecp-history .ecp-filter input,.ecp-history .ecp-filter select {appearance:auto;display:block;width:100%;height:39px;min-width:0;margin-top:5px;padding:8px 10px;border:1px solid var(--ec-line);border-radius:8px;background:var(--ec-bg);color:var(--ec-ink);font:inherit;font-size:13px;}
.ecp-history .ecp-filter-actions {display:flex;gap:7px;}
.ecp-history .ecp-scroll {overflow-x:auto;border:1px solid var(--ec-line);border-radius:9px;max-width:100%;}
.ecp-history .ecp-table {border-collapse:collapse;width:100%;font-size:13px;text-align:left;}
.ecp-history .ecp-list {min-width:940px;}
.ecp-history .ecp-table th {font-size:11px;letter-spacing:.015em;font-weight:700;color:var(--ec-muted);background:var(--ec-soft);padding:12px;text-align:left;vertical-align:middle;white-space:nowrap;}
.ecp-history .ecp-table td {padding:13px 12px;border-top:1px solid var(--ec-line);vertical-align:top;}
.ecp-history .ecp-table tbody tr:hover {background:var(--ec-soft);}
.ecp-history .ecp-table .ecp-number {text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;}
.ecp-history .ecp-nowrap {white-space:nowrap;}
.ecp-history .ecp-name {font-size:13px;font-weight:650;overflow-wrap:anywhere;line-height:1.4;min-width:170px;max-width:285px;}
.ecp-history .ecp-client {font-weight:700;overflow-wrap:anywhere;min-width:120px;max-width:230px;}
.ecp-history .ecp-money {font-weight:750;font-size:14px;font-variant-numeric:tabular-nums;white-space:nowrap;}
.ecp-history .ecp-chip {display:inline-block;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.35;white-space:nowrap;background:var(--ec-soft);color:var(--ec-muted);}
.ecp-history .ecp-chip-accepted {background:var(--ec-teal-bg);color:var(--ec-teal);}
.ecp-history .ecp-chip-sent {background:var(--ec-blue-bg);color:var(--ec-blue);}
.ecp-history .ecp-chip-pending {background:var(--ec-amber-bg);color:var(--ec-amber);}
.ecp-history .ecp-detail-top {display:flex;gap:16px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;}
.ecp-history .ecp-detail-title {font-size:23px;line-height:1.35;font-weight:750;letter-spacing:-.025em;overflow-wrap:anywhere;margin:8px 0 6px;}
.ecp-history .ecp-detail-top > div {flex:1;min-width:200px;}
.ecp-history .ecp-summary {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.ecp-history .ecp-value {background:var(--ec-soft);border:1px solid var(--ec-line);border-radius:10px;padding:15px;min-width:0;}
.ecp-history .ecp-value strong {display:block;font-size:23px;font-weight:750;line-height:1.3;overflow-wrap:anywhere;font-variant-numeric:tabular-nums;}
.ecp-history .ecp-value-main {background:var(--ec-teal-bg);border-color:transparent;}
.ecp-history .ecp-value-main strong {color:var(--ec-teal);}
.ecp-history .ecp-facts {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px 20px;margin:0;}
.ecp-history .ecp-facts dt {font-size:12px;color:var(--ec-muted);font-weight:600;margin:0 0 5px;}
.ecp-history .ecp-facts dd {font-size:14px;font-weight:550;margin:0;overflow-wrap:anywhere;}
.ecp-history .ecp-two {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
.ecp-history .ecp-block {display:grid;gap:12px;min-width:0;}
.ecp-history .ecp-note {background:var(--ec-soft);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--ec-muted);line-height:1.6;}
.ecp-history .ecp-warning {background:var(--ec-amber-bg);color:var(--ec-amber);}
.ecp-history .ecp-empty {padding:26px!important;text-align:center;color:var(--ec-muted);}
.ecp-history .ecp-disclosure {border:1px solid var(--ec-line);border-radius:9px;background:var(--ec-bg);min-width:0;}
.ecp-history .ecp-disclosure summary {font-size:13px;font-weight:650;padding:14px 16px;cursor:pointer;}
.ecp-history .ecp-raw {white-space:pre-wrap;overflow-wrap:anywhere;max-height:420px;overflow:auto;line-height:1.6;font-size:12px;margin:0;padding:14px 16px;background:var(--ec-soft);color:var(--ec-ink);}
.ecp-history .ecp-doc {display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:12px;border:1px solid var(--ec-line);border-radius:8px;}
.ecp-history .ecp-doc-name {font-size:13px;font-weight:650;overflow-wrap:anywhere;flex:1;min-width:180px;}
.ecp-history .ecp-pagination {margin-top:14px;}
.ecp-history .ecp-service {font-size:12px;font-weight:550;display:block;line-height:1.45;}
.ecp-history .ecp-spaced {margin-top:14px;}
@media(max-width:1250px){.ecp-history .ecp-filter{grid-template-columns:repeat(4,minmax(0,1fr));}.ecp-history .ecp-search{grid-column:span 2;}.ecp-history .ecp-filter-actions{grid-column:span 3;justify-content:flex-end;}}
@media(max-width:780px){.ecp-history .ecp-kpis,.ecp-history .ecp-summary,.ecp-history .ecp-facts,.ecp-history .ecp-two,.ecp-history .ecp-filter{grid-template-columns:repeat(2,minmax(0,1fr));}.ecp-history .ecp-filter-actions{grid-column:span 2;}.ecp-history .ecp-panel{padding:14px;}.ecp-history .ecp-detail-title{font-size:19px;}}
@media(max-width:480px){.ecp-history .ecp-summary,.ecp-history .ecp-facts,.ecp-history .ecp-two{grid-template-columns:minmax(0,1fr);}.ecp-history .ecp-kpi{padding:11px;}.ecp-history .ecp-kpi strong{font-size:24px;}}
</style>
