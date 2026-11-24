#!/usr/bin/env python3
"""Identity/volume enrichment from each stored original, never from a reused proposal number."""
import argparse
import collections
import csv
import json
import re
from pathlib import Path
from extract_history import text_of, sha256, clean, norm, words, list_json, EMAIL, issue_date, cnpj_values
import history_metadata_v3 as v3
import history_finance_v2 as v2
import history_legacy_v6 as v6

COUNT = r'(\d{1,3}(?:\.\d{3})+|\d{1,9})'
MAIL_GENERIC = v3.GENERIC
RECIP = re.compile(r'^(?:AOS?\s+CUIDADOS\s+D[EAO]|A/C|ATENCAO|DESTINATARIO|CONTATO(?: DO CLIENTE)?|RESPONSAVEL DO CLIENTE)\s*(?:[:–-]\s*)?(.*)$')
STOP = re.compile(r'^(?:CLIENTE\b|DOCUMENTO\b|PROPOSTA\b|EXPORTCONTROL\b|J TOLEDO\b|FORNECEDOR\b|RESUMO EXECUTIVO\b|ESCOPO\b|INVESTIMENTO\b|CONDICOES COMERCIAIS\b|TOLEDO\b|DATA DE EMISSAO\b|PAGINA\b)')
SIGN_ROLE = re.compile(r'^(?:COMERCIAL|EQUIPE COMERCIAL|(?:GERENTE|COORDENADOR[A]?|ASSISTENTE|ANALISTA|CONSULTOR[A]?|EXECUTIVO[A]?|DIRETOR[A]?|SUPERVISOR[A]?)\b.{0,50})$')
ANNUAL_LABEL = re.compile(r'^(?:VOLUME(?: DE DOCUMENTOS)? (?:DOS|NOS|EM) ULTIMOS 12 MESES|DOCUMENTOS(?: FISCAIS)? (?:DOS|NOS|EM) ULTIMOS 12 MESES|NOTAS(?: FISCAIS)? (?:DOS|NOS|EM) ULTIMOS 12 MESES|(?:NFS(?:-E)?|NF-E|DF-E|CT-E) (?:DOS |NOS |EM )?(?:ULTIMOS )?12 MESES|TOTAL (?:DE DOCUMENTOS |DE NOTAS |DE NFS )?(?:DOS |NOS |EM )?(?:ULTIMOS )?12 MESES)$')
MONTH_LABEL = re.compile(r'^(?:MEDIA MENSAL|BASE MENSAL|MEDIA DE REFERENCIA|QUANTIDADE MENSAL)$')

def one(items):
    values = list(dict.fromkeys(x for x in items if x is not None and x != ''))
    return (values[0], 'identified') if len(values)==1 else (None, 'conflict' if values else 'missing')

def integer(s):
    try:
        n=int(s.replace('.',''))
        return str(n) if 0<=n<=2147483647 else None
    except (ValueError, AttributeError):return None

def valid_name(s):
    x=clean(s)
    if not re.fullmatch(r"[A-Za-zÀ-ÖØ-öø-ÿ'’.-]+(?: [A-Za-zÀ-ÖØ-öø-ÿ'’.-]+){1,11}",x):return None
    if STOP.search(norm(x)) or SIGN_ROLE.fullmatch(norm(x)) or re.search(r'\b(?:E-MAIL|EMAIL|LTDA|VALIDADE|PAGAMENTO|EMISSAO|MENSALIDADE)\b',norm(x)):return None
    return x

def mail(s, supplier=False):
    return [m[1].lower() for m in EMAIL.finditer(s) if (m[1].lower().endswith('@exportcontrol.com.br')) == supplier and (not supplier or m[1].split('@')[0].lower() not in MAIL_GENERIC)]

def recipient(text):
    # AOS CUIDADOS DE is often a separate block *after* DOCUMENTO Nº.
    # Do not look for recipient email in the supplier directory or body.
    cover=text.split('\f',1)[0].splitlines()[:140]
    names=[]; emails=[]; found=False
    for i,line in enumerate(cover):
        cell=v3.first_cell(line); m=RECIP.match(norm(cell))
        if not m:continue
        found=True
        block=[]
        if m[1]:block.append(cell[m.start(1):])
        for nxt in cover[i+1:i+11]:
            cell2=v3.first_cell(nxt); n=norm(cell2)
            if not cell2:continue
            if STOP.match(n) or v3.ROLE.search(n) or RECIP.match(n) or SIGN_ROLE.fullmatch(n):break
            if any(mail(cell2,True)):break
            block.append(cell2)
        local_n=[];local_e=[]
        for s in block:
            local_e+=mail(s)
            name=valid_name(EMAIL.sub('',s).strip(' :;,-'))
            if name:local_n.append(name)
        names+=local_n; emails+=local_e
    name,ns=one(names); email,es=one(emails)
    return {'name':name,'email':email,'name_state':ns,'email_state':es,'found':found,'candidates':list(dict.fromkeys(emails))}

def footer_signer(text):
    # Restrict modern signature to the last page or the final commercial section
    # of a Word document. Inspect the left column only, not supplier legal data.
    pages=[p for p in text.split('\f') if clean(p)]
    if len(pages)>1: tail=pages[-1]
    else:
        matches=list(re.finditer(r'^\s*(?:CONDICOES COMERCIAIS|TRIBUTOS, PAGAMENTO E VALIDADE)\s*$',norm(text),re.M))
        if not matches:return None,None,'missing'
        tail=text[matches[-1].start():]
    lines=[v3.first_cell(l) for l in tail.splitlines() if l.strip()]
    pairs=[]
    for i,line in enumerate(lines):
        e=mail(line,True)
        if len(e)!=1 or i<2:continue
        if not SIGN_ROLE.fullmatch(norm(lines[i-1])):continue
        name=valid_name(lines[i-2])
        if name:pairs.append((name,e[0]))
    pair,state=one(pairs)
    return (pair[0],pair[1],state) if pair else (None,None,state)

def read_cover_cnpj(text):
    # Only explicitly labelled CNPJ in the customer block, never the vendor footer.
    lines=text.split('\f',1)[0].splitlines()[:120]; active=False; candidates=[]
    for line in lines:
        cell=v3.first_cell(line); n=norm(cell)
        if re.match(r'^(?:CLIENTE|CONTRATANTE|RAZAO SOCIAL(?: DO CLIENTE)?)\s*(?::|$)',n):active=True;continue
        if not active:continue
        if STOP.match(n) or SIGN_ROLE.fullmatch(n) or v3.ROLE.search(n) or mail(cell,True):break
        if re.match(r'^CNPJ(?: DO CLIENTE| DA CONTRATANTE)?\s*:',n):candidates+=cnpj_values(cell)
    return one(candidates)

def label_quantities(text, pattern):
    out=[]
    for page in text.split('\f'):
        lines=page.splitlines()
        for i,line in enumerate(lines):
            for pos,cell in v2.cells(line):
                n=norm(cell)
                if not pattern.fullmatch(n):continue
                for nxt in lines[i+1:i+8]:
                    if not nxt.strip():continue
                    choices=[s for p,s in v2.cells(nxt) if abs(p-pos)<=12]
                    if len(choices)!=1:break
                    match=re.fullmatch(COUNT+r'\s*(?:NFS?|NF-E|NFS-E|NOTAS(?: FISCAIS)?|DOCUMENTOS(?: FISCAIS)?)?',norm(choices[0]))
                    if match:out.append((integer(match[1]),clean(cell)+' | '+clean(choices[0])))
                    break
    return out

def quantities(text, svc):
    if len(svc)!=1 or svc==['RETROATIVAS']:return [],[]
    n=norm(clean(text)); a=label_quantities(text,ANNUAL_LABEL); b=label_quantities(text,MONTH_LABEL)
    for pat in [
        r'(?<![\d.,])'+COUNT+r'\s+(?:DOCUMENTOS(?: FISCAIS)?|NOTAS(?: FISCAIS)?|NFS?-E|NFS?)\s+(?:EMITID[OA]S?\s+)?(?:NOS|DOS|EM|DURANTE OS)\s+(?:ULTIMOS\s+)?12\s+MESES\b',
        r'\bTOTALIZANDO\s+'+COUNT+r'\s+(?:DOCUMENTOS(?: FISCAIS)?\s+|NOTAS(?: FISCAIS)?\s+)?NOS\s+ULTIMOS\s+12\s+MESES\b',
        r'\b(?:NOS|DOS)\s+ULTIMOS\s+12\s+MESES\s+(?:FORAM\s+EMITID[OA]S?\s+)?'+COUNT+r'\s+(?:DOCUMENTOS(?: FISCAIS)?|NOTAS(?: FISCAIS)?|NFS?-E|NFS?)\b',
    ]:
        for m in re.finditer(pat,n):a.append((integer(m[1]),clean(m.group())))
    for pat in [r'\bMEDIA MENSAL\s+(?:UTILIZADA\s+E\s+DE|DE|INFORMADA\s+DE)\s+'+COUNT+r'\s+(?:NOTAS(?: FISCAIS)?|DOCUMENTOS(?: FISCAIS)?|NFS?)\b',r'\bBASE DE PRECIFICACAO:\s*'+COUNT+r'\s+(?:NOTAS(?: FISCAIS)?|DOCUMENTOS(?: FISCAIS)?)\s+MENSAIS\b']:
        for m in re.finditer(pat,n):b.append((integer(m[1]),clean(m.group())))
    return a,b

def current_conditions(text):
    # Capture wrapped sentences, including blank PDF rows, from the original.
    out={}
    for page in text.split('\f'):
        lines=page.splitlines()
        for i,line in enumerate(lines):
            cells=v2.cells(line)
            for pos,cell in cells:
                key={'VALIDADE':'validade','PAGAMENTO':'pagamento','DEPENDENCIA':'dependencia'}.get(norm(cell))
                if not key or len(cells)<2:continue
                parts=[]
                for nxt in lines[i+1:i+10]:
                    if not nxt.strip():continue
                    choices=[s for p,s in v2.cells(nxt) if abs(p-pos)<=12]
                    if len(choices)!=1:break
                    s=choices[0]; n=norm(s)
                    if STOP.match(n) or n.startswith('OBSERVACOES') or EMAIL.search(s):break
                    if not parts and not re.search(r'\b(?:DIAS|LICENCIAMENTO|FATURAMENTO|PAGAMENTO)\b',n):break
                    parts.append(s)
                    if s.rstrip().endswith(('.', ';')):break
                if parts:out.setdefault(key,[]).append(clean(' '.join(parts)))
    return {k:val for k,items in out.items() if (val:=one(items)[0])}

def extract(text, title, existing):
    svc=v6.classify(title,text,existing)
    # Keep old signature/cover parsing as fallback. Modern explicit blocks take priority.
    ident=v3.client_block(text); rn,em,source=v3.responsible(text)
    if rn and not em:em=v6.signer_email(text,rn)
    modern=recipient(text)
    if modern['found']:
        ident['recipient_candidates']=modern['candidates']
        if modern['name_state']=='identified':ident['recipient_name']=modern['name']
        elif modern['name_state']=='conflict':ident['recipient_name']=None
        if modern['email_state']=='identified':ident['recipient_email']=modern['email']
        elif modern['email_state']=='conflict':ident['recipient_email']=None
    fn,fe,fs=footer_signer(text)
    # A signed old-style closing is higher priority; generic footer must be unambiguous.
    if fs=='identified' and source.get('source')!='assinatura_final':rn,em=fn,fe;source={'source':'bloco_comercial_final_v7','candidates':[fn]}
    elif fs=='conflict' and source.get('source')!='assinatura_final':rn,em=None,None;source={'source':'conflito_bloco_final','candidates':[]}
    ident.update(responsible_name=rn,responsible_email=em,responsible_source=source)
    annual,monthly=quantities(text,svc)
    av,ast=one(v for v,_ in annual);bv,bst=one(v for v,_ in monthly)
    # No implicit annualization or conversion of sample totals into volumes.
    return {'identity':ident,'services':svc,'recipient_state':modern,'signature_state':fs,'annual':av,'annual_state':ast,'monthly_base':bv,'monthly_base_state':bst,'annual_evidence':annual,'monthly_evidence':monthly,'cnpj':read_cover_cnpj(text)[0],'conditions':current_conditions(text)}

def generate(data,root):
    metas={int(m['proposal_id']):m for m in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[]; pending=[]; counts=collections.Counter(); root=root.resolve()
    for p in data['proposals']:
        pid=int(p['id']); m=metas.get(pid); issues=[]
        try:
            if not m or len(docs[pid])!=1:raise ValueError('Metadados ou documento histórico único indisponíveis')
            doc=docs[pid][0]; path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root) or doc['sha256']!=m['document_sha256'] or sha256(path)!=doc['sha256']:raise ValueError('Hash/caminho divergente; nenhum dado atualizado')
            text=text_of(path)
            if len(clean(text))<40:raise ValueError('Documento sem texto extraível')
            evidence=v6.obj(m.get('evidence')); old_identity=v6.obj(evidence.get('identity_v3')); finance=v6.obj(evidence.get('finance_v2'))
            svc=list_json(m.get('services')) or list_json(p.get('service_codes')); r=extract(text,m.get('title',''),svc); ident=r['identity']; meta={}; core={}; identity_patch={}; finance_patch={}
            def filled(v):return v is not None and v!=''
            def equal(a,b):return str(a).strip().casefold()==str(b).strip().casefold()
            def agree(a,b,key):
                if filled(a) and not equal(a,b):issues.append('Divergência: '+key);return False
                return True
            for key,column,corecol,limit in [('recipient_name',None,'client_contact',160),('recipient_email','recipient_email','client_email',200),('responsible_name','responsible_name','sales_responsible',160),('responsible_email','responsible_email',None,320),('client_legal_name',None,None,220)]:
                val=ident.get(key)
                if not filled(val):
                    if not filled(old_identity.get(key)) and not (column and filled(m.get(column))):issues.append('Não identificado: '+key)
                    continue
                if len(val)>limit:issues.append('Campo excede limite: '+key);continue
                if not agree(old_identity.get(key),val,key) or (column and not agree(m.get(column),val,column)) or (corecol and not agree(p.get(corecol),val,corecol)):continue
                if not filled(old_identity.get(key)):identity_patch[key]=val
                if column and not filled(m.get(column)):meta[column]=val
                if corecol and not filled(p.get(corecol)):core[corecol]=val
                counts[key+'_confirmado']+=1
            if ident.get('responsible_name') and (identity_patch.get('responsible_name') or identity_patch.get('responsible_email')):
                identity_patch['responsible_source']=ident['responsible_source']
            if r['cnpj'] and agree(p.get('client_cnpj'),r['cnpj'],'client_cnpj') and not filled(p.get('client_cnpj')):core['client_cnpj']=r['cnpj']
            date,_=issue_date(text.replace('\f','\n').splitlines(),m.get('historic_year'))
            if date and agree(m.get('issued_on'),date,'issued_on') and agree(p.get('issued_on'),date,'issued_on'):
                if not filled(m.get('issued_on')):meta['issued_on']=date
                if not filled(p.get('issued_on')):core['issued_on']=date
            # Preserve V6 financial results. Add only explicit, non-conflicting document volumes.
            for field,val,state,proof in [('annual_quantity',r['annual'],r['annual_state'],r['annual_evidence']),('base_quantity',r['monthly_base'],r['monthly_base_state'],r['monthly_evidence'])]:
                if state=='conflict':issues.append('Volumes divergentes: '+field);continue
                if val is None:continue
                if not agree(finance.get(field),val,field):continue
                if field=='annual_quantity' and not agree(p.get('recurring_total'),val,'recurring_total'):continue
                if not filled(finance.get(field)):finance_patch[field]=val;finance_patch[field+'_evidence_v7']=[{'valor':v,'trecho':s} for v,s in proof[:15]]
                if field=='annual_quantity' and not filled(p.get('recurring_total')):core['recurring_total']=int(val)
                counts[field+'_confirmado']+=1
            if len(r['services'])==1 and r['services']!=['RETROATIVAS'] and not filled(finance.get('annual_quantity')) and 'annual_quantity' not in finance_patch:issues.append('Volume em 12 meses não identificado')
            for k,v in r['conditions'].items():
                previous=v6.obj(finance.get('conditions')).get(k)
                if len(v)<=400 and (not previous or (isinstance(previous,str) and len(v)>len(previous) and v.startswith(previous))):finance_patch.setdefault('conditions_patch',{})[k]=v
            if r['recipient_state']['name_state']=='conflict' or r['recipient_state']['email_state']=='conflict':issues.append('Mais de um destinatário no documento')
            if r['signature_state']=='conflict':issues.append('Mais de uma assinatura comercial')
            if not r['cnpj'] and not filled(p.get('client_cnpj')):issues.append('CNPJ do cliente não localizado na capa')
            if meta or core or identity_patch or finance_patch:
                rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':doc['sha256'],'meta':meta,'core':core,'identity_patch':identity_patch,'finance_patch':finance_patch})
            counts['lidos']+=1
            if issues:pending.append({'proposal_id':pid,'reason':'; '.join(dict.fromkeys(issues))})
        except Exception as e:pending.append({'proposal_id':pid,'reason':type(e).__name__+': '+str(e)[:200]});counts['falhas_leitura']+=1
    return {'version':7,'rows':rows,'summary':{'total_historico':len(data['proposals']),**counts,'registros_a_atualizar':len(rows),'propostas_com_pendencias':len(pending)},'pending':pending}

def main():
    p=argparse.ArgumentParser();p.add_argument('--export',type=Path,required=True);p.add_argument('--root',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    plan=generate(json.loads(a.export.read_text()),a.root)
    for name,val in [(a.output.name,plan),('resumo.json',plan['summary']),('pendencias.json',plan['pending'])]:(a.output.parent/name).write_text(json.dumps(val,ensure_ascii=False,indent=2))
    with a.output.with_name('revisao.csv').open('w',encoding='utf-8-sig',newline='') as fp:
        w=csv.writer(fp,delimiter=';');w.writerow(['id','campos_principais','metadados','identidade','volumes_condicoes'])
        for row in plan['rows']:w.writerow([row['proposal_id'],json.dumps(row['core'],ensure_ascii=False),json.dumps(row['meta'],ensure_ascii=False),json.dumps(row['identity_patch'],ensure_ascii=False),json.dumps(row['finance_patch'],ensure_ascii=False)])
    print(json.dumps(plan['summary'],ensure_ascii=False,indent=2))
if __name__=='__main__':main()
