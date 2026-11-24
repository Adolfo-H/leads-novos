#!/usr/bin/env python3
"""V11: contextual price and issue-date audit. Offline, no historical repricing.
Keep explicit quotes, tariff references and unresolved conditions separate.
"""
import argparse
import collections
import csv
import datetime as dt
import json
import re
from decimal import Decimal
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

import history_scenarios_v10 as v10
import history_values_v9 as v9
import history_legacy_v6 as v6
import history_finance_v2 as v2
from extract_history import clean, norm, dates, list_json, sha256, text_of

MONEY = r'(?:R\$\s*)?(\d{1,3}(?:\.\d{3})+,\d{2}|\d{1,9},\d{2})(?!\d|[.,]\d)'
HEADING = re.compile(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(?:INVESTIMENTO(?:\s+DO\s+PROJETO)?|FORMACAO\s+DA\s+MENSALIDADE|PRECIFICACAO|PROPOSTA\s+DE\s+INVESTIMENTO)\s*$',re.M)
END = re.compile(r'^\s*(?:ATENCIOSAMENTE\b|\d+(?:\.\d+)+\.?\s+(?:COMPARATIVO|ESCOPO|OBJETIVO|DOCUMENTOS DE REFERENCIA)\b)',re.M)
SKIP = re.compile(r'\b(?:DESCONTO|ISENCAO|ISENTO|BONIFICACAO|CONDICIONAD[OA]|ADICIONAL|REAJUSTE|PARCELA|MINIM[OA]|EXCEDENTE|POR\s+NF|POR\s+NOTA|ANUAL|SEMESTRAL|TOTAL\s+LIQUIDO|VALOR\s+LIQUIDO|PAGAMENTO\s+UNICO|CASO|SE\s+CONTRAT)\b')
# If a document offers separate commercial plans or negotiated discounts, do not
# select a fixed recurring amount without modelling those conditions explicitly.
ALTERNATIVE = re.compile(r'\b(?:DESCONTO|BONIFICACAO|ISENCAO|ISENTO|ADICIONAL\s+(?:DE\s+)?(?:R\$|\d)|MENSALIDADE\s+MINIMA|OPCAO\s+[12AB]|PLANO\s+[12AB]|CARÊNCIA|CARENCIA|PRIMEIR[OA]S?\s+\d+\s+MESES|APOS\s+\d+\s+MESES)\b')
MONTH_LABEL = r'(?:INVESTIMENTO\s+MENSAL(?:\s+FINAL)?|(?:VALOR|PRECO|CUSTO)\s+MENSAL(?:\s+FINAL)?|VALOR\s+DA\s+MENSALIDADE|MENSALIDADE(?:\s+(?:FINAL|FIXA|TOTAL|PRE\s*[-–]?\s*FIXADA|ESTIMADA))?)'
QUALIFIER = r'\s*(?::|[-–—]|SERA\s+DE|E\s+DE|NO\s+VALOR\s+DE|DE)?\s*'
BULLETS = r'^\s*[➢•▪✓\-–—]?\s*'
DATE_LABEL = re.compile(r'\b(?:DATA\s+(?:DE\s+EMISSAO|DA\s+PROPOSTA)|EMITID[AO]\s+EM)\b')
CITY_DATE = re.compile(r'\b(?:TOLEDO|PALOTINA)\s*(?:(?:[/\-–]\s*PR))?\s*[,·–-]')


def uniq(items):
    return list(dict.fromkeys(x for x in items if x is not None))


def evidence(value, line, kind, page=None):
    return {'valor':value,'trecho':clean(line)[:350],'tipo':kind,'pagina':page,'prioridade':120,'linha':0}


def exact_money(line, label):
    # Match the complete labelled cell; numbers in arbitrary sentences, unit
    # tariffs and percentage-based clauses are not treated as fixed prices.
    return re.match(BULLETS + label + QUALIFIER + MONEY + r'\s*(?:/\s*MES|MENSAIS|POR\s+MES)?\s*[.;]?\s*(?:\([^)]{1,200}\))?\s*$', norm(line))


def cash(m):
    return v10.money_br(m)


def issue_audit(text, docname):
    pages=text.split('\f'); pages=[p for p in pages if p.strip()]
    found=[]
    for pi,page in enumerate(pages):
        for line in page.splitlines():
            n=norm(line)
            if DATE_LABEL.search(n) or CITY_DATE.search(n):
                # Only explicit date labels or dated locality lines; never dates
                # from the service period, meetings, invoice, or fiscal notes.
                if re.search(r'\b(?:VENCIMENTO|VALIDADE|PERIODO|REUNIAO|CONTRATO)\b',n) and not DATE_LABEL.search(n):continue
                for d in dates(line):found.append(evidence(d,line,'emissao',pi+1 if len(pages)>1 else None))
    vals=uniq(r['valor'] for r in found)
    number=v10.cover_numbers(text)
    if not number:number=uniq(f'{m[1]}/{m[2]}' for m in v10.CODE.finditer(docname))
    issue=vals[0] if len(vals)==1 else None
    return {'date':issue,'state':'identified' if issue else ('conflict' if vals else 'missing'),'candidates':vals,'evidence':found[:12], 'number_year':number[0][:4] if len(number)==1 else None, 'year_mismatch':bool(issue and len(number)==1 and number[0][:4]!=issue[:4])}


def quote_parts(text):
    # V6 already models pre-/post-fixed tables and the illustrative sections.
    parts,_,modes=v6.section_tables(text)
    if 'pre' in modes:
        return [(mode,chunk) for mode,chunk in parts if mode=='pre'], True
    # For a plain document, only the investment area may supply a price.
    selected=[]
    for mode,chunk in parts:
        if mode!='base':continue
        n=norm(chunk)
        m=HEADING.search(n)
        if m:
            end=END.search(n,m.end())
            selected.append((mode,chunk[m.start():end.start() if end else len(chunk)]))
    return selected,False


def monthly_audit(text, svc, known):
    """Returns a structured audit, with auto-recovery only for single recurring offers."""
    if len(svc)!=1 or svc[0] not in v9.MONTHLY:return None
    n=norm(clean(text))
    if v9.POINT_MODEL.search(n) or known.get('billing_mode') in ('percentage_addon','historical_bundle_one_time') or known.get('pricing_terms_v10'):
        return None
    parts,prefixed=quote_parts(text)
    if not parts:return None
    base_text='\n'.join(s for _,s in parts)
    main=norm(clean(base_text))
    if not (prefixed or v9.REC_MODEL.search(n) or re.search(r'\b(?:MENSALIDADE|INVESTIMENTO MENSAL|VALOR MENSAL)\b',main)):return None
    label=v9.LABELS.get(svc[0],'')
    svc_label=r'(?:(?:VALOR|INVESTIMENTO|PRECO|CUSTO|MENSALIDADE)\s+(?:MENSAL\s+)?(?:DO\s+|DE\s+)?(?:SERVICO\s+DE\s+)?|SERVICO\s+(?:MENSAL\s+)?DE\s+)'+label
    declared=[]; tables=[]; reference=[]; net_tables=[]
    for _,chunk in parts:
        # Legacy price tables have a labelled total, and are validated by V9.
        for t in v9.parsed_tables(chunk):
            if 'MENSALIDADE' in norm(t['heading']):tables.append(t)
        lines=chunk.split('\n')
        for i,line in enumerate(lines):
            # preserve columns: an adjacent label must not supply the amount.
            segments=v2.cells(line)
            for pos,cell in segments:
                if SKIP.search(norm(cell)):continue
                mm=exact_money(cell,MONTH_LABEL)
                if not mm and label:mm=exact_money(cell,svc_label)
                if not mm and label:
                    # Old tables may use the bare service label; require explicit
                    # recurring model and a colon, not mention of a dependency.
                    mm=re.match(BULLETS+label+r'\s*:\s*'+MONEY+r'\s*[.;]?\s*$',norm(cell))
                if mm and cash(mm[1]) is not None:
                    declared.append(evidence(cash(mm[1]),cell,'valor_mensal_declarado'))
                    continue
                # Multicolumn summary card: header alone and amount aligned below.
                if re.fullmatch(BULLETS+MONTH_LABEL+r'\s*:?',norm(cell)):
                    for later in lines[i+1:i+7]:
                        if '\f' in later:break
                        if not later.strip():continue
                        cc=v2.cells(later)
                        # Match a currency-only cell near the original label.
                        nearby=[(p,v) for p,v in cc if abs(p-pos)<=8]
                        if len(nearby)==1:
                            z=re.fullmatch(r'R\$\s*'+MONEY+r'\s*(?:/\s*MES)?',norm(nearby[0][1]))
                            if z and cash(z[1]) is not None:declared.append(evidence(cash(z[1]),cell+' | '+nearby[0][1],'valor_mensal_declarado'))
                        break
        # Explicitly identify net table totals instead of comparing them to the
        # gross price; do not apply any tax calculation to historical values.
        for line in lines:
            m=re.match(r'^\s*TOTAL\s+LIQUIDO\s+MENSAL\s+([\d.]+)\s+'+MONEY+r'\s*$',norm(line))
            if m and cash(m[2]) is not None:net_tables.append(evidence(cash(m[2]),line,'total_liquido'))
    # Supplement modern gross-up with the exact final row, only if the document
    # has one recurring service and no separate setup/tax tables to confuse it.
    tax, tax_conflict=v2.tax_rows(text.replace('\f','\n').split('\n'))
    if not tax_conflict and len(tax)>0 and not re.search(r'\b(?:IMPLANTACAO|INSTALACAO/CONFIGURACAO/TREINAMENTO)\s*:?\s*R\$',main):
        finals=[v2.cash_values(r['valor'],True)[0] for r in tax if norm(r['item'])=='VALOR FINAL']
        if len(finals)==1 and net_tables:
            declared.append(evidence(finals[0],'Valor final da composição tributária mensal','valor_final_tributos'))
    # Do not use a simulation annual total or a tariff minimum as a monthly value.
    # Prefer the table whose base matches the explicit textual quantity, when unique.
    b,bs=v6.consensus(v6.base_evidence(text)[0]); base=b if bs=='identified' else None
    tables=list({v9.table_key(t):t for t in tables}.values())
    selected=[t for t in tables if base is None or t['table_quantity']==base]
    table=selected[0] if len(selected)==1 and len(tables)==1 else None
    tv=uniq(t['table_total'] for t in tables)
    dv=uniq(e['valor'] for e in declared)
    if not dv and not tv and not net_tables:return None
    for t in tables:reference.append(evidence(t['table_total'],f"{t['heading']} | TOTAL {t['table_quantity']} NFs | R$ {t['table_total']}",'tabela_base'))
    a={'kind':'monthly','state':'unresolved','declared_values':dv,'table_values':tv,'net_values':uniq(e['valor'] for e in net_tables),'base_quantity':base,'prefixed':prefixed,'evidence':(declared+reference+net_tables)[:24], 'table':table,'reason':'Sem consenso sobre o valor principal.'}
    if len(dv)>1:
        a.update(state='conflict',reason='Mais de um preço mensal explicitamente declarado.');return a
    if len(tables)>1 or (tables and table is None) or bs=='conflict':
        a.update(state='conflict',reason='Mais de uma tabela-base ou base mensal incompatível.');return a
    if dv and tv and dv[0]!=tv[0]:
        # Actual contradiction, not a simulation. Both remain visible; no automatic
        # final price, even if one source seems more authoritative.
        a.update(state='declared_table_conflict',reason='O investimento mensal declarado difere do total da tabela-base.');return a
    if ALTERNATIVE.search(main):
        a.update(state='conditional_review',reason='Há condições, descontos ou alternativas na seção comercial.');return a
    # No automatic use of a net-only table. A table-only legacy amount needs
    # explicit reference quantity that agrees with the priced table.
    if dv and (not tv or table):
        if re.search(r'\b(?:LIQUIDO|TRIBUTOS|GROSS-UP)\b',main) and not any(e['tipo']=='valor_final_tributos' or 'FINAL' in norm(e['trecho']) for e in declared):
            a.update(state='unresolved',reason='Não foi comprovado se o valor mensal é líquido ou final.');return a
        a.update(state='identified',value=dv[0],reason='Valor mensal explícito na seção de investimento; tabela-base concordante quando presente.');return a
    if table and base is not None and not net_tables and not re.search(r'\b(?:LIQUIDO|TRIBUTOS|GROSS-UP)\b',main):
        a.update(state='identified',value=table['table_total'],reason='Tabela-base mensal única, com total e volume corroborados.');return a
    return a


def report_excerpts(text):
    # Compact commercial evidence for unresolved variations, not the full document.
    sections,pre=quote_parts(text)
    s='\n'.join(c for _,c in sections) or v10.investment(text) or text[-6000:]
    return [clean(line)[:260] for line in s.splitlines() if re.search(r'R\$|\d+,\d{2}|\d\s*%',line)][:32]


def generate(data,root):
    # V10 remains the conservative resolver of one-time values, discounts,
    # signatures and other already supported models. Reuse only validated plans.
    baseplan,_=v10.generate(data,root)
    baseline={int(r['proposal_id']):r for r in baseplan['rows']}
    prevwarnings=collections.defaultdict(list)
    for x in baseplan['pending']:prevwarnings[int(x['proposal_id'])].append(x['reason'])
    metas={int(m['proposal_id']):m for m in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[]; report=[]; pending=[]; stats=collections.Counter(); years=collections.defaultdict(collections.Counter)
    for p in data['proposals']:
        pid=int(p['id']);m=metas.get(pid);row=baseline.get(pid); text=''; audit={}; warnings=[]
        old=v6.obj(m.get('evidence')) if m else {}; prior=v6.obj(old.get('finance_v2'))
        svc=list_json(m.get('services')) if m else list_json(p.get('service_codes'))
        if not svc:svc=list_json(p.get('service_codes'))
        prev_month=v6.money(m.get('monthly_amount')) if m else None;prev_once=v6.money(m.get('one_time_amount')) if m else None
        year=str(m.get('historic_year') or '') if m else ''; year=year or 'indefinido'
        historic_year=year
        try:
            if not m or len(docs[pid])!=1:raise ValueError('metadados ou documento histórico único ausente')
            doc=docs[pid][0];path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or sha256(path)!=doc['sha256'] or m['document_sha256']!=doc['sha256']:raise ValueError('hash ou caminho divergente')
            text=text_of(path)
            if len(clean(text))<40:raise ValueError('sem texto extraível')
            stats['documentos_lidos']+=1
            date=issue_audit(text,doc.get('original_name') or m.get('document_name') or '')
            if historic_year=='indefinido' and date['number_year']:historic_year=date['number_year']
            if date['date']:year=date['date'][:4]
            elif m.get('issued_on') or p.get('issued_on'):year=str(m.get('issued_on') or p.get('issued_on'))[:4]
            if date['year_mismatch']:stats['ano_numero_diferente_da_emissao']+=1;warnings.append('Ano da numeração diferente do ano de emissão; ambos preservados.')
            if date['state']=='conflict':warnings.append('Datas de emissão explícitas divergentes.')
            if date['date'] and any(str(d)[:10]!=date['date'] for d in (m.get('issued_on'),p.get('issued_on')) if d):warnings.append('Data de emissão já cadastrada diverge do documento; preservada.')
            current_svc=row['meta']['services'] if row else svc
            f=row['evidence']['finance_v2'] if row else prior
            monthly=monthly_audit(text,current_svc,f)
            audit={'version':11,'source':'document_sha256','issue':date,'monthly':monthly}
            # Keep existing financial value on real conflicts. V10 could pick a
            # different partial signal, so cancel a new monthly projection here.
            if monthly and monthly['state'] in ('conflict','declared_table_conflict','conditional_review'):
                warnings.append(monthly['reason'])
                if row and row['meta']['monthly_amount']!=prev_month:
                    row=None
                stats['divergencias_ou_condicoes_documentais']+=1
            if monthly and monthly['state']=='identified':
                val=monthly['value']; core_month=v6.money(p.get('monthly_final'))
                if (prev_month not in (None,val) or core_month not in (None,val) or (row and row['meta']['monthly_amount'] not in (None,val))):
                    warnings.append('Valor mensal anteriormente preenchido difere da nova evidência; preservado.')
                    monthly['state']='previous_review';monthly['reason']=warnings[-1]
                    if row and row['meta']['monthly_amount']!=prev_month:row=None
                elif prev_month is None and current_svc==svc and len(svc)==1:
                    # Only restore fields the existing proposal can safely accept.
                    if row is None:
                        row={'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':{'monthly_amount':prev_month,'one_time_amount':prev_once,'services':svc},'core':{},'evidence':{'finance_v2':dict(prior),'finance_v10':{'version':10,'source':'document_sha256','number_warning':v10.audit_number(text,doc.get('original_name') or m['title']),'document_number':(v10.cover_numbers(text) or [None])[0]}}}
                    patch=row['evidence']['finance_v2'];patch.update(version=2,reader_version=11,monthly_state='identified',monthly_evidence=monthly['evidence'])
                    if monthly['prefixed']:patch.update(billing_mode='pre_fixed_reference',billing_options=['pre_fixed','post_fixed'])
                    t=monthly.get('table')
                    if t and (patch.get('base_quantity') in (None,t['table_quantity']) or str(patch.get('base_quantity'))==t['table_quantity']):
                        patch.update({k:t[k] for k in ('bands','table_total','table_quantity')});patch.update(legacy_table=True,band_conflict=False,calculation_mode='progressive',base_quantity=t['table_quantity'])
                    row['meta']['monthly_amount']=val
                    if core_month is None:row['core']['monthly_final']=val
                    stats['mensalidades_recuperadas_v11']+=1
            # Re-read the final projected state, not a stale V10 warning.
            out_month=row['meta']['monthly_amount'] if row else prev_month
            out_once=row['meta']['one_time_amount'] if row else prev_once
            f=row['evidence']['finance_v2'] if row else prior
            is_point=current_svc==['RETROATIVAS'] or f.get('billing_mode')=='historical_bundle_one_time'
            formula=f.get('billing_mode')=='percentage_addon'
            if not formula and not is_point and out_month is None:
                if not monthly or monthly['state'] not in ('declared_table_conflict','conflict','conditional_review','previous_review'):warnings.append('Mensalidade sem identificação segura.')
            if (is_point or formula) and out_once is None:warnings.append('Valor pontual sem identificação segura.')
            if not current_svc:warnings.append('Serviço não identificado.')
            # Include specific earlier warnings when still relevant; filter stale
            # monthly warnings once V11 has corroborated the price.
            for reason in prevwarnings[pid]:
                if ('mensal' in norm(reason).lower() or 'tabela' in norm(reason).lower()) and monthly and monthly['state']=='identified' and out_month==monthly.get('value'):continue
                if 'modelo de cobrança não inequívoco' in reason and (is_point or formula or out_month is not None):continue
                if 'modalidades ou simulações' in reason and (out_month is not None or monthly):continue
                if 'mensalidade não identificada' in reason and monthly and monthly['declared_values']:continue
                warnings.append(reason)
            audit['warnings']=uniq(warnings)
            if row:
                row['evidence']['finance_v2']['reader_version']=11
                row['evidence']['audit_v11']=audit
            elif old.get('audit_v11')!=audit or (date['date'] and (not m.get('issued_on') or not p.get('issued_on')) and not any(str(d)[:10]!=date['date'] for d in (m.get('issued_on'),p.get('issued_on')) if d)):
                row={'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':{},'core':{},'evidence':{'audit_v11':audit},'audit_only':True}
            if row and date['date'] and not any(str(d)[:10]!=date['date'] for d in (m.get('issued_on'),p.get('issued_on')) if d):
                if not m.get('issued_on'):row['meta']['issued_on']=date['date'];stats['datas_recuperadas']+=1
                if not p.get('issued_on'):row['core']['issued_on']=date['date']
            if row:
                def comparable(k,v):
                    if k in ('monthly_amount','one_time_amount','monthly_final','one_time_final'):return v6.money(v)
                    if k in ('services','service_codes'):return list_json(v)
                    if k in ('retro_total','recurring_total'):return str(v) if v is not None else None
                    return v
                def changed(source,patch):return any(comparable(k,source.get(k))!=comparable(k,v) for k,v in patch.items())
                if changed(m,row['meta']) or changed(p,row['core']) or any(old.get(k)!=v for k,v in row['evidence'].items()):rows.append(row)
            status='conferir' if warnings else 'revisado'
            if warnings:pending.append({'proposal_id':pid,'ano_emissao':year,'ano_codigo':historic_year,'proposta':m.get('title',''),'motivos':uniq(warnings),'precos_declarados':monthly.get('declared_values',[]) if monthly else [],'totais_tabela':monthly.get('table_values',[]) if monthly else [],'trechos_financeiros':report_excerpts(text)})
            report.append([pid,year,historic_year,m.get('title',''),','.join(current_svc),prev_month,out_month,prev_once,out_once,' / '.join(monthly.get('declared_values',[])) if monthly else '', ' / '.join(monthly.get('table_values',[])) if monthly else '',status,'; '.join(uniq(warnings))])
            stats['mensalidades_recuperadas_total']+=prev_month is None and out_month is not None
            stats['pontuais_recuperados']+=prev_once is None and out_once is not None
        except Exception as exc:
            stats['falhas_preservadas']+=1
            reason=f'{type(exc).__name__}: {str(exc)[:180]}'
            pending.append({'proposal_id':pid,'ano_emissao':year,'ano_codigo':historic_year,'proposta':m.get('title','') if m else '','motivos':[reason],'trechos_financeiros':report_excerpts(text) if text else []})
            report.append([pid,year,historic_year,m.get('title','') if m else '',','.join(svc),prev_month,prev_month,prev_once,prev_once,'','','conferir',reason]);status='conferir'
        years[year]['total']+=1;years[year][status]+=1
    summary={'total_historico':len(data['proposals']),**stats,'atualizacoes':len(rows),'registros_com_pendencias':len(pending),'por_ano_emissao':{k:dict(v) for k,v in sorted(years.items())},'pendencias_2024_por_emissao_ou_codigo':sum(x['ano_emissao']=='2024' or x['ano_codigo']=='2024' for x in pending),'motivos_2024':dict(collections.Counter(reason for x in pending if x['ano_emissao']=='2024' or x['ano_codigo']=='2024' for reason in x['motivos']))}
    return {'version':11,'rows':rows,'summary':summary,'pending':pending},report


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--export',type=Path,required=True);parser.add_argument('--root',type=Path,required=True);parser.add_argument('--output',type=Path,required=True);args=parser.parse_args()
    plan,report=generate(json.loads(args.export.read_text()),args.root)
    folder=args.output.parent
    for path,obj in [(args.output,plan),(folder/'resumo.json',plan['summary']),(folder/'pendencias.json',plan['pending']),(folder/'pendencias_2024.json',[r for r in plan['pending'] if r['ano_emissao']=='2024' or r['ano_codigo']=='2024'])]:
        path.write_text(json.dumps(obj,ensure_ascii=False,indent=2));path.chmod(0o600)
    with (folder/'revisao.csv').open('w',encoding='utf-8-sig',newline='') as fp:
        w=csv.writer(fp,delimiter=';');w.writerow(['id','ano_emissao','ano_codigo','proposta','servicos','mensal_anterior','mensal_novo','pontual_anterior','pontual_novo','valor_mensal_declarado','total_tabela_base','resultado','motivos']);w.writerows([["'"+x if isinstance(x,str) and x[:1] in ('=','+','-','@','\t','\r') else x for x in row] for row in report])
    # Bundle only review summaries; never full PDFs, export, write manifest or backup.
    with ZipFile(folder/'Auditoria_Propostas_V11.zip','w',ZIP_DEFLATED) as z:
        for name in ('resumo.json','revisao.csv','pendencias.json','pendencias_2024.json'):z.write(folder/name,name)
    print(json.dumps(plan['summary'],ensure_ascii=False,indent=2))

if __name__=='__main__':main()
