"""Conservative fallback for old cover directories and final closings.
Emails come from the document, not a guessed domain or CRM owner.
"""
import re
from extract_history import clean, norm, words, EMAIL

CLOSING=re.compile(r'^\s*(?:ATENCIOSAMENTE|CORDIALMENTE|SAUDACOES|ATT\.?)\s*[,.:]?\s*(.*)$',re.I)
ROLES=r'(?:GERENTE|COORDENADOR[AO]?|ASSISTENTE|ANALISTA|CONSULTOR[AO]?|DIRETOR[AO]?|EXECUTIVO[AO]?|CEO|COMERCIAL)(?:\s+(?:COMERCIAL|DE NEGOCIOS|DE VENDAS|GERAL))?'
BAD=re.compile(r'\b(?:EXPORTCONTROL|LTDA|SOFTWARES|CLIENTE|PROPOSTA|DOCUMENTO|PAGINA|CNPJ|EMISSAO|DATA|VALIDADE|PAGAMENTO|SERVICO|INVESTIMENTO|EQUIPE|ATENCIOSAMENTE|CORDIALMENTE|EMAIL|ASSINATURA)\b')
EMPTY={'','NAO IDENTIFICADO','NAO IDENTIFICADA','NAO INFORMADO','NAO INFORMADA','PENDENTE','—','-'}

def missing(x):return x is None or norm(clean(x)) in EMPTY

def compatible(a,b):
    x,y=words(a).split(),words(b).split()
    return bool(x and y and x[0]==y[0] and (set(x)<=set(y) or set(y)<=set(x)))

def valid_name(s):
    s=clean(s).strip(' ,:;–—-')
    if not re.fullmatch(r"[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ'’.-]*(?: [A-Za-zÀ-ÖØ-öø-ÿ'’.-]+){0,10}",s) or BAD.search(norm(s)) or re.fullmatch(ROLES,norm(s)):return None
    if len(s)<3 or len(s)>160:return None
    return s

def email_matches(name,email):
    t=words(name).split();local=words(email.split('@')[0]).split()
    return bool(t and local and (t[0] in local or any(x in local for x in t[1:] if len(x)>=4)))

def signature(text):
    # DOCX sometimes flattens many cover contacts into one paragraph. Restrict
    # alias matching to the cover, and require the signature itself at the end.
    tail=[x for x in text.split('\f') if clean(x)]
    if not tail:return None
    lines=[x.strip() for x in tail[-1][-6000:].splitlines() if x.strip()]
    sign=[]
    for i,line in enumerate(lines):
        first=re.split(r'\s{3,}|\t+',line,maxsplit=1)[0]
        m=CLOSING.fullmatch(norm(first))
        if not m:continue
        name=valid_name(first[m.start(1):]) if m[1] else (valid_name(re.split(r'\s{3,}|\t+',lines[i+1],maxsplit=1)[0]) if i+1<len(lines) else None)
        if name:sign.append(name)
    names=list(dict.fromkeys(sign))
    if len(names)!=1:return None
    name=names[0];tokens=words(name).split()
    cover=tail[0][:4500];cover=re.split(r'(?im)^\s*(?:\d+(?:\.\d+)*\.?\s+)?OBJETIVO\s*$',cover,maxsplit=1)[0]
    n=norm(clean(cover))
    # Match a cover alias with a commercial role and the exact associated
    # mailbox before the next name/role block. This also allows Jean Toledo.
    prefix=re.escape(tokens[0]);alias_pattern=r'(?<![A-Z0-9])('+prefix+r'(?:\s+[A-Z]+){0,8})\s*[–—-]\s*'+ROLES+r'\b'
    all_headers=list(re.finditer(r'(?<![A-Z0-9.@])(?:[A-Z]+\s+){0,7}[A-Z]+\s*[–—-]\s*'+ROLES+r'\b',n))
    found=[]
    for m in re.finditer(alias_pattern,n):
        if not compatible(name,m[1]):continue
        stop=min([h.start() for h in all_headers if h.start()>=m.end()]+[m.end()+360,len(n)])
        segment=n[m.end():stop]
        mails=[e[1].lower() for e in EMAIL.finditer(segment) if e[1].lower().endswith('@exportcontrol.com.br') and email_matches(name,e[1])]
        for mail in dict.fromkeys(mails):found.append((mail,m[1]))
    emails=list(dict.fromkeys(v[0] for v in found))
    if len(emails)!=1:return None
    return {'name':name,'email':emails[0],'source':'assinatura_final_e_contato_comercial_da_capa','alias':next(a for e,a in found if e==emails[0])}

def recover(text,p,m):
    s=signature(text)
    if not s:return {},{},None,None
    name,email=s['name'],s['email']
    names=[x for x in (p.get('sales_responsible'),m.get('responsible_name')) if not missing(x)]
    if any(not compatible(x,name) for x in names) or (not missing(m.get('responsible_email')) and clean(m['responsible_email']).lower()!=email):
        return {},{},None,'responsável existente diverge da assinatura da proposta'
    # Fill only blank or placeholder fields. Preserve the literal signature.
    meta={};core={}
    if missing(m.get('responsible_name')):meta['responsible_name']=name
    if missing(m.get('responsible_email')):meta['responsible_email']=email
    if missing(p.get('sales_responsible')):core['sales_responsible']=name
    return meta,core,s,None
