#!/usr/bin/env python3
"""Recover the author of each historical proposal from its final signature, offline."""
import argparse
import collections
import csv
import json
import re
from pathlib import Path
from extract_history import text_of, sha256, clean, norm, words, EMAIL

GENERIC = {'contato','comercial','financeiro','suporte','atendimento','vendas','noreply','naoresponder','administrativo','propostas','info','marketing','exportcontrol','equipe','sac','fiscal'}
ROLE = re.compile(r'^(?:COMERCIAL|EQUIPE COMERCIAL|(?:GERENTE|COORDENADOR[A]?|ASSISTENTE|ANALISTA|CONSULTOR[A]?|EXECUTIVO[A]?|DIRETOR[A]?|SUPERVISOR[A]?|SOCIO[A]?)(?:\s+(?:COMERCIAL|DE NEGOCIOS|DE VENDAS|GERAL|DE RELACIONAMENTO))?)$')
CLOSING = re.compile(r'^(?:ATENCIOSAMENTE|CORDIALMENTE|SAUDACOES|ATT\.?)\s*[,.:]?\s*(.*)$')
EXPLICIT = re.compile(r'^(?:RESPONSAVEL (?:PELA PROPOSTA|COMERCIAL)|ELABORADO POR|EMITIDO POR)\s*:\s*(.+)$')
BAD = re.compile(r'\b(?:CLIENTE|CONTATO|CUIDADOS|FORNECEDOR|LTDA|EXPORTCONTROL|SOFTWARES|PROPOSTA|DOCUMENTO|PAGINA|CNPJ|EMISSAO|DATA|VALIDADE|PAGAMENTO|DEPENDENCIA|MENSALIDADE|VALOR|SERVICO|OBSERVACOES|INVESTIMENTO|EQUIPE|COMERCIAL|GERENTE|COORDENADOR|ASSISTENTE|ANALISTA|ATENCIOSAMENTE|CORDIALMENTE|OBRIGADO|VIGENCIA|TOLEDO|EMAIL|ASSINATURA|TRIBUTOS|REPRESENTANTE)\b')
EMPTY = {'','NAO IDENTIFICADO','NAO IDENTIFICADA','NAO INFORMADO','NAO INFORMADA','PENDENTE'}


def missing(v):
    return v is None or norm(str(v).strip()) in EMPTY


def first(line):
    return re.split(r'\s{3,}|\t+',line.strip(),maxsplit=1)[0]


def name_only(s):
    s=clean(s).strip(' ,:;–—-')
    if not re.fullmatch(r"[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ'’.-]*(?: [A-Za-zÀ-ÖØ-öø-ÿ'’.-]+){0,10}",s):return None
    if len(s)<3 or len(s)>160 or BAD.search(norm(s)) or ROLE.fullmatch(norm(s)):return None
    return s


def supplier_mails(s):
    return list(dict.fromkeys(m[1].lower() for m in EMAIL.finditer(s) if m[1].lower().endswith('@exportcontrol.com.br') and m[1].lower().split('@')[0] not in GENERIC))


def compatible_names(a,b):
    if missing(a) or missing(b):return False
    a,b=words(a).split(),words(b).split()
    if a==b:return True
    # Only compare aliases when evidence elsewhere has established identity.
    return bool(a and b and a[0]==b[0] and (set(a)<=set(b) or set(b)<=set(a)))


def compatible_email(name,email):
    tokens=words(name).split(); local=words(email.split('@')[0]).split()
    return bool(tokens and local and (tokens[0] in local or any(t in local for t in tokens if len(t)>=4)))


def roster_from(path):
    if not path or not path.is_file():return {}
    data=json.loads(path.read_text(encoding='utf-8'))
    roster=collections.defaultdict(list)
    for person in data.get('empresa_fornecedora',{}).get('contatos_exibidos',[]):
        n=name_only(person.get('nome',''))
        if n:
            for e in person.get('emails',[]):
                for address in supplier_mails(str(e)):roster[address].append(n)
    return {e:list(dict.fromkeys(names)) for e,names in roster.items()}


def cover_directory(text):
    lines=text.split('\f',1)[0].splitlines()[:150]; out=[]
    for i,line in enumerate(lines):
        s=first(line); parts=re.split(r'\s+[–—-]\s+',s,maxsplit=1)
        if len(parts)!=2 or not ROLE.fullmatch(norm(parts[1])):continue
        n=name_only(parts[0]); addresses=[]
        if not n:continue
        for following in lines[i+1:i+7]:
            c=first(following)
            if not c:continue
            if re.search(r'\s+[–—-]\s+',c) or name_only(c) or BAD.search(norm(c)) and not EMAIL.search(c):break
            addresses+=supplier_mails(c)
        for e in dict.fromkeys(addresses):out.append((n,e))
    return out


def signature(text,roster=None):
    roster=roster or {}
    pages=[(i+1,p) for i,p in enumerate(text.split('\f')) if clean(p)]
    if not pages:return {'state':'missing','candidates':[]}
    page_num,tail=pages[-1]
    lines=[(i+1,first(l),l.strip()) for i,l in enumerate(tail.splitlines()) if l.strip() and first(l)]
    if len(pages)==1:
        # Word documents lack page separators. Require a final closing or a
        # commercial section, never treat a cover directory as a signature.
        starts=[i for i,(_,c,_) in enumerate(lines) if CLOSING.match(norm(c)) or re.fullmatch(r'CONDICOES COMERCIAIS|TRIBUTOS, PAGAMENTO E VALIDADE',norm(c)) or EXPLICIT.match(norm(c))]
        if starts:lines=lines[starts[-1]:]
        else:lines=lines[max(len(lines)//2,len(lines)-35,0):]
    closing=[]; modern=[]; directory=cover_directory(text)
    def add(target,n,e,source,i,proof):
        if not n:return
        target.append({'signature_name':n,'email':e,'source':source,'page':page_num if len(pages)>1 else None,'line':lines[i][0],'evidence':clean(proof)[:500]})
    for i,(_,c,raw) in enumerate(lines):
        m=CLOSING.match(norm(c)) or EXPLICIT.match(norm(c))
        if m:
            n=name_only(c[m.start(1):]); j=i
            if not n and not m[1] and i+1<len(lines):j=i+1;n=name_only(lines[j][1])
            if not n:continue
            emails=[]; proof=[c,lines[j][1]]
            for _,s,_ in lines[j+1:j+4]:
                if ROLE.fullmatch(norm(s)) or re.fullmatch(r'[\d()+ .-]{7,}',s):proof.append(s);continue
                es=supplier_mails(s)
                if es:emails+=es;proof.append(s);continue
                break
            if not emails:
                emails=[e for dn,e in directory if compatible_names(n,dn) and (len(words(n).split())>1 or compatible_email(n,e))]
            emails=list(dict.fromkeys(emails))
            if len(emails)>1:
                for e in emails:add(closing,n,e,'assinatura_final_diretorio',i,' | '.join(proof))
            else:add(closing,n,emails[0] if emails else None,'assinatura_final',i,' | '.join(proof))
        es=supplier_mails(c)
        if len(es)!=1 or EMAIL.sub('',c).strip(' :;,-'):continue
        # Read left column only; support single first names and wrapped layout.
        if i>=2 and ROLE.fullmatch(norm(lines[i-1][1])):
            n=name_only(lines[i-2][1]);e=es[0]
            # A single token is credible only when the mailbox or roster agrees.
            if n and (len(words(n).split())>=2 or compatible_email(n,e) or any(compatible_names(n,x) for x in roster.get(e,[]))):
                # Without a page break, require a modern final block, not a cover list.
                supplier_column=bool(re.search(r'\s{3,}(?:EXPORTCONTROL|J TOLEDO)',norm(lines[i-2][2])))
                if len(pages)>1 or supplier_column or any(re.fullmatch(r'CONDICOES COMERCIAIS|TRIBUTOS, PAGAMENTO E VALIDADE',norm(x[1])) for x in lines[:i]):
                    add(modern,n,e,'bloco_comercial_final',i-2,' | '.join(x[1] for x in lines[i-2:i+1]))
        # A Word table may contain name, role and email in a single cell.
        elif i>=1:
            n=name_only(lines[i-1][1]);e=es[0]
            if n and compatible_email(n,e) and re.search(r'\s{3,}(?:EXPORTCONTROL|J TOLEDO)',norm(lines[i-1][2])):
                add(modern,n,e,'bloco_comercial_final',i-1,' | '.join(x[1] for x in lines[i-1:i+1]))
    candidates=closing+modern
    if not candidates:return {'state':'missing','candidates':[]}
    # Never choose one of two incompatible signatures, regardless of the order.
    name=candidates[0]['signature_name']; addresses=list(dict.fromkeys(x['email'] for x in candidates if x['email']))
    if any(not compatible_names(name,x['signature_name']) for x in candidates) or len(addresses)>1:
        return {'state':'conflict','candidates':candidates}
    e=addresses[0] if addresses else None
    actual=max(candidates,key=lambda x:len(words(x['signature_name']).split()))
    name=actual['signature_name'];display=name;canonical=None
    # Normalize only when the same personal mailbox uniquely identifies the
    # commercial roster and the literal signature agrees. Never change history.
    if e and len(roster.get(e,[]))==1:
        candidate=roster[e][0]
        if compatible_names(name,candidate) and len(words(candidate).split())>len(words(name).split()):display=candidate;canonical='cadastro_comercial_email'
    return {'state':'identified','name':display,'email':e,'signature_name':name,'source':actual['source'],'page':actual['page'],'evidence':actual['evidence'],'canonical_source':canonical,'candidates':candidates}


def obj(v):
    if isinstance(v,str):
        try:v=json.loads(v)
        except ValueError:return {}
    return v if isinstance(v,dict) else {}


def generate(data,root,roster=None):
    root=root.resolve(); metas={int(m['proposal_id']):m for m in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[];pending=[];counts=collections.Counter()
    for p in data['proposals']:
        pid=int(p['id']);m=metas.get(pid)
        try:
            if not m or len(docs[pid])!=1:raise ValueError('Metadados ou original único indisponíveis')
            d=docs[pid][0];path=(root/d['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root) or d['sha256']!=m['document_sha256'] or sha256(path)!=d['sha256']:raise ValueError('Hash ou caminho divergente')
            result=signature(text_of(path),roster);counts['lidos']+=1
            if result['state']!='identified':
                counts[result['state']]+=1;pending.append({'proposal_id':pid,'reason':'Assinatura '+result['state'],'candidates':result['candidates']});continue
            name,e=result['name'],result['email']; identity=obj(obj(m.get('evidence')).get('identity_v3'))
            prev_names=[v for v in [p.get('sales_responsible'),m.get('responsible_name'),identity.get('responsible_name')] if not missing(v)]
            prev_mails=[str(v).lower().strip() for v in [m.get('responsible_email'),identity.get('responsible_email')] if not missing(v)]
            # Preserve conflicting/manual assignments. A compatible alias can
            # fill other empty fields; it never overwrites an existing name.
            if any(not compatible_names(v,name) for v in prev_names) or (e and any(v!=e for v in prev_mails)) or len(set(prev_mails))>1:
                counts['divergencias']+=1;pending.append({'proposal_id':pid,'reason':'Responsável existente diverge da assinatura; preservar e revisar','existing_names':prev_names,'existing_emails':prev_mails,'signature':result});continue
            if prev_names and not e and not all(words(v)==words(name) for v in prev_names):
                counts['divergencias']+=1;pending.append({'proposal_id':pid,'reason':'Alias sem e-mail de confirmação','signature':result});continue
            display=max([name]+prev_names,key=lambda n:len(words(n).split()))
            meta={};core={};patch={}
            for field,prior,val,dest in [('sales_responsible',p.get('sales_responsible'),display,core),('responsible_name',m.get('responsible_name'),display,meta),('responsible_email',m.get('responsible_email'),e,meta),('responsible_name',identity.get('responsible_name'),display,patch),('responsible_email',identity.get('responsible_email'),e,patch)]:
                if val and missing(prior):dest[field]=val
            counts['assinaturas_identificadas']+=1
            if e:counts['emails_identificados']+=1
            else:pending.append({'proposal_id':pid,'reason':'Assinatura encontrada; e-mail pessoal não comprovado','signature':result})
            if meta or core or patch:
                rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':d['sha256'],'meta':meta,'core':core,'identity_patch':patch,'signature':result})
        except Exception as exc:
            counts['falhas_leitura']+=1;pending.append({'proposal_id':pid,'reason':type(exc).__name__+': '+str(exc)[:200]})
    return {'version':8,'rows':rows,'pending':pending,'summary':{'total_historico':len(data['proposals']),**counts,'registros_a_atualizar':len(rows),'propostas_com_pendencias':len(pending)}}


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--export',type=Path,required=True);parser.add_argument('--root',type=Path,required=True);parser.add_argument('--output',type=Path,required=True);parser.add_argument('--roster',type=Path);args=parser.parse_args()
    plan=generate(json.loads(args.export.read_text()),args.root,roster_from(args.roster))
    for path,val in [(args.output,plan),(args.output.with_name('resumo.json'),plan['summary']),(args.output.with_name('pendencias.json'),plan['pending'])]:path.write_text(json.dumps(val,ensure_ascii=False,indent=2))
    with args.output.with_name('revisao.csv').open('w',encoding='utf-8-sig',newline='') as f:
        w=csv.writer(f,delimiter=';');w.writerow(['proposal_id','assinatura_literal','nome_exibido','email','pagina','campos_cadastro','campos_historico','evidencia'])
        for row in plan['rows']:
            s=row['signature'];w.writerow([row['proposal_id'],s['signature_name'],s['name'],s['email'],s['page'],json.dumps(row['core'],ensure_ascii=False),json.dumps(row['meta'],ensure_ascii=False),s['evidence']])
    print(json.dumps(plan['summary'],ensure_ascii=False,indent=2))

if __name__=='__main__':main()
