<style>
.wa11-shortcut{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 0 15px;padding:10px 14px;border:1px solid #d8e9e6;background:#f5fbf9;border-radius:9px;font-size:11px;color:#507e78}.wa11-shortcut a{color:#008b7e;font-weight:750;text-decoration:none}.wa11-block{margin:18px 0 24px;border:1px solid #dbe9ed;border-radius:12px;background:#fff;color:#103449;overflow:hidden;box-shadow:0 4px 16px rgba(10,45,55,.035)}
.wa11-heading{padding:18px 20px;border-top:3px solid #0da391;display:flex;gap:14px;justify-content:space-between;align-items:start}
.wa11-kicker{font-size:10px;letter-spacing:.1em;color:#008e80;font-weight:800;display:block;margin:0 0 7px}
.wa11-heading h2{font-size:19px;color:#0d344a;font-weight:760;margin:0 0 7px}
.wa11-heading p{margin:0;font-size:12px;color:#738d9d;line-height:1.6;max-width:780px}
.wa11-tag{padding:6px 10px;border:1px solid #c3e8dc;background:#eaf8f3;border-radius:100px;color:#078775;font-size:10px;font-weight:750;white-space:nowrap}
.wa11-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 20px 16px}
.wa11-metrics>div{padding:12px;border:1px solid #dce8ed;border-radius:8px;display:flex;flex-direction:column;gap:4px;background:#fbfdfe}
.wa11-metrics span{font-size:10px;color:#718e9f}.wa11-metrics strong{font-size:24px;color:#0d344a}.wa11-metrics small{font-size:10px;color:#89a0af}
.wa11-metrics .wa11-metric-focus{border-color:#b9e5d7;background:#eaf8f2}.wa11-metrics .wa11-metric-focus strong{color:#008d7a}
.wa11-primary{margin:0 20px 16px;border:1px solid #d5e6e9;background:#f9fcfc;border-radius:10px;padding:16px}
.wa11-primary-top{display:flex;justify-content:space-between;gap:10px;align-items:start;margin-bottom:14px}.wa11-primary-top strong{font-size:14px;color:#12384d}.wa11-primary-top small{font-size:11px;color:#7792a0;display:block;margin-top:4px}
.wa11-search{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.wa11-search label{flex:1;min-width:220px;display:flex;flex-direction:column;gap:6px;color:#50798b;font-size:11px;font-weight:650}
.wa11-search input{height:39px;border:1px solid #cadce4;background:#fff;color:#14364a;border-radius:7px;padding:9px 12px;width:100%;font-size:12px}
.wa11-btn{font-size:11px;font-weight:750;background:#008f83;color:#fff;border:1px solid #008f83;border-radius:7px;cursor:pointer;padding:10px 13px;text-align:center;min-height:38px}
.wa11-btn-outline{background:#fff;color:#008f83;border-color:#b0d8d0}.wa11-btn:disabled{opacity:.55;cursor:not-allowed}
.wa11-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:12px}
.wa11-company-button{background:#fff;border:1px solid #dce8ec;border-radius:8px;padding:10px 12px;text-align:left;cursor:pointer;display:flex;gap:5px;flex-direction:column;color:#163d52;font-size:12px}
.wa11-company-button:hover{border-color:#40b8aa;background:#f0faf7}.wa11-company-button small{font-size:10px;color:#7d9bab}
.wa11-company-label{margin:6px 0 13px;display:flex;align-items:center;gap:9px;justify-content:space-between;border:1px solid #bfebdf;background:#effaf6;padding:10px 12px;border-radius:8px}
.wa11-company-label strong{font-size:13px}.wa11-company-label small{display:block;font-size:10px;color:#628b80;margin-top:3px}
.wa11-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}.wa11-cards>div{border:1px solid #dce9ed;background:#fff;border-radius:8px;padding:10px}
.wa11-cards small{color:#8099a8;font-size:10px;display:block}.wa11-cards strong{font-size:20px;color:#0e384b}
.wa11-row{display:flex;align-items:center;gap:12px;justify-content:space-between;padding:10px 0;border-top:1px solid #e5eff2;font-size:12px}
.wa11-row:first-child{border-top:0}.wa11-row-main{min-width:0;display:flex;flex-direction:column;gap:3px}.wa11-row-main strong{font-size:12px}.wa11-row-main small{font-size:10px;color:#809bab}.wa11-row-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:flex-end}
.wa11-status{border-radius:100px;padding:4px 7px;background:#eff4f6;color:#617d8e;font-size:10px;font-weight:700}.wa11-status.yes,.wa11-status.same{background:#e6f8f1;color:#048673}.wa11-status.pending{background:#edf5f7;color:#426e83}.wa11-status.other{background:#fff6e7;color:#a77619}
.wa11-link{color:#078c80;font-size:11px;font-weight:750;background:transparent;border:0;cursor:pointer;text-decoration:none;padding:3px 0}
.wa11-empty{padding:16px;border:1px dashed #cfe2e8;border-radius:8px;color:#7994a2;font-size:12px;margin-top:8px}
.wa11-secondary{border-top:1px solid #e4eef0;padding:15px 20px}.wa11-secondary summary{cursor:pointer;font-size:12px;font-weight:750;color:#197d79}
.wa11-general{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0}.wa11-general-controls{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.wa11-general-controls label{display:flex;flex-direction:column;gap:5px;color:#708f9e;font-size:10px}.wa11-general-controls select{background:#fff;border:1px solid #dce8ed;border-radius:7px;padding:8px}
.wa11-checkbox{display:flex;gap:8px;align-items:center;color:#6c8697;font-size:11px}
.wa11-footer{border-top:1px solid #e5eef1;padding:10px 20px;font-size:10px;color:#8299a7}
.wa11-note{margin-top:9px;font-size:11px;color:#788e9a;line-height:1.5}
.wa11-message{margin:12px 20px;padding:10px 12px;background:#effaf5;color:#087d71;border:1px solid #bfe5d8;border-radius:8px;font-size:12px}.wa11-message.error{background:#fff6ef;border-color:#eed9c4;color:#a4632a}
@media(max-width:1050px){.wa11-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.wa11-options{grid-template-columns:1fr}}@media(max-width:700px){.wa11-metrics,.wa11-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.wa11-heading,.wa11-primary-top{flex-direction:column}.wa11-primary,.wa11-metrics{margin-left:12px;margin-right:12px}.wa11-search label{min-width:100%}}
</style>
