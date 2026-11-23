#!/usr/bin/env python3
"""Offline historical-proposal extraction. No network; never changes originals."""
import argparse, collections, datetime as dt, hashlib, json, re, subprocess, unicodedata, zipfile
from pathlib import Path
from decimal import Decimal, InvalidOperation
from xml.etree import ElementTree as ET

SERVICES = {
    'NFSE': r'(?<![A-Z0-9])NFS[\s_\-–]*E(?![A-Z0-9])|\bNFS\b',
    'DFE': r'(?<![A-Z0-9])DF[\s_\-–]*E(?![A-Z0-9])',
    'RETROATIVAS': r'\bRETROATIV\w*|\bRETRO\b',
    'ACOMPANHAMENTO': r'\bACOMP(?:ANHAMENTO)?\b|\bCOBRANCA\b',
    'LICENCIAMENTO': r'\bLICENCIAMENTO\b|\bLICENC\b',
}
ACCEPTED = {'ACEITE DA PROPOSTA','CONTRATO ASSINADO','INICIO DO TESTE','NEGOCIO FECHADO'}
MONEY = re.compile(r'R\$\s*([0-9]+(?:\.[0-9]{3})*,[0-9]{2}|[0-9]+,[0-9]{2})(?![0-9])',re.I)
EMAIL = re.compile(r'(?<![\w.+-])([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})(?![\w.-])',re.I)
CODE = re.compile(r'\bEC[ _\-–]*(20\d{2})[ _\-–]+(\d{1,10})(?!\d)',re.I)
MONTHS = 'JANEIRO FEVEREIRO MARCO ABRIL MAIO JUNHO JULHO AGOSTO SETEMBRO OUTUBRO NOVEMBRO DEZEMBRO'.split()
DATE = re.compile(r'\b(\d{1,2})\s+DE\s+('+'|'.join(MONTHS)+r')\s+DE\s+(20\d{2})\b|\b(\d{1,2})[/.\-](\d{1,2})[/.\-](20\d{2})\b')

def norm(s):
    return ''.join(c for c in unicodedata.normalize('NFKD',str(s or '')) if not unicodedata.combining(c)).upper()

def clean(s): return re.sub(r'\s+', ' ',str(s or '')).strip()
def words(s): return clean(re.sub(r'[^A-Z0-9]+',' ',norm(s)))
def company_key(s):
    s=words(s)
    s=re.sub(r'\s+20\d{2}$','',s)
    s=re.sub(r'\s+(?:LTDA|LIMITADA|S A|SA|EIRELI|ME|EPP)$','',s)
    return s.strip()
def unique(xs): return list(dict.fromkeys(x for x in xs if x))
def services(s):
    s=norm(s).replace('_',' ')
    return [k for k,p in SERVICES.items() if re.search(p,s)]
def cnpj_values(s):
    found=[]
    for m in re.finditer(r'(?<![0-9])(?:[0-9]{2}\.?[0-9]{3}\.?[0-9]{3}/?[0-9]{4}-?[0-9]{2})(?![0-9])',str(s or '')):
        d=re.sub(r'\D','',m.group())
        if len(d)!=14 or len(set(d))==1 or d=='43386711000159': continue
        def check(digits,weights):
            r=sum(int(v)*w for v,w in zip(digits,weights))%11
            return str(0 if r<2 else 11-r)
        if check(d[:12],[5,4,3,2,9,8,7,6,5,4,3,2])==d[12] and check(d[:13],[6,5,4,3,2,9,8,7,6,5,4,3,2])==d[13]: found.append(d)
    return unique(found)

def client_names(lines):
    values=[]
    for i,line in enumerate(lines[:75]):
        match=re.match(r'^\s*(?:CLIENTE|CONTRATANTE|RAZAO SOCIAL DO CLIENTE)\s*[:–-]?\s*$',norm(line))
        if match:
            for nxt in lines[i+1:i+5]:
                if nxt.strip():
                    val=re.split(r'\s{3,}',nxt.strip())[0]
                    if 3<=len(val)<=180 and not re.search(r'PROPOSTA|DOCUMENTO|CNPJ|EXPORTCONTROL|EMISSAO',norm(val)): values.append(val)
                    break
        else:
            match=re.match(r'^\s*(?:CLIENTE|CONTRATANTE|RAZ[AÃ]O SOCIAL DO CLIENTE)\s*:\s*(.+)',line,re.I)
            if match:
                val=re.split(r'\s{3,}',match[1].strip())[0]
                if 3<=len(val)<=180 and not re.search(r'PROPOSTA|DOCUMENTO|EXPORTCONTROL',norm(val)): values.append(val)
    return unique(values)

def list_json(x):
    if isinstance(x,str):
        try: x=json.loads(x)
        except ValueError: return []
    return x if isinstance(x,list) else []

def sha256(p):
    h=hashlib.sha256()
    with p.open('rb') as f:
        for b in iter(lambda:f.read(1024*1024),b''): h.update(b)
    return h.hexdigest()

def text_of(path):
    ext=path.suffix.lower()
    if ext=='.pdf':
        p=subprocess.run(['pdftotext','-layout','-enc','UTF-8',str(path),'-'],capture_output=True,timeout=45,check=True)
        return p.stdout.decode('utf-8',errors='replace')
    if ext=='.doc':
        p=subprocess.run(['antiword',str(path)],capture_output=True,timeout=45,check=True)
        return p.stdout.decode('utf-8',errors='replace')
    if ext=='.docx':
        ns={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}; W='{'+ns['w']+'}'
        with zipfile.ZipFile(path) as z:
            parts=[n for n in z.namelist() if n=='word/document.xml' or re.fullmatch(r'word/(?:header|footer)\d+\.xml',n)]
            if 'word/document.xml' not in parts: raise ValueError('DOCX sem documento principal')
            lines=[]
            for n in parts:
                if z.getinfo(n).file_size>20*1024*1024: raise ValueError('XML muito grande')
                root=ET.fromstring(z.read(n))
                def para(e): return ''.join(t.text or '' for t in e.iter(W+'t'))
                for e in root.iter():
                    if e.tag==W+'body' or e is root and n!='word/document.xml':
                        for c in e:
                            if c.tag==W+'p': lines.append(para(c))
                            elif c.tag==W+'tbl':
                                for tr in c.findall('w:tr',ns): lines.append('    '.join(' '.join(para(q) for q in tc.findall('w:p',ns)) for tc in tr.findall('w:tc',ns)))
            return '\n'.join(lines)
    raise ValueError('Formato fora do escopo')

def dates(s):
    out=[]
    for m in DATE.finditer(norm(s)):
        try:
            if m[1]: d=dt.date(int(m[3]),MONTHS.index(m[2])+1,int(m[1]))
            else: d=dt.date(int(m[6]),int(m[5]),int(m[4]))
            out.append(d.isoformat())
        except ValueError: pass
    return unique(out)

def issue_date(lines, year):
    preferred=[]; cover=[]
    for i,l in enumerate(lines):
        n=norm(l)
        if re.search(r'\b(?:EMISSAO|EMITID[AO] EM|DATA DA PROPOSTA)\s*[:–-]',n) or re.search(r'\b(?:TOLEDO|PALOTINA)\s*(?:/\s*PR)?\s*[,·–-]',n):
            preferred.extend(dates(l) or (dates(lines[i+1]) if i+1<len(lines) else []))
        if i<35 and not re.search(r'VALIDADE|PERIODO|VENCIMENTO|ULTIMOS|\bATE\b|\bENTRE\b',n): cover.extend(dates(l))
    vals=unique(preferred) or unique(cover)
    if year: vals=[d for d in vals if d[:4]==str(year)]
    return vals[0] if len(vals)==1 else None, vals

def amount_candidates(lines, patterns):
    out=[]
    for i,l in enumerate(lines):
        n=norm(l)
        for score,pat in patterns:
            for label in re.finditer(pat,n):
                # Match amount on same cell or adjacent value line; never take a different labeled column.
                after=l[label.end():]; same=re.split(r'\s{3,}',after.strip(),maxsplit=1)[0] if after.strip() else ''
                matches=list(MONEY.finditer(after))
                if matches and not re.search(r'[A-Z]{3,}',norm(after[:matches[0].start()]).replace('R$','')):
                    out.append((score,matches[0][1],clean(l)))
                    continue
                if matches and MONEY.search(same):
                    # Unrelated intervening text is not accepted.
                    continue
                for j in range(i+1,min(i+3,len(lines))):
                    if not lines[j].strip(): continue
                    vals=list(MONEY.finditer(lines[j]))
                    if vals:
                        near=min(vals,key=lambda m:abs(m.start()-label.start()))
                        if abs(near.start()-label.start())<=14 and (len(vals)==1 or all(abs(m.start()-label.start())>abs(near.start()-label.start())+5 for m in vals if m is not near)):
                            out.append((score,near[1],clean(l)+' | '+clean(lines[j])))
                    break
    converted=[]
    for score,value,line in out:
        try:
            d=Decimal(value.replace('.','').replace(',','.'))
            if 0<=d<=Decimal('999999999.99'): converted.append((score,format(d,'.2f'),line))
        except InvalidOperation: pass
    if not converted: return None,[]
    best=max(x[0] for x in converted)
    vals=unique(x[1] for x in converted if x[0]==best)
    return (vals[0] if len(vals)==1 else None),[{'valor':v,'trecho':l,'prioridade':s} for s,v,l in unique(converted)][:25]

MONTHLY=[(4,r'\bMENSALIDADE\s+(?:FINAL|COM (?:TRIBUTOS|IMPOSTOS))\b|\bVALOR\s+MENSAL\s+FINAL\b|\bTOTAL\s+MENSAL\s+FINAL\b'),(3,r'\b(?:VALOR|INVESTIMENTO|TOTAL|CUSTO)\s+MENSAL\b|\bMENSALIDADE\s+(?:DE|FIXA|TOTAL)\b|\bVALOR\s+DA\s+MENSALIDADE\b'),(2,r'\bMENSALIDADE\s*(?=[:–-]|R\$|$)')]
ONE_TIME=[(4,r'\bVALOR\s+FINAL\s+DO\s+SERVICO\b|\bVALOR\s+TOTAL\s+DO\s+SERVICO\b'),(3,r'\bVALOR\s+(?:DA\s+)?IMPLANTACAO\b|\bTAXA\s+DE\s+IMPLANTACAO\b|\bVALOR\s+UNICO\b|\bINVESTIMENTO\s+UNICO\b')]

def names_emails(lines):
    emails=[]; recipients=[]; names=[]
    for i,l in enumerate(lines):
        n=norm(l)
        for m in EMAIL.finditer(l):
            e=m[1].lower().rstrip('.')
            if re.search(r'\b(?:DESTINATARIO|ENVIAD[AO] PARA|E[ -]?MAIL (?:DO CLIENTE|DESTINATARIO)|CONTATO DO CLIENTE)\b',n): recipients.append(e)
            elif e.endswith('@exportcontrol.com.br') and e.split('@')[0] not in {'contato','comercial','financeiro','suporte','atendimento','vendas','noreply','administrativo'}:
                emails.append(e)
                # Signature: the person's name is usually above the commercial role.
                for prev in reversed(lines[max(0,i-4):i]):
                    first=re.split(r'\s{2,}',prev.strip())[0]
                    if re.search(r'\b(?:ASSISTENTE|ANALISTA|DIRETOR|GERENTE|CONSULTOR|COORDENADOR|SUPERVISOR|EXECUTIVO|COMERCIAL|EXPORTCONTROL|PROPOSTA|CNPJ|CONTATO)\b',norm(first)): continue
                    if re.fullmatch(r"[A-Za-zÀ-ÖØ-öø-ÿ'’.-]+(?: [A-Za-zÀ-ÖØ-öø-ÿ'’.-]+){1,5}",first) and norm(first) not in {'ATENCIOSAMENTE','MUITO OBRIGADO'}:
                        names.append(first); break
        explicit=re.match(r'\s*(?:RESPONS[AÁ]VEL (?:PELA PROPOSTA|COMERCIAL)|ELABORADO POR|EMITIDO POR|CONSULTOR COMERCIAL)\s*:\s*(.+)',l,re.I)
        if explicit:
            first=re.split(r'\s{2,}|\s*\|\s*',explicit[1].strip())[0]
            if not EMAIL.search(first) and 3<=len(first)<=100: names.append(first)
    emails=unique(emails); recipients=unique(recipients); names=unique(names)
    return (names[0] if len(names)==1 else None,emails[0] if len(emails)==1 else None,recipients[0] if len(recipients)==1 else None,{'nomes':names,'emails_responsaveis':emails,'emails_destinatarios':recipients})

def memory(lines):
    indices=set(); headings=r'^\s*(?:MEMORIA(?: DE CALCULO)?|INVESTIMENTO|CONDICOES COMERCIAIS|FORMACAO DO VALOR|CALCULO(?: DO VALOR)?|PRECIFICACAO)\b'
    for i,l in enumerate(lines):
        n=norm(l)
        if re.search(headings,n) and len(l.strip())<120:
            for j in range(i,min(i+65,len(lines))):
                if j>i+1 and re.match(r'^\s*(?:ESCOPO|RESUMO EXECUTIVO|OBJETIVO|RESPONSABILIDADES|ACEITE|ASSINATURA)\s*$',norm(lines[j])): break
                if j>i+1 and re.search(headings,norm(lines[j])) and len(lines[j].strip())<100: break
                indices.add(j)
        elif re.search(r'MEMORIA|GROSS[ -]?UP|\bFAIXA\b|\bSUBTOTAL\b|\bALIQUOTA\b|\bMENSALIDADE\b|VALOR FINAL|VALOR TOTAL',n) and (MONEY.search(l) or re.search(r'FAIXA|MEMORIA|GROSS[ -]?UP|ALIQUOTA|SUBTOTAL',n)):
            indices.update(range(max(0,i-2),min(len(lines),i+4)))
    if not indices: return None
    out=[]; prev=-2
    for i in sorted(indices):
        if i>prev+1 and out: out.append('…')
        out.append(lines[i].rstrip()); prev=i
    return '\n'.join(out).strip()[:26000] or None

def extract(row,doc,root):
    name=Path(doc['original_name']).name; title=Path(name).stem
    path=(root/doc['storage_path']).resolve(strict=True)
    if not path.is_relative_to(root.resolve()) or not path.is_file(): raise ValueError('Caminho fora do disco privado')
    if sha256(path)!=doc['sha256']: raise ValueError('SHA-256 diferente do original')
    base={'proposal_id':int(row['id']),'document_sha256':doc['sha256'],'title':title[:500], 'document_name':name[:255], 'client_name':row['client_name'], 'legacy_code':row['legacy_code']}
    filename_codes=unique(CODE.findall(title.replace('_',' ')))
    try:
        txt=text_of(path); error=None if len(clean(txt))>=40 else 'Sem texto suficiente (PDF digitalizado ou documento sem texto)'
    except (ValueError, OSError, subprocess.SubprocessError, ET.ParseError, zipfile.BadZipFile) as e: txt=''; error=type(e).__name__+': '+str(e)[:250]
    lines=txt.replace('\f','\n').splitlines()
    doc_codes=unique(CODE.findall(txt.replace('_',' ')))
    codes=filename_codes or doc_codes
    conflict=bool(filename_codes and doc_codes and not set(filename_codes).issubset(set(doc_codes)))
    year,number=codes[0] if len(codes)==1 and not conflict else (None,None)
    date,dc=issue_date(lines,year)
    svc=services(title) or [x for x in list_json(row.get('service_codes')) if x in SERVICES]
    if not svc:
        svc=services(' '.join(lines[:18]))
        if len(svc)>1: svc=[]
    monthly,mc=amount_candidates(lines,MONTHLY)
    once,oc=amount_candidates(lines,ONE_TIME)
    if svc==['RETROATIVAS']: monthly=None
    resp,email,recipient,ec=names_emails(lines)
    return {**base,'historic_year':int(year) if year else None,'historic_number':number,'issued_on':date,'responsible_name':resp,'responsible_email':email,'recipient_email':recipient,'monthly_amount':monthly,'one_time_amount':once,'services':svc,'calculation':memory(lines),'extraction_status':'review' if error or conflict else 'extracted','commercial_status':'sent_history','crm_company_id':None,'crm_deal_id':None,'crm_stage':None,'crm_match':'unmatched','evidence':{'leitura':error,'codigos_nome':filename_codes,'codigos_documento':doc_codes,'conflito_codigo':conflict,'datas_candidatas':dc,'valores_mensais':mc,'valores_pontuais':oc,'clientes_documento':client_names(lines),'cnpjs_documento':cnpj_values(txt),'cnpjs_cliente_explicito':unique(c for i,line in enumerate(lines[:100]) if re.search(r'\b(?:CNPJ DO CLIENTE|CNPJ DA CONTRATANTE|CNPJ CONTRATANTE|CLIENTE|CONTRATANTE)\s*[:–-]',norm(line)) for c in cnpj_values(' '.join(lines[i:i+3]))),**ec}}

def reconcile(rows,crm):
    company_index=collections.defaultdict(list); cnpj_index=collections.defaultdict(list); alias_index=collections.defaultdict(set); companies={}
    for c in crm['companies']:
        cid=int(c['id']); companies[cid]=c
        key=company_key(c.get('name'))
        if key: company_index[key].append(cid)
        for field in ('cnpj','tax_id','document_number','cnpj_number','document'):
            for cnpj in cnpj_values(c.get(field)): cnpj_index[cnpj].append(cid)
    deals={int(d['id']):d for d in crm['deals']}; links=collections.defaultdict(set)
    deal_companies=collections.defaultdict(set)
    for link in crm['links']:
        cid,did=int(link['hubspot_company_id']),int(link['hubspot_deal_id'])
        links[cid].add(did); deal_companies[did].add(cid)
    for did,d in deals.items():
        if len(deal_companies[did])!=1: continue
        parts=re.split(r'\s+[-–—|]\s+',str(d.get('name') or ''))
        if len(parts)<2 or not services(d.get('name')): continue
        for part in parts:
            if services(part) or re.fullmatch(r'20\d{2}',part.strip()): continue
            key=company_key(part)
            if len(key)>=3: alias_index[key].update(deal_companies[did])
    candidates={}; groups=collections.defaultdict(list)
    for r in rows:
        keys=unique(company_key(x) for x in [r['client_name'],*r['evidence'].get('clientes_documento',[])])
        name_ids=unique(cid for key in keys for cid in company_index.get(key,[]))
        doc_ids=unique(cid for cnpj in r['evidence'].get('cnpjs_documento',[]) for cid in cnpj_index.get(cnpj,[]))
        alias_ids=unique(cid for key in keys for cid in sorted(alias_index.get(key,set())))
        known=name_ids or alias_ids
        if doc_ids and known and not set(doc_ids).intersection(known):
            cids=unique(doc_ids+known)
        elif doc_ids and known: cids=sorted(set(doc_ids).intersection(known))
        elif known: cids=known
        else: cids=unique(cid for cnpj in r['evidence'].get('cnpjs_cliente_explicito',[]) for cid in cnpj_index.get(cnpj,[]))
        r['evidence']['criterio_empresa']='cnpj' if doc_ids else ('nome_exato' if name_ids else 'alias_exato_negocio')
        if len(cids)!=1:
            r['crm_match']='ambiguous_company' if cids else 'unmatched_company'; r['evidence']['empresas_candidatas']=cids; continue
        cid=cids[0]; r['crm_company_id']=cid
        svc=set(r['services']); year=r['historic_year']; num=r['historic_number']
        exact=[]; possible=[]; known_deals=[]
        for did in sorted(links[cid]):
            d=deals.get(did)
            if not d: continue
            if d.get('archived') in (True,1,'1','true','t'): continue
            name=d.get('name') or ''; ds=set(services(name)); dy=set(re.findall(r'\b20\d{2}\b',name)); codes=CODE.findall(name.replace('_',' '))
            known_deals.append(did)
            if year and num and (str(year),num) in codes and (not ds or ds==svc): exact.append(did); continue
            if not svc or ds!=svc or not year or (dy and str(year) not in dy) or codes: continue
            possible.append(did)
        r['evidence']['negocios_candidatos']=exact or possible
        r['evidence']['total_negocios_empresa']=len(known_deals)
        if len(exact)==1: candidates[r['proposal_id']]=(exact[0],'exact_code'); groups[exact[0]].append(r['proposal_id'])
        elif len(exact)>1: r['crm_match']='ambiguous_deal'
        elif len(possible)==1: candidates[r['proposal_id']]=(possible[0],'unique_company_service'); groups[possible[0]].append(r['proposal_id'])
        else: r['crm_match']='ambiguous_deal' if possible else 'no_service_deal'
    # No same deal automatically accepted for multiple historical files, including revisions.
    for r in rows:
        if r['proposal_id'] not in candidates: continue
        did,method=candidates[r['proposal_id']]
        if len(groups[did])!=1: r['crm_match']='multiple_proposals_same_deal'; continue
        d=deals[did]; r['crm_deal_id']=did; r['crm_stage']=d.get('stage_label'); r['crm_match']=method
        if words(d.get('stage_label')) in ACCEPTED: r['commercial_status']='accepted_crm'
    return rows

def main():
    p=argparse.ArgumentParser(); p.add_argument('--export',required=True,type=Path);p.add_argument('--root',required=True,type=Path);p.add_argument('--output',required=True,type=Path);a=p.parse_args()
    data=json.loads(a.export.read_text()); docs=collections.defaultdict(list)
    for d in data['documents']: docs[int(d['proposal_id'])].append(d)
    rows=[]; errors=[]
    for i,r in enumerate(data['proposals'],1):
        dd=sorted(docs[int(r['id'])],key=lambda d:(not d['original_name'].lower().endswith('.pdf'),int(d['id'])))
        try:
            if len(dd)!=1: raise ValueError('Esperado um original por registro historico')
            rows.append(extract(r,dd[0],a.root))
        except (ValueError,OSError) as e: errors.append({'id':r['id'],'erro':str(e)[:300]})
        if i%50==0: print(f'Analisados {i}/{len(data["proposals"])}',flush=True)
    reconcile(rows,data)
    summary={'propostas':len(data['proposals']),'metadados':len(rows),'falhas_integridade':len(errors),'texto_pendente':sum(x['extraction_status']=='review' for x in rows),'mensalidades_identificadas':sum(x['monthly_amount'] is not None for x in rows),'valores_pontuais':sum(x['one_time_amount'] is not None for x in rows),'datas':sum(x['issued_on'] is not None for x in rows),'responsaveis':sum(x['responsible_name'] is not None for x in rows),'emails_responsaveis':sum(x['responsible_email'] is not None for x in rows),'memorias':sum(x['calculation'] is not None for x in rows),'aceitas_crm':sum(x['commercial_status']=='accepted_crm' for x in rows),'vinculos':dict(collections.Counter(x['crm_match'] for x in rows))}
    a.output.write_text(json.dumps({'version':1,'summary':summary,'errors':errors,'rows':rows},ensure_ascii=False,indent=2))
    print(json.dumps(summary,ensure_ascii=False,indent=2))
    if errors: print('ATENCAO: arquivos com falha de integridade ficaram fora do enriquecimento.')
if __name__=='__main__': main()
