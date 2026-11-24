#!/usr/bin/env python3
"""Conciliação offline V4: documento > cadastro > nomes de negócio. Nunca grava CRM."""
import argparse, collections, csv, difflib, json, re
from decimal import Decimal, InvalidOperation
from pathlib import Path
from extract_history import company_key, words, norm, services, cnpj_values, CODE, list_json, text_of, sha256, clean

ACCEPTED={'ACEITE DA PROPOSTA','CONTRATO ASSINADO','INICIO DO TESTE','NEGOCIO FECHADO'}
SERVICES={'LICENCIAMENTO','ACOMPANHAMENTO','RETROATIVAS','DFE','NFSE'}

def obj(v):
    if isinstance(v,str):
        try: v=json.loads(v)
        except ValueError: return {}
    return v if isinstance(v,dict) else {}

def ids(v): return int(v) if v is not None and str(v).isdigit() and int(v)>0 else None

def uniq(xs): return list(dict.fromkeys(x for x in xs if x))

def true(v): return v in (True,1,'1','t','true','SIM')

def name_key(v):
    k=company_key(v)
    return k if len(k)>=4 and k not in {'CLIENTE','EMPRESA','PROPOSTAS','PROPOSTAS ANTIGAS','MODELO','TESTE'} else ''

def svc(v):
    s=words(v); found=set(services(s))
    if re.search(r'\bLIC(?:ENC(?:IAMENTO)?)?\b',s): found.add('LICENCIAMENTO')
    if re.search(r'\b(?:NFS|N F S E|NFSE)\b',s): found.add('NFSE')
    if re.search(r'\b(?:DFE|D F E)\b',s): found.add('DFE')
    return found

def aliases(v):
    raw=str(v or '')
    parts=re.split(r'\s+[-–—|]\s+',raw)
    out=[]
    for p in parts:
        if svc(p) or re.fullmatch(r'20\d{2}|NOVO|NEW DEAL|RENOVACAO|PROPOSTA',words(p)): continue
        k=name_key(p)
        if k: out.append(k)
    # A generic deal named after its company is also useful, but never a fuzzy match.
    return uniq(out)

def amount(v):
    if v is None: return None
    try: d=Decimal(str(v))
    except InvalidOperation: return None
    if not d.is_finite() or d<0 or d>=Decimal('1000000000') or d!=d.quantize(Decimal('.01')): return None
    return format(d,'.2f')

def small(v,n):
    if not isinstance(v,str): return None
    v=v.strip()
    return v if v and len(v)<=n else None

def valid_email(v):
    v=small(v,200)
    return v if v and re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',v) else None

def date(v): return v if isinstance(v,str) and re.fullmatch(r'20\d{2}-\d{2}-\d{2}',v) else None

def num(v):
    v=str(v or '')
    return int(v) if v.isdigit() and int(v)<=2147483647 else None

def core_fields(p,m,e):
    """Fill blanks from the V3 projection. Preserve edited/native values and technical IDs."""
    ident=obj(e.get('identity_v3')); f=obj(e.get('finance_v2')); fields={}
    def fill(k,v):
        if v is not None and p.get(k) in (None,''): fields[k]=v
    # No replacing the original imported client alias: it participates in legacy identity.
    fill('client_contact',small(ident.get('recipient_name'),160)); fill('client_email',valid_email(m.get('recipient_email')))
    fill('sales_responsible',small(m.get('responsible_name'),160)); fill('issued_on',date(m.get('issued_on')))
    explicit=uniq(x for value in e.get('cnpjs_cliente_explicito',[]) if isinstance(value,str) for x in cnpj_values(value))
    if len(explicit)==1: fill('client_cnpj',explicit[0])
    codes=[s for s in list_json(m.get('services')) if s in SERVICES]
    if codes and not list_json(p.get('service_codes')): fields['service_codes']=codes
    if f.get('monthly_state')=='identified' and codes!=['RETROATIVAS']: fill('monthly_final',amount(m.get('monthly_amount')))
    impl=amount(f.get('implantation_amount')) if f.get('implantation_state')=='identified' else None
    if codes==['RETROATIVAS']:
        if f.get('one_time_state')=='identified':
            # A retroactive fee and an implantation may coexist. Do not guess an absent fee.
            point=amount(m.get('one_time_amount'))
            if point is not None and impl is not None: fill('one_time_final',amount(Decimal(point)+Decimal(impl)))
            elif point is not None and impl is None: fill('one_time_final',point)
        fill('retro_total',num(f.get('base_quantity')))
    elif len(codes)==1:
        if impl is not None: fill('one_time_final',impl)
        elif f.get('one_time_state')=='identified': fill('one_time_final',amount(m.get('one_time_amount')))
        fill('recurring_total',num(f.get('annual_quantity')))
    return fields

def deal_code(d): return {(int(y),int(n)) for y,n in CODE.findall(norm(d.get('name')).replace('_',' '))}

def stage_accepted(d): return words(d.get('stage_label')) in ACCEPTED

def payment_evidence(text):
    # Old proposals describe conditional payment in prose rather than a labelled field.
    out=[]
    for para in re.split(r'\n[ \t]*\n|\f',text):
        for line in re.split(r'\n(?=\s*(?:Atenciosamente|Data de emissão|Página |ExportControl LTDA))',para):
            compact=clean(line); n=norm(compact)
            if len(compact)>1200: continue
            if (re.search(r'\b(?:PRIMEIRO PAGAMENTO DA MENSALIDADE|VALOR DO INVESTIMENTO INICIAL|PAGAMENTO DO INVESTIMENTO INICIAL)\b',n) and re.search(r'\b(?:DIAS|MES|MESES|TESTE|VENCIMENTO|CONTRATACAO)\b',n)) or (re.match(r'^(?:PAGAMENTO|FATURAMENTO)\s*:',n) and re.search(r'\b(?:DIAS|MES|MESES|VENCIMENTO)\b',n)):
                out.append(compact)
    return ' '.join(uniq(out))[:2400] or None

def plan(data,root=None):
    companies={int(c['id']):c for c in data['companies']}; deals={int(d['id']):d for d in data['deals'] if not true(d.get('archived'))}
    links=collections.defaultdict(set); primary=collections.defaultdict(set); names=collections.defaultdict(set); tax=collections.defaultdict(set); alias=collections.defaultdict(set)
    for l in data['links']:
        c,d=ids(l.get('hubspot_company_id')),ids(l.get('hubspot_deal_id'))
        if c in companies and d in deals:
            links[d].add(c)
            if true(l.get('is_primary')): primary[d].add(c)
    commercial={d:(primary[d] if len(primary[d])==1 else (links[d] if len(links[d])==1 else set())) for d in links}
    by_company=collections.defaultdict(set)
    for d,cs in commercial.items():
        for c in cs:
            by_company[c].add(d)
            for k in aliases(deals[d].get('name')): alias[k].add(c)
    for c,row in companies.items():
        k=name_key(row.get('name'))
        if k: names[k].add(c)
        raw=obj(row.get('raw_properties')); props=obj(raw.get('properties')) or raw
        for field in ['cnpj','tax_id','document_number','cnpj_number','document','cnpj_da_empresa','cnpj_empresa']:
            for x in cnpj_values(row.get(field) or props.get(field)): tax[x].add(c)
    contacts=collections.defaultdict(set)
    for row in data.get('contacts',[]):
        e=valid_email(row.get('email'))
        if e and ids(row.get('hubspot_company_id')) in companies: contacts[e.lower()].add(int(row['hubspot_company_id']))
    metadata={int(m['proposal_id']):m for m in data['metadata']}; proposals={int(p['id']):p for p in data['proposals']}
    docs=collections.defaultdict(list)
    for doc in data['documents']: docs[int(doc['proposal_id'])].append(doc)
    result=[]; missing=[]
    for pid,p in proposals.items():
        m=metadata.get(pid)
        if not m or len(docs[pid])!=1 or docs[pid][0]['sha256']!=m['document_sha256']:
            missing.append({'proposal_id':pid,'reason':'metadados/documento ausentes ou divergentes'}); continue
        e=obj(m.get('evidence')); ident=obj(e.get('identity_v3')); legal=name_key(ident.get('client_legal_name')); folder=name_key(p.get('client_name'))
        payment=None
        if root is not None:
            try:
                path=(root/docs[pid][0]['storage_path']).resolve(strict=True)
                if not path.is_relative_to(root.resolve()) or sha256(path)!=m['document_sha256']: raise ValueError('arquivo divergente')
            except (OSError,ValueError) as exc:
                missing.append({'proposal_id':pid,'reason':'arquivo indisponível ou alterado'}); continue
            if not obj(obj(e.get('finance_v2')).get('conditions')).get('pagamento'):
                try: payment=payment_evidence(text_of(path))
                except Exception: pass

        legal_ids=names[legal] if legal else set(); fallback=names[folder] if folder else set()
        cnpj_ids=set().union(*(tax[x] for x in e.get('cnpjs_cliente_explicito',[]) if isinstance(x,str)))
        email=(m.get('recipient_email') or '').lower(); email_ids=contacts[email] if email else set()
        ali=set().union(*(alias[k] for k in [legal,folder] if k))
        candidate=set(); company_reason='unmatched_company'
        if cnpj_ids:
            candidate=cnpj_ids;company_reason='exact_cnpj'
            if legal_ids and not candidate.intersection(legal_ids): candidate|=legal_ids;company_reason='conflicting_company'
            elif legal_ids: candidate &= legal_ids
        elif legal_ids: candidate=set(legal_ids);company_reason='document_legal_name'
        elif fallback: candidate=set(fallback);company_reason='folder_exact'
        elif email_ids and ali and email_ids.intersection(ali): candidate=email_ids.intersection(ali);company_reason='email_and_deal_alias'
        elif ali: candidate=set(ali);company_reason='deal_alias'
        # Contact-only and fuzzy matches are suggestions, never automatic links.
        suggestions=set(email_ids)
        if not candidate:
            for key in [legal,folder]:
                if not key: continue
                for close in difflib.get_close_matches(key,list(names),n=5,cutoff=.78): suggestions.update(names[close])
        # Never resolve legal conflicts merely from a generic contact email.
        if len(candidate)>1 and company_reason!='conflicting_company' and len(candidate & email_ids)==1: candidate &= email_ids;company_reason+='_contact'
        core_c=ids(p.get('hubspot_company_id')); core_d=ids(p.get('hubspot_deal_id')); old_c=ids(m.get('crm_company_id')); old_d=ids(m.get('crm_deal_id'))
        manual=obj(e.get('crm_v4')).get('manual') is True
        c=None; d=None; method='unmatched_company'; exact=[]; service_matches=[]; generic=[]; candidate_deals=[]; protected=False
        if core_c or core_d:
            # Explicit existing core associations are preserved, but inconsistent links are flagged.
            if core_c in companies and (not core_d or core_d in deals and core_c in links[core_d]):
                c=core_c; d=core_d;method='manual_confirmed' if manual else ('existing_core' if d else 'existing_company');protected=bool(d)
                if d and not manual and old_c==c and old_d==d and m.get('crm_match') in ('exact_code','unique_company_service'):
                    method=str(m['crm_match'])
            else: method='existing_link_conflict';protected=True
        elif len(candidate)==1 and company_reason!='conflicting_company': c=next(iter(candidate));method='company_only'
        elif len(candidate)>1: method='ambiguous_company'
        elif old_c in companies and old_d in deals and old_c in commercial.get(old_d,set()) and m.get('crm_match') in ('exact_code','unique_company_service','manual_confirmed'):
            c=old_c;d=old_d;method=str(m['crm_match']);protected=True
        if c:
            sc=set(list_json(m.get('services'))); y=num(m.get('historic_year')); n=num(m.get('historic_number')); issued=date(m.get('issued_on'))
            for did in sorted(by_company[c]):
                item=deals[did]; title=str(item.get('name') or ''); ds=svc(title); codes=deal_code(item); years={int(x) for x in re.findall(r'\b20\d{2}\b',words(title))}
                # Exact reference can establish the specific proposal, not just its company.
                if y and n and (y,n) in codes and (not ds or ds==sc): exact.append(did);continue
                if codes or (years and y and y not in years): continue
                created=str(item.get('hubspot_created_at') or '')[:10]
                closed=str(item.get('closed_at') or '')[:10]
                if issued and date(closed) and closed<issued: continue
                # Reject deals created well after emission (allow historical CRM entry lag).
                if y and date(created) and int(created[:4])>y+1: continue
                if sc and ds==sc: service_matches.append(did)
                elif not ds and (name_key(title)==name_key(companies[c].get('name')) or name_key(companies[c].get('name')) in aliases(title) or legal in aliases(title) or folder in aliases(title)):
                    generic.append(did)
            candidate_deals=uniq(([d] if d else [])+exact+service_matches+generic+sorted(by_company[c]))
            if not d and method!='existing_link_conflict':
                if len(exact)==1: d=exact[0];method='exact_code'
                elif len(exact)>1: method='ambiguous_deal'
                elif len(service_matches)==1: d=service_matches[0];method='unique_company_service'
                elif len(service_matches)>1: method='ambiguous_deal'
                elif len(generic)==1: method='generic_candidate'
                else: method='company_only' if candidate_deals else 'no_deal'
        result.append({'proposal_id':pid,'company_id':c,'deal_id':d,'match':method,'company_reason':company_reason,'company_candidates':uniq(([c] if c else [])+sorted(candidate)+sorted(suggestions))[:20],'deal_candidates':candidate_deals[:30],'generic_candidate':generic[0] if len(generic)==1 else (candidate_deals[0] if len(candidate_deals)==1 else None),'payment_evidence':payment,'protected':protected,'fields':core_fields(p,m,e),'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':m['document_sha256']})
    # Do not automatically allocate one service deal to several files (including revisions).
    groups=collections.defaultdict(list)
    for r in result:
        if r['deal_id']: groups[r['deal_id']].append(r)
    for did,rs in groups.items():
        if len(rs)>1:
            for r in rs:
                if not r['protected'] and r['match']!='exact_code': r['deal_id']=None;r['match']='shared_deal_candidate'
    # Acceptance from shared deals requires explicit confirmation or exact unique proposal reference.
    owners=collections.Counter(r['deal_id'] for r in result if r['deal_id'])
    for r in result:
        d=deals.get(r['deal_id']); r['stage']=d.get('stage_label') if d else None
        r['commercial_status']='accepted_crm' if d and stage_accepted(d) and (r['match']=='manual_confirmed' or (owners[r['deal_id']]==1 and r['match'] in ('exact_code','unique_company_service','existing_core'))) else 'sent_history'
        r.pop('protected',None)
    summary={'total_historico':len(proposals),'analisados':len(result),'empresas_identificadas':sum(bool(r['company_id']) for r in result),'negocios_conciliados':sum(bool(r['deal_id']) for r in result),'negocios_pendentes':sum(not r['deal_id'] for r in result),'aceitas_crm':sum(r['commercial_status']=='accepted_crm' for r in result),'cadastros_com_campos_a_preencher':sum(bool(r['fields']) for r in result),'sem_metadados_ou_documento':len(missing),'condicoes_pagamento_recuperadas':sum(bool(r['payment_evidence']) for r in result),'criterios':dict(collections.Counter(r['match'] for r in result))}
    return {'version':4,'crm_fingerprint':data['crm_fingerprint'],'rows':result,'missing':missing,'summary':summary}

def main():
    p=argparse.ArgumentParser();p.add_argument('--export',required=True,type=Path);p.add_argument('--output',required=True,type=Path);p.add_argument('--root',required=True,type=Path);a=p.parse_args();data=json.loads(a.export.read_text());out=plan(data,a.root)
    a.output.write_text(json.dumps(out,ensure_ascii=False,indent=2));a.output.with_name('resumo.json').write_text(json.dumps(out['summary'],ensure_ascii=False,indent=2))
    C={int(x['id']):x for x in data['companies']};D={int(x['id']):x for x in data['deals']};M={int(x['proposal_id']):x for x in data['metadata']}
    with a.output.with_name('conciliacao.csv').open('w',encoding='utf-8-sig',newline='') as f:
        w=csv.writer(f,delimiter=';');w.writerow(['proposta_id','titulo','empresa','negocio','etapa','criterio','negocios_candidatos'])
        for r in out['rows']:
            w.writerow([r['proposal_id'],M[r['proposal_id']]['title'],C.get(r['company_id'],{}).get('name'),D.get(r['deal_id'],{}).get('name'),r['stage'],r['match'],' | '.join(f"{D[i].get('hubspot_id')}: {D[i].get('name')} [{D[i].get('stage_label')}]" for i in r['deal_candidates'] if i in D)])
    a.output.with_name('pendencias.json').write_text(json.dumps(out['missing'],ensure_ascii=False,indent=2))
    print(json.dumps(out['summary'],ensure_ascii=False,indent=2))
    if not out['rows']: raise SystemExit('Nenhum registro elegível. Execute o enriquecimento V3 antes.')
if __name__=='__main__': main()
