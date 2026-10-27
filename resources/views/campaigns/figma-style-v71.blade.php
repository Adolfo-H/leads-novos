{{-- CAMPANHAS_FIGMA_V71_STYLES: carregado APÓS o CSS legado, somente nestas duas telas. --}}
<style>
/* M11 / M12 – novo padrão ExportControl: cabeçalho claro e cards brancos */
body.ec-app-body:has(.ec71-page) { background: #f2f6f8 !important; color: #17374c !important; color-scheme: light !important; }
body.ec-app-body:has(.ec71-page) .ec-topbar { background: #fff !important; border-bottom: 1px solid #dbe6ec !important; box-shadow: none !important; color: #17374c !important; }
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-topbar-title,
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-topbar-context { color: #163b51 !important; }
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-topbar-eyebrow,
body.ec-app-body:has(.ec71-page) .ec-global-search kbd { display: none !important; }
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-global-search,
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-global-search--desktop { background: #f2f6f8 !important; border: 1px solid #e1e9ee !important; border-radius: 10px !important; box-shadow: none !important; }
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-global-search input { background: transparent !important; color: #143b53 !important; -webkit-text-fill-color:#143b53 !important; }
body.ec-app-body:has(.ec71-page) .ec-topbar .ec-global-search input::placeholder { color: #8ca1b1 !important; -webkit-text-fill-color: #8ca1b1 !important; }
body.ec-app-body:has(.ec71-page) .ec-sidebar-world-v7 { display: none !important; }
body.ec-app-body:has(.ec71-page) .ec-sidebar-footer { display: block !important; }
body.ec-app-body:has(.ec71-page) .ec-app-workspace,
body.ec-app-body:has(.ec71-page) .ec-main-content,
body.ec-app-body:has(.ec71-page) .ec-content,
body.ec-app-body:has(.ec71-page) main.ec-main { background: #f2f6f8 !important; }

.ec71-page,.ec71-page *, .ec71-page *::before, .ec71-page *::after { box-sizing: border-box; }
.ec71-page.ecp8,.ec71-page.eci9 { width:100%;max-width:1450px;margin:0 auto;padding:32px clamp(20px,3.1vw,48px) 48px;background:#f2f6f8!important; color:#17374c!important;min-height:calc(100vh - 60px);font-family: 'Instrument Sans', Inter, ui-sans-serif,system-ui,-apple-system,sans-serif;font-size:14px;line-height:1.5; }
.ec71-page :is(h1,h2,h3,h4) { color:#142f46!important;letter-spacing:-.024em;font-weight:760;margin:0;line-height:1.25; }
.ec71-page h1 {font-size:clamp(29px,2.4vw,40px)!important;line-height:1.14!important;margin:12px 0 9px!important;font-weight:790!important;}
.ec71-page h2 {font-size:19px!important;}
.ec71-page h3 {font-size:16px!important;}
.ec71-page :is(p,small) {color:#728a9a!important;line-height:1.5;}
.ec71-page p {font-size:13px;margin:5px 0;}
.ec71-page a {color:#008477!important;text-decoration:none;}
.ec71-page a:hover{text-decoration:underline;}
.ec71-page .ec71-eyebrow {display:flex;flex-wrap:wrap;align-items:center;gap:13px;color:#607c93!important;font-size:11px;font-weight:740;letter-spacing:.08em;text-transform:uppercase;}
.ec71-page .ec71-head-chip {background:#e0f5ef;color:#008577!important;letter-spacing:0;text-transform:none;font-size:11px;font-weight:690;border-radius:100px;padding:5px 11px;}
.ec71-page .ec71-hero {padding:2px 0 16px;}
.ec71-page .ec71-hero p {max-width:920px;font-size:14px;}
.ec71-page .ec71-actions{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin-top:16px;}
.ec71-page .ec71-btn {display:inline-flex;align-items:center;justify-content:center;min-height:40px;gap:8px;border-radius:9px;border:1px solid #d4e4ea;padding:10px 15px;font:inherit;font-weight:740;font-size:13px;text-decoration:none!important;white-space:normal;text-align:center;cursor:pointer;transition:background .15s,border-color .15s;}
.ec71-page .ec71-btn-primary{background:#008a7c!important;border-color:#008a7c!important;color:#fff!important;-webkit-text-fill-color:#fff!important;}
.ec71-page .ec71-btn-primary:hover{background:#006f68!important;border-color:#006f68!important;}
.ec71-page .ec71-btn-outline{background:#fff!important;border-color:#d9e5ec!important;color:#193d54!important;-webkit-text-fill-color:#193d54!important;}
.ec71-page .ec71-btn-outline:hover{background:#f2faf7!important;border-color:#81cab8!important;}
.ec71-page :is(button,input,select,textarea,a):focus-visible {outline:2px solid #12a899!important;outline-offset:2px;}
.ec71-page .ecm6-nav,.ec71-page .ecm7-nav {display:flex!important;align-items:center;gap:7px;flex-wrap:wrap;margin:0 0 18px!important;padding:5px!important;border:1px solid #dce8ee!important;border-radius:10px!important;background:#fff!important;}
.ec71-page .ecm6-nav > a,.ec71-page .ecm7-nav > a {min-height:34px;padding:8px 14px!important;background:#fff!important;border:0!important;border-radius:8px!important;font-size:12px!important;color:#4a6a7e!important;font-weight:700;text-decoration:none!important;box-shadow:none!important;}
.ec71-page .ecm6-nav > a.is-active,.ec71-page .ecm7-nav > a.is-active {background:#e4f6f1!important;color:#007f73!important;}
.ec71-page .ec69-more-options {margin-left:auto;position:relative;color:#193c52;font-size:12px;}
.ec71-page .ec69-more-options>summary {list-style:none;cursor:pointer;background:#fff!important;color:#4a687c!important;border:1px solid #dce8ee!important;padding:7px 12px!important;border-radius:8px;font-size:12px;font-weight:700;}
.ec71-page .ec69-more-options>summary::-webkit-details-marker{display:none;}
.ec71-page .ec69-more-options-panel {background:#fff!important;border:1px solid #dae6ec!important;border-radius:10px!important;box-shadow:0 12px 30px #173a521b!important;z-index:12!important;}
.ec71-page .ec69-more-options-panel a {background:#fff!important;color:#24495d!important;font-size:12px;}
.ec71-page .ec69-more-options-panel a:is(:hover,.is-active) {background:#eaf8f3!important;color:#007d71!important;}
.ec71-page .ec71-kpis {display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin:16px 0 17px;}
.ec71-page .ec71-kpi {display:flex;flex-direction:column;align-items:flex-start;gap:5px;border:1px solid #dce8ee;background:#fff;border-radius:12px;padding:19px 20px;min-height:122px;}
.ec71-page .ec71-kpi >span{font-size:12px;color:#70889a;font-weight:600;}
.ec71-page .ec71-kpi strong{color:#0d3048!important;font-size:clamp(26px,2.3vw,34px);line-height:1.1;font-weight:780;letter-spacing:-.05em;}
.ec71-page .ec71-kpi small{font-size:11px;}
.ec71-page .ec71-kpi .ec71-text-warning{color:#bd7516!important;}
.ec71-page .ec71-notice {display:flex;align-items:start;gap:12px;background:#eaf6f2;border:1px solid #d0ece4;border-radius:11px;padding:14px 17px;color:#176957;margin:14px 0 19px;}
.ec71-page .ec71-notice>div{min-width:0;}
.ec71-page .ec71-notice strong{display:block;color:#057669;font-size:13px;font-weight:740;}
.ec71-page .ec71-notice p{font-size:12px;color:#4e7b72!important;}
.ec71-page .ec71-notice-icon{border-radius:50%;background:#d2ebe2;color:#008579;display:grid;place-items:center;font-weight:800;width:22px;height:22px;flex:0 0 22px;}
.ec71-page .ec71-notice-warning{background:#fff4da;border-color:#ffead0;color:#855a1c;}
.ec71-page .ec71-notice-warning strong {color:#9c650e;}
.ec71-page .ec71-notice-warning p {color:#9b784e!important;}
.ec71-page .ec71-notice-warning .ec71-notice-icon{background:#f9e5af;color:#9c650e;}
.ec71-page .ec71-notice-error{background:#fff1f0;border-color:#ffd4d1;color:#b52e31;}
.ec71-page .ec71-notice-error strong,.ec71-page .ec71-notice-error p{color:#9f292c!important;}
.ec71-page .ec71-card {background:#fff!important;border:1px solid #dbe6ec!important;border-radius:13px;padding:22px 24px;margin:0 0 16px;min-width:0;box-shadow:none!important;}
.ec71-page .ec71-card-header{margin-bottom:18px;}
.ec71-page .ec71-card-header h2{margin:0 0 6px!important;}
.ec71-page .ec71-card-header p{margin:0;font-size:12px;}
.ec71-page .ec71-form {margin:0;}
.ec71-page .ec71-grid-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px 20px;}
.ec71-page .ec71-field{display:flex;flex-direction:column;align-items:stretch;gap:7px;min-width:0;margin:0 0 16px;color:#173e54!important;font-size:12px;font-weight:690;}
.ec71-page .ec71-field>span:first-child {font-size:12px;color:#1e4559!important;font-weight:700;}
.ec71-page .ec71-field input,.ec71-page .ec71-field textarea,.ec71-page .ec71-field select{width:100%;min-width:0;min-height:43px;border:1px solid #d6e4ed!important;background:#fff!important;color:#294a61!important;-webkit-text-fill-color:#294a61!important;color-scheme:light;border-radius:8px;padding:10px 12px!important;outline:none;box-shadow:none!important;font:inherit;font-size:13px;font-weight:500;}
.ec71-page .ec71-field input::placeholder,.ec71-page .ec71-field textarea::placeholder{color:#9aabba!important;-webkit-text-fill-color:#9aabba!important;}
.ec71-page .ec71-field textarea{resize:vertical;min-height:93px;line-height:1.55;}
.ec71-page .ec71-field small{font-size:11px;font-weight:480;}
.ec71-page .ec71-readonly-value{background:#f8fbfc;border:1px solid #dce6ec;border-radius:8px;min-height:43px;padding:11px 12px;color:#567588!important;font-size:12px;overflow-wrap:anywhere;font-weight:500;}
.ec71-page .ec71-review-option{min-width:0;display:flex;align-items:center;justify-content:space-between;gap:14px;border:1px solid #edf1f3;border-radius:10px;background:#f5f8f9;padding:13px 15px;margin:0 0 16px;cursor:pointer;}
.ec71-page .ec71-review-option>span{display:grid;gap:4px;min-width:0;}
.ec71-page .ec71-review-option strong{color:#21475b;font-size:12px;font-weight:690;}
.ec71-page .ec71-review-option small{font-size:11px;}
.ec71-page .ec71-review-option input[type=checkbox],.ec71-page .ec71-checkbox input[type=checkbox]{width:20px;height:20px;min-width:20px;accent-color:#008a7b;cursor:pointer;}
.ec71-page .ec71-inline-stats{display:flex;flex-wrap:wrap;gap:8px 20px;border-top:1px solid #e2eaee;padding-top:15px;font-size:12px;color:#6c8699;}
.ec71-page .ec71-inline-stats strong{color:#16475b;font-weight:750;}
.ec71-page .ec71-form-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:6px 0 18px;flex-wrap:wrap;}
.ec71-page .ec71-form-footer strong{font-size:12px;color:#174257;}
.ec71-page .ec71-page-tabs{display:flex;flex-wrap:wrap;gap:5px;margin:0 0 14px;border:0;}
.ec71-page .ec71-page-tabs a{border-radius:9px;background:transparent!important;color:#516b7f!important;padding:10px 14px;font-size:12px;font-weight:660;text-decoration:none!important;}
.ec71-page .ec71-page-tabs a:is(:hover,:focus-visible){background:#e0f5ef!important;color:#008577!important;}
.ec71-page .ec71-small-context{font-size:11px!important;margin:9px 0 18px!important;color:#688195!important;}
.ec71-page .ec71-table-scroll{width:100%;overflow-x:auto;border:1px solid #e0e9ef;border-radius:10px;}
.ec71-page .ec71-table{border-collapse:collapse;width:100%;min-width:690px;color:#25495f!important;font-size:12px;}
.ec71-page .ec71-table th{background:#f2f6f8!important;color:#6e879a!important;padding:14px 13px!important;font-size:12px;font-weight:690;text-align:left;white-space:nowrap;border-bottom:1px solid #dce7ed!important;}
.ec71-page .ec71-table td{background:#fff!important;color:#294c62!important;padding:13px!important;border-bottom:1px solid #e2eaf0!important;overflow-wrap:anywhere;}
.ec71-page .ec71-table tr:last-child td{border-bottom:0!important;}
.ec71-page .ec71-table td strong{color:#173f54!important;font-weight:700;}
.ec71-page .ec71-table td small{display:block;margin-top:3px;font-size:11px;}
.ec71-page .ec71-badge{display:inline-block;border:1px solid #c9e7df;background:#e4f6f1;color:#007d72;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:670;margin:2px;white-space:nowrap;}
.ec71-page .ec71-empty{text-align:center;color:#8198a6!important;padding:26px!important;font-size:12px;}
.ec71-page .ec71-segment-save{display:flex;align-items:end;flex-wrap:wrap;gap:10px;padding-top:16px;border-top:1px solid #e3ebef;margin-top:14px;}
.ec71-page .ec71-segment-save .ec71-field{flex:1 1 260px;margin:0;}
.ec71-page .ec71-checkbox{display:flex;align-items:flex-start;gap:9px;margin:8px 0 12px;font-size:12px;color:#35566a;line-height:1.5;cursor:pointer;}
.ec71-page .ec71-stacked-form{display:flex;flex-direction:column;gap:0;}
.ec71-page .ec71-stacked-form .ec71-btn{align-self:flex-start;margin-top:5px;}
.ec71-page .ec71-workflows{align-items:stretch;}
.ec71-page .ec71-workflows .ec71-card{height:100%;margin:0 0 16px;}
.ec71-page .ec71-plan-action{display:flex;flex-wrap:wrap;align-items:center;gap:7px;}
.ec71-page .ec71-plan-action .ec71-checkbox{margin:0;}
.ec71-page .ec71-hint{color:#9d7d54!important;font-size:12px;}
.ec71-page .ec71-btn:disabled{opacity:.5;cursor:not-allowed;}
.ec71-page .ec71-page-foot{color:#8ba0b0;font-size:11px;padding:10px 0 0;margin-top:10px;}
.ec71-page section[id]{scroll-margin-top:75px;}
@media(max-width:1110px){.ec71-page .ec71-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:750px){.ec71-page.ecp8,.ec71-page.eci9{padding:20px 15px 32px}.ec71-page .ec71-grid-two{grid-template-columns:1fr;gap:8px}.ec71-page .ec71-card{padding:19px 16px}.ec71-page .ec71-hero h1{font-size:30px!important}.ec71-page .ec71-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.ec71-page .ec71-kpi{padding:14px;min-height:108px}.ec71-page .ec71-kpi strong{font-size:27px}.ec71-page .ec71-segment-save{align-items:stretch}.ec71-page .ec71-segment-save button{width:100%}.ec71-page .ec71-review-option{margin-bottom:8px}}
@media(max-width:420px){.ec71-page .ec71-kpis{grid-template-columns:1fr;}}
@media(prefers-reduced-motion:reduce){.ec71-page *{transition:none!important;animation:none!important;}}
</style>
