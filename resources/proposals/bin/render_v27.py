#!/usr/bin/env python3
# EC_PROPOSALS_V28
"""Render additional services from the frozen quote; delegate original services unchanged."""
import argparse, json, re, shutil, subprocess, sys
from pathlib import Path
from html import escape
from decimal import Decimal as D
from quote import core, SERVICES as LEGACY

NEW = {'DFE':'27_MODELO_PROPOSTA_DFE.html','NFSE':'28_MODELO_PROPOSTA_NFSE.html'}

def e(v): return escape(str(v or ''), quote=True)
def money(v): return core.money(v)
def num(v): return core.int_br(v)
def grid(a,b): return '<div class="grid"><div class="box">'+a+'</div><div class="box">'+b+'</div></div>'
def listing(title, rows): return '<h2>'+e(title)+'</h2><ul>'+''.join('<li>'+e(x)+'</li>' for x in rows)+'</ul>'

def table(headers,rows):
    return '<table><thead><tr>'+''.join('<th>'+e(x)+'</th>' for x in headers)+'</tr></thead><tbody>'+''.join('<tr'+(' class="active"' if active else '')+'>'+''.join('<td>'+e(x)+'</td>' for x in cells)+'</tr>' for active,cells in rows)+'</tbody></table>'

def html_for(service, data, quote, source, *, preview=False):
    item=quote['items'][service]; cfg=quote['pricing_config']['prices'][service]; rates=quote['tax_rates']; net=item['net']; final=item['final']
    client=data['cliente']; conditions=data['condicoes']; day=data['data_emissao']; revision=conditions.get('revisao',''); number=int(data['numeros'][service])
    if number < 1 or (revision and not re.fullmatch(r'REV\d+',revision)): raise ValueError('Identificador inválido')
    pid=f'PROPOSTA_COMERCIAL_EC_{day[:4]}_{number}_{service}'+('_'+revision if revision else '')
    ec_logo=core.data_uri(source/'18_LOGO_EXPORTCONTROL.jpg'); client_logo=core.data_uri(Path(client['logo']))
    date=core.date_extenso(day); header=f'<div class="header"><img src="{ec_logo}"><div class="id"><b>{pid}</b><br><span class="status">EMITIDA</span> · Toledo/PR · {e(date)}</div></div>'
    footer=f'<div class="foot"><span>ExportControl LTDA · exportcontrol.com.br</span><span>{pid}</span></div>'
    dfe=service=='DFE'; name='DF-e' if dfe else 'NFS-e'; count=item['cnpj_count']; avg=item['monthly_average']; annual=item['annual_total']
    if dfe:
        summary='Gestão e captura centralizada do DF-e'
        desc='Módulo para captura, centralização e gestão de documentos fiscais eletrônicos.'
        a=['NF-e (modelo 55).','CT-e (modelo 57) e CT-e OS (modelo 67).','MDF-e (modelo 58), quando disponível e aplicável.','Centralização dos CNPJs cadastrados.']
        b=['Consultas periódicas nas fontes fiscais oficiais disponíveis.','Download de XMLs e documentos auxiliares conforme disponibilidade.','Filtros e relatórios em Excel.','Acompanhamento por período, CNPJ e tipo de documento.']
        s1=['Consulta de documentos disponíveis para os CNPJs.','Manifestações de destinatário da NF-e quando autorizadas.','Extração de XML/PDF em lote, sujeita ao acesso.','Suporte remoto e orientação inicial.']
        s2=['Certificado e-CNPJ A1 e autorizações necessárias.','Disponibilidade condicionada às bases oficiais; sem garantia de tempo real.','Integrações personalizadas e módulo Exportação não incluídos.']
        title='Valor mensal por quantidade de CNPJs'; price_text='A mensalidade corresponde a uma única faixa pelo número de CNPJs monitorados. Os valores exibidos abaixo são líquidos, antes dos tributos.'
        match=next(t for t in item['tiers'] if t['applied'])
        basis=f"{match['min']}–{match['max']} CNPJs"
        headers=['Quantidade de CNPJs','XMLs de referência','Fixo líquido base','Fixo líquido vigente']
        rows=[(bool(t['applied']),[f"{t['min']} a {t['max']}",num(t['xml_reference']),money(o['fixed']),money(t['fixed'])]) for o,t in zip(cfg['faixas'],item['tiers'])]
        note=f'Base: {count} CNPJs, {num(annual)} documentos em 12 meses. A periodicidade dos XMLs de referência e eventuais excedentes devem ser definidos na contratação. Não há tarifa de excedente nesta tabela.'
        billing='Mensalidade após a prestação do serviço, no mês subsequente.'
    else:
        summary='Captura automática e gestão de NFS-e';desc='Captura, monitoramento e centralização de notas fiscais de serviços eletrônicas.'
        a=['Consultas programadas ao Ambiente Nacional e fontes municipais compatíveis.','Centralização dos documentos disponíveis e seus históricos.','Consulta de documentos dos CNPJs cadastrados.']
        b=['Filtros por período, prestador, tomador e situação.','Download de XML e documento auxiliar disponível.','Relatórios em Excel e download em lote.','Suporte operacional remoto.']
        s1=['Acesso SaaS ao módulo NFS-e.','Consulta e organização de documentos disponibilizados nas fontes compatíveis.','Relatórios em Excel e exportação de documentos.','Treinamento online e suporte remoto.']
        s2=['Cliente fornece credenciais, certificados ou autorizações quando necessários.','A captura depende dos órgãos emissores; sem garantia de tempo real.','Emissão de NFS-e pelo cliente não incluída.','Customizações dependem de orçamento específico.']
        title='Mensalidade pela média dos documentos';price_text='Primeira faixa de valor fixo, seguida de faixas progressivas marginais. As faixas seguintes incidem apenas sobre os excedentes.'
        basis=f'{num(avg)} NFS-e';headers=['Faixa','Min.','Máx.','Fixo líquido','Unitário líquido','NFs aplicadas','Subtotal']
        rows=[(bool(t['applied']),[t['tier'],num(t['min']),num(t['max']) if t['max'] is not None else 'Sem limite',money(t['fixed']),money(t['unit']),num(t['applied']),money(t['subtotal'])]) for t in item['tiers']]
        rows.append((True,['Total','','','','',num(avg),money(net)]));note=f'Memória: {num(annual)} ÷ 12 = {core.br_num(D(annual)/12)}; média arredondada {num(avg)}. Total líquido {money(net)}; preço final {money(final)}.'
        billing='Mensalidade referente ao mês da prestação.'
    tax_rows=[(False,['Valor líquido','—',money(net)])]+[(False,[k,core.pct(rates[k]),money(item['taxes'][k])]) for k in ('ISS','PIS','COFINS','IBS','CBS')]+[(True,['Valor final',core.pct(sum(D(str(v)) for v in rates.values())),money(final)])]
    sales=core.commercial_contact(core.load_json(source/'03_CONFIGURACAO_GERAL_AGENTE.json'),conditions.get('responsavel_comercial'))
    sales_html='<b>'+e(sales['nome'])+'</b><br>'+e(sales['cargo'])+'<br>'+e(sales['emails'][-1])
    mapping={'ID':pid,'TITLE':'Captura e Gestão de '+name,'LOGO_EC':ec_logo,'LOGO_CLIENT':client_logo,'CLIENT':e(client['razao_social']),'CONTACT':e(client['responsavel_principal']['nome'])+' · '+e(client['responsavel_principal']['email']),'NUMBER':str(number)+(' '+revision if revision else ''),'DATE':e(date),'HEADER':header,'FOOTER':footer,'SUMMARY_TITLE':summary,'SUMMARY_TEXT':desc+' Base informada: '+num(annual)+' documentos em 12 meses para '+num(count)+' CNPJs.','CNPJ_COUNT':num(count),'ANNUAL':num(annual),'AVG':num(avg),'FINAL':money(final),'BENEFITS':grid(listing('Documentos e consultas',a),listing('Gestão e produtividade',b)),'PRICE_TITLE':title,'PRICE_TEXT':price_text,'BASIS':basis,'PRICE_TABLE':table(headers,rows),'PRICE_NOTE':note,'SCOPE':grid(listing('Itens contemplados',s1),listing('Responsabilidades e limitações',s2)),'TAX_TABLE':table(['Item','Alíquota','Valor'],tax_rows),'PRICE_DATE':e(core.date_extenso(data['data_base_precificacao'])),'VALIDITY':str(int(conditions['validade_dias'])),'PAYMENT':str(int(conditions['pagamento_dias'])),'BILLING':billing,'NOTES':('<div class="callout notes">'+e(conditions['observacoes'])+'</div>') if conditions.get('observacoes') and len(conditions['observacoes']) <= 600 else '','SALES':sales_html}
    if preview:
        # MODELO: use the exact production layout and scope, never real customer data or a quote.
        dash='—'; price='R$ —'; pid='MODELO_'+service
        header=f'<div class="header"><img src="{ec_logo}" alt="ExportControl"><div class="id"><b>{pid}</b><br><span class="status">MODELO · NÃO EMITIDO</span></div></div>'
        footer=f'<div class="foot"><span>ExportControl LTDA · exportcontrol.com.br</span><span>{pid}</span></div>'
        if dfe:
            rows=[(False,[f"{t['min']} a {t['max']}",num(t['xml_reference']),price,price]) for t in cfg['faixas']]
            note='Base de cálculo: [quantidade de CNPJs] e [volume de documentos]. A periodicidade dos XMLs e eventuais excedentes devem ser definidos na contratação.'
        else:
            rows=[(False,[t['tier'],num(t['min']),num(t['max']) if t['max'] is not None else 'Sem limite',price,price,dash,price]) for t in cfg['faixas']]
            rows.append((False,['Total','','','','',dash,price]))
            note='Memória: [documentos em 12 meses] ÷ 12; média arredondada; cálculo marginal, valor líquido e preço final.'
        taxes=[(False,['Valor líquido',dash,price])]+[(False,[k,'—%',price]) for k in ('ISS','PIS','COFINS','IBS','CBS')]+[(True,['Valor final','—%',price])]
        mapping.update({'ID':pid,'CLIENT':'[Razão social]','CONTACT':'[Contato e e-mail]','NUMBER':dash,'DATE':dash,'HEADER':header,'FOOTER':footer,'SUMMARY_TEXT':desc+' Base informada: [volume e CNPJs].','CNPJ_COUNT':dash,'ANNUAL':dash,'AVG':dash,'FINAL':price,'BASIS':dash,'PRICE_TABLE':table(headers,rows),'PRICE_NOTE':note,'TAX_TABLE':table(['Item','Alíquota','Valor'],taxes),'PRICE_DATE':dash,'VALIDITY':dash,'PAYMENT':dash,'NOTES':'','SALES':'[Responsável comercial]<br>[Cargo e e-mail]'})
    template=(source/NEW[service]).read_text(encoding='utf-8')
    # single-pass replacement: user input can never expand template placeholders
    html=re.sub(r'\{\{([A-Z_]+)\}\}',lambda m:mapping[m[1]],template)
    if preview:
        html=html.replace('PROPOSTA COMERCIAL EMITIDA','MODELO · NÃO EMITIDO')
    notes=conditions.get('observacoes') or ''
    if len(notes)>600:
        html=html.replace('</body></html>', '<section class="page">'+header+'<div class="eyebrow">CONDIÇÕES COMERCIAIS</div><h1>Observações da proposta</h1><div class="callout notes">'+e(notes)+'</div>'+footer+'</section></body></html>')
    return pid,html


def render(html,pdf,pages):
    from playwright.sync_api import sync_playwright
    import fitz
    with sync_playwright() as p:
        executable=next((shutil.which(x) for x in ('chromium','chromium-browser','google-chrome') if shutil.which(x)),None)
        if not executable: raise RuntimeError('Chromium ausente')
        browser=p.chromium.launch(executable_path=executable,args=['--no-sandbox'])
        try:
            page=browser.new_page();page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(('file:','data:')) else route.abort())
            page.set_content(html.read_text(encoding='utf-8'),wait_until='load');page.emulate_media(media='print');page.evaluate('document.fonts.ready')
            bad=page.evaluate("""() => [...document.querySelectorAll('section.page')].map((s,i)=>({i:i+1,overflow:s.scrollHeight>s.clientHeight+2,footer:(s.querySelector('.foot')?.getBoundingClientRect().top??Infinity),content:Math.max(...[...s.children].filter(n=>!n.classList.contains('foot')).map(n=>n.getBoundingClientRect().bottom))})).filter(x=>x.overflow||x.content>x.footer-5)""")
            if bad: raise RuntimeError('Conteúdo ultrapassa a página: '+str(bad))
            page.pdf(path=str(pdf),prefer_css_page_size=True,print_background=True)
        finally: browser.close()
    with fitz.open(pdf) as doc:
        if len(doc)!=pages or any(len(p.get_text().strip())<30 for p in doc): raise RuntimeError('QA PDF: páginas inesperadas ou vazias')


def main():
    p=argparse.ArgumentParser();p.add_argument('--input',type=Path,required=True);p.add_argument('--out',type=Path,required=True);p.add_argument('--source',type=Path,required=True);a=p.parse_args()
    data=json.loads(a.input.read_text()); source=a.source.resolve(); out=a.out.resolve();out.mkdir(parents=True,exist_ok=True)
    services=data['servicos'];old=[s for s in services if s in LEGACY]; new=[s for s in services if s in NEW]; quote=data.pop('snapshot_v27');
    if not new: raise RuntimeError('Adaptador reservado para DF-e/NFS-e')
    if set(services)!=set(old+new): raise RuntimeError('Serviço inválido')
    record={'pdfs':{},'htmls':{},'servicos':{},'snapshot_v27':quote,'versao_gerador':'V27'}
    if old:
        legacy_input={**data,'servicos':old,'numeros':{s:data['numeros'][s] for s in old}}
        lp=out.parent/'input_legacy_v27.json';lp.write_text(json.dumps(legacy_input,ensure_ascii=False),encoding='utf-8')
        proc=subprocess.run([sys.executable,str(source/'20_GERADOR_DOCUMENTOS_EXPORTCONTROL.py'),'--input',str(lp),'--out',str(out)],cwd=source,check=True,capture_output=True,text=True)
        legacy=json.loads(proc.stdout);record['pdfs'].update(legacy['pdfs']);record['htmls'].update(legacy.get('htmls',{}));record['servicos'].update(legacy.get('servicos',{}));record['legacy_audit']=legacy
    for s in new:
        pid,html=html_for(s,data,quote,source);hp=out/(pid+'.html');pdf=out/(pid+'.pdf');hp.write_text(html,encoding='utf-8');render(hp,pdf,6 if len(data['condicoes'].get('observacoes') or '')>600 else 5)
        record['pdfs'][s]=str(pdf);record['htmls'][s]=str(hp);record['servicos'][s]=quote['items'][s]
    # A self-contained HTML presentation, preserving each individual document in a sandboxed srcdoc iframe.
    # PDF uses a summary cover followed by every individual PDF (no old-only consolidated document).
    import fitz
    cards=''.join(f'<tr><td>{e(s)}</td><td>{e(data["numeros"][s])}</td><td>{money(quote["items"][s]["monthly"])}</td><td>{money(D(quote["items"][s]["one_time"])+D(quote["items"][s]["implementation"]))}</td></tr>' for s in services)
    presentation='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>body{font:15px Arial;margin:24px;color:#174744}table{border-collapse:collapse;width:100%}th,td{padding:12px;border:1px solid #cbdedb}iframe{width:100%;height:1500px;border:1px solid #d4e8e5;margin:12px 0}</style></head><body><h1>ExportControl · Apresentação comercial</h1><h2>'+e(data['cliente']['razao_social'])+'</h2><table><tr><th>Serviço</th><th>Número</th><th>Mensalidade final</th><th>Avulso + implantação</th></tr>'+cards+'</table>'
    frames=''.join('<h2>'+e(s)+'</h2><iframe sandbox srcdoc="'+escape(Path(record['htmls'][s]).read_text(encoding='utf-8'),quote=True)+'"></iframe>' for s in services)
    hp=out/'APRESENTACAO_COMERCIAL_V27.html';hp.write_text(presentation+frames+'</body></html>',encoding='utf-8');record['html_apresentacao']=str(hp)
    if len(services)>1:
        summary=out/'RESUMO_COMERCIAL_V27.html';summary.write_text(presentation.replace('body{','@page{size:A4;margin:15mm}body{')+'</body></html>',encoding='utf-8')
        sp=out/'RESUMO_COMERCIAL_V27.pdf';render(summary,sp,1)
        pdf=out/'APRESENTACAO_COMERCIAL_V27.pdf'
        with fitz.open() as merged:
            for f in [str(sp)]+[record['pdfs'][s] for s in services]:
                with fitz.open(f) as d: merged.insert_pdf(d)
            merged.save(pdf)
        record['pdf_consolidado']=str(pdf)
    (out/'REGISTRO_OPORTUNIDADE_E_CALCULOS_REV1.json').write_text(json.dumps(record,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps(record,ensure_ascii=False))

if __name__=='__main__': main()
