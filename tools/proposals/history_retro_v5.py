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
    if len(x['services'])>1:
        # Independent service amounts are not a consolidated total.
        x['one_time_amount']=None;f['one_time_state']='conflict'
    # Preserve old identified values/evidence for fields the new parser cannot recover, without inventing values.
    # Retro service price cannot inherit an unverified old implementation amount.
    for field,state in [('monthly_amount','monthly_state'),('one_time_amount','one_time_state')]:
        if x[field] is None and f[state]=='missing' and old.get(field) is not None and not (retro and field=='one_time_amount'):
            val=money(old[field]);
            if val and val in v2.cash_values(text):x[field]=val;f[state]='previous_review'
    f['version']=2; f['reader_version']=5
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
            existing=list_json(m['services']); named=services(m['title']);
            if len(named)==1 and existing and named[0] not in existing:
                pending.append({'proposal_id':pid,'reason':'serviço do título diverge do cadastro/CRM; vínculo preservado para conferência'}); c['servicos_divergentes']+=1; continue
            old=obj(m['evidence']); x=enrich(text,m['title'],list_json(m['services']),{**old,**{k:m.get(k) for k in ('monthly_amount','one_time_amount')}}); f=x['finance']; ident=x['identity']; svc=x['services']; retro=svc==['RETROATIVAS']
            # Only fill missing identity values in this finance-focused correction; preserve CRM & prior manual work.
            values={'monthly_amount':x['monthly_amount'],'one_time_amount':x['one_time_amount'],'responsible_email':m.get('responsible_email') or (ident['responsible_email'] if not m.get('responsible_name') or words(m['responsible_name'])==words(ident.get('responsible_name')) else None),'services':svc,'calculation':x['calculation']}
            previous_finance=obj(old.get('finance_v2'))
            if not svc or len(svc)>1:
                pending.append({'proposal_id':pid,'reason':'serviço indefinido ou múltiplos serviços; valores e vínculos anteriores preservados'});c['multisservico_ou_indefinido']+=1;continue
            if not f['bands'] and previous_finance.get('bands') and not f.get('band_conflict'):
                f['bands']=previous_finance['bands']; f['calculation_mode']='progressive'
                for key in ('legacy_table','table_quantity','table_total'):
                    if key in previous_finance:f[key]=previous_finance[key]
            if not f['taxes'] and previous_finance.get('taxes') and not f.get('tax_conflict'):f['taxes']=previous_finance['taxes']
            if not f.get('conditions'):f['conditions']={}
            f['conditions']={**obj(previous_finance.get('conditions')),**f['conditions']}
            # Keep already verified, uncontradicted prior financial data; explicit conflict remains pending.
            for field,state in [('monthly_amount','monthly_state'),('one_time_amount','one_time_state')]:
                if values[field] is None and f[state]=='missing' and m.get(field) is not None and not (retro and field=='one_time_amount'):
                    if money(m[field]) in v2.cash_values(text):
                        f[state]='previous_review';values[field]=money(m[field])
            evidence={**old,'finance_v2':{**previous_finance,**f},'finance_v5':{'version':5,'source':'document_sha256','mode':f['calculation_mode']}}
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
            c['revisados']+=1;c['retroativas']+=retro;c['retroativas_valor_identificado']+=retro and values['one_time_amount'] is not None;c['retroativas_sem_tabela_adequadas']+=retro and f['calculation_mode']=='single_fee';c['implantacoes_condicionais']+=f['implantation_mode']=='conditional';c['mensalidades_identificadas']+=values['monthly_amount'] is not None;c['bases_identificadas']+=f.get('base_quantity') is not None;c['emails_recuperados']+=bool(values['responsible_email'] and not m.get('responsible_email'))
            if (retro and values['one_time_amount'] is None) or (not retro and values['monthly_amount'] is None) or f.get('implantation_state')=='conflict':pending.append({'proposal_id':pid,'reason':'valor pendente ou divergente — conferir documento'})
        except Exception as e:pending.append({'proposal_id':pid,'reason':type(e).__name__+': '+str(e)[:180]});c['falhas_preservadas']+=1
    return {'version':5,'rows':rows,'summary':{'total_historico':len(proposals),**c,'pendencias':len(pending)},'pending':pending}

def main():
    p=argparse.ArgumentParser();p.add_argument('--export',type=Path,required=True);p.add_argument('--root',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    plan=generate(json.loads(a.export.read_text()),a.root); a.output.write_text(json.dumps(plan,ensure_ascii=False,indent=2));a.output.with_name('resumo.json').write_text(json.dumps(plan['summary'],ensure_ascii=False,indent=2));a.output.with_name('pendencias.json').write_text(json.dumps(plan['pending'],ensure_ascii=False,indent=2));print(json.dumps(plan['summary'],ensure_ascii=False,indent=2));
    with a.output.with_name('revisao.csv').open('w',encoding='utf-8-sig',newline='') as fp:
        w=csv.writer(fp,delimiter=';');w.writerow(['id','servicos','mensalidade','valor_pontual','implantacao','tipo_implantacao','base','modelo_calculo']);
        for r in plan['rows']:
            f=r['evidence']['finance_v2'];m=r['meta'];w.writerow([r['proposal_id'],','.join(m['services']),m['monthly_amount'],m['one_time_amount'],f.get('implantation_amount'),f.get('implantation_mode'),f.get('base_quantity'),f.get('calculation_mode')])
    if not plan['rows']:raise SystemExit('Nenhuma proposta elegível; dados preservados.')
if __name__=='__main__':main()
