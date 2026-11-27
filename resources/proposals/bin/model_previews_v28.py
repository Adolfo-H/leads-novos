#!/usr/bin/env python3
"""Render blank, non-issued PDFs from the actual proposal HTML sources. No CRM or DB access."""
import argparse, hashlib, json, re, shutil, tempfile, os
from pathlib import Path
from html import escape
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright
try:
    import pymupdf as fitz
except ImportError:
    import fitz  # Compatibility with older PyMuPDF releases.
from quote import core
from render_v27 import html_for, table, NEW

LEGACY = {'LICENCIAMENTO': ('10_MODELO_PROPOSTA_LICENCIAMENTO.html','06_PRECOS_LICENCIAMENTO.json','LIC'),
          'ACOMPANHAMENTO': ('12_MODELO_PROPOSTA_ACOMPANHAMENTO.html','07_PRECOS_ACOMPANHAMENTO.json','AC'),
          'RETROATIVAS': ('14_MODELO_PROPOSTA_RETROATIVAS.html','08_PRECOS_RETROATIVAS.json','RET')}
LABELS={'LICENCIAMENTO':'Licenciamento da Plataforma','ACOMPANHAMENTO':'Acompanhamento Especializado','RETROATIVAS':'Consultas Retroativas','DFE':'Captura e Gestão de DF-e','NFSE':'Captura e Gestão de NFS-e'}
MODEL='MODELO · NÃO EMITIDO'
DASH='—'
MONEY='R$ —'
TRANSPARENT='data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'


def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()

def legacy_html(code, source):
    filename, config, prefix=LEGACY[code]
    raw=(source/filename).read_text(encoding='utf-8')
    cfg=json.loads((source/config).read_text(encoding='utf-8'))
    bounds={f'{prefix}_F{n}_{key}': ('Sem limite' if value is None else str(value)) for n,t in enumerate(cfg['faixas'],1) for key,value in [('MIN',t['min']),('MAX',t['max'])]}
    def replace(m):
        k=m[1]
        if k=='LOGO_EXPORTCONTROL_DATA_URI':return core.data_uri(source/'18_LOGO_EXPORTCONTROL.jpg')
        if k=='LOGO_CLIENTE_DATA_URI':return TRANSPARENT
        if k in ('STATUS_DOCUMENTO','STATUS_PROPOSTA'):return MODEL
        if k.startswith('ID_PROPOSTA') and not k.endswith('_RELACIONADA'):return 'MODELO_'+code
        if k=='RAZAO_SOCIAL_CLIENTE':return '[Razão social]'
        if k.startswith('RESPONSAVEL_COMERCIAL'):return DASH
        if k=='BLOCO_IMPLANTACAO_RETRO_SEM_LICENCIAMENTO':return '<div class="callout"><strong>Implantação condicional:</strong> [valor e condição conforme licenciamento]</div>'
        if k=='OBSERVACOES_COMERCIAIS_HTML':return ''
        if k in bounds:return bounds[k]
        if 'ALIQ' in k:return '—%'
        if 'VALOR' in k or k.endswith(('_FIXO_LIQUIDO','_UNIT_LIQUIDO','_SUBTOTAL_LIQUIDO')):return MONEY
        return DASH
    return re.sub(r'\{\{([A-Z0-9_]+)\}\}',replace,raw)


def additional_html(code, source):
    cfg=json.loads((source/('27_PRECOS_DFE.json' if code=='DFE' else '28_PRECOS_NFSE.json')).read_text(encoding='utf-8'))
    tiers=[{**t,'applied': 1 if i==0 else 0,'subtotal':'0.00'} for i,t in enumerate(cfg['faixas'])]
    rates={k:'0' for k in ('ISS','PIS','COFINS','IBS','CBS')}
    item={'net':'0','final':'0','cnpj_count':1,'monthly_average':1,'annual_total':12,'tiers':tiers,'taxes':rates}
    quote={'items':{code:item},'pricing_config':{'prices':{code:cfg}},'tax_rates':rates}
    # All values below are ephemeral placeholders; the preview branch replaces them before rendering.
    data={'cliente':{'razao_social':'','logo':str(source/'18_LOGO_EXPORTCONTROL.jpg'),'responsavel_principal':{'nome':'','email':''}},'condicoes':{'validade_dias':30,'pagamento_dias':10,'responsavel_comercial':'','observacoes':'','revisao':''},'data_emissao':'2000-01-01','data_base_precificacao':'2000-01-01','numeros':{code:1}}
    _, html=html_for(code,data,quote,source,preview=True)
    return html


def clip_cover_ornaments(soup):
    """Isolate only the original, deliberately off-page decorative curves.

    The official Clean Teal cover has a wave below the A4 boundary. Its parent
    already clips it for printing, but Chromium still counts it in scrollHeight.
    Put those empty, absolute-positioned shapes inside a page-sized clipping
    layer, preserving the foreground and the original visual geometry.
    Never wrap text, tables, logos, the footer or any content-bearing element.
    """
    ornament_classes={'cover-curve','cover-watermark','cover-wave'}
    for section in soup.select('section.cover-page'):
        ornaments=[child for child in section.find_all('div',recursive=False)
                   if ornament_classes.intersection(child.get('class',[]))]
        if not ornaments:
            continue
        if any(set(node.get('class',[]))-ornament_classes or node.get_text(strip=True)
               or node.find(True) is not None for node in ornaments):
            raise ValueError('Elemento decorativo inesperado na capa; não será ocultado.')
        clip=soup.new_tag('div',attrs={'class':'v28-cover-decoration-clip','aria-hidden':'true'})
        ornaments[0].insert_before(clip)
        for node in ornaments:
            clip.append(node.extract())


def blank_html(code,source):
    raw=legacy_html(code,source) if code in LEGACY else additional_html(code,source)
    if re.search(r'\{\{[A-Z0-9_]+\}\}',raw):raise ValueError('Marcadores restantes: '+code)
    soup=BeautifulSoup(raw,'html.parser')
    for node in soup.select('.cover-logo-client, img.client-logo'): node.decompose()
    clip_cover_ornaments(soup)
    for section in soup.select('section.cover-page, section.proposal-page, section.page'):
        badge=soup.new_tag('div');badge['class']='v28-preview-stamp';badge.string='MODELO · SEM VALIDADE COMERCIAL';section.append(badge)
    style=soup.new_tag('style');style.string='''section.cover-page > .v28-cover-decoration-clip{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:1} @media print {.cover-page .v28-preview-stamp,.proposal-page .v28-preview-stamp{bottom:1mm}.v28-preview-stamp{position:absolute;right:16mm;bottom:9mm;font:700 7px Arial,sans-serif;color:#627c78;letter-spacing:.4px;background:#fff;padding:2px 4px;border:1px solid #c8dfdb;border-radius:3px;z-index:20}}''';soup.head.append(style)
    pages=len(soup.select('section.cover-page, section.proposal-page, section.page'))
    if pages < 2:raise ValueError('Modelo sem páginas reconhecidas: '+code)
    return str(soup), pages


def render(html, pdf, pages):
    exe=next((shutil.which(x) for x in ('chromium','chromium-browser','google-chrome') if shutil.which(x)),None)
    if not exe:raise RuntimeError('Chromium não encontrado no Sail')
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=exe,args=['--no-sandbox'])
        try:
            page=browser.new_page(viewport={'width':1240,'height':1754},device_scale_factor=1)
            page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(('data:','about:')) else route.abort())
            page.set_content(html,wait_until='load');page.emulate_media(media='print');page.evaluate('document.fonts.ready')
            bad=page.eval_on_selector_all('section.cover-page,section.proposal-page,section.page','els=>els.map((x,i)=>({i:i+1,overflow:x.scrollHeight-x.clientHeight,overflowX:x.scrollWidth-x.clientWidth})).filter(x=>x.overflow>3||x.overflowX>3)')
            if bad:raise RuntimeError('Overflow no modelo: '+str(bad))
            page.pdf(path=str(pdf),print_background=True,prefer_css_page_size=True,margin={'top':'0','bottom':'0','left':'0','right':'0'})
        finally:browser.close()
    with fitz.open(pdf) as doc:
        if len(doc)!=pages:raise ValueError(f'Número de páginas inesperado: {pdf.name}: {len(doc)} != {pages}')
        for p in doc:
            t=p.get_text()
            if 'MODELO' not in t or 'SEM VALIDADE COMERCIAL' not in t or len(t.strip())<40:raise ValueError('Página sem marca de modelo: '+pdf.name)
            if re.search(r'MM Cereais|Bela Cereais|PROPOSTA_COMERCIAL_EC_2000|1 de janeiro de 2000',t,re.I):raise ValueError('Dados de simulação vazaram: '+pdf.name)


def generate(source,out, codes=None):
    source=source.resolve();out.mkdir(parents=True,exist_ok=True)
    names={**{k:v[0] for k,v in LEGACY.items()},**NEW}
    codes=codes or list(names)
    manifest={}
    with tempfile.TemporaryDirectory(prefix='.v28-',dir=out) as tmp:
        stage=Path(tmp)
        for code in codes:
            html,pages=blank_html(code,source);dest=stage/(code+'.pdf')
            try:
                render(html,dest,pages)
            except Exception as exc:
                raise RuntimeError(f'{code}: {exc}') from exc
            manifest[code]={'template':names[code],'source_sha256':digest(source/names[code]),'pdf_sha256':digest(dest),'pages':pages}
            print(f'{code}: {pages} páginas · modelo sem dados do cliente',flush=True)
        (stage/'manifest.json').write_text(json.dumps(manifest,indent=2,ensure_ascii=False),encoding='utf-8')
        for code in codes:os.replace(stage/(code+'.pdf'),out/(code+'.pdf'))
        os.replace(stage/'manifest.json',out/'manifest.json')
    return manifest

if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--source',type=Path,required=True);ap.add_argument('--out',type=Path,required=True);a=ap.parse_args();generate(a.source,a.out)
