<style>
/* Escopo exclusivo: não altera o design de Contatos nem de Campanhas. */
.wpr{--wpr-ink:#f4f5ff;--wpr-muted:#a9b4c8;--wpr-line:rgba(173,195,230,.13);--wpr-panel:#222944;--wpr-deep:#1b2239;--wpr-green:#43b9a7;max-width:1640px;margin:0 auto;padding:27px clamp(15px,2.8vw,43px) 65px;color:var(--wpr-ink);font-family:inherit}
.wpr *{box-sizing:border-box}
.wpr-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:23px}
.wpr-kicker,.wpr-eyebrow{font-size:10px;font-weight:800;letter-spacing:.135em;color:var(--wpr-green);text-transform:uppercase}
.wpr-kicker span{color:#67778d;margin:0 6px}
.wpr-heading h1{font-weight:800;font-size:clamp(24px,2.2vw,35px);letter-spacing:-.04em;margin:7px 0 6px}
.wpr-heading p:not(.wpr-kicker){color:var(--wpr-muted);font-size:13px;line-height:1.6;max-width:680px}
.wpr-button{display:inline-flex;justify-content:center;align-items:center;gap:9px;text-decoration:none;border:1px solid transparent;border-radius:9px;min-height:39px;padding:8px 15px;font-size:12px;font-weight:750;cursor:pointer;transition:.16s ease}
.wpr-button:disabled{opacity:.45;cursor:not-allowed}
.wpr-button-quiet{border-color:var(--wpr-line);color:#dfe7f5;background:rgba(255,255,255,.035)}
.wpr-button-quiet:hover{background:rgba(255,255,255,.08)}
.wpr-button-primary{background:var(--wpr-green);border-color:var(--wpr-green);color:#102b2e}
.wpr-button-primary:hover{filter:brightness(1.08)}
.wpr-hero{display:grid;grid-template-columns:minmax(0,1fr) 255px;overflow:hidden;border:1px solid rgba(77,209,187,.24);background:linear-gradient(116deg,#21364a 0%,#222b4b 66%,#283057 100%);border-radius:16px;margin-bottom:15px;box-shadow:0 20px 45px rgba(10,16,37,.1)}
.wpr-hero-main{padding:27px 32px}
.wpr-hero-eyebrow{font-size:10px;font-weight:800;letter-spacing:.12em;color:#84e5d5}
.wpr-hero h2{font-size:23px;letter-spacing:-.03em;font-weight:800;margin:11px 0 6px;color:#f8fcff}
.wpr-hero p{color:#c2d2df;max-width:690px;font-size:12px;line-height:1.7}
.wpr-progress-label{display:flex;justify-content:space-between;margin:20px 0 7px;max-width:640px;color:#dce9f2;font-size:11px}
.wpr-progress-label strong{color:#99eadb}
.wpr-progress{height:7px;max-width:640px;border-radius:12px;background:#3f5663;overflow:hidden}
.wpr-progress span{display:block;height:100%;border-radius:12px;background:linear-gradient(90deg,#35c4ac,#9ef0e0)}
.wpr-hero-main>small{display:block;margin-top:8px;font-size:10px;color:#a9c2ca}
.wpr-hero-side{padding:28px 22px;border-left:1px solid rgba(188,223,230,.15);display:flex;flex-direction:column;justify-content:center;align-items:flex-start;background:rgba(8,23,51,.17)}
.wpr-hero-side>span{font-size:10px;letter-spacing:.12em;color:#abc3d0;font-weight:800}
.wpr-hero-side>strong{font-size:42px;line-height:1.5;letter-spacing:-.06em;color:#fff;font-weight:800}
.wpr-hero-side>span:nth-of-type(2){letter-spacing:0;font-size:11px;font-weight:500}
.wpr-hero-sync{font-size:10px;margin-top:16px;color:#c4dddf;display:flex;align-items:center;gap:8px;line-height:1.45}
.wpr-sync-dot{width:7px;height:7px;background:#67dbc6;border-radius:50%;flex:0 0 auto}
.wpr-sync-dot-off{background:#d9a95a}
.wpr-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px;margin-bottom:14px}
.wpr-stat{text-align:left;background:var(--wpr-panel);border:1px solid var(--wpr-line);border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:7px;color:var(--wpr-ink);cursor:pointer;min-width:0}
.wpr-stat:hover,.wpr-stat:focus-visible{border-color:rgba(67,185,167,.65);outline:0;background:#273451}
.wpr-stat>span{font-size:11px;color:#b7c1d4;font-weight:700}
.wpr-stat strong{font-size:26px;letter-spacing:-.035em;line-height:1.2}
.wpr-stat small{font-size:10px;color:#8c9db4}
.wpr-stat-green strong{color:#7cdfce}
.wpr-info{display:flex;align-items:flex-start;gap:13px;background:rgba(66,137,161,.08);border:1px solid rgba(102,171,185,.22);padding:12px 16px;border-radius:10px;margin-bottom:17px}
.wpr-info-icon{width:19px;height:19px;flex:0 0 19px;border:1px solid #8bb9c0;border-radius:100%;text-align:center;font-size:12px;font-weight:700;color:#83d8cc}
.wpr-info p{font-size:11px;color:#bdd1d9;line-height:1.7}
.wpr-info strong{color:#e5f2f5}
.wpr-alert{background:rgba(47,183,137,.13);color:#c3f0df;border:1px solid rgba(47,183,137,.28);border-radius:9px;padding:10px 14px;font-size:12px;margin:10px 0}
.wpr-alert-error{background:rgba(210,81,81,.14);color:#f2bbbb;border-color:rgba(210,81,81,.32)}
.wpr-filters{background:var(--wpr-panel);border:1px solid var(--wpr-line);border-radius:12px;padding:16px;display:grid;grid-template-columns:minmax(230px,2fr) repeat(3,minmax(110px,1fr)) auto;align-items:end;gap:12px;margin-bottom:15px}
.wpr-filters label,.wpr-note-label{display:flex;flex-direction:column;gap:6px;min-width:0;font-weight:700;font-size:10px;color:#bdc9dd}
.wpr-filters input,.wpr-filters select,.wpr-note-label textarea{width:100%;min-width:0;background:#161e34;border:1px solid rgba(161,185,220,.22);border-radius:8px;padding:11px 12px;font-size:12px;color:#f3f8ff;outline:none}
.wpr-filters input::placeholder,.wpr-note-label textarea::placeholder{color:#7e90ad}
.wpr-filters input:focus,.wpr-filters select:focus,.wpr-note-label textarea:focus{border-color:var(--wpr-green);box-shadow:0 0 0 3px rgba(67,185,167,.13)}
.wpr-filters select{appearance:auto}
.wpr-workspace{display:grid;grid-template-columns:minmax(0,1.12fr) minmax(350px,.88fr);gap:15px;align-items:start}
.wpr-list-panel,.wpr-detail-panel{min-width:0;background:var(--wpr-panel);border:1px solid var(--wpr-line);border-radius:14px;overflow:hidden}
.wpr-panel-header{display:flex;justify-content:space-between;align-items:center;padding:20px 21px 16px;border-bottom:1px solid var(--wpr-line)}
.wpr-panel-header h2{font-size:15px;font-weight:800;margin-bottom:5px}
.wpr-panel-header p,.wpr-mini-label{font-size:11px;color:var(--wpr-muted)}
.wpr-mini-label{background:#313c59;padding:7px 10px;border-radius:8px}
.wpr-number{width:100%;display:flex;align-items:center;gap:12px;background:transparent;border:0;border-bottom:1px solid var(--wpr-line);padding:13px 17px;color:inherit;text-align:left;cursor:pointer}
.wpr-number:hover{background:rgba(255,255,255,.035)}
.wpr-number.is-active{background:rgba(67,185,167,.09);box-shadow:inset 3px 0 0 var(--wpr-green)}
.wpr-number-icon{display:grid;place-items:center;background:#313d59;color:#d2dfeb;border-radius:10px;flex:0 0 36px;width:36px;height:36px;font-size:17px}
.wpr-number.is-active .wpr-number-icon{background:#27544e;color:#88dccc}
.wpr-number-content{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px}
.wpr-number-content strong{font-size:12px;font-weight:800;letter-spacing:.003em}
.wpr-number-content small{font-size:10px;color:var(--wpr-muted)}
.wpr-number-chevron{color:#8d9eb7;font-size:21px}
.wpr-status{white-space:nowrap;font-size:10px;font-weight:800;padding:6px 9px;border-radius:100px;background:#39435e;color:#bcc8dc}
.wpr-status-yes{background:rgba(61,183,135,.15);color:#78dbaf}
.wpr-status-no{background:rgba(217,132,93,.16);color:#f0b599}
.wpr-pagination{display:flex;align-items:center;justify-content:space-between;padding:15px 17px;color:var(--wpr-muted);font-size:11px}
.wpr-pagination>div{display:flex;align-items:center;gap:10px}
.wpr-pagination button,.wpr-empty button{border:1px solid var(--wpr-line);background:#343e58;color:#f0f4fc;border-radius:7px;padding:7px 10px;cursor:pointer;font-size:11px}
.wpr-pagination button:disabled{opacity:.32;cursor:not-allowed}
.wpr-detail-panel{padding:22px 23px 25px;position:sticky;top:16px}
.wpr-detail-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.wpr-detail-panel>h2{font-size:26px;letter-spacing:-.045em;font-weight:800;overflow-wrap:anywhere}
.wpr-muted{font-size:11px;color:var(--wpr-muted);line-height:1.6}
.wpr-action-panel{background:#1a243c;border:1px solid var(--wpr-line);border-radius:11px;padding:18px;margin-top:19px}
.wpr-action-panel h3,.wpr-subhead h3{font-size:13px;font-weight:800}
.wpr-action-panel>p{font-size:11px;color:#a9bad2;line-height:1.6;margin:8px 0 14px}
.wpr-action-panel>a{width:100%;margin-bottom:16px}
.wpr-note-label{margin-bottom:12px}
.wpr-note-label small{color:#8b9db6;font-weight:500}
.wpr-note-label textarea{resize:vertical;min-height:67px}
.wpr-action-row{display:flex;gap:9px}
.wpr-action-row .wpr-button{flex:1;padding:8px 7px}
.wpr-button-positive{background:#1d5349;color:#a1e9d2;border-color:#326d5f}
.wpr-button-positive:hover{background:#226658}
.wpr-button-neutral{background:#3f3545;color:#f3c3bc;border-color:#624c56}
.wpr-button-neutral:hover{background:#533e4b}
.wpr-clear{display:block;margin:13px auto 0;background:transparent;border:0;color:#a0b8cf;text-decoration:underline;cursor:pointer;font-size:11px}
.wpr-warning{border:1px solid rgba(224,157,79,.32);background:rgba(224,157,79,.08);border-radius:11px;padding:17px;margin:18px 0;color:#f3d0a0}
.wpr-warning strong{font-size:12px}
.wpr-warning p{margin-top:7px;font-size:11px;line-height:1.65}
.wpr-last-verified{font-size:10px;color:#9cb8ba;margin:12px 0}
.wpr-linked,.wpr-history{border-top:1px solid var(--wpr-line);padding-top:18px;margin-top:20px}
.wpr-subhead{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px}
.wpr-subhead>span{border-radius:6px;background:#303c59;padding:5px 7px;font-size:10px;color:#c6d2e1}
.wpr-linked-row{display:flex;align-items:flex-start;gap:10px;padding:12px 0;border-bottom:1px solid rgba(173,195,230,.08)}
.wpr-origin{font-size:9px;color:#c7d0e0;background:#35415c;border-radius:5px;padding:5px 7px;white-space:nowrap;font-weight:800}
.wpr-origin-hubspot{color:#ffd5b3;background:#55412f}
.wpr-origin-manual{color:#cdbffb;background:#43385f}
.wpr-origin-receita{color:#9ed8cb;background:#294747}
.wpr-linked-row>div{display:flex;flex-direction:column;gap:4px;min-width:0}
.wpr-linked-row strong{font-size:11px;overflow-wrap:anywhere}
.wpr-linked-row small{font-size:10px;color:var(--wpr-muted);line-height:1.4}
.wpr-event{display:flex;gap:11px;margin-top:15px}
.wpr-event-point{width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:var(--wpr-green);margin-top:5px}
.wpr-event>div{display:flex;flex-direction:column;gap:4px}
.wpr-event strong{font-size:11px}
.wpr-event small{font-size:10px;color:var(--wpr-muted)}
.wpr-event p{font-size:11px;color:#bbc6d8;line-height:1.5;overflow-wrap:anywhere}
.wpr-empty{text-align:center;padding:42px 22px;color:#d0d9e7}
.wpr-empty strong{font-size:14px}
.wpr-empty p{font-size:11px;color:var(--wpr-muted);margin:9px 0 16px;line-height:1.65}
.wpr-is-loading{opacity:.65;transition:opacity .15s}
@media(max-width:1350px){.wpr-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.wpr-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.wpr-filters .wpr-search{grid-column:span 2}.wpr-workspace{grid-template-columns:minmax(0,1fr) minmax(330px,.92fr)}}
@media(max-width:900px){.wpr-hero{grid-template-columns:1fr}.wpr-hero-side{border-left:0;border-top:1px solid var(--wpr-line);padding:15px 24px}.wpr-hero-side>strong{font-size:28px}.wpr-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.wpr-workspace{grid-template-columns:1fr}.wpr-detail-panel{position:static}.wpr-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.wpr-filters .wpr-search{grid-column:span 2}}
@media(max-width:560px){.wpr{padding:16px 12px 40px}.wpr-heading{align-items:flex-start;flex-direction:column}.wpr-hero-main{padding:22px 18px}.wpr-hero h2{font-size:19px}.wpr-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.wpr-stat{padding:12px}.wpr-stat strong{font-size:21px}.wpr-filters{gap:9px}.wpr-filters label:not(.wpr-search){grid-column:span 1}.wpr-number{gap:8px;padding:12px 10px}.wpr-number-icon{flex-basis:29px;width:29px;height:29px}.wpr-status{font-size:9px;padding:5px}.wpr-detail-panel{padding:18px 15px}.wpr-action-row{flex-direction:column}}
</style>
