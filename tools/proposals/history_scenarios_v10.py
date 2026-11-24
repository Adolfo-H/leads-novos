#!/usr/bin/env python3
"""Semantic commercial terms for historical proposals. Local-only; no repricing.
Rules select roles in the investment section, not global monetary occurrences.
All alternatives remain conditional, not acceptance or an invoice.
"""
import argparse, collections, csv, json, re
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
import history_legacy_v6 as v6
import history_values_v9 as v9
from history_signature_v10 import recover as recover_signature, missing as missing_identity
from extract_history import clean, norm, sha256, text_of, list_json

CODE = re.compile(r"(?<![A-Z0-9])EC[ _\-–]*(20\d{2})[ _\-–]+(\d{1,10})(?!\d)", re.I)

CASH = r'(?:R\$\s*)?(\d+(?:\.\d{3})*,\d{2})(?!\d|[.,]\d)'
PCT = r'(\d{1,3}(?:,\d{1,4})?)\s*%'
HEADER = re.compile(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(?:INVESTIMENTO(?:\s+DO\s+PROJETO)?|CONDICOES\s+COMERCIAIS)\s*$', re.M)
END = re.compile(r'^\s*(?:ATENCIOSAMENTE\b|\d+(?:\.\d+)+\.?\s+(?:COMPARATIVO|ESCOPO|OBJETIVO|DOCUMENTOS DE REFERENCIA)\b)', re.M)
FEE = r'(?:CRIACAO\s+(?:E\s+CONFIGURACAO\s+)?DO\s+AMBIENTE|TAXA\s+DE\s+CRIACAO(?:\s+E\s+CONFIGURACAO)?\s+DO\s+AMBIENTE|IMPLANTACAO(?:\s+ESPECIFICA)?|INSTALACAO\s*/\s*CONFIGURACAO\s*/\s*TREINAMENTO)'
NO_LICENSE = r'(?:CASO\s+NAO\s+(?:SEJA\s+CONTRATAD[OA]|OCORRA\s+ACEITE)|SEM\s+(?:O\s+)?LICENCIAMENTO|NAO\s+SERA\s+COBRAD[OA])'
CONDITIONAL_DISCOUNT = r'(?:DESCONTO\s+OFERECIDO\s+EM\s+TROCA\s+DO\s+ACEITE|DESCONTO.{0,170}\bEM\s+TROCA\s+DO\s+ACEITE|DESCONTO.{0,100}\bCONDICIONADO)'
ROLE_PATTERNS = {
 'gross': r'VALOR\s+DO\s+SERVICO(?:\s+(?:DA|DO)\s+(?:EXPORTCONTROL|J\s+TOLEDO\s+SOFTWARES))?',
 'discounted': r'(?:VALOR\s+(?:FINAL|LIQUIDO)\s+DO\s+SERVICO(?:\s+DA\s+EXPORTCONTROL)?|VALOR\s+DO\s+SERVICO(?:\s+DA\s+EXPORTCONTROL)?\s+COM\s+DESCONTO|VALOR\s+DO\s+INVESTIMENTO\s+CONSIDERANDO\s+O\s+DESCONTO)',
 'discount': r'(?:VALOR\s+D[EO]\s+)?DESCONTO(?:\s+(?:OFERECIDO\s+AO\s+CLIENTE|DO\s+SERVICO|OFERECIDO\s+EM\s+TROCA\s+DO\s+ACEITE\s+DA\s+PROPOSTA\s+COMERCIAL\s+EC[ _-]+20\d{2}[ _-]+\d+))?',
 'service': r'(?:INVESTIMENTO|VALOR\s+DO\s+SERVICO|CONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?)',
 'fee': FEE,
}
SEPARATOR=r'\s*(?::|[-–—]|SERA\s+DE|E\s+DE|NO\s+VALOR\s+DE|DE)?\s*'

def money_br(raw):
    return v6.money(raw.replace('.','').replace(',','.'))

def uniq(xs): return list(dict.fromkeys(xs))

def candidates(text,pat):
    return [(money_br(m[1]),clean(m.group())) for m in re.finditer(pat,norm(text),re.I) if money_br(m[1]) is not None]

def role(text,key):
    # Anchored labels avoid "valor do serviço" inside a comparison or a long clause.
    return candidates(text,r'(?m)^\s*[➢•▪✓\-–—]?\s*'+ROLE_PATTERNS[key]+SEPARATOR+CASH+r'(?=\s*(?:[.;]?\s*$|\())')

def values(c): return uniq([v for v,_ in c])
def only(c):
    vs=values(c); return vs[0] if len(vs)==1 else None

def amount(a): return Decimal(a) if a is not None else None

def add(a,b): return v6.money(amount(a)+amount(b))
def sub(a,b): return v6.money(amount(a)-amount(b))
def pct_off(gross,p): return v6.money((amount(gross)*(1-Decimal(p)/100)).quantize(Decimal('.01'),rounding=ROUND_HALF_UP))

def investment(text):
    n=norm(text); starts=list(HEADER.finditer(n))
    # The earliest *investment* heading is the start. Exclude earlier comparisons.
    starts=[m for m in starts if 'INVESTIMENTO' in m.group()]
    if not starts:return None
    start=starts[0].end(); end=END.search(n,start)
    return n[start:end.start() if end else len(n)]

def source_evidence(rows): return [v6.ev(v,s) for v,s in rows][:60]

def scenarios(text,svc):
    """Resolve discounts, service totals and setup as different semantic roles.
    Refuse unsupported contradictory arithmetic and mixed unknown services.
    """
    if svc!=['RETROATIVAS']:return None,None
    n=norm(clean(text)); inv=investment(text)
    if not inv or not v9.POINT_MODEL.search(n):return None,None
    flat=clean(inv)
    gross_rows=role(inv,'gross'); final_rows=role(inv,'discounted'); discount_rows=role(inv,'discount')
    # Conditional discount table labels may wrap onto the following line.
    final_rows+=candidates(flat,r'\bVALOR\s+DO\s+INVESTIMENTO\s+CONSIDERANDO\s+O\s+DESCONTO'+SEPARATOR+CASH)
    totals=candidates(flat,r'\b(?:O\s+)?(?:VALOR\s+TOTAL\s+(?:DO\s+INVESTIMENTO|DOS?\s+SERVICOS?)|INVESTIMENTO\s+TOTAL)\s*(?:SERA\s+DE|SERIA\s+DE|SERA|E\s+DE|:|DE)\s*'+CASH)
    explicit_service=role(inv,'service')
    explicit_service+=candidates(flat,r'\b(?:FICANDO\s+SOMENTE\s+O\s+VALOR\s+DO\s+SERVICO|VALOR\s+DO\s+SERVICO\s+FICARA\s+EM)\s*'+CASH)
    fees=role(inv,'fee')
    fees+=candidates(flat,FEE+r'\s*(?:NO\s+VALOR\s+DE|VALOR\s+DE|DE|:)\s*'+CASH)
    # Require a setup-specific clause, not an arbitrary amount elsewhere.
    fees+= [(e['valor'],e['trecho']) for e in v6.conditional_fee(text)]
    if len(values(fees))>1:return None,'implantação: valores realmente divergentes'
    fee=only(fees)
    conditional_fee=fee is not None and bool(re.search(NO_LICENSE,flat))
    # A bare "desconto" must refer to a labelled commercial row, not comparative wages.
    percent_rows=re.findall(r'(?m)^\s*(?:DESCONTO(?:\s+DO\s+SERVICO)?)\s*:?\s*'+PCT+r'\s*$',inv)
    percent=uniq([p.replace(',','.') for p in percent_rows])
    has_discount=bool(discount_rows or percent or re.search(r'(?m)^\s*(?:VALOR DE )?DESCONTO\b',inv))
    # Do not reinterpret ordinary no-discount retro proposals unless an actual
    # setup/discount distinction is needed. Existing V9 handles modern gross-up.
    if not has_discount and fee is None:return None,None
    if re.search(r'\b(?:VALOR\s+LIQUIDO|TRIBUTOS\s+INCORPORADOS|GROSS-UP)\b',flat):return None,None
    tab=v9.parsed_tables(inv)
    if len(tab)>1:return None,'mais de uma tabela de preço sem classificação inequívoca'
    t=tab[0] if tab else None
    gross=only(gross_rows); discount=only(discount_rows); final=only(final_rows)
    if len(values(discount_rows))>1 or len(percent)>1 or len(values(final_rows))>1:return None,'desconto ou preço final divergente'
    # A repeated generic service label may be gross and discounted (Moreira).
    if len(values(gross_rows))>1:
        if final is not None and len(values(gross_rows))==2 and final in values(gross_rows):
            gross=next(v for v in values(gross_rows) if v!=final)
        else:return None,'valores brutos do serviço divergentes'
    if has_discount:
        if gross is None or (discount is None and not percent):return None,'desconto sem valor bruto ou regra explícita'
        computed=sub(gross,discount) if discount is not None else pct_off(gross,percent[0])
        if computed is None or (percent and pct_off(gross,percent[0])!=computed):return None,'desconto não conciliado'
        if final is None:
            # Require an independently printed total or service amount.
            if computed not in values(totals+explicit_service):return None,'valor após desconto não confirmado no original'
            final=computed
        if final!=computed:return None,'valor final não corresponde ao desconto declarado'
        service=final
    else:
        possible=values(explicit_service+gross_rows+final_rows)
        if t and t['table_total'] not in possible:possible.append(t['table_total'])
        if len(possible)!=1:return None,'serviço e implantação não separados com segurança'
        service=possible[0];gross=service
    if t and t['table_total'] not in (service,gross):return None,'total da tabela não corresponde ao serviço'
    conditional_discount=has_discount and bool(re.search(CONDITIONAL_DISCOUNT,flat))
    if conditional_discount and not re.search(r'\bLICENCIAMENTO\b',flat):return None,'condição do desconto não identificada com segurança'
    if conditional_discount and not conditional_fee and fee is not None:return None,'condição do desconto e implantação não conciliada'
    expected_without=service
    expected_with=add(gross if conditional_discount else service,fee) if fee is not None else (gross if conditional_discount else service)
    allowed={service,expected_with}
    if conditional_discount:allowed.add(gross)
    if totals and any(v not in allowed for v in values(totals)):return None,'total declarado não corresponde a nenhum cenário comercial'
    if not totals and not final_rows and t is None:return None,'sem total ou tabela de confirmação'
    if fee is not None and not conditional_fee and expected_with not in values(totals):return None,'implantação sem total combinado confirmado'
    # In a conditional discount the second scenario explicitly needs no acceptance assumption.
    discount_amount=sub(gross,service) if has_discount else None
    notes=[]
    if conditional_discount:
        notes.append('Desconto condicionado ao aceite indicado no documento; nenhum aceite presumido.')
    conditions={}
    if conditional_fee:
        clauses=re.findall(r'\b(?:CASO\s+(?:NAO\s+SEJA\s+CONTRATAD[OA]|HAJA\s+O\s+ACEITE|NAO\s+OCORRA\s+ACEITE)).{0,420}',flat)
        if clauses:conditions['implantacao_condicional']=clauses[0][:420]
    scenarios_out=[]
    if conditional_discount:
        scenarios_out=[{'label':'Com aceite do licenciamento (desconto condicionado)','service':service,'implantation':None,'total':service},{'label':'Sem aceite do licenciamento','service':gross,'implantation':fee,'total':expected_with}]
    elif conditional_fee:
        scenarios_out=[{'label':'Sem cobrança de implantação','service':service,'implantation':None,'total':service},{'label':'Se a implantação for devida','service':service,'implantation':fee,'total':expected_with}]
    elif fee is not None:
        scenarios_out=[{'label':'Serviço e implantação','service':service,'implantation':fee,'total':expected_with}]
    ev=source_evidence(gross_rows+discount_rows+final_rows+explicit_service+fees+totals)
    patch={'billing_mode':'historical_one_time_scenarios','monthly_state':'not_applicable','one_time_state':'identified','service_amount':service,'service_amount_state':'identified','one_time_evidence':ev,'monthly_evidence':[], 'implantation_amount':fee,'implantation_state':'identified' if fee is not None else 'missing','implantation_mode':'conditional' if conditional_fee else ('stated' if fee is not None else 'unknown'),'conditional_total':expected_with if conditional_fee else None,'calculation_mode':'progressive' if t else 'single_fee','band_conflict':False,'scenario_review':False,'conditions':conditions,'pricing_terms_v10':{'kind':'one_time','gross':gross,'discount_amount':discount_amount,'discount_percent':percent[0] if percent else None,'conditional_discount':conditional_discount,'scenarios':scenarios_out,'notes':notes,'evidence':ev}}
    if t:patch.update({k:t[k] for k in ('bands','table_total','table_quantity')});patch.update(legacy_table=True,base_quantity=t['table_quantity'],base_label='Base total de documentos')
    else:
        patch.update(bands=[],table_total=None,table_quantity=None)
        q,qs=v6.consensus(v6.retro_quantity(text))
        if qs=='identified':patch.update(base_quantity=q,base_label='Base total de documentos')
    return {'monthly_amount':None,'one_time_amount':service,'finance':patch},None

def percentage_addon(text,svc):
    if len(svc)!=1 or svc[0] not in ('DFE','NFSE','LICENCIAMENTO','ACOMPANHAMENTO'):return None,None
    n=norm(text)
    dev=candidates(n,r'(?m)^\s*DESENVOLVIMENTO\s*:\s*(?:O\s+)?VALOR\s+TOTAL\s+DO\s+INVESTIMENTO\s*(?:SERA\s+DE|E\s+DE|:)\s*'+CASH)
    # Restrict to explicit conditional additional percentage, not a tax or discount.
    match=list(re.finditer(r'(?m)^\s*MENSALIDADE\s*:\s*([^\f]{1,550}?\bADICIONAL\s+DE\s+'+PCT+r'\s+SOBRE\s+A\s+MENSALIDADE\b[^\n.]*)',n))
    if not match:return None,None
    if len(match)!=1 or only(dev) is None:return None,'adicional percentual sem desenvolvimento inequívoco'
    clause=clean(match[0][1]); rate=match[0][2].replace(',','.')
    if not re.search(r'\bCASO\b|\bSE\b',clause) or not 0<Decimal(rate)<=100:return None,'regra percentual não confirmada'
    # Reject if an explicit fixed monthly price is also offered in this document.
    if v9.explicit_monthly(text,svc[0]):return None,'percentual e valor mensal fixo coexistem; revisar'
    service=only(dev); ev=source_evidence(dev)
    return {'monthly_amount':None,'one_time_amount':service,'finance':{'billing_mode':'percentage_addon','monthly_state':'formula','one_time_state':'identified','service_amount':service,'service_amount_state':'identified','monthly_evidence':[],'one_time_evidence':ev,'implantation_amount':None,'implantation_state':'missing','implantation_mode':'unknown','conditional_total':None,'calculation_mode':'custom_development','band_conflict':False,'scenario_review':False,'conditions':{'pagamento':'Desenvolvimento pago na entrega do projeto' if 'PAGO NA ENTREGA DO PROJETO' in clean(n) else 'Conforme proposta','mensalidade_percentual':clause},'pricing_terms_v10':{'kind':'percentage_addon','development_amount':service,'percent':rate,'condition':clause,'evidence':ev}}},None

def resolve(text,title,svc,previous):
    svc=v6.classify(title,text,svc)
    for reader in (percentage_addon,scenarios):
        result,reason=reader(text,svc)
        if result or reason:return result,reason,svc
    return v9.resolve(text,title,svc,previous)

def cover_numbers(text):
    cover=text.split('\f')[0][:2500]
    # In Word no page break may exist: stop before the objective or scope.
    cover=re.split(r"(?im)^\s*(?:\d+(?:\.\d+)*\.?\s+)?OBJETIVO\s*$",cover,maxsplit=1)[0]
    return uniq([f'{m[1]}/{m[2]}' for m in CODE.finditer(cover)])

def audit_number(text,docname):
    found=cover_numbers(text)
    names=uniq([f'{m[1]}/{m[2]}' for m in CODE.finditer(docname)])
    if len(found)==1 and len(names)==1 and found!=names:return {'document_number':found[0],'filename_number':names[0],'reason':'número na capa diverge do nome do arquivo'}
    return None

# Use V9's proven batch shape, with a V10-specific projection for new scenarios.
def generate(data,root):
    proposals={int(p['id']):p for p in data['proposals']}; metas={int(m['proposal_id']):m for m in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[]; pending=[]; report=[]; stats=collections.Counter(); identities=collections.defaultdict(list)
    for pid,p in proposals.items():
        m=metas.get(pid); old={}; prior={}; svc=[]; monthly=once=None; f={}; outcome='preservado'; special=None; text=None; doc=None; nums=[]; mismatch=None; row_start=len(rows); sig_meta={}; sig_core={}; sig=None
        def warn(reason,**kw):pending.append({'proposal_id':pid,'reason':reason,**kw})
        try:
            if not m or len(docs[pid])!=1:raise ValueError('metadados ou documento único ausente')
            doc=docs[pid][0]; path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or sha256(path)!=doc['sha256'] or m['document_sha256']!=doc['sha256']:raise ValueError('hash ou caminho divergente')
            text=text_of(path)
            if len(clean(text))<40:raise ValueError('sem texto extraível')
            stats['documentos_lidos']+=1
            old=v6.obj(m.get('evidence'));prior=v6.obj(old.get('finance_v2'));f=dict(prior)
            sig_meta,sig_core,sig,sig_warning=recover_signature(text,p,m)
            if sig_warning:warn(sig_warning)
            monthly=v6.money(m.get('monthly_amount'));once=v6.money(m.get('one_time_amount'))
            svc=list_json(m.get('services')); core_svc=list_json(p.get('service_codes'))
            if svc and core_svc and set(svc)!=set(core_svc):raise ValueError('serviços do cadastro e metadados divergem')
            original_svc=svc or core_svc
            docname=doc.get('original_name') or m.get('document_name') or m['title']
            mismatch=audit_number(text,docname)
            if mismatch:warn(mismatch['reason'],**{k:v for k,v in mismatch.items() if k!='reason'})
            nums=cover_numbers(text)
            if len(nums)==1:identities[nums[0]].append((pid,doc['sha256']))
            result,reason,svc=resolve(text,m['title'],original_svc,{**old,'monthly_amount':monthly,'one_time_amount':once})
            if reason and (not (once is not None and (svc==['RETROATIVAS'] or prior.get('billing_mode')=='historical_bundle_one_time')) or 'diverg' in reason or 'concili' in reason):warn(reason);outcome=reason
            if result:
                patch=result['finance']; special=patch.get('pricing_terms_v10')
                if original_svc and set(svc)!=set(original_svc):raise ValueError('serviço anterior diverge; preservar vínculo')
                fm=v6.money(result.get('monthly_amount',monthly));fo=v6.money(result.get('one_time_amount',once))
                # Never replace an already populated conflicting financial value.
                # For the newly modelled percent addon, a prior fixed value is a genuine conflict.
                if monthly is not None and fm!=monthly and patch.get('billing_mode')!='historical_bundle_one_time':raise ValueError('mensalidade anterior diferente; revisar antes de substituir')
                if once is not None and fo!=once:raise ValueError('valor pontual anterior diferente; revisar antes de substituir')
                if prior.get('implantation_amount') is not None and 'implantation_amount' in patch and v6.money(prior['implantation_amount'])!=v6.money(patch['implantation_amount']):raise ValueError('implantação anterior diferente; revisão necessária')
                if prior.get('base_quantity') is not None and patch.get('base_quantity') is not None and str(prior['base_quantity'])!=str(patch['base_quantity']):raise ValueError('base anterior diferente; revisão necessária')
                f={**prior,**patch,'version':2,'reader_version':10};f['conditions']={**v6.obj(prior.get('conditions')),**v6.obj(patch.get('conditions'))}
                core={}
                def project(k,val,allowed=()):
                    stored=v6.money(p.get(k))
                    if stored==val:return
                    if p.get(k) is None or stored in {v6.money(z) for z in allowed if z is not None}:core[k]=val
                    else:warn('campo principal preservado: '+k)
                if fm is not None or patch.get('billing_mode')=='historical_bundle_one_time':project('monthly_final',fm,[monthly])
                if fo is not None:
                    total=fo
                    fee=f.get('implantation_amount')
                    if fee is not None and f.get('implantation_mode')=='stated' and (svc==['RETROATIVAS'] or f.get('billing_mode')=='historical_bundle_one_time'):total=add(fo,fee)
                    # Core projection of proposal reference only; no acceptance or invoice.
                    project('one_time_final',total,[once,prior.get('implantation_amount')])
                if not core_svc and svc:core['service_codes']=svc
                if svc==['RETROATIVAS'] and f.get('base_quantity') is not None and p.get('retro_total') is None:core['retro_total']=int(f['base_quantity'])
                if f.get('annual_quantity') is not None and p.get('recurring_total') is None and svc!=['RETROATIVAS']:core['recurring_total']=int(f['annual_quantity'])
                meta={'monthly_amount':fm,'one_time_amount':fo,'services':svc}
                extra={'version':10,'source':'document_sha256','number_warning':mismatch,'document_number':nums[0] if len(nums)==1 else None}
                if meta!={'monthly_amount':monthly,'one_time_amount':once,'services':list_json(m.get('services'))} or f!=prior or old.get('finance_v10')!=extra or core:
                    rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':meta,'evidence':{'finance_v2':f,'finance_v10':extra},'core':core});stats['atualizacoes']+=1
                    stats['mensalidades_recuperadas']+=fm is not None and monthly is None
                    stats['pontuais_recuperados']+=fo is not None and once is None
                    stats['regras_percentuais']+=f.get('billing_mode')=='percentage_addon'
                    stats['cenarios_comerciais']+=bool(special and special.get('scenarios'))
                monthly,once=fm,fo;outcome='revisado'
            elif (mismatch or (not m.get('historic_number') and len(nums)==1)) and v6.obj(old.get('finance_v10'))!={'version':10,'source':'document_sha256','number_warning':mismatch,'document_number':nums[0] if len(nums)==1 else None}:
                # Persist warning alone without changing financial state.
                f={**prior,'version':2,'reader_version':10}
                if (svc==['RETROATIVAS'] or f.get('billing_mode')=='historical_bundle_one_time') and monthly is not None:raise ValueError('aviso de número preservado apenas no relatório: valor mensal incompatível')
                rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':{'monthly_amount':monthly,'one_time_amount':once,'services':svc},'evidence':{'finance_v2':f,'finance_v10':{'version':10,'source':'document_sha256','number_warning':mismatch,'document_number':nums[0] if len(nums)==1 else None}},'core':{}});stats['atualizacoes']+=1
            if f.get('annual_reference_conflict'):warn('volumes anuais divergentes',values=f['annual_reference_conflict'])
            if f.get('billing_mode')=='percentage_addon':pass
            elif svc==['RETROATIVAS'] or f.get('billing_mode')=='historical_bundle_one_time':
                if once is None:warn('valor pontual não identificado');stats['pontuais_pendentes']+=1
            elif len(svc)==1 and svc[0] in v9.MONTHLY:
                if monthly is None:warn('mensalidade não identificada');stats['mensais_pendentes']+=1
            else:warn('combinação ou serviço ainda não classificado')
            if f.get('band_conflict'):warn('tabelas de cálculo divergentes')
            if any(c.get('table_pending') for c in f.get('components',[]) if isinstance(c,dict)):warn('tabela de componente ainda não estruturada')
            if f.get('implantation_state')=='conflict':warn('implantação divergente')
            if f.get('tax_conflict'):warn('composição tributária divergente')
            if f.get('monthly_state')=='previous_review' or f.get('one_time_state')=='previous_review':warn('valor anterior requer conferência')
        except Exception as exc:
            # All modifications are appended only after guards; preserve previous data on error.
            warn(type(exc).__name__+': '+str(exc)[:220]);stats['falhas_preservadas']+=1;outcome='conferir'
        if sig_meta or sig_core:
            if len(rows)>row_start:
                row=rows[-1]
            else:
                # For an identity-only correction, preserve the finance snapshot and
                # don't bypass the finance guard on contradictory historical data.
                stored_svc=list_json(m.get('services'))
                compatible_finance=not ((stored_svc==['RETROATIVAS'] or prior.get('billing_mode')=='historical_bundle_one_time') and m.get('monthly_amount') is not None)
                if compatible_finance and stored_svc:
                    row={'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':{'monthly_amount':v6.money(m.get('monthly_amount')),'one_time_amount':v6.money(m.get('one_time_amount')),'services':stored_svc},'evidence':{'finance_v2':{**prior,'version':2,'reader_version':10},'finance_v10':{'version':10,'source':'document_sha256','number_warning':mismatch,'document_number':nums[0] if len(nums)==1 else None}},'core':{}}
                    rows.append(row);stats['atualizacoes']+=1
                else:row=None;warn('assinatura recuperada, mas cadastro financeiro incompatível; conferir antes de gravar')
            if row is not None:
                row['meta'].update(sig_meta);row['core'].update(sig_core);row['evidence']['identity_v10']=sig
                stats['responsaveis_enriquecidos']+=1
        terms=f.get('pricing_terms_v10',{}) if isinstance(f,dict) else {}
        # Record source snippets only in the private report, for future patterns.
        current_pending=[x for x in pending if x['proposal_id']==pid]
        if current_pending and text:
            source=investment(text) or norm(text[-4500:])
            excerpts=[clean(line)[:260] for line in source.splitlines() if re.search(r'R\$|\d,\d{2}|\d\s*%',line)][:20]
            if excerpts:current_pending[-1]['trechos_financeiros']=excerpts
        report.append([pid,m.get('title','') if m else '',','.join(svc),m.get('monthly_amount') if m else None,monthly,m.get('one_time_amount') if m else None,once,f.get('implantation_amount'),f.get('billing_mode'),outcome,terms.get('gross'),terms.get('discount_amount'),terms.get('percent'),json.dumps(terms.get('scenarios',[]),ensure_ascii=False),nums[0] if len(nums)==1 else None,(sig_meta.get('responsible_email') or (m.get('responsible_email') if m else None)),f.get('base_quantity')])
    for number,items in identities.items():
        if len({h for _,h in items})>1:
            for pid,_ in items:pending.append({'proposal_id':pid,'reason':'número de capa repetido em documentos diferentes; preservar registros','document_number':number})
    return {'version':10,'rows':rows,'summary':{'total_historico':len(proposals),**stats,'registros_com_pendencias':len({r['proposal_id'] for r in pending})},'pending':pending},report

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--export',type=Path,required=True);parser.add_argument('--root',type=Path,required=True);parser.add_argument('--output',type=Path,required=True);a=parser.parse_args()
    plan,report=generate(json.loads(a.export.read_text()),a.root)
    for path,val in [(a.output,plan),(a.output.with_name('resumo.json'),plan['summary']),(a.output.with_name('pendencias.json'),plan['pending'])]:
        path.write_text(json.dumps(val,ensure_ascii=False,indent=2));path.chmod(0o600)
    with a.output.with_name('revisao.csv').open('w',encoding='utf-8-sig',newline='') as fp:
        w=csv.writer(fp,delimiter=';');w.writerow(['id','proposta','servicos','mensal_anterior','mensal_novo','pontual_anterior','pontual_novo','implantacao','modalidade','resultado','valor_bruto','desconto','percentual_mensal','cenarios','numero_capa','email_responsavel','base_documentos']);w.writerows([["'"+x if isinstance(x,str) and x[:1] in ('=','+','-','@','\t','\r') else x for x in row] for row in report])
    print(json.dumps(plan['summary'],ensure_ascii=False,indent=2))
if __name__=='__main__':main()
