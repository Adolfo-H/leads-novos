#!/usr/bin/env python3
"""Source-first history correction. No pricing engine, no CRM mutation."""
import argparse, collections, csv, json, re
from decimal import Decimal
from pathlib import Path
import history_metadata_v3 as v3
import history_finance_v2 as v2
from extract_history import text_of, sha256, clean, norm, words, list_json, services, EMAIL

AMOUNT=r'R\$\s*(\d+(?:\.\d{3})*,\d{2})(?!\d)'
NUM=r'(\d+(?:\.\d{3})*)'

def obj(x):
    if isinstance(x,str):
        try: x=json.loads(x)
        except ValueError: return {}
    return x if isinstance(x,dict) else {}

def unique(xs): return list(dict.fromkeys(x for x in xs if x is not None))
def ev(value,source,rank=120): return {'valor':value,'trecho':clean(source)[:700],'prioridade':rank,'linha':0}
def consensus(rows):
    vals=unique(x['valor'] for x in rows)
    return (vals[0],'identified') if len(vals)==1 else (None,'conflict' if vals else 'missing')
def money(x):
    try:
        d=Decimal(str(x)); return format(d,'.2f') if d.is_finite() and 0<=d<=Decimal('999999999.99') and d==d.quantize(Decimal('.01')) else None
    except Exception: return None

def classify(title,text,existing):
    # Prefer filename and cover. Exclusions and references in body cannot define the service.
    explicit=services(title)
    if len(explicit)==1 and not existing: return explicit
    if existing: return existing
    if explicit: return explicit
    cover=text.split('\f')[0]
    for line in cover.splitlines()[:25]:
        if re.match(r'^\s*(?:PROPOSTA|CONSULTAS RETROATIVAS|CAPTURA E GESTAO|LICENCIAMENTO|ACOMPANHAMENTO)',norm(line)):
            found=services(line)
            if len(found)==1:return found
    # Older retro proposals use this explicit objective AND one-off service language.
    n=norm(clean(text))
    if re.search(r'CONSULTAR OS EVENTOS DE AVERBACAO',n) and re.search(r'MODELO\s*[“"\']?PRESTACAO DE SERVICO|VALOR TOTAL DO INVESTIMENTO',n) and not re.search(r'MODELO\s*[“"\']?MENSALIDADE',n):return ['RETROATIVAS']
    # Historical licensing documents did not name the service in the file title.
    # Require an explicit recurring model, software access and export scope. Mere
    # mentions of other services in exclusions do not establish a service.
    scope=re.search(r'\b1\.1\.\s*OBJETIVO\b(.*?)(?:\b1\.2\.|\Z)',n,re.S)
    objective=scope[1] if scope else ''
    if (re.search(r'MODELO\s*[“"\']?MENSALIDADE',n)
        and re.search(r'\bSAAS\b|\bCLOUD COMPUTING\b',n)
        and re.search(r'FORNECER\s+(?:A\s+)?FERRAMENTA|DISPONIBILIZAR\s+(?:A\s+)?FERRAMENTA',objective)
        and re.search(r'EXPORTACAO',objective)
        and not re.search(r'ACOMPANHAMENTO|NFS[- ]?E|DF[- ]?E|RETROATIV',objective)):
        return ['LICENCIAMENTO']
    return []

def signer_email(text, name):
    # A full signature may differ from its short name in the cover directory.
    if not name:return None
    selected=set(words(name).split()); options=[]
    if len(selected)<2:return None
    for i,line in enumerate(text.split('\f')[0].splitlines()):
        nm=v3.name_only(line)
        if not nm:continue
        tokens=set(words(nm).split())
        if len(tokens)<2 or not (tokens<=selected or selected<=tokens):continue
        lines=text.split('\f')[0].splitlines()
        if not v3.ROLE.search(norm(line)):continue
        emails=[]
        for nxt in lines[i:i+7]:
            if nxt!=line and (v3.ROLE.search(norm(nxt)) or v3.name_only(nxt)):break
            emails+=v3.company_mail(nxt)
        options+=emails
    vals=unique(options)
    return vals[0] if len(vals)==1 else None

def direct(text,patterns):
    out=[]
    for pat in patterns:
        for m in re.finditer(pat+AMOUNT,norm(text),re.I):
            value=v2.decimal(m[1]);
            if value is not None:out.append(ev(value,m.group()))
    return out

POINT=[r'\bVALOR\s+(?:FINAL|TOTAL)\s+(?:DO\s+SERVICO|DAS\s+CONSULTAS(?:\s+RETROATIVAS)?|DO\s+INVESTIMENTO)(?:\s+DA\s+EXPORTCONTROL)?\s*(?:[:–-]|SERA\s+DE|E\s+DE|DE)?\s*',r'\bVALOR\s+DO\s+SERVICO(?:\s+(?:DA|DO)\s+(?:EXPORTCONTROL|J\s+TOLEDO\s+SOFTWARES(?:\s+LTDA)?))?\s*(?:[:–-]|SERA\s+DE|E\s+DE|DE)?\s*',r'\b(?:INVESTIMENTO\s+TOTAL|VALOR\s+DAS\s+CONSULTAS(?:\s+RETROATIVAS)?|VALOR\s+DA\s+PRESTACAO\s+DE\s+SERVICO|VALOR\s+UNICO|PAGAMENTO\s+UNICO)\s*(?:[:–-]|SERA\s+DE|E\s+DE|DE)?\s*']
MONTH=[r'\b(?:INVESTIMENTO|VALOR|CUSTO)\s+MENSAL(?:\s+FINAL)?\s*(?:[:–-]|SERA\s+DE|E\s+DE|DE)\s*',r'\bMENSALIDADE\s+(?:FINAL|TOTAL|FIXA)\s*(?:[:–-]|SERA\s+DE|E\s+DE|DE)?\s*',r'\bMENSALIDADE\s*[:,–-]\s*(?:NO\s+VALOR\s+DE\s*)?']
CONDITIONAL=re.compile(r'\b(?:CASO\s+NAO\s+SEJA\s+CONTRATAD[OA]|SE\s+NAO\s+(?:FOR\s+CONTRATAD[OA]|CONTRATAR)|SEM\s+(?:O\s+)?LICENCIAMENTO|NAO\s+CONTRATACAO\s+D[OE]|NAO\s+CONTRATAR)\b')
IMPL_LABEL=re.compile(r'\b(?:IMPLANTACAO|TAXA\s+DE\s+CRIACAO(?:\s+E\s+CONFIGURACAO)?(?:\s+DO\s+AMBIENTE)?|INVESTIMENTO\s+INICIAL|TAXA\s+DE\s+INSTALACAO)\b')

def conditional_fee(text):
    found=[]
    for para in re.split(r'\n[ \t]*\n|\f',text):
        original=clean(para); n=norm(original)
        if len(n)>1500 or not CONDITIONAL.search(n) or not IMPL_LABEL.search(n):continue
        # Only one stated price in the paragraph, associated with the fee, not a service total.
        for m in IMPL_LABEL.finditer(n):
            tail=n[m.end():m.end()+150]
            a=re.match(r'\s*(?:ESPECIFICA\s*)?(?:NO\s+VALOR\s+DE|VALOR\s+DE|DE|:|–|-)?\s*'+AMOUNT,tail)
            if a:found.append(ev(v2.decimal(a[1]),original));break
        else:
            vals=v2.cash_values(original)
            if len(set(vals))==1 and not re.search(r'\b(?:ISENTO|NAO SERA COBRAD[OA])\b',n):found.append(ev(vals[0],original))
    return found

def retro_quantity(text):
    # Explicit counts of total document base, never days, table unit prices or 12-month averages.
    n=norm(clean(text)); out=[]
    pat=NUM+r'\s*(?:\([^)]{1,180}\)\s*)?(?:DOCUMENTOS\s+FISCAIS|DOCUMENTOS\s+EMITIDOS|DOCUMENTOS\b|NOTAS\s+FISCAIS|NFS\b|NF-E\b)'
    for m in re.finditer(pat,n):
        start=max(0,m.start()-130); end=min(len(n),m.end()+105); around=n[start:end]
        if re.search(r'\b(?:PERIODO|PERIODOS|BASE\s+TOTAL|BASE\s+PARA\s+CALCULO|INFORMACAO\s+FORNECIDA|FORAM\s+EMITIDOS|QUANTIDADE\s+TOTAL|NOTAS\s+NO\s+PERIODO)\b',around) and not re.search(r'\b(?:POR MES|MEDIA MENSAL|ULTIMOS 12 MESES)\b',around):
            val=m[1].replace('.','');
            if 0<int(val)<=2147483647:out.append(ev(val,around))
    return out

# A simulation is not an offer. Split only on explicit headings, not on
# references such as "item 1.6.2" in paragraphs elsewhere in the document.
PRE = re.compile(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(?:MODALIDADE\s+)?PRE\s*[-–]?\s*FIXADA\b')
POST = re.compile(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(?:MODALIDADE\s+)?POS\s*[-–]?\s*FIXADA\b')
EXAMPLES = re.compile(r'^\s*(?:EXEMPLO(?:S)?\s+(?:DO|DE)\s+CALCULO|CASO\s+NAO\s+IMPORTE\s+NOTAS|IMPORTACAO\s+DE\s+[\d.]+\s+DOCUMENTOS)\b')
SIMULATION = re.compile(r'^\s*SIMULACAO\s+(?:DO|DE)\s+INVESTIMENTO\b')
IMPL_OLD = [r'\bINSTALACAO\s*/\s*CONFIGURACAO\s*/\s*TREINAMENTO\s*[:–-]\s*',r'\b(?:INVESTIMENTO\s+INICIAL|VALOR\s+DA\s+IMPLANTACAO)\s*(?:SERA\s+DE|E\s+DE|:|–|-)\s*',r'\b(?:TAXA\s+DE\s+INSTALACAO(?:\s*,?\s+CONFIGURACAO\s+E\s+TREINAMENTO)?|INSTALACAO\s*,\s*CONFIGURACAO\s+E\s+TREINAMENTO)\s*[:–-]\s*']
COUNT = r'(?<![\d.,])(\d{1,3}(?:\.\d{3})+|\d{1,9})(?![\d.,])'
DOCS = r'(?:DOCUMENTOS(?:\s+FISCAIS)?|NOTAS(?:\s+FISCAIS)?|NFS?)(?![A-Z])'
WRITTEN = r'(?:\s*\([^)]{1,180}\)\s*)?'

def count_text(v):
    n=int(v.replace('.',''))
    return str(n) if 0 <= n <= 2147483647 else None

def base_evidence(text):
    n=norm(clean(text)); monthly=[]; yearly=[]; refyear=[]
    # Restrict to textual statements, not table examples / consultation counts.
    monthly_patterns=[
        r'\b(?:NUMERO\s+MEDIO\s+DE|MEDIA\s+MENSAL(?:\s+DE)?|QUANTIDADE\s+MEDIA\s+DE)\s+'+COUNT+r'\s*(?:/\s*MES|POR\s+MES|NFS?\s*/\s*MES|NOTAS\s+POR\s+MES)'+WRITTEN,
        r'\b(?:MEDIA\s+MENSAL(?:\s+DE)?|NUMERO\s+MEDIO\s+MENSAL\s+DE|QUANTIDADE\s+MEDIA\s+MENSAL\s+DE)\s+'+COUNT+WRITTEN+r'\s*'+DOCS,
    ]
    for pat in monthly_patterns:
        for m in re.finditer(pat,n):
            near=n[m.start():m.end()+100]
            if re.search(DOCS,near) and not re.search(r'\bCONSULTAS\s+(?:POR|/)',m.group()):monthly.append(ev(count_text(m[1]),near))
    for m in re.finditer(r'\b(?:TOTAL\s+DE\s+(?:APROXIMADAMENTE\s+)?|FORAM\s+EMITIDOS\s+)'+COUNT+WRITTEN+r'\s*'+DOCS+r'\b',n):
        near=n[max(0,m.start()-100):m.end()+100]
        years=re.findall(r'\b(?:ANO\s+DE|ANO|DECORRER\s+(?:NO|DO)\s+ANO\s+DE)\s*(20\d{2})\b',near)
        if years or re.search(r'\b(?:EM|NOS\s+ULTIMOS|ULTIMOS)\s+12\s+MESES\b',near):
            yearly.append(ev(count_text(m[1]),near));refyear+=years
    return monthly,yearly,unique(refyear)

def section_tables(text):
    """Separate the quoted base table, a variable tariff, and illustrative cases."""
    parts=[]; kind='base'; buf=[]; seen_modes=set()
    def flush():
        if buf:
            parts.append((kind,'\n'.join(buf)))
            buf.clear()
    for line in text.replace('\f','\n\f\n').split('\n'):
        n=norm(line)
        next_kind=None
        if PRE.match(n):next_kind='pre';seen_modes.add('pre')
        elif POST.match(n):next_kind='post';seen_modes.add('post')
        elif EXAMPLES.match(n):next_kind='example'
        elif SIMULATION.match(n):next_kind='simulation'
        if next_kind is not None:flush();kind=next_kind
        buf.append(line)
    flush()
    tables=[]
    for mode,chunk in parts:
        for table in v3.legacy_tables(chunk.split('\n')):
            tables.append({**table,'mode':mode})
    return parts,tables,seen_modes

def improve_recurring(text,x):
    f=x['finance']; n=norm(clean(text))
    parts,tables,modes=section_tables(text)
    # Model-specific direct labels also occur in prose and may repeat on several pages.
    # Do not use an example as the only price source. Strong conflicts remain visible.
    impl=direct(clean(text),IMPL_OLD)
    if impl and f.get('implantation_mode')!='conditional':
        strong=[a for a in f.get('one_time_evidence',[]) if a.get('prioridade',0)>=100 and IMPL_LABEL.search(norm(a.get('trecho','')))]
        amount,state=consensus(strong+impl)
        f['implantation_amount']=amount;f['implantation_state']=state
        f['implantation_mode']='stated' if state=='identified' else 'unknown'
        f['implantation_evidence']=impl;f['one_time_evidence']=(strong+impl+f.get('one_time_evidence',[]))[:60]
        # In a recurring legacy proposal, the labelled installation fee is the one-off component.
        # A separately identified, different one-time service is not silently replaced.
        if state=='conflict':
            x['one_time_amount']=None;f['one_time_state']='conflict'
        elif x['one_time_amount'] in (None,amount) or f['one_time_state']=='missing':
            x['one_time_amount']=amount;f['one_time_state']=state
        elif state=='identified':
            f['one_time_state']='conflict';x['one_time_amount']=None
    base,annual,years=base_evidence(text)
    b,bs=consensus(base);a,ass=consensus(annual)
    if ass=='identified' and f.get('annual_quantity') in (None,a):
        f['annual_quantity']=a;f['annual_evidence']=annual
        if len(years)==1:f['reference_year']=years[0]
    elif ass=='conflict':f['scenario_review']=True
    if bs=='identified' and f.get('base_quantity') in (None,b):
        f['base_quantity']=b;f['quantity_evidence']=base
    elif bs=='conflict':f['scenario_review']=True
    if {'pre','post'}<=modes:
        f['billing_options']=['pre_fixed','post_fixed'];f['billing_mode']='pre_fixed_reference'
        f['base_label']='Base mensal da modalidade pré-fixada'
        f['post_fixed_terms']='Valor apurado conforme a quantidade de notas; modalidade alternativa, não contratação confirmada.'
        preparts=[chunk for mode,chunk in parts if mode=='pre']
        pretext='\n'.join(preparts)
        explicit=direct(clean(pretext),MONTH)
        pre=[t for t in tables if t['mode']=='pre' and t['table_total'] is not None and t['table_quantity'] is not None]
        # Require agreement between the explicitly labelled amount and the base table
        # (when supplied). Never choose the 0-NF or 1500-NF simulation just because it is last.
        candidates=[t for t in pre if (b is None or t['table_quantity']==b)]
        if explicit:candidates=[t for t in candidates if t['table_total'] in {e['valor'] for e in explicit}]
        signatures={json.dumps([t['bands'],t['table_quantity'],t['table_total']],sort_keys=True) for t in candidates}
        confirmed=None
        if len(signatures)==1:
            t=candidates[0]; amount,state=consensus(explicit+[ev(t['table_total'],'Tabela-base pré-fixada: total informado no documento')]);
            if state=='identified' and bs!='conflict':confirmed=t
        if confirmed is not None:
            f.update({k:confirmed[k] for k in ('bands','table_quantity','table_total')});f['legacy_table']=True;f['band_conflict']=False
            f['base_quantity']=confirmed['table_quantity'];f['monthly_state']='identified';x['monthly_amount']=confirmed['table_total']
            f['monthly_evidence']=explicit+[ev(confirmed['table_total'],'Tabela-base pré-fixada')]
            f['calculation_mode']='progressive';f['base_label']='Base mensal da modalidade pré-fixada'
        else:
            # Keep the raw evidence for review but never label any example as the base.
            f['bands']=[];f['table_total']=None;f['table_quantity']=None;f['band_conflict']=True;f['scenario_review']=True
            amount,state=consensus(explicit)
            if state=='identified' and any(t['table_total']!=amount for t in pre if b is None or t['table_quantity']==b):
                x['monthly_amount']=None;f['monthly_state']='conflict'
            elif state=='identified':x['monthly_amount']=amount;f['monthly_state']=state
            elif explicit:x['monthly_amount']=None;f['monthly_state']='conflict'
            else:x['monthly_amount']=None;f['monthly_state']='missing'
            f['base_quantity']=b
        scenarios=[{'bands':t['bands'],'table_quantity':t['table_quantity'],'table_total':t['table_total'],'mode':t['mode']} for t in tables if t['mode']=='example']
        # A zero-use example often has an empty quantity cell in the first tier.
        # Its explicit TOTAL row remains usable as an illustrative summary only.
        for mode,chunk in parts:
            if mode!='example':continue
            first=norm(chunk.strip().split('\n')[0]); target=None
            if re.match(r'^CASO\s+NAO\s+IMPORTE\s+NOTAS',first):target='0'
            else:
                match=re.match(r'^IMPORTACAO\s+DE\s+([\d.]+)\s+DOCUMENTOS',first)
                if match:target=count_text(match[1])
            if target is None:continue
            totals=[]
            for line in chunk.split('\n'):
                if not re.match(r'^\s*TOTAL\s{2,}',norm(line)):continue
                m=re.match(r'^\s*TOTAL\s+([\d.]+)\s+(?:R\$\s*)?(\d+(?:\.\d{3})*,\d{2})\s*$',norm(line))
                if m and count_text(m[1])==target:totals.append(v2.decimal(m[2]))
            totals=unique(totals)
            if len(totals)==1 and not any(t['table_quantity']==target and t['table_total']==totals[0] for t in scenarios):
                scenarios.append({'bands':[],'table_quantity':target,'table_total':totals[0],'mode':'example'})
        f['illustrative_tables']=scenarios[:12]
    elif (len(tables)>1 or any(t['mode'] in ('example','simulation','post') for t in tables)) and (f.get('band_conflict') or f.get('legacy_table')):
        # Old proposals may include examples without using both modality headings.
        # Use the main table only if the actual quoted amount AND base agree.
        explicit=direct(clean('\n'.join(chunk for mode,chunk in parts if mode in ('base','pre'))),MONTH)
        amount,state=consensus(explicit)
        candidates=[t for t in tables if t['mode'] in ('base','pre') and t['table_quantity']==b and t['table_total']==amount]
        signatures={json.dumps([t['bands'],t['table_quantity'],t['table_total']],sort_keys=True) for t in candidates}
        if state=='identified' and bs=='identified' and b is not None and len(signatures)==1:
            t=candidates[0];f.update({k:t[k] for k in ('bands','table_quantity','table_total')})
            f['band_conflict']=False;f['legacy_table']=True;f['calculation_mode']='progressive';f['base_quantity']=b
            x['monthly_amount']=amount;f['monthly_state']='identified';f['monthly_evidence']=explicit+[ev(t['table_total'],'Tabela-base com quantidade confirmada')]
        else:
            f['bands']=[];f['table_total']=None;f['table_quantity']=None;f['band_conflict']=True;f['scenario_review']=True
            f['base_quantity']=b if bs=='identified' else None
            x['monthly_amount']=amount if state=='identified' else None;f['monthly_state']=state
    if f.get('base_quantity') is not None and not f.get('billing_options'):
        f['base_label']='Base mensal de documentos'
    # Record conditions verbatim in meaning, not as a universal payment rule.
    trial=unique(re.findall(r'\b(\d{1,3})\s+DIAS\s+(?:DE\s+TESTE(?:S)?|DE\s+TESTE\s+DA\s+SOLUCAO)',n))
    if len(trial)==1:f['conditions']['teste']=trial[0]+' dias de teste'
    if re.search(r'PRIMEIRO PAGAMENTO DA MENSALIDADE.{0,180}APROXIMADAMENTE\s+60\s+DIAS',n):
        f['conditions']['pagamento']='Primeira mensalidade ao término do mês subsequente ao teste (aproximadamente 60 dias), conforme documento.'


def enrich(text,title,existing,previous=None):
    svc=classify(title,text,existing); x=v3.parsed(text,title,svc); f=x['finance']; ident=x['identity']; old=previous or {}; prior=obj(old.get('finance_v2')); retro=x['services']==['RETROATIVAS']; single=len(x['services'])==1
    if ident['responsible_name'] and not ident['responsible_email']:
        email=signer_email(text,ident['responsible_name'])
        if email:ident['responsible_email']=email;ident['responsible_source']['email_source']='assinatura e nome abreviado na capa'
    # V3 has a good parser for existing progressive/current proposals. Add explicit old prose and price labels.
    if single and not retro and x['services']:
        more=direct(clean(text),MONTH)
        if more:
            strong=[a for a in f.get('monthly_evidence',[]) if a['prioridade']>=100]
            value,state=consensus(strong+more)
            x['monthly_amount']=value;f['monthly_state']=state;f['monthly_evidence']=(strong+more)[:60]
    f['conditional_total']=None; f['implantation_evidence']=[]
    conditional=conditional_fee(text)
    f['implantation_mode']='conditional' if conditional else ('stated' if f.get('implantation_amount') is not None else 'unknown')
    if conditional:
        fee,state=consensus(conditional);f['implantation_amount']=fee;f['implantation_state']=state;f['implantation_evidence']=conditional[:20];f['conditions']['implantacao_condicional']=conditional[0]['trecho'] if state=='identified' else 'Condições diferentes — consultar o original.'
    if retro:
        x['monthly_amount']=None;f['monthly_state']='not_applicable';f['annual_quantity']=None
        explicit=direct(clean(text),POINT)
        # Strong current labels corroborate old prose. Never treat installation, minimum or unit price as principal.
        known=[e for e in f.get('one_time_evidence',[]) if re.search(r'VALOR\s+(?:FINAL|TOTAL)\s+(?:DO SERVICO|DAS CONSULTAS)|VALOR DO SERVICO|INVESTIMENTO TOTAL|PAGAMENTO UNICO',norm(e.get('trecho',''))) and not IMPL_LABEL.search(norm(e.get('trecho',''))) and e['prioridade']>=85]
        if explicit:
            x['one_time_amount'],f['one_time_state']=consensus(explicit+known)
        elif f.get('service_amount') is not None and f.get('service_amount_state')=='identified':
            x['one_time_amount']=f['service_amount'];f['one_time_state']='identified'
        # V3 fallback may pick implantation as the only amount: never do that.
        elif f.get('implantation_amount')==x['one_time_amount'] and x['one_time_amount'] is not None:
            x['one_time_amount']=None;f['one_time_state']='missing'
        f['service_amount']=x['one_time_amount'];f['service_amount_state']=f['one_time_state'];f['one_time_evidence']=(explicit+known+f.get('one_time_evidence',[]))[:60]
        qty=retro_quantity(text)
        if qty:
            q,qs=consensus(qty)
            if qs=='identified' and f.get('base_quantity') not in (None,q):q,qs=None,'conflict'
            f['base_quantity']=q;f['quantity_state']=qs;f['quantity_evidence']=qty[:20]
        f['base_label']='Base total de documentos'
        n=norm(clean(text)); periods=[]
        for m in re.finditer(r'\b(?:PERIODO(?:S)?(?:\s+DE)?|PERIODOS\s+DE)\s+(20\d{2}(?:(?:\s*,\s*|\s+E\s+|\s+A\s+)20\d{2}){1,5})\b',n):
            years=re.findall(r'20\d{2}',m[1]);periods.append('–'.join([years[0],years[-1]]) if len(years)>1 else years[0])
        periods=unique(periods)
        if len(periods)==1:f['conditions']['periodo_consultado']=periods[0]
        days=unique(re.findall(r'\bPRAZO\s+(?:PARA\s+(?:A\s+)?(?:CONCLUSAO|ENTREGA)|DE\s+ENTREGA)\s*(?:SERA\s+DE|DE|:)?\s*(\d{1,4})\s+DIAS\b',n))
        if len(days)==1:f['conditions']['prazo']=days[0]+' dias'+(' corridos a partir do fornecimento dos dados necessários' if re.search(r'PRAZO PARA A CONCLUSAO SERA DE '+days[0]+r' DIAS CORRIDOS A PARTIR DO FORNECIMENTO DOS DADOS NECESSARIOS',n) else '')
        f['calculation_mode']='progressive' if f['bands'] else ('single_fee' if x['one_time_amount'] is not None and not f.get('band_conflict') and not re.search(r'^\s*FAIXA\s*(?:\d|MIN\b)|^\s*\d{1,2}\s{2,}\d+\s{2,}\d+\s{2,}R\$',norm(text),re.M) else 'unstructured')
        if f['implantation_mode']=='conditional' and x['one_time_amount'] is not None and f.get('implantation_amount') is not None:
            f['conditional_total']=money(Decimal(x['one_time_amount'])+Decimal(f['implantation_amount']))
    else:
        f['calculation_mode']='progressive' if f['bands'] else 'unstructured'
    if single and not retro:
        improve_recurring(text,x)
    if len(x['services'])>1:
        # Independent service amounts are not a consolidated total.
        x['one_time_amount']=None;f['one_time_state']='conflict'
    # Preserve old identified values/evidence for fields the new parser cannot recover, without inventing values.
    # Retro service price cannot inherit an unverified old implementation amount.
    for field,state in [('monthly_amount','monthly_state'),('one_time_amount','one_time_state')]:
        if x[field] is None and f[state]=='missing' and old.get(field) is not None and not (retro and field=='one_time_amount') and not (field=='monthly_amount' and f.get('scenario_review')):
            val=money(old[field]);
            if val and val in v2.cash_values(text):x[field]=val;f[state]='previous_review'
    f['version']=2; f['reader_version']=6
    return x

def generate(data,root):
    proposals={int(p['id']):p for p in data['proposals']}; metas={int(m['proposal_id']):m for m in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[];pending=[];c=collections.Counter()
    for pid,p in proposals.items():
        try:
            m=metas[pid]; dd=docs[pid]
            if len(dd)!=1:raise ValueError('documento histórico único não localizado')
            doc=dd[0]; path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or sha256(path)!=doc['sha256'] or doc['sha256']!=m['document_sha256']:raise ValueError('hash ou caminho divergente')
            text=text_of(path)
            if len(clean(text))<40:raise ValueError('sem texto extraível')
            existing=list_json(m['services']); core_services=list_json(p.get('service_codes')); named=services(m['title']);
            if existing and core_services and set(existing)!=set(core_services):
                pending.append({'proposal_id':pid,'reason':'serviços do cadastro e metadados divergem'});c['servicos_divergentes']+=1;continue
            existing=existing or core_services
            if len(named)==1 and existing and named[0] not in existing:
                pending.append({'proposal_id':pid,'reason':'serviço do título diverge do cadastro/CRM; vínculo preservado para conferência'}); c['servicos_divergentes']+=1; continue
            old=obj(m['evidence']); x=enrich(text,m['title'],existing,{**old,**{k:m.get(k) for k in ('monthly_amount','one_time_amount')}}); f=x['finance']; ident=x['identity']; svc=x['services']; retro=svc==['RETROATIVAS']
            # Only fill missing identity values in this finance-focused correction; preserve CRM & prior manual work.
            values={'monthly_amount':x['monthly_amount'],'one_time_amount':x['one_time_amount'],'responsible_email':m.get('responsible_email') or (ident['responsible_email'] if not m.get('responsible_name') or words(m['responsible_name'])==words(ident.get('responsible_name')) else None),'services':svc,'calculation':x['calculation']}
            previous_finance=obj(old.get('finance_v2'))
            if not svc or len(svc)>1:
                pending.append({'proposal_id':pid,'reason':'serviço indefinido ou múltiplos serviços; valores e vínculos anteriores preservados'});c['multisservico_ou_indefinido']+=1;continue
            if not f['bands'] and previous_finance.get('bands') and not f.get('band_conflict') and not f.get('scenario_review'):
                f['bands']=previous_finance['bands']; f['calculation_mode']='progressive'
                for key in ('legacy_table','table_quantity','table_total'):
                    if key in previous_finance:f[key]=previous_finance[key]
            if not f['taxes'] and previous_finance.get('taxes') and not f.get('tax_conflict'):f['taxes']=previous_finance['taxes']
            if not f.get('conditions'):f['conditions']={}
            f['conditions']={**obj(previous_finance.get('conditions')),**f['conditions']}
            # Keep already verified, uncontradicted prior financial data; explicit conflict remains pending.
            for field,state in [('monthly_amount','monthly_state'),('one_time_amount','one_time_state')]:
                if values[field] is None and f[state]=='missing' and m.get(field) is not None and not (retro and field=='one_time_amount') and not (field=='monthly_amount' and f.get('scenario_review')):
                    if money(m[field]) in v2.cash_values(text):
                        f[state]='previous_review';values[field]=money(m[field])
            evidence={**old,'finance_v2':{**previous_finance,**f},'finance_v6':{'version':6,'source':'document_sha256','mode':f['calculation_mode']}}
            core={}
            def fill(k,v):
                if v is not None and p.get(k) in (None,''):core[k]=v
            if not list_json(p.get('service_codes')) and svc:core['service_codes']=svc
            # Update previous projections only if demonstrably the same prior imported value; never overwrite unrelated edits.
            def finance_core(k,v,old_candidates):
                if v is None:return
                if p.get(k) in (None,'') or money(p[k]) in {money(z) for z in old_candidates if z is not None}:core[k]=v
                elif money(p[k])!=v:pending.append({'proposal_id':pid,'reason':'campo principal diverge: '+k})
            if retro:
                if p.get('monthly_final') is not None and money(p['monthly_final'])==money(m.get('monthly_amount')) and m.get('monthly_amount') is not None:core['monthly_final']=None
                if f['one_time_state']=='identified' and values['one_time_amount'] is not None:
                    principal=values['one_time_amount'];impl=f.get('implantation_amount');prev=m.get('one_time_amount');pi=previous_finance.get('implantation_amount');oldsum=money(Decimal(str(prev))+Decimal(str(pi))) if money(prev) is not None and money(pi) is not None else None
                    total=money(Decimal(principal)+Decimal(impl)) if impl is not None and f['implantation_mode']=='stated' else principal
                    finance_core('one_time_final',total,[prev,oldsum])
                if f.get('base_quantity') is not None:fill('retro_total',int(f['base_quantity']))
            elif len(svc)==1:
                if f['monthly_state']=='identified':finance_core('monthly_final',values['monthly_amount'],[m.get('monthly_amount')])
                if f['implantation_mode']=='stated' and f.get('implantation_state')=='identified':finance_core('one_time_final',f.get('implantation_amount'),[m.get('one_time_amount'),previous_finance.get('implantation_amount')])
                if f.get('annual_quantity') is not None:fill('recurring_total',int(f['annual_quantity']))
            rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':values,'evidence':evidence,'core':core})
            c['revisados']+=1;c['modelos_pre_pos']+=bool(f.get('billing_options'));c['servicos_recuperados']+=bool(not list_json(m['services']) and svc);c['implantacoes_identificadas']+=f.get('implantation_amount') is not None;c['retroativas']+=retro;c['retroativas_valor_identificado']+=retro and values['one_time_amount'] is not None;c['retroativas_sem_tabela_adequadas']+=retro and f['calculation_mode']=='single_fee';c['implantacoes_condicionais']+=f['implantation_mode']=='conditional';c['mensalidades_identificadas']+=values['monthly_amount'] is not None;c['bases_identificadas']+=f.get('base_quantity') is not None;c['emails_recuperados']+=bool(values['responsible_email'] and not m.get('responsible_email'))
            missing=[]
            if retro and values['one_time_amount'] is None:missing.append('valor único')
            if not retro and values['monthly_amount'] is None:missing.append('mensalidade')
            if svc==['LICENCIAMENTO'] and f.get('implantation_amount') is None:missing.append('implantação (inclusive isenção, se expressa)')
            if f.get('base_quantity') is None:missing.append('base de documentos')
            if f.get('scenario_review') or f.get('band_conflict'):missing.append('tabela-base/cenários')
            if f.get('implantation_state')=='conflict':missing.append('implantação divergente')
            if missing:pending.append({'proposal_id':pid,'reason':'Conferir: '+', '.join(missing)})
        except Exception as e:pending.append({'proposal_id':pid,'reason':type(e).__name__+': '+str(e)[:180]});c['falhas_preservadas']+=1
    return {'version':6,'rows':rows,'summary':{'total_historico':len(proposals),**c,'pendencias':len(pending),'propostas_com_pendencias':len({r['proposal_id'] for r in pending})},'pending':pending}

def main():
    p=argparse.ArgumentParser();p.add_argument('--export',type=Path,required=True);p.add_argument('--root',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    plan=generate(json.loads(a.export.read_text()),a.root); a.output.write_text(json.dumps(plan,ensure_ascii=False,indent=2));a.output.with_name('resumo.json').write_text(json.dumps(plan['summary'],ensure_ascii=False,indent=2));a.output.with_name('pendencias.json').write_text(json.dumps(plan['pending'],ensure_ascii=False,indent=2));print(json.dumps(plan['summary'],ensure_ascii=False,indent=2));
    with a.output.with_name('revisao.csv').open('w',encoding='utf-8-sig',newline='') as fp:
        w=csv.writer(fp,delimiter=';');w.writerow(['id','servicos','mensalidade','valor_pontual','implantacao','tipo_implantacao','base','modelo_calculo','documentos_periodo_anual','ano_referencia','modalidade','faixas_base','email_responsavel']);
        for r in plan['rows']:
            f=r['evidence']['finance_v2'];m=r['meta'];w.writerow([r['proposal_id'],','.join(m['services']),m['monthly_amount'],m['one_time_amount'],f.get('implantation_amount'),f.get('implantation_mode'),f.get('base_quantity'),f.get('calculation_mode'),f.get('annual_quantity'),f.get('reference_year'),f.get('billing_mode'),len(f.get('bands',[])),m.get('responsible_email')])
    if not plan['rows']:raise SystemExit('Nenhuma proposta elegível; dados preservados.')
if __name__=='__main__':main()
