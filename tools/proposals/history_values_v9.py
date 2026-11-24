#!/usr/bin/env python3
"""V9: document-grounded recurring and historical bundled one-time prices.
No pricing engine, no external calls, no writes to the database.
"""
import argparse
import collections
import csv
import json
import re
from decimal import Decimal
from pathlib import Path

import history_legacy_v6 as v6
import history_metadata_v3 as v3
import history_finance_v2 as v2
from extract_history import clean, norm, sha256, text_of, list_json

MONTHLY = {'LICENCIAMENTO', 'ACOMPANHAMENTO', 'DFE', 'NFSE'}
LABELS = {'LICENCIAMENTO': r'LICENCIAMENTO(?:\s+DA\s+FERRAMENTA)?',
          'ACOMPANHAMENTO': r'ACOMPANHAMENTO(?:\s+(?:ESPECIALIZADO|E\s+COBRANCA))?',
          'DFE': r'(?:DF[- ]?E|CAPTURA\s+(?:E\s+GESTAO\s+)?DE\s+DF[- ]?E)',
          'NFSE': r'(?:NFS[- ]?E|CAPTURA\s+(?:E\s+GESTAO\s+)?DE\s+NFS[- ]?E)'}
CASH = r'R\$\s*(\d+(?:\.\d{3})*,\d{2})(?![\d,])'
REC_MODEL = re.compile(r'MODELO\s*[“"\']?MENSALIDADE|MENSALIDADE\s+FINAL|ESTIMATIVA\s+DE\s+MENSALIDADE')
POINT_MODEL = re.compile(r'MODELO\s*[“"\']?PRESTACAO\s+DE\s+SERVICO|PAGAMENTO\s+UNIC[OA]|VALOR\s+UNIC[OA]')
RET_HEAD = re.compile(r'^\s*(?:\d+(?:\.\d+)+\.?\s+)?CONSULTAS?\s+RETROATIVAS?\s*$')
ACOMP_HEAD = re.compile(r'^\s*(?:\d+(?:\.\d+)+\.?\s+)?(?:SERVICO\s+DE\s+)?ACOMPANHAMENTO(?:\s+RETROATIVO)?(?:\s+E\s+COBRANCA)?\s*$')
SECTION_INVEST = re.compile(r'^\s*(?:\d+(?:\.\d+)+\.?\s+)?(?:INVESTIMENTO(?:\s+DO\s+PROJETO)?|FORMACAO\s+DA\s+MENSALIDADE)\s*$')
EXPLICIT_OTHER_MODEL = re.compile(r'\bMODELO\s*[“"\']?MENSALIDADE\b|\b(?:VALOR|INVESTIMENTO|CUSTO)\s+MENSAL\s*:|\bMENSALIDADE\s+FINAL\b')


def uniq(values):
    return list(dict.fromkeys(v for v in values if v is not None))


def table_key(t):
    return json.dumps([t['bands'], t['table_quantity'], t['table_total']], sort_keys=True)


def parsed_tables(text):
    # Legacy layouts have both "FAIXA 1" and plain numeric first columns.
    # Prefix only numeric rows within a recognized historical price table.
    lines = text.replace('\f', '\n\f\n').split('\n')
    adjusted = []
    active = False
    for line in lines:
        n = norm(line).strip()
        if re.match(r'^(?:ESTIMATIVA\s+(?:(?:DE|DO)\s+)?(?:MENSALIDADE|INVESTIMENTO|VALOR)|TABELA\s+(?:DE\s+)?(?:MENSALIDADE|INVESTIMENTO))\b', n):
            active = True
        if active and re.match(r'^\s*\d{1,2}\s{2,}\d[\d.]*\s{2,}', line) and re.search(r'\d,\d{2}', line):
            # All columns shift by the same amount, preserving relative alignment.
            line = re.sub(r'^(\s*)(\d{1,2})(?=\s{2,})', r'\1FAIXA \2', line, count=1)
        adjusted.append(line)
        if n.startswith('TOTAL') or line == '\f':
            active = False
    # Some headings span "ESTIMATIVA DO VALOR DO SERVIÇO". The existing reader
    # starts at the column headers; leave those unchanged.
    # Do not rely on splitlines() retaining form-feed: explicit page markers below.
    tables = v3.legacy_tables(adjusted)
    def valid(t):
        # Verify the printed subtotal/quantity totals, not rounded unit rates.
        # Empty unused cells remain empty; they contribute nothing to totals.
        if t.get('table_total') is None or t.get('table_quantity') is None:
            return False
        rows=t['bands']
        if [int(r['faixa']) for r in rows]!=list(range(1,len(rows)+1)):
            return False
        try:
            q=sum(int(r['quantidade'].replace('.','')) for r in rows if r['quantidade'] not in ('-','—','–'))
            subtotal=sum((Decimal(v2.cash_values(r['subtotal'],True)[0]) for r in rows if r['subtotal'] not in ('-','—','–')),Decimal('0'))
            return q==int(t['table_quantity']) and subtotal==Decimal(t['table_total'])
        except (ValueError,IndexError,ArithmeticError):
            return False
    return list({table_key(t): t for t in tables if valid(t)}.values())


def amount_matches(text, pattern):
    return [v6.ev(v2.decimal(m[1]), m.group()) for m in re.finditer(pattern + CASH, norm(clean(text)), re.I) if v2.decimal(m[1]) is not None]


def explicit_monthly(text, svc):
    # Direct labelled values, not broad matches on every occurrence of "mensalidade".
    evs = v6.direct(clean(text), v6.MONTH)
    label = LABELS.get(svc)
    if label:
        pat = r'^\s*[➢•▪✓\-–—]?\s*(?:(?:VALOR|INVESTIMENTO|CUSTO|MENSALIDADE)\s+(?:MENSAL\s+)?(?:DO\s+|DE\s+)?(?:SERVICO\s+DE\s+)?|SERVICO\s+(?:MENSAL\s+)?DE\s+)?' + label + r'\s*(?:MENSAL|MENSALIDADE)?\s*:\s*' + CASH + r'\s*[.;]?\s*$'
        for line in text.splitlines():
            m = re.match(pat, norm(line), re.I)
            if m:
                evs.append(v6.ev(v2.decimal(m[1]), line))
    # Generic headings are allowed only once the document is known to have a
    # recurring model and the investment section is selected by the caller.
    pat = r'^\s*[➢•▪✓\-–—]?\s*(?:VALOR\s+(?:DO\s+SERVICO|DA\s+MENSALIDADE)|INVESTIMENTO|PRECO\s+MENSAL|MENSALIDADE)\s*:\s*' + CASH + r'\s*[.;]?\s*$'
    for line in text.splitlines():
        m = re.match(pat, norm(line), re.I)
        if m:
            evs.append(v6.ev(v2.decimal(m[1]), line))
    return [e for e in evs if e['valor'] is not None]


def investment_text(text):
    lines = text.replace('\f', '\n\f\n').split('\n')
    for i, line in enumerate(lines):
        if SECTION_INVEST.match(norm(line)):
            return '\n'.join(lines[i:])
    return ''


def monthly_rescue(text, svc, baseline):
    n = norm(clean(text))
    if svc not in MONTHLY or not REC_MODEL.search(n) or POINT_MODEL.search(n):
        return None, 'modelo de cobrança não inequívoco'
    f = baseline['finance']
    # Existing V6 handles explicit pre/post-fixed modalities; don't select examples.
    parts, _, modes = v6.section_tables(text)
    if modes or any(mode in ('simulation', 'example') for mode, _ in parts):
        return None, 'modalidades ou simulações: utilizar leitura V6'
    invest = investment_text(text)
    if not invest:
        return None, 'seção de investimento não identificada'
    explicit = explicit_monthly(invest, svc)
    values = uniq(e['valor'] for e in explicit)
    tabs = parsed_tables(invest)
    # One unambiguous table labelled ESTIMATIVA DE MENSALIDADE is evidence.
    tabs = [t for t in tabs if 'MENSALIDADE' in t['heading']]
    totals = uniq(t['table_total'] for t in tabs)
    if len(values)>1 or len(totals)>1 or (values and totals and values[0]!=totals[0]):
        return None, 'valores de investimento e tabela mensal divergentes'
    if not values and not totals:
        return None, 'sem valor mensal explícito ou tabela-base mensal'
    if len(tabs)>1:
        return None, 'mais de uma tabela-base mensal'
    if not values and tabs:
        base_candidates,_,_=v6.base_evidence(invest)
        stated=uniq(e['valor'] for e in base_candidates)
        if stated!=[tabs[0]['table_quantity']]:
            return None,'tabela sem valor explícito e sem base mensal textual corroborada'
    amount = (values or totals)[0]
    # A gross-up table shows NET subtotal and final price separately. This rescue
    # is for legacy tables; never choose a net amount in a modern proposal.
    if re.search(r'\b(?:TOTAL LIQUIDO MENSAL|FIXO LIQUIDO|TRIBUTOS JA INCORPORADOS)\b', norm(invest)) and not values:
        return None, 'valor líquido não confirma mensalidade final'
    if f.get('monthly_state') == 'conflict':
        # Reject conflicting explicitly labelled final monthly prices even if a
        # lower-confidence table agrees with just one of them.
        high = uniq(e.get('valor') for e in f.get('monthly_evidence', []) if e.get('prioridade',0)>=100)
        if any(v!=amount for v in high):
            return None, 'outro valor mensal final explícito diverge'
    patch = {'monthly_state':'identified','monthly_evidence':explicit + ([v6.ev(amount,'TOTAL da tabela de mensalidade')] if tabs else []), 'billing_mode':'monthly_reference'}
    if tabs:
        t=tabs[0]; patch.update({k:t[k] for k in ('bands','table_total','table_quantity')})
        patch.update(legacy_table=True,band_conflict=False,calculation_mode='progressive',base_quantity=t['table_quantity'],base_label='Base mensal de documentos')
    # Annual reference from the INVESTMENT section, not a different scope paragraph.
    annual=[]
    for m in re.finditer(r'\bTOTALIZANDO\s+'+v6.COUNT+r'\s+'+v6.DOCS+r'\s+(?:AO\s+LONGO\s+DE|EM|NOS\s+PROXIMOS|NOS\s+ULTIMOS)\s+12\s+MESES',norm(clean(invest))):
        annual.append(v6.count_text(m[1]))
    all_annual=uniq(annual+[v6.count_text(m[1]) for m in re.finditer(r'\b(?:EMISSAO\s+DE|EMITIDOS|FORAM\s+EMITIDOS)\s+'+v6.COUNT+r'\s+'+v6.DOCS,n)])
    if len(uniq(annual))==1 and patch.get('base_quantity') and int(annual[0])==12*int(patch['base_quantity']):
        patch['annual_quantity']=annual[0]
        patch['annual_basis']='projected' if re.search(r'PROXIMOS\s+12\s+MESES|ESTIMA-SE',norm(clean(invest))) else 'stated'
        if len(all_annual)>1:
            patch['annual_reference_conflict']=all_annual
    return {'monthly_amount':amount,'finance':patch}, None


def bundled_retroactive(text, svc):
    # Both services are billed once only when the document explicitly states the
    # service model AND has separate price sections and a corroborated total.
    if set(svc)!={'RETROATIVAS','ACOMPANHAMENTO'}:
        return None, 'combinação não suportada com segurança'
    n=norm(clean(text))
    if not POINT_MODEL.search(n) or EXPLICIT_OTHER_MODEL.search(n):
        return None, 'natureza pontual do acompanhamento não confirmada'
    lines=text.replace('\f','\n\f\n').split('\n')
    starts=[(i,'RETROATIVAS' if RET_HEAD.match(norm(l)) else 'ACOMPANHAMENTO') for i,l in enumerate(lines) if RET_HEAD.match(norm(l)) or ACOMP_HEAD.match(norm(l))]
    if len(starts)!=2 or [x[1] for x in starts]!=['RETROATIVAS','ACOMPANHAMENTO']:
        return None,'seções de investimento distintas não identificadas'
    ret='\n'.join(lines[starts[0][0]:starts[1][0]])
    acomp='\n'.join(lines[starts[1][0]:])
    retro_ev=amount_matches(ret,r'\bCONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?\s*:\s*')
    # Permit "Consulta às notas retroativas" and explicit repetition in the second section.
    retro_ev+=amount_matches(acomp,r'\bCONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?\s*:\s*')
    acomp_ev=amount_matches(acomp,r'\b(?:VALOR\s+(?:ESTIMADO\s+)?(?:PARA\s+O\s+|DO\s+)?SERVICO\s+DE\s+(?:COBRANCA\s+E\s+REGISTRO\s+DA\s+INFORMACAO\s+NA\s+PLATAFORMA|ACOMPANHAMENTO(?:\s+E\s+COBRANCA)?)|SERVICO\s+DE\s+ACOMPANHAMENTO(?:\s+E\s+COBRANCA)?)\s*:\s*')
    total_ev=amount_matches(acomp,r'\b(?:O\s+)?(?:INVESTIMENTO\s+TOTAL|VALOR\s+TOTAL\s+D[EO]S?\s+SERVICOS?)(?:\s+NESTE\s+CASO)?\s*(?:SERA\s+DE|E\s+DE|:|DE)\s*')
    r,rs=v6.consensus(retro_ev); a,as_=v6.consensus(acomp_ev); total,ts=v6.consensus(total_ev)
    if (rs,as_,ts)!=('identified','identified','identified') or Decimal(r)+Decimal(a)!=Decimal(total):
        return None,'componentes ou total explícito não conciliados'
    rt=parsed_tables(ret); at=parsed_tables(acomp)
    if len(rt)>1 or len(at)>1 or (rt and rt[0]['table_total']!=r) or (at and at[0]['table_total']!=a):
        return None,'tabelas de cada serviço não conciliadas com valores explícitos'
    # Explicit component prices and the explicitly quoted combined total are enough
    # to record prices even if there is no price table (or it is not extractible).
    # Never reconstruct a missing table or infer quantities from the unit price.
    def component_table(tables, section):
        if tables:return {**tables[0],'table_pending':False}
        return {'bands':[],'table_quantity':None,'table_total':None,'table_pending':bool(re.search(r'\bFAIXA\b|ESTIMATIVA\s+(?:DE|DO)\s+VALOR',norm(section)))}
    rt0=component_table(rt,ret);at0=component_table(at,acomp)
    impl=v6.conditional_fee(text)
    # Some old texts say "valor de R$ ... referente à implantação" rather
    # than place the amount after the label. Require one amount in that paragraph.
    if not impl:
        for para in re.split(r'\n\s*\n|\f',text):
            p=norm(clean(para)); vals=uniq(v2.cash_values(para))
            if v6.CONDITIONAL.search(p) and re.search(r'\bIMPLANTACAO\b',p) and len(vals)==1 and not re.search(r'\b(?:ISENTO|NAO SERA COBRAD[OA])\b',p):
                impl.append(v6.ev(vals[0],para))
    fee,fs=v6.consensus(impl)
    if fs=='conflict':return None,'implantação condicional divergente'
    components=[{'service':'RETROATIVAS','label':'Consultas retroativas','billing':'one_time','amount':r,'estimated':False,**rt0}, {'service':'ACOMPANHAMENTO','label':'Acompanhamento retroativo e cobrança','billing':'one_time','amount':a,'estimated':bool(re.search(r'ESTIMAD[OA]|RECALCULADO',norm(acomp))),**at0}]
    patch={'version':2,'reader_version':9,'billing_mode':'historical_bundle_one_time','monthly_state':'not_applicable','one_time_state':'identified','service_amount':total,'service_amount_state':'identified','base_quantity':rt0['table_quantity'],'base_label':'Base das consultas retroativas','annual_quantity':None,'bands':[],'table_total':None,'table_quantity':None,'band_conflict':False,'taxes':[],'tax_conflict':False,'billing_options':[],'illustrative_tables':[],'scenario_review':False,'legacy_table':True,'calculation_mode':'service_components','components':components,'one_time_evidence':retro_ev+acomp_ev+total_ev,'monthly_evidence':[], 'bundle_estimated':components[1]['estimated'],'implantation_amount':fee,'implantation_state':fs,'implantation_mode':'conditional' if fee is not None else 'unknown','conditional_total':v6.money(Decimal(total)+Decimal(fee)) if fee is not None else None,'conditions':{'implantacao_condicional':impl[0]['trecho']} if fee is not None else {}}
    return {'monthly_amount':None,'one_time_amount':total,'finance':patch},None


def resolve(text,title,svc,previous):
    old=v6.obj(previous.get('finance_v2')); base=v6.enrich(text,title,svc,previous)
    svc=base['services']
    if len(svc)>1:
        result,reason=bundled_retroactive(text,svc)
        return result,reason,svc
    if len(svc)!=1:return None,'serviço não identificado',svc
    if svc[0] in MONTHLY:
        result,reason=monthly_rescue(text,svc[0],base)
        if result:
            f=result['finance']
            if (v6.money(previous.get('monthly_amount'))==result['monthly_amount'] and old.get('monthly_state')=='identified'
                and (not f.get('bands') or old.get('bands')) and (not f.get('annual_quantity') or old.get('annual_quantity')==f['annual_quantity'])
                and (not f.get('base_quantity') or old.get('base_quantity')==f['base_quantity']) and not f.get('annual_reference_conflict')):
                return None,None,svc
            result['finance']['reader_version']=9
            return result,None,svc
        if reason and 'diverg' in reason:
            return None,reason,svc
        if base['monthly_amount'] is not None and base['finance'].get('monthly_state')=='identified':
            # V6 supports older pre/post and modern structures. Only recover a
            # missing price or explicitly pending one, never regress later identity/volumes.
            if previous.get('monthly_amount') is None or old.get('monthly_state') in ('missing','conflict','previous_review'):
                keys=('monthly_state','monthly_evidence','billing_mode','billing_options','post_fixed_terms','illustrative_tables','bands','table_total','table_quantity','legacy_table','band_conflict','calculation_mode','base_quantity','base_label')
                return {'monthly_amount':base['monthly_amount'],'finance':{k:base['finance'][k] for k in keys if k in base['finance']}},None,svc
            return None,None,svc
        return None,reason or 'mensalidade ainda pendente',svc
    if svc==['RETROATIVAS']:
        if base['one_time_amount'] is not None and base['finance'].get('one_time_state')=='identified' and (previous.get('one_time_amount') is None or old.get('one_time_state') in ('missing','conflict','previous_review')):
            keys=('monthly_state','one_time_state','one_time_evidence','service_amount','service_amount_state','implantation_amount','implantation_mode','implantation_state','conditional_total','bands','table_total','table_quantity','legacy_table','band_conflict','calculation_mode','base_quantity','base_label')
            return {'monthly_amount':None,'one_time_amount':base['one_time_amount'],'finance':{k:base['finance'][k] for k in keys if k in base['finance']}},None,svc
        return None,('valor único ainda pendente' if previous.get('one_time_amount') is None else None),svc
    return None,'serviço não suportado',svc


def generate(data,root):
    proposals={int(p['id']):p for p in data['proposals']}; metas={int(m['proposal_id']):m for m in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[]; pending=[]; report=[]; c=collections.Counter()
    for pid,p in proposals.items():
        m=metas.get(pid); reason=None
        try:
            if not m or len(docs[pid])!=1:raise ValueError('metadados ou documento único não localizado')
            doc=docs[pid][0]; path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or sha256(path)!=doc['sha256'] or m['document_sha256']!=doc['sha256']:raise ValueError('hash ou caminho divergente')
            text=text_of(path)
            if len(clean(text))<40:raise ValueError('sem texto extraível')
            c['documentos_lidos']+=1
            svc=list_json(m['services']); core_svc=list_json(p.get('service_codes'))
            if svc and core_svc and set(svc)!=set(core_svc):raise ValueError('serviços do cadastro e metadados divergem')
            named=v6.services(m['title'])
            if len(named)==1 and (svc or core_svc) and named[0] not in (svc or core_svc):raise ValueError('serviço do título diverge do cadastro')
            old=v6.obj(m['evidence']); prior=v6.obj(old.get('finance_v2')); previous={**old,'monthly_amount':m.get('monthly_amount'),'one_time_amount':m.get('one_time_amount')}
            result,reason,svc=resolve(text,m['title'],svc or core_svc,previous)
            if result:
                f={**prior,**result['finance'],'version':2,'reader_version':9}
                f['conditions']={**v6.obj(prior.get('conditions')),**v6.obj(result['finance'].get('conditions'))}
                # Preserve known values except when resolving explicit bundle billing.
                monthly=v6.money(result.get('monthly_amount',m.get('monthly_amount'))); once=v6.money(result.get('one_time_amount',m.get('one_time_amount')))
                bundle=f.get('billing_mode')=='historical_bundle_one_time'
                if not bundle and monthly is not None and m.get('monthly_amount') is not None and v6.money(m['monthly_amount'])!=monthly:
                    raise ValueError('mensalidade anterior diverge do documento; revisão necessária')
                if once is not None and m.get('one_time_amount') is not None and v6.money(m['one_time_amount'])!=once:
                    raise ValueError('valor pontual anterior diverge do documento; revisão necessária')
                if not bundle and prior.get('base_quantity') is not None and f.get('base_quantity') is not None and str(prior['base_quantity'])!=str(f['base_quantity']):
                    raise ValueError('base anterior diverge; revisão necessária')
                if prior.get('implantation_amount') is not None and 'implantation_amount' in result['finance'] and v6.money(prior['implantation_amount'])!=f.get('implantation_amount'):
                    raise ValueError('implantação anterior diverge; revisão necessária')
                core={}
                def project(k,val,allowed):
                    oldvalue=v6.money(p.get(k)); permitted={v6.money(x) for x in allowed if x is not None}
                    if oldvalue==val:return
                    if p.get(k) is None or oldvalue in permitted:core[k]=val
                    else:raise ValueError('campo principal divergente: '+k)
                if monthly is not None or bundle or svc==['RETROATIVAS']:
                    project('monthly_final',monthly,[m.get('monthly_amount')])
                if once is not None and (bundle or svc==['RETROATIVAS']):
                    fee=f.get('implantation_amount'); total=v6.money(Decimal(once)+Decimal(fee)) if fee is not None and f.get('implantation_mode')=='stated' else once
                    prev=m.get('one_time_amount'); pi=prior.get('implantation_amount'); oldsum=v6.money(Decimal(str(prev))+Decimal(str(pi))) if v6.money(prev) is not None and v6.money(pi) is not None else None
                    project('one_time_final',total,[prev,pi,oldsum])
                if not core_svc:core['service_codes']=svc
                if (bundle or svc==['RETROATIVAS']) and f.get('base_quantity') is not None and p.get('retro_total') is None:core['retro_total']=int(f['base_quantity'])
                if not bundle and svc!=['RETROATIVAS'] and f.get('annual_quantity') is not None:
                    target=int(f['annual_quantity']); stored=p.get('recurring_total')
                    if stored is None:core['recurring_total']=target
                    elif int(stored)!=target:
                        # A contradictory annual statement may previously have been projected.
                        # Preserve a nonempty core value; the user must check it before contracting.
                        pending.append({'proposal_id':pid,'reason':'volume anual no cadastro diverge da base extraída; cadastro preservado para conferência'})
                meta={'monthly_amount':monthly,'one_time_amount':once,'services':svc}
                old_meta={'monthly_amount':v6.money(m.get('monthly_amount')),'one_time_amount':v6.money(m.get('one_time_amount')),'services':list_json(m['services'])}
                # Do not update rows already identical on a repeated run.
                if meta!=old_meta or f!=prior or core:
                    rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':meta,'evidence':{'finance_v2':f,'finance_v9':{'version':9,'source':'document_sha256'}},'core':core})
                    c['atualizacoes']+=1
                    c['mensalidades_recuperadas']+=monthly is not None and m.get('monthly_amount') is None
                    c['valores_pontuais_recuperados']+=once is not None and m.get('one_time_amount') is None
                    c['pacotes_pontuais']+=bundle
                if any(z.get('table_pending') for z in f.get('components',[])):
                    pending.append({'proposal_id':pid,'reason':'preços dos serviços recuperados; tabela por serviço ainda precisa de conferência'})
                if f.get('annual_reference_conflict'):
                    pending.append({'proposal_id':pid,'reason':'volume anual divergente no documento; base de investimento preservada','values':f['annual_reference_conflict']})
                report.append([pid,m['title'],','.join(svc),m.get('monthly_amount'),monthly,m.get('one_time_amount'),once,f.get('implantation_amount'),f.get('base_quantity'),f.get('annual_quantity'),f.get('billing_mode'),'recuperado'])
            else:
                if reason:pending.append({'proposal_id':pid,'reason':reason})
                report.append([pid,m['title'],','.join(svc),m.get('monthly_amount'),m.get('monthly_amount'),m.get('one_time_amount'),m.get('one_time_amount'),prior.get('implantation_amount'),prior.get('base_quantity'),prior.get('annual_quantity'),prior.get('billing_mode'),reason or 'preservado'])
        except Exception as exc:
            pending.append({'proposal_id':pid,'reason':type(exc).__name__+': '+str(exc)[:200]});c['falhas_preservadas']+=1
            report.append([pid,m.get('title','') if m else '', '',m.get('monthly_amount') if m else None,m.get('monthly_amount') if m else None,m.get('one_time_amount') if m else None,m.get('one_time_amount') if m else None,None,None,None,None,'conferir integridade / divergência'])
    for row in report:
        billing=row[10]; services=row[2].split(',')
        if billing=='historical_bundle_one_time' or services==['RETROATIVAS']:
            c['pontuais_sem_valor_apos_revisao']+=row[6] is None
        elif len(services)==1 and services[0] in MONTHLY:
            c['mensais_sem_valor_apos_revisao']+=row[4] is None
        elif len(services)>1:
            c['multisservico_a_conferir']+=1
    return {'version':9,'rows':rows,'summary':{'total_historico':len(proposals),**c,'registros_com_pendencias':len({r['proposal_id'] for r in pending})},'pending':pending},report


def main():
    p=argparse.ArgumentParser();p.add_argument('--export',type=Path,required=True);p.add_argument('--root',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    plan,report=generate(json.loads(a.export.read_text()),a.root)
    for path,value in [(a.output,plan),(a.output.with_name('resumo.json'),plan['summary']),(a.output.with_name('pendencias.json'),plan['pending'])]:
        path.write_text(json.dumps(value,ensure_ascii=False,indent=2));path.chmod(0o600)
    with a.output.with_name('revisao.csv').open('w',encoding='utf-8-sig',newline='') as fp:
        w=csv.writer(fp,delimiter=';');w.writerow(['id','proposta','servicos','mensal_anterior','mensal_novo','pontual_anterior','pontual_novo','implantacao','base','volume_anual','modalidade','situacao_leitura']);w.writerows(report)
    print(json.dumps(plan['summary'],ensure_ascii=False,indent=2))


if __name__=='__main__':main()
