<style>
/*
 * ExportControl Prospector — WhatsApp Registry V2 (visual somente).
 * Carregado DEPOIS dos estilos originais V1.
 * Todas as regras ficam dentro de .wpr-v2: não alteram sidebar,
 * Contatos, Leads, Campanhas ou outros módulos.
 */
.wpr.wpr-v2 {
    --wpr-ink: #102f47;
    --wpr-muted: #70869a;
    --wpr-line: #dbe7ed;
    --wpr-panel: #fff;
    --wpr-deep: #f5f9fb;
    --wpr-green: #008f84;
    --wpr-teal-wash: #eaf8f4;
    --wpr-focus: rgba(0,143,132,.14);
    width: 100%;
    max-width: 1510px;
    margin: 0 auto;
    padding: 35px clamp(18px, 3.2vw, 47px) 70px;
    color: var(--wpr-ink);
    background: transparent;
    font-family: inherit;
    font-size: 13px;
}
.wpr-v2, .wpr-v2 button, .wpr-v2 input, .wpr-v2 select, .wpr-v2 textarea { font-family: inherit; }
.wpr-v2 a, .wpr-v2 button { -webkit-tap-highlight-color: transparent; }
.wpr-v2 button:focus-visible, .wpr-v2 a:focus-visible {
    outline: 3px solid var(--wpr-focus);
    outline-offset: 2px;
}
/* Cabeçalho alinhado aos módulos Campanhas / Contatos */
.wpr-v2 .wpr-heading { align-items: center; gap: 22px; margin-bottom: 26px; }
.wpr-v2 .wpr-kicker, .wpr-v2 .wpr-eyebrow { color: #007e78; font-size: 10px; font-weight: 800; letter-spacing: .1em; }
.wpr-v2 .wpr-kicker span { color: #93a8b4; margin: 0 6px; }
.wpr-v2 .wpr-heading h1 {
    color: #082b46;
    font-size: clamp(27px, 2.3vw, 34px);
    letter-spacing: -.045em;
    line-height: 1.18;
    font-weight: 760;
    margin: 8px 0 7px;
}
.wpr-v2 .wpr-heading p:not(.wpr-kicker) {
    color: #74899b;
    font-size: 13px;
    line-height: 1.55;
    max-width: 750px;
}
.wpr-v2 .wpr-button { border-radius: 8px; min-height: 39px; padding: 9px 15px; font-size: 12px; font-weight: 700; box-shadow: none; }
.wpr-v2 .wpr-button-quiet {
    color: #15415b; background: #fff; border: 1px solid #d1e0e7;
}
.wpr-v2 .wpr-button-quiet:hover { background: #f5fafb; border-color: #aebfcb; }
.wpr-v2 .wpr-button-primary {
    color: #fff; background: #008e83; border: 1px solid #008e83;
}
.wpr-v2 .wpr-button-primary:hover { background: #00786f; border-color: #00786f; filter: none; }
/* Resumo: fundo branco, bordas leves e barra de progresso teal */
.wpr-v2 .wpr-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 244px;
    background: #fff;
    border: 1px solid #d7e6e7;
    box-shadow: 0 4px 16px rgba(14, 55, 65, .035);
    border-radius: 13px;
    overflow: hidden;
    margin-bottom: 13px;
}
.wpr-v2 .wpr-hero-main {
    background: linear-gradient(115deg, #edf9f6 0%, #f8fdfb 53%, #fff 100%);
    padding: 22px 27px 20px;
}
.wpr-v2 .wpr-hero-eyebrow { color: #007f75; font-size: 10px; letter-spacing: .10em; }
.wpr-v2 .wpr-hero h2 { color: #103d50; font-size: 18px; font-weight: 770; line-height: 1.25; margin: 8px 0 6px; letter-spacing: -.025em; }
.wpr-v2 .wpr-hero p { color: #638094; font-size: 11.5px; line-height: 1.62; }
.wpr-v2 .wpr-progress-label { color: #365e67; font-size: 11px; max-width: 800px; margin: 17px 0 7px; }
.wpr-v2 .wpr-progress-label strong { color: #007e73; font-size: 12px; }
.wpr-v2 .wpr-progress { max-width: 800px; height: 7px; background: #dcece9; border-radius: 999px; }
.wpr-v2 .wpr-progress span { background: #1eaf9c; border-radius: 999px; }
.wpr-v2 .wpr-hero-main>small { color: #8095a2; font-size: 10px; line-height: 1.4; }
.wpr-v2 .wpr-hero-side {
    border-left: 1px solid #e3edf0;
    background: #fcfefe;
    padding: 21px 23px;
    justify-content: center;
}
.wpr-v2 .wpr-hero-side>span { color: #78909f; font-size: 10px; letter-spacing: .07em; }
.wpr-v2 .wpr-hero-side>strong { color: #073b54; font-size: 36px; line-height: 1.2; margin: 8px 0 6px; letter-spacing: -.04em; }
.wpr-v2 .wpr-hero-side>span:nth-of-type(2) { color: #788d9f; font-size: 11px; letter-spacing: normal; }
.wpr-v2 .wpr-hero-sync { color: #507c85; font-size: 10px; line-height: 1.55; margin-top: 14px; }
.wpr-v2 .wpr-sync-dot { background: #12ae97; width: 7px; height: 7px; }
.wpr-v2 .wpr-sync-dot-off { background: #cfa161; }
/* KPIs: cards equivalentes aos indicadores das Campanhas */
.wpr-v2 .wpr-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 11px; margin-bottom: 16px; }
.wpr-v2 .wpr-stat {
    background: #fff;
    color: #133b53;
    text-align: left;
    border: 1px solid #dbe6ec;
    border-radius: 11px;
    padding: 16px 17px 15px;
    min-height: 108px;
    gap: 4px;
    box-shadow: none;
    transition: border-color .15s, background-color .15s, box-shadow .15s;
}
.wpr-v2 .wpr-stat>span { color: #71889a; font-size: 11px; font-weight: 650; }
.wpr-v2 .wpr-stat strong { color: #0c3351; font-size: 27px; font-weight: 760; margin: 2px 0; line-height: 1.1; }
.wpr-v2 .wpr-stat small { color: #8297a8; font-size: 10px; line-height: 1.45; }
.wpr-v2 .wpr-stat-green strong { color: #00887d; }
.wpr-v2 .wpr-stat:hover, .wpr-v2 .wpr-stat:focus-visible { background: #f7fcfa; border-color: #80c8bc; }
.wpr-v2 .wpr-stat.is-active { background: #f0faf6; border-color: #4db9aa; box-shadow: inset 0 3px 0 #1ca493; }
/* Mensagem informativa: não compete visualmente com as ações */
.wpr-v2 .wpr-info {
    background: #f3f9fb;
    color: #597588;
    border: 1px solid #dcebf0;
    border-radius: 9px;
    padding: 12px 15px;
    gap: 11px;
    margin: 0 0 17px;
    align-items: center;
}
.wpr-v2 .wpr-info-icon {
    border-color: #b3d0dc; background: #fff; color: #23899c;
    font-size: 11px; line-height: 17px;
}
.wpr-v2 .wpr-info p, .wpr-v2 .wpr-info strong { color: #5d7889; font-size: 11px; line-height: 1.6; }
.wpr-v2 .wpr-info strong { color: #164760; }
.wpr-v2 .wpr-alert { color: #146e53; background: #eaf9ef; border: 1px solid #b5e4c8; font-size: 12px; }
.wpr-v2 .wpr-alert-error { color: #a3413c; background: #fff3f2; border-color: #f0c5bd; }
/* Atalhos do fluxo: nova UI do V2 */
.wpr-v2 .wpr-shortcuts {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; flex-wrap: wrap; margin-bottom: 11px;
}
.wpr-v2 .wpr-shortcuts-title {
    display: flex; align-items: center; gap: 8px;
    color: #385c71; font-size: 11px; font-weight: 800; letter-spacing: .01em;
}
.wpr-v2 .wpr-shortcuts-title::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #20ae9c; }
.wpr-v2 .wpr-shortcuts-list { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; }
.wpr-v2 .wpr-shortcut {
    border: 1px solid #d7e6e9; background: #fff; color: #597588;
    border-radius: 8px; padding: 8px 12px; font-size: 11px; font-weight: 690;
    cursor: pointer; transition: .15s ease;
}
.wpr-v2 .wpr-shortcut:hover { background: #f3faf8; border-color: #83c9bd; color: #08776e; }
.wpr-v2 .wpr-shortcut.is-active { background: #e7f6f0; border-color: #8fd4c6; color: #007c70; }
/* Filtros claros, com dimensões próximas aos filtros da Central de Contatos */
.wpr-v2 .wpr-filters {
    display: grid;
    grid-template-columns: minmax(250px, 2.1fr) repeat(3, minmax(105px, 1fr)) auto;
    align-items: end; gap: 12px;
    background: #fff; border: 1px solid #dce7ed; border-radius: 11px;
    padding: 15px 17px; margin-bottom: 15px;
}
.wpr-v2 .wpr-filters label, .wpr-v2 .wpr-note-label { color: #5d788b; font-size: 10.5px; font-weight: 680; gap: 7px; }
.wpr-v2 .wpr-filters input, .wpr-v2 .wpr-filters select, .wpr-v2 .wpr-note-label textarea {
    min-height: 40px; width: 100%; color: #153952; background: #fff;
    border: 1px solid #d5e2e9; border-radius: 8px; padding: 10px 12px;
    font-size: 12px; line-height: 1.45; box-shadow: none;
}
.wpr-v2 .wpr-filters input::placeholder, .wpr-v2 .wpr-note-label textarea::placeholder { color: #9badb8; }
.wpr-v2 .wpr-filters input:focus, .wpr-v2 .wpr-filters select:focus, .wpr-v2 .wpr-note-label textarea:focus {
    border-color: #4cbbac; box-shadow: 0 0 0 3px rgba(32,175,154,.12);
}
.wpr-v2 .wpr-filters .wpr-button { min-height: 40px; }
/* Duas colunas: fila + inspeção, ambos brancos */
.wpr-v2 .wpr-workspace { grid-template-columns: minmax(0, 1.10fr) minmax(355px, .90fr); align-items: start; gap: 15px; }
.wpr-v2 .wpr-list-panel, .wpr-v2 .wpr-detail-panel {
    background: #fff; border: 1px solid #dbe7ed;
    color: #103850; border-radius: 12px; box-shadow: 0 2px 10px rgba(13, 54, 75, .018);
}
.wpr-v2 .wpr-panel-header { background: #fbfdfd; border-bottom: 1px solid #e2ebef; padding: 18px 20px 17px; }
.wpr-v2 .wpr-panel-header h2 { color: #0e344b; font-size: 15px; font-weight: 770; margin-bottom: 4px; }
.wpr-v2 .wpr-panel-header p { color: #7b90a0; font-size: 11px; }
.wpr-v2 .wpr-mini-label { color: #5c798a; background: #edf4f7; font-size: 10px; font-weight: 650; border-radius: 7px; }
.wpr-v2 .wpr-number-list { background: #fff; }
.wpr-v2 .wpr-number {
    color: #143a53; background: #fff; border-bottom: 1px solid #edf2f4;
    padding: 15px 17px; gap: 12px; min-height: 68px; transition: background .12s ease;
}
.wpr-v2 .wpr-number:hover { background: #f7fbfa; }
.wpr-v2 .wpr-number.is-active { background: #eaf7f3; box-shadow: inset 3px 0 0 #0aa293; }
.wpr-v2 .wpr-number-icon {
    background: #eff5f8; color: #5a8297; border: 1px solid #e4eef1;
    width: 37px; height: 37px; flex: 0 0 37px; border-radius: 10px; font-size: 16px;
}
.wpr-v2 .wpr-number.is-active .wpr-number-icon { background: #d1eee5; color: #008679; border-color: #b5dfd4; }
.wpr-v2 .wpr-number-content strong { color: #113b55; font-size: 12px; font-weight: 760; letter-spacing: 0; }
.wpr-v2 .wpr-number-content small { color: #8497a7; font-size: 10px; line-height: 1.45; }
.wpr-v2 .wpr-number-chevron { color: #a0b2bf; font-size: 19px; }
.wpr-v2 .wpr-status {
    color: #65798e; background: #eff3f7;
    border: 1px solid #e4eaf0; font-size: 10px; font-weight: 690;
    padding: 5px 10px; border-radius: 999px;
}
.wpr-v2 .wpr-status-yes { color: #007d58; border-color: #ccebdc; background: #e8f7ee; }
.wpr-v2 .wpr-status-no { color: #b15f42; border-color: #f1d6cb; background: #fff0e9; }
.wpr-v2 .wpr-pagination {
    background: #fcfefe; border-top: 1px solid #e8eff2;
    color: #748a9a; font-size: 11px; padding: 14px 18px;
}
.wpr-v2 .wpr-pagination button, .wpr-v2 .wpr-empty button {
    background: #fff; color: #1d4b62; border: 1px solid #d8e4ea;
    border-radius: 7px; padding: 8px 12px; font-size: 11px; font-weight: 680;
}
.wpr-v2 .wpr-pagination button:hover:not(:disabled), .wpr-v2 .wpr-empty button:hover { border-color: #83c9bd; color: #007e74; background: #f6fbf9; }
.wpr-v2 .wpr-pagination button:disabled { opacity: .45; background: #f8fafb; }
/* Detalhes: a principal área operacional, em vez de um bloco escuro */
.wpr-v2 .wpr-detail-panel { padding: 21px 22px 25px; top: 18px; }
.wpr-v2 .wpr-detail-top { gap: 10px; align-items: center; margin-bottom: 9px; }
.wpr-v2 .wpr-detail-panel>h2 {
    color: #082e47; font-size: clamp(21px, 1.8vw, 26px);
    font-weight: 770; line-height: 1.15; letter-spacing: -.035em; margin: 0 0 7px;
}
.wpr-v2 .wpr-muted { color: #7b91a1; font-size: 11px; line-height: 1.65; }
.wpr-v2 .wpr-action-panel {
    color: #153c53; background: #f8fcfa; border: 1px solid #d7e9e2;
    border-radius: 11px; padding: 19px; margin-top: 18px;
}
.wpr-v2 .wpr-action-panel h3, .wpr-v2 .wpr-subhead h3 { color: #143a52; font-size: 13px; font-weight: 780; }
.wpr-v2 .wpr-action-panel>p { color: #738b9a; font-size: 11px; line-height: 1.65; margin: 7px 0 15px; }
.wpr-v2 .wpr-action-panel>a { width: 100%; min-height: 43px; margin-bottom: 17px; }
.wpr-v2 .wpr-note-label { margin-bottom: 12px; }
.wpr-v2 .wpr-note-label small { color: #8b9eaa; font-size: 10px; font-weight: 500; }
.wpr-v2 .wpr-note-label textarea {
    resize: vertical; min-height: 77px; max-height: 180px; background: #fff;
    border-color: #d7e4e8;
}
.wpr-v2 .wpr-action-row { gap: 10px; }
.wpr-v2 .wpr-action-row .wpr-button { flex: 1; padding: 10px; min-height: 40px; white-space: nowrap; }
.wpr-v2 .wpr-button-positive { color: #087858; background: #e7f7ee; border: 1px solid #b9e5cb; }
.wpr-v2 .wpr-button-positive:hover { background: #d9f2e5; border-color: #94d6b1; }
.wpr-v2 .wpr-button-neutral { color: #a65a51; background: #fff5f3; border: 1px solid #f1d4cf; }
.wpr-v2 .wpr-button-neutral:hover { background: #ffece8; border-color: #e9bbb2; }
.wpr-v2 .wpr-clear { color: #607f91; text-decoration: underline; text-underline-offset: 3px; font-size: 11px; margin-top: 13px; }
.wpr-v2 .wpr-clear:hover { color: #007c75; }
.wpr-v2 .wpr-warning { background: #fff9eb; border: 1px solid #f0dfa5; color: #9c7022; border-radius: 9px; padding: 17px; }
.wpr-v2 .wpr-warning strong { color: #92681c; }
.wpr-v2 .wpr-warning p { color: #a17f43; line-height: 1.6; }
.wpr-v2 .wpr-last-verified { color: #678d82; font-size: 10px; margin: 12px 0; }
.wpr-v2 .wpr-linked, .wpr-v2 .wpr-history { border-top: 1px solid #e5edf0; padding-top: 18px; margin-top: 20px; }
.wpr-v2 .wpr-subhead { margin-bottom: 8px; }
.wpr-v2 .wpr-subhead>span { color: #637f91; background: #f0f5f8; border-radius: 7px; font-size: 10px; padding: 5px 8px; }
.wpr-v2 .wpr-linked-row { border-bottom: 1px solid #edf2f4; padding: 12px 0; gap: 10px; align-items: center; }
.wpr-v2 .wpr-linked-row strong { color: #1c4358; font-size: 11px; font-weight: 730; }
.wpr-v2 .wpr-linked-row small { color: #879baa; font-size: 10px; }
.wpr-v2 .wpr-origin { color: #647a8d; background: #f0f4f7; border: 1px solid #e8eef1; font-size: 9px; border-radius: 5px; }
.wpr-v2 .wpr-origin-hubspot { color: #ab631a; background: #fff5e6; border-color: #f8e8d2; }
.wpr-v2 .wpr-origin-manual { color: #7554a5; background: #f6f0ff; border-color: #ebe0fa; }
.wpr-v2 .wpr-origin-receita { color: #2f807b; background: #eaf7f4; border-color: #d5ece7; }
.wpr-v2 .wpr-event { margin-top: 13px; gap: 12px; }
.wpr-v2 .wpr-event-point { background: #1ba995; border: 2px solid #d6f2e8; width: 9px; height: 9px; flex: 0 0 9px; }
.wpr-v2 .wpr-event strong { color: #24475a; font-size: 11px; }
.wpr-v2 .wpr-event small, .wpr-v2 .wpr-event p { color: #8599a8; font-size: 10px; }
.wpr-v2 .wpr-empty { color: #3f6073; padding: 50px 22px; }
.wpr-v2 .wpr-empty strong { color: #143d55; font-size: 14px; }
.wpr-v2 .wpr-empty p { color: #7b92a2; font-size: 11px; }
.wpr-v2 .wpr-is-loading { opacity: .55; }
@media (min-width: 1100px) {
    .wpr-v2 .wpr-number-list { max-height: 620px; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; }
    .wpr-v2 .wpr-detail-panel { position: sticky; top: 18px; max-height: calc(100vh - 38px); overflow-y: auto; scrollbar-width: thin; }
}
@media (max-width: 1380px) {
    .wpr-v2 .wpr-stats { grid-template-columns: repeat(3,minmax(0,1fr)); }
    .wpr-v2 .wpr-filters { grid-template-columns: minmax(200px,2fr) repeat(2,minmax(120px,1fr)); }
    .wpr-v2 .wpr-filters .wpr-search { grid-column: span 2; }
    .wpr-v2 .wpr-workspace { grid-template-columns: minmax(0,1fr) minmax(330px,.92fr); }
}
@media (max-width: 1040px) {
    .wpr-v2 .wpr-workspace { grid-template-columns: 1fr; }
    .wpr-v2 .wpr-detail-panel { position: static; max-height: none; overflow: visible; }
    .wpr-v2 .wpr-hero { grid-template-columns: minmax(0, 1fr) 210px; }
    .wpr-v2 .wpr-stats { grid-template-columns: repeat(3,minmax(0,1fr)); }
}
@media (max-width: 700px) {
    .wpr.wpr-v2 { padding: 20px 14px 40px; }
    .wpr-v2 .wpr-heading { align-items: flex-start; flex-direction: column; gap: 13px; }
    .wpr-v2 .wpr-heading h1 { font-size: 26px; }
    .wpr-v2 .wpr-hero { grid-template-columns: 1fr; }
    .wpr-v2 .wpr-hero-main { padding: 20px 18px; }
    .wpr-v2 .wpr-hero-side { border-left: 0; border-top: 1px solid #e3edf0; padding: 17px 18px; }
    .wpr-v2 .wpr-hero-side>strong { font-size: 28px; }
    .wpr-v2 .wpr-stats { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 9px; }
    .wpr-v2 .wpr-stat { min-height: 96px; padding: 13px; }
    .wpr-v2 .wpr-stat strong { font-size: 24px; }
    .wpr-v2 .wpr-filters { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; }
    .wpr-v2 .wpr-filters .wpr-search { grid-column: span 2; }
    .wpr-v2 .wpr-filters .wpr-button { grid-column: span 2; }
    .wpr-v2 .wpr-shortcuts { align-items: flex-start; flex-direction: column; }
    .wpr-v2 .wpr-shortcuts-list { width: 100%; }
    .wpr-v2 .wpr-shortcut { flex: 1; }
    .wpr-v2 .wpr-number { gap: 9px; padding: 12px; }
    .wpr-v2 .wpr-number-icon { width: 32px; height: 32px; flex-basis: 32px; }
    .wpr-v2 .wpr-detail-panel { padding: 18px 16px; }
}
@media (max-width: 440px) {
    .wpr-v2 .wpr-stat strong { font-size: 21px; }
    .wpr-v2 .wpr-action-row { flex-direction: column; }
    .wpr-v2 .wpr-pagination { gap: 10px; flex-direction: column; align-items: flex-start; }
    .wpr-v2 .wpr-status { font-size: 9px; padding: 5px 7px; }
}
</style>
