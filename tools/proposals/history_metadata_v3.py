#!/usr/bin/env python3
"""Structured metadata from old and current proposal documents. Private, offline, no recalculation."""
import argparse, collections, csv, json, re
from decimal import Decimal
from pathlib import Path
from extract_history import text_of, sha256, norm, clean, list_json, services, issue_date, EMAIL, words, dates
import history_finance_v2 as v2

ROLE = re.compile(r'\b(?:GERENTE|COORDENADOR[A]?|ASSISTENTE|CONSULTOR[A]?|DIRETOR[A]?|ANALISTA|EXECUTIVO[A]?|SUPERVISOR[A]?|SOCIO[A]?)\b')
BLOCK = re.compile(r'^(?:EXPORTCONTROL(?: LTDA)?\b|FORNECEDOR\b|PROPONENTE\b|DOCUMENTO N[Oº°]?\b|TOLEDO\b|PROPOSTA\b|PAGINA\b|CNPJ\b|DATA DE EMISSAO\b)')
NAME = re.compile(r"[A-Za-zÀ-ÖØ-öø-ÿ'’.-]+(?: [A-Za-zÀ-ÖØ-öø-ÿ'’.-]+){1,6}$")
GENERIC = {'contato','comercial','financeiro','suporte','atendimento','vendas','noreply','administrativo','propostas'}
MONEY = v2.MONEY

def first_cell(l):
    return re.split(r'\s{3,}',l.strip(),maxsplit=1)[0]

def name_only(l):
    x=clean(re.split(r'\s+[–—-]\s+|\s*\|\s*',first_cell(l),maxsplit=1)[0])
    n=norm(x)
    if not NAME.fullmatch(x) or ROLE.search(n) or re.search(r'\b(?:ATENCIOSAMENTE|CORDIALMENTE|OBRIGADO|PROPOSTA|EXPORTCONTROL|CLIENTE|CONTATO|EMISSAO|TOLEDO|VALIDADE|COMERCIAL|DOCUMENTO|PAGAMENTO|VALOR|SERVICO|ASSINATURA|MENSALIDADE|OBSERVACOES|RESPONSABILIDADE|EQUIPE|GERENCIA|DATA)\b',n): return None
    return x

def one(xs):
    d={words(x):x for x in xs if x}
    return next(iter(d.values())) if len(d)==1 else None

def company_mail(l):
    return [m[1].lower() for m in EMAIL.finditer(l) if m[1].lower().endswith('@exportcontrol.com.br') and m[1].split('@')[0].lower() not in GENERIC]

def client_block(text):
    # Cover only: stop before supplier/contact directory or document number.
    lines=text.split('\f')[0].splitlines()[:120]
    legal=[]; names=[]; emails=[]; active=False; started=False
    for i,line in enumerate(lines):
        c=first_cell(line); n=norm(c)
        if re.match(r'^(?:CLIENTE|CONTRATANTE)\s*(?:[:–-]|$)',n):
            active=started=True
            value=re.sub(r'^(?:Cliente|Contratante)\s*[:–-]?\s*','',c,flags=re.I).strip()
            if value:
                value=EMAIL.sub('',value).strip(' ,;')
                if value: legal.append(value)
            emails.extend(m[1].lower() for m in EMAIL.finditer(line) if not m[1].lower().endswith('@exportcontrol.com.br'))
            continue
        if re.match(r'^RAZAO SOCIAL(?: DO CLIENTE)?\s*:',n) and (active or not started):
            active=started=True; legal.append(c.split(':',1)[1].strip()); continue
        if not active: continue
        if BLOCK.match(n) or ROLE.search(n): break
        if not c: continue
        if re.match(r'^(?:CONTATO(?: DO CLIENTE)?|RESPONSAVEL(?: DO CLIENTE)?|A/C|ATENCAO|DESTINATARIO)\s*[:–-]',n):
            value=re.split(r'[:–-]',c,maxsplit=1)[1].strip()
            value=EMAIL.sub('',value).strip(' ,-;')
            if value and len(value)<=180: names.append(value)
        elif not legal and not EMAIL.search(c) and not re.search(r'\d|\b(?:TELEFONE|EMAIL|E-MAIL)\b',n): legal.append(c)
        for m in EMAIL.finditer(line):
            email=m[1].lower()
            if not email.endswith('@exportcontrol.com.br'): emails.append(email)
    return {'client_legal_name':one(legal),'recipient_name':one(names),'recipient_email':one(emails), 'recipient_candidates':list(dict.fromkeys(emails))}

def responsible(text):
    lines=text.replace('\f','\n').splitlines()
    # Explicit closing signature has priority over the entire cover directory.
    closing=[]; explicit=[]; footer=[]; directory=collections.defaultdict(set)
    for i,l in enumerate(lines):
        c=first_cell(l); n=norm(c)
        m_close=re.match(r'^(?:ATENCIOSAMENTE|CORDIALMENTE|SAUDACOES|ATT\.?)\s*[,.:]?\s*(.*)$',n)
        if m_close:
            inline=name_only(c[m_close.start(1):])
            if inline: closing.append(inline); continue
            for s in lines[i+1:i+10]:
                if not s.strip(): continue
                candidate=name_only(s)
                if candidate: closing.append(candidate); break
                if BLOCK.match(norm(first_cell(s))): break
        m=re.match(r'^(?:RESPONSAVEL (?:PELA PROPOSTA|COMERCIAL)|ELABORADO POR|EMITIDO POR)\s*:\s*(.+)',n)
        if m:
            candidate=name_only(c[m.start(1):]);
            if candidate: explicit.append(candidate)
        # Associate each email only with its own preceding named commercial role.
        if ROLE.search(n):
            nm=name_only(re.split(r'\s+[–—-]\s+',c,maxsplit=1)[0])
            if not nm:
                for p in reversed(lines[max(0,i-4):i]):
                    if not p.strip(): continue
                    nm=name_only(p); break
            if nm:
                for s in lines[i:i+7]:
                    sc=first_cell(s)
                    if s!=l and ROLE.search(norm(sc)): break
                    if s!=l and name_only(s): break
                    for e in company_mail(sc): directory[words(nm)].add(e)
                if i>=len(lines)*0.65: footer.append(nm)
        if i>=len(lines)*0.65 and company_mail(c) and not ROLE.search(n):
            # Some signatures have no job title, just name and the company mailbox.
            for prev in reversed(lines[max(0,i-4):i]):
                if not prev.strip(): continue
                nm=name_only(prev)
                if nm:
                    directory[words(nm)].update(company_mail(c)); footer.append(nm)
                break
        # Inline role with no role line is not guessed from an address alone.
    selected=one(closing) if closing else one(explicit) if explicit else one(footer)
    source='assinatura_final' if closing else 'responsavel_explicito' if explicit else 'bloco_comercial_final' if footer else 'pendente'
    email=None
    if selected:
        opts=directory.get(words(selected),set())
        if len(opts)==1: email=next(iter(opts))
        # Closing signature sometimes includes name then email without role.
        if not email:
            local=set()
            for i,l in enumerate(lines):
                if i>=len(lines)*0.60 and name_only(l)==selected:
                    for s in lines[i+1:i+5]:
                        if name_only(s) or BLOCK.match(norm(first_cell(s))): break
                        local.update(company_mail(first_cell(s)))
            if len(local)==1: email=next(iter(local))
    return selected,email,{'source':source,'candidates':list(dict.fromkeys(closing or explicit or footer))}

NUM = re.compile(r'(?<![\w,])(?:R\$\s*)?(?:\d+(?:\.\d{3})*(?:,\d{2})?|[-–—]|SEM LIMITE)(?![\w,])',re.I)
LEGACY_ROW = re.compile(r'^\s*FAIXA\s*(\d{1,2})\b',re.I)

def legacy_tables(lines):
    """Recover blank cells using column alignment from full rows in legacy PDF tables.
    For Word tables use explicit cell separators when available; never manufacture zeroes."""
    groups=[]; active=None; pending=None
    for i,l in enumerate(lines):
        n=norm(l).strip()
        if re.match(r'^(?:ESTIMATIVA\s+(?:DE\s+)?(?:MENSALIDADE|INVESTIMENTO|VALOR)|TABELA\s+(?:DE\s+)?(?:MENSALIDADE|INVESTIMENTO))\b',n):
            if active and active['rows']: groups.append(active)
            active={'heading':n,'rows':[],'total':None,'quantity':None,'line':i+1}; continue
        if not active:
            if re.search(r'INVESTIMENTO.*(?:FIXO|POR NF)|QTDA\.?\s*NFS',n): pending=i
            if LEGACY_ROW.match(l) and pending is not None and i-pending<=5: active={'heading':'TABELA HISTORICA','rows':[],'total':None,'quantity':None,'line':pending+1}
            else: continue
        if '\f' in l or (active['rows'] and (re.match(r'^\s*(?:\d+\.\d+\.\s+|INVESTIMENTO MENSAL\s*:|IMPLANTACAO\s*:)',n))):
            if active['rows']: groups.append(active)
            active=None; continue
        m=LEGACY_ROW.match(l)
        if m:
            tokens=[(t.end(),t.group().strip()) for t in NUM.finditer(l,m.end())]
            active['rows'].append((m[1],tokens,l)); continue
        if active['rows'] and re.match(r'^TOTAL\b',n):
            vals=[m.group().strip() for m in NUM.finditer(l)]
            if len(vals)>=2 and re.fullmatch(r'[\d.]+',vals[-2]) and v2.cash_values(vals[-1],True):
                active['quantity']=vals[-2].replace('.',''); active['total']=v2.cash_values(vals[-1],True)[0]
            groups.append(active); active=None
        elif active['rows'] and l.strip():
            groups.append(active); active=None
    if active and active['rows']: groups.append(active)
    results=[]
    for g in groups:
        full=[t for _,t,_ in g['rows'] if len(t)==6]
        explicit=[l.split('    ') for _,_,l in g['rows']]
        word_cells=all(len(c)==7 and LEGACY_ROW.fullmatch(c[0].strip()) for c in explicit)
        if not full and not word_cells: continue
        # Median column endpoints from full rows. Distances reflect right-aligned amounts.
        anchors=[sorted(t[k][0] for t in full)[len(full)//2] for k in range(6)] if full else []
        if not word_cells and any(b-a<3 for a,b in zip(anchors,anchors[1:])): continue
        rows=[]
        for label,tokens,line in g['rows']:
            if not tokens or len(tokens)>6: break
            if word_cells: fields=[v.strip() or '—' for v in line.split('    ')[1:]]
            elif len(tokens)==6: fields=[v for _,v in tokens]
            else:
                import itertools
                choices=[]
                for ids in itertools.combinations(range(6),len(tokens)):
                    if ids[0]!=0: continue
                    score=sum(abs(p-anchors[k]) for (p,_),k in zip(tokens,ids))
                    choices.append((score,ids))
                if not choices: break
                choices.sort(); score,ids=choices[0]
                if any(abs(p-anchors[k])>11 for (p,_),k in zip(tokens,ids)) or (len(choices)>1 and choices[1][0]==score): break
                fields=['—']*6
                for (_,val),k in zip(tokens,ids): fields[k]=val
            if not re.fullmatch(r'[\d.]+',fields[0]) or not re.fullmatch(r'[\d.]+|—|SEM LIMITE',fields[1],re.I): break
            if any(v not in ('—','-','–') and not v2.cash_values(v,True) for v in (fields[2],fields[3],fields[5])): break
            if not re.fullmatch(r'[\d.]+|[-–—]',fields[4]): break
            rows.append(dict(zip(['faixa','min','max','fixo','unitario','quantidade','subtotal'],[label,*fields])))
        if len(rows)!=len(g['rows']) or len({r['faixa'] for r in rows})!=len(rows): continue
        results.append({'heading':g['heading'],'bands':rows,'table_total':g['total'],'table_quantity':g['quantity']})
    return results

def amount_explicit(lines,patterns):
    out=[]
    for i,l in enumerate(lines):
        for c in [v for _,v in v2.cells(l)]:
            # Strip bullets. Match explicit price labels, not mentions of future payments or formulas.
            s=c.lstrip(' ➢•▪✓-–—\t'); n=norm(s)
            for rank,pat in patterns:
                m=re.match(pat,n)
                if not m: continue
                after=s[m.end():]; a=MONEY.match(after.lstrip(' :–—-'))
                if a and len(set(v2.cash_values(after)))==1 and not re.search(r'\b(?:A PARTIR|MINIMO|MAXIMO|ESTIMATIVA DE ECONOMIA)\b',n):
                    out.append({'valor':v2.decimal(a[1]),'trecho':clean(c)[:500],'prioridade':rank,'linha':i+1})
    return out

MONTH=[(110,r'(?:INVESTIMENTO|VALOR|CUSTO) MENSAL(?: FINAL)?\s*[:–-]\s*'),(110,r'MENSALIDADE(?: FINAL)?\s*[:–-]\s*')]
IMPL=[(110,r'(?:IMPLANTACAO(?: FINAL)?|INVESTIMENTO INICIAL|(?:VALOR|TAXA|CUSTO) (?:DA |DE )?IMPLANTACAO(?: FINAL)?)\s*[:–-]\s*')]
POINT=[(110,r'(?:VALOR TOTAL(?: DO SERVICO)?|INVESTIMENTO (?:TOTAL|UNICO)|VALOR (?:FINAL DO SERVICO|DAS CONSULTAS RETROATIVAS|RETROATIVO))\s*[:–-]\s*')]

def parsed(text, title, existing_services):
    lines=text.replace('\f','\n\f\n').split('\n')
    svc=existing_services or services(title)
    if not svc:
        # Scope mentions competing services/exclusions; use only explicit title/header.
        for l in text.split('\f')[0].splitlines()[:20]:
            if 'PROPOSTA' in norm(l) or re.match(r'^\s*(?:LICENCIAMENTO|ACOMPANHAMENTO|CONSULTAS RETROATIVAS|CAPTURA E GESTAO DE NFS-E|CAPTURA E GESTAO DE DF-E)\b',norm(l)):
                found=services(l)
                if len(found)==1: svc=found; break
    month,once,f,raw=v2.parse(text,svc)
    v2_once=once
    retro=svc==['RETROATIVAS']; multi=len(svc)>1
    tables=legacy_tables(lines)
    if len(tables)==1 and not f['bands'] and not f['band_conflict']:
        f.update(tables[0]); f['legacy_table']=True
    elif len(tables)>1:
        f['legacy_tables']=tables; f['band_conflict']=True
    mc=f.get('monthly_evidence',[])+amount_explicit(lines,MONTH)
    ic=v2.evidence(lines,[(110,r'\bIMPLANTACAO FINAL\b'),*v2.IMPL])+amount_explicit(lines,IMPL)
    pc=v2.evidence(lines,v2.POINT)+amount_explicit(lines,POINT)
    if retro:
        pc+=amount_explicit(lines,[(110,r'(?:INVESTIMENTO|VALOR DO INVESTIMENTO|VALOR DAS CONSULTAS|CONSULTAS RETROATIVAS)\s*[:–-]\s*')])
    # Old template's explicitly named estimate total is a separate confirmation, not a recalculation.
    if len(tables)==1 and tables[0]['table_total'] is not None:
        t=tables[0]; ev={'valor':t['table_total'],'trecho':t['heading']+' | TOTAL '+t['table_total'],'prioridade':110,'linha':0}
        if 'MENSALIDADE' in t['heading'] and not retro: mc.append(ev)
        elif retro: pc.append(ev)
        if not f.get('base_quantity') and t['table_quantity']: f['base_quantity']=t['table_quantity']
    # Explicit final totals from both styles must agree; do not hide conflicts by score.
    strong=[dict(x,prioridade=110) for x in mc if x['prioridade']>=100]
    month,ms=v2.best(strong or mc)
    if retro: month,ms=None,'not_applicable'
    if multi:
        # No summation of independent service/scenario amounts unless document explicitly provides total.
        totals=[x for x in mc if re.search(r'\b(?:TOTAL MENSAL|MENSALIDADE TOTAL)\b',norm(x['trecho']))]
        month,ms=v2.best(totals) if totals else (None,'conflict')
    impl,ims=v2.best(ic)
    point,ps=v2.best(pc) if retro else (None,'missing')
    if retro and ps=='missing' and v2_once is not None and f.get('one_time_state')=='identified': point,ps=v2_once,'identified'
    if multi: point,ps=None,'conflict'
    once,os=(point,ps) if retro else (impl,ims)
    if not retro and impl is None and not multi and f.get('one_time_state')=='identified' and once is None:
        # V2 may contain a labelled one-time fee not specifically identified as implantation.
        once,os=v2.best([x for x in pc if re.search(r'UNIC[OA]',norm(x['trecho']))])
    f.update({'version':2,'monthly_state':ms,'one_time_state':os,'monthly_evidence':mc[:60],'one_time_evidence':(pc+ic)[:60], 'implantation_amount':impl,'implantation_state':ims,'service_amount':point,'service_amount_state':ps})
    ntext=norm(text)
    annual=re.findall(r'\b(\d+(?:\.\d{3})*)\s+(?:DOCUMENTOS(?: FISCAIS)?|NFS?|NOTAS(?: FISCAIS)?)\s+(?:EM|NOS ULTIMOS|NOS|NOS ULTIMOS)\s+12\s+MESES\b',ntext)
    f['annual_quantity']=one([s.replace('.','') for s in annual])
    if not f.get('base_quantity'):
        vals=re.findall(r'\b(\d+(?:\.\d{3})*)\s*/\s*MES\s+DOCUMENTOS\b',ntext)
        f['base_quantity']=one([s.replace('.','') for s in vals])
    val=re.findall(r'VALIDADE DE\s+(\d{1,3})\s+DIAS',ntext)
    if len(set(val))==1: f.setdefault('conditions',{})['validade']=val[0]+' dias a partir da emissão'
    if retro and f.get('table_quantity'): f['base_label']='Base total de documentos'
    c=client_block(text); rn,em,source=responsible(text)
    c.update({'responsible_name':rn,'responsible_email':em,'responsible_source':source,'version':3})
    return {'monthly_amount':month,'one_time_amount':once,'finance':f,'identity':c,'services':svc,'calculation':raw}

def generate(data,root):
    docs=collections.defaultdict(list)
    for d in data['documents']: docs[int(d['proposal_id'])].append(d)
    rows=[]; errors=[]; counts=collections.Counter(); pending=[{'proposal_id':int(id),'title':'','pendencias':'sem metadados V1 — conferir separadamente'} for id in data.get('missing_metadata_ids',[])]
    for i,meta in enumerate(data['metadata'],1):
        id=int(meta['proposal_id'])
        try:
            dd=docs[id]
            if len(dd)!=1: raise ValueError('Mais de um documento ou documento ausente')
            doc=dd[0]; path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or sha256(path)!=doc['sha256'] or doc['sha256']!=meta['document_sha256']: raise ValueError('Caminho ou hash divergente')
            text=text_of(path)
            if len(clean(text))<40: raise ValueError('Documento sem texto suficiente')
            x=parsed(text,meta['title'],list_json(meta['services'])); f=x['finance']; ident=x['identity']
            # Previous evidence is merged server-side, not replaced by the extractor.
            # Missing new values never silently discard prior finance: preserve only as a marked candidate
            # if its amount occurs in source. Explicitly conflicting values stay null for review.
            actual=set(v2.cash_values(text))
            for field,state in [('monthly_amount','monthly_state'),('one_time_amount','one_time_state')]:
                previous=meta.get(field)
                if x[field] is None and f[state]=='missing' and previous is not None:
                    val=format(Decimal(str(previous)),'.2f')
                    if val in actual and not (field=='one_time_amount' and val==x['monthly_amount']): x[field]=val; f[state]='previous_review'
            # No fallback to cover employee names. A historical recipient must come from the client block.
            date,candidates=issue_date(text.replace('\f','\n').splitlines(),meta.get('historic_year'))
            ident['issue_candidates']=candidates
            # Keep trace of any prior projection superseded by the source-first read.
            ident['previous_projection']={key:meta.get(key) for key in ('responsible_name','responsible_email','recipient_email') if meta.get(key) and meta.get(key)!=ident.get(key)}
            # Preserve current service if already identified; do not change commercial matching.
            row={'proposal_id':id,'expected_fingerprint':meta['fingerprint'],'document_sha256':doc['sha256'],'monthly_amount':x['monthly_amount'],'one_time_amount':x['one_time_amount'],'calculation':x['calculation'],'responsible_name':ident['responsible_name'],'responsible_email':ident['responsible_email'],'recipient_email':ident['recipient_email'],'issued_on':date or meta.get('issued_on'),'services':x['services'],'evidence':{'finance_v2':f,'identity_v3':ident}}
            rows.append(row)
            for key,value in [('mensalidades',x['monthly_amount']),('implantacoes',f['implantation_amount']),('valores_pontuais',x['one_time_amount']),('responsaveis',ident['responsible_name']),('emails_responsaveis',ident['responsible_email']),('contatos_clientes',ident['recipient_name']),('emails_clientes',ident['recipient_email']),('razoes_sociais',ident['client_legal_name']),('datas',row['issued_on']),('tabelas',f['bands'])]: counts[key]+=value is not None and value!=[]
            issues=[]
            if f['monthly_state']!='not_applicable' and (x['monthly_amount'] is None or f['monthly_state']=='previous_review'): issues.append('mensalidade')
            if 'LICENCIAMENTO' in x['services'] and f['implantation_amount'] is None: issues.append('implantacao')
            if not ident['responsible_name']: issues.append('responsavel')
            if not ident['recipient_email']: issues.append('destinatario')
            if not f['bands']: issues.append('faixas')
            if issues: pending.append({'proposal_id':id,'title':meta['title'],'pendencias':', '.join(issues)})
        except Exception as e:
            errors.append({'proposal_id':id,'error':type(e).__name__+': '+str(e)[:200]})
            pending.append({'proposal_id':id,'title':meta.get('title',''),'pendencias':'falha de leitura/integridade — metadados anteriores preservados'})
        if i%50==0: print(f'Analisados {i}/{len(data["metadata"])}',flush=True)
    return {'version':3,'rows':rows,'errors':errors,'pending':pending,'summary':{'propostas_historicas':data['total'],'com_metadados':len(data['metadata']),'revisados':len(rows),'falhas_preservadas':len(errors),'registros_com_pendencias':len(pending),**counts}}

def main():
    p=argparse.ArgumentParser();p.add_argument('--export',required=True,type=Path);p.add_argument('--root',required=True,type=Path);p.add_argument('--output',required=True,type=Path);a=p.parse_args()
    review=generate(json.loads(a.export.read_text()),a.root)
    a.output.write_text(json.dumps(review,ensure_ascii=False,indent=2)); a.output.with_name('resumo.json').write_text(json.dumps(review['summary'],ensure_ascii=False,indent=2))
    with a.output.with_name('pendencias.csv').open('w',newline='',encoding='utf-8-sig') as fp:
        w=csv.DictWriter(fp,fieldnames=['proposal_id','title','pendencias'],delimiter=';');w.writeheader();w.writerows(review['pending'])
    print(json.dumps(review['summary'],ensure_ascii=False,indent=2))
    if not review['rows']: raise SystemExit('Nenhum registro válido para atualização.')

if __name__=='__main__': main()
