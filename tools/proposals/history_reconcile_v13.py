#!/usr/bin/env python3
"""Contextual review, local documents only. Never infer current price or acceptance."""
import argparse
import collections
import csv
import json
import re
from decimal import Decimal
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
from extract_history import clean, norm, words, dates, list_json, sha256, text_of, EMAIL
import history_legacy_v6 as v6
import history_finance_v2 as v2
import history_values_v9 as v9
import history_scenarios_v10 as v10
import history_audit_v11 as v11
import history_billing_v12 as v12
import history_signature_v10 as sign

MONEY = v10.CASH
SEP = r'\s*(?:[:–—-]|SERA\s+DE|E\s+DE|DE)?\s*'
HEAD = re.compile(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(?:INVESTIMENTO(?:\s+DO\s+PROJETO)?|FORMACAO\s+(?:DA\s+MENSALIDADE|DO\s+VALOR)|PRECIFICACAO|PROPOSTA\s+DE\s+INVESTIMENTO|CONDICOES\s+COMERCIAIS|VALOR\s+DO\s+SERVICO)\s*$',re.M)
POINT_HEAD = r'(?:VALOR\s+(?:FINAL\s+(?:DO\s+SERVICO|DAS\s+CONSULTAS)|DO\s+SERVICO(?:\s+DA\s+EXPORTCONTROL)?(?:\s+COM\s+DESCONTO)?|TOTAL\s+DAS\s+CONSULTAS)|CONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?|INVESTIMENTO\s+(?:TOTAL\s+DO\s+SERVICO|UNIC[OA])|PAGAMENTO\s+UNICO|VALOR\s+UNICO)'
MONTH_HEAD = v11.MONTH_LABEL
SERVICE = {'DFE':r'DF[\s_-]*E','NFSE':r'NFS[\s_-]*E','LICENCIAMENTO':r'LICENCIAMENTO(?:\s+DA\s+FERRAMENTA)?','ACOMPANHAMENTO':r'ACOMPANHAMENTO(?:\s+ESPECIALIZADO|\s+E\s+COBRANCA)?','RETROATIVAS':r'(?:CONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?|RETROATIVAS?)'}
LABELS = {'DFE':'DF-e','NFSE':'NFS-e','LICENCIAMENTO':'Licenciamento','ACOMPANHAMENTO':'Acompanhamento','RETROATIVAS':'Consultas retroativas'}
ALT = re.compile(r'\b(?:OPCAO|PLANO|CENARIO|ALTERNATIVA|DESCONTO|BONIFICACAO|CARENCIA|ISENCAO|ISENTO|PRIMEIR[OA]S?\s+\d+\s+MESES|APOS\s+\d+\s+MESES|CONDICIONAD[OA]|MENSALIDADE\s+MINIMA)\b')
EXCLUDE = re.compile(r'\b(?:LIQUID[OA]S?|UNITARI[OA]S?|POR\s+NF|POR\s+NOTA|EXCEDENT\w*|MINIM[OA]|MAXIM[OA]|ECONOMIA|CUSTO\s+(?:MEDIO|EFETIVO|DO\s+FUNCIONARIO)|PARCELA|IMPLANTACAO|INSTALACAO|DESCONTO|ANUAL|SEMESTRAL|A\s+PARTIR\s+DE|ATE\s+R\$|SIMULACAO|EXEMPLO)\b')

def uniq(xs):return list(dict.fromkeys(x for x in xs if x is not None))
def amount(x):return v6.money(x)
def cash(x):return v10.money_br(x)
def difference(a,b):return abs(Decimal(a)-Decimal(b))
def total(xs):return amount(sum((Decimal(v) for v in xs),Decimal(0)))
def ev(value,source,kind):return {'value':value,'text':clean(source)[:650],'kind':kind}
def values(rows):return uniq(r['value'] for r in rows)
def one(rows):
    vals=values(rows);return vals[0] if len(vals)==1 else None

def sections(text):
    # V6 explicitly separates simulations, zero-volume examples and post-fixed tariffs.
    parts,_,modes=v6.section_tables(text)
    result=[]
    for mode,chunk in parts:
        if mode not in ('base','pre'):continue
        if 'pre' in modes and mode=='base':continue
        n=norm(chunk)
        start=HEAD.search(n)
        if mode=='pre':start=None
        elif not start:continue
        part=chunk[start.start():] if start else chunk
        end=re.search(r'^\s*(?:ATENCIOSAMENTE|CORDIALMENTE|\d+(?:\.\d+)+\.?\s+(?:COMPARATIVO|ESCOPO|OBJETIVO|DOCUMENTOS DE REFERENCIA))\b',norm(part),re.M)
        result.append(part[:end.start()] if end else part)
    return result, 'pre' in modes

def labelled(text,label,kind,allow_total_tax=False):
    """Full labels only; aligned values or an explicit adjacent currency cell."""
    out=[]
    pat=r'^\s*[➢•▪✓\-–—]?\s*'+label+SEP
    lines=text.replace('\f','\n\f\n').split('\n')
    for i,line in enumerate(lines):
        if line=='\f':continue
        cells=v2.cells(line)
        # Whole line handles adjacent label+amount, including DOCX rows.
        for pos,cell in [(0,line.strip())]+cells:
            n=norm(cell)
            if EXCLUDE.search(n) and not (allow_total_tax and 'FINAL' in n):continue
            m=re.fullmatch(pat+MONEY+r'\s*(?:/\s*MES|MENSAIS|POR\s+MES)?\s*[.;]?\s*(?:\([^)]{1,180}\))?',n)
            if m and cash(m[1]) is not None:out.append(ev(cash(m[1]),cell,kind));continue
            if not re.fullmatch(pat,n):continue
            nxt=[(x,c) for x,c in cells if x>pos]
            if len(nxt)==1 and (m:=re.fullmatch(MONEY,norm(nxt[0][1]))):
                if cash(m[1]) is not None:out.append(ev(cash(m[1]),cell+' | '+nxt[0][1],kind))
                continue
            # Match below the label within its own column, not across a page or adjacent heading.
            for after in lines[i+1:i+7]:
                if after=='\f':break
                if not after.strip():continue
                near=[c for x,c in v2.cells(after) if abs(x-pos)<=8]
                if len(near)==1 and (m:=re.fullmatch(r'R\$\s*'+MONEY+r'\s*(?:/\s*MES)?',norm(near[0]))):
                    if cash(m[1]) is not None:out.append(ev(cash(m[1]),cell+' | '+near[0],kind))
                break
    return list({(r['value'],r['text'],r['kind']):r for r in out}.values())

def issue(text, filename):
    a=v11.issue_audit(text,filename)
    explicit=uniq(e['valor'] for e in a['evidence'] if v11.DATE_LABEL.search(norm(e['trecho'])))
    if len(explicit)==1:
        a['date']=explicit[0];a['state']='identified' if len(a['candidates'])==1 else 'explicit_with_other_dates'
        a['year_mismatch']=bool(a['number_year'] and a['date'][:4]!=a['number_year'])
    elif len(explicit)>1:
        a['date']=None;a['state']='conflict'
    return a

def signature(text, p, m, identity):
    # Preserve manually-entered names; use direct signature evidence in the UI if
    # the previously inferred name is literally the customer, not a seller.
    existing=sign.signature(text)
    pages=[q for q in text.split('\f') if clean(q)]
    if not pages:return None
    tail=pages[-1][-8500:]; lines=[l for l in tail.splitlines() if clean(l)]
    found=[]
    if existing:found.append((existing['name'],existing['email'], 'assinatura e cadastro comercial da capa'))
    for i,line in enumerate(lines):
        first=re.split(r'\s{3,}|\t+',line.strip(),maxsplit=1)[0]
        close=sign.CLOSING.fullmatch(norm(first))
        if close:
            inline=first[close.start(1):] if close[1] else ''
            nm=sign.valid_name(inline) if inline else None
            if not nm and i+1<len(lines):nm=sign.valid_name(re.split(r'\s{3,}|\t+',lines[i+1].strip(),maxsplit=1)[0])
            if nm:found.append((nm,None,'assinatura final'))
        for email in EMAIL.findall(first):
            email=email.lower()
            if not email.endswith('@exportcontrol.com.br') or email.split('@')[0] in ('comercial','contato','suporte','financeiro','vendas','administrativo'):continue
            # For a modern footer require the name directly above a commercial role,
            # or a matching name immediately above the email; skip supplier column.
            for j in range(i-1,max(-1,i-4),-1):
                c=re.split(r'\s{3,}|\t+',lines[j].strip(),maxsplit=1)[0]
                if re.fullmatch(sign.ROLES,norm(c)):continue
                nm=sign.valid_name(c)
                if nm and sign.email_matches(nm,email):found.append((nm,email,'bloco comercial final'))
                break
    client=words(identity.get('client_legal_name') or p.get('client_name'))
    contacts=words(identity.get('recipient_name') or p.get('client_contact'))
    def valid(nm):
        w=words(nm);return bool(w and not (len(w.split())>=2 and (w in client or w==contacts)) and not re.search(r'\b(?:LTDA|AGRO|CEREAIS|COOPERATIVA|USINA)\b',w))
    found=[x for x in found if valid(x[0])]
    if not found:return None
    names=uniq(x[0] for x in found)
    if any(not sign.compatible(names[0],x) for x in names[1:]):return None
    name=max(names,key=len); mails=uniq(x[1] for x in found)
    if not mails:
        # Partial signatures such as Natan Felipe and Natan Kainak: the first
        # name must identify exactly one named commercial mailbox in the cover.
        cover=pages[0][:4500]; n=norm(cover); opts=[]
        for line in n.splitlines():
            if not re.search(r'\b'+re.escape(words(name).split()[0])+r'\b',line):continue
            if not re.search(sign.ROLES,line):continue
            at=n.find(line); seg=n[at:at+350]
            # Stop at next explicit commercial role/name block if possible.
            for mail in EMAIL.findall(seg):
                mail=mail.lower()
                if mail.endswith('@exportcontrol.com.br') and sign.email_matches(name,mail):opts.append(mail)
        mails=uniq(opts)
    return {'name':name,'email':mails[0] if len(mails)==1 else None,'source':found[0][2]}

def classify(text, filename, prior):
    if prior:return prior
    svc=v6.classify(filename,text,[])
    if svc:return svc
    if v12.licensing(text):return ['LICENCIAMENTO']
    obj=v12.objective(text); n=norm(clean(text))
    # Explicit objective + recurring pricing, not a mention in exclusions.
    if re.search(r'(?:FORNECER|DISPONIBILIZAR|ACESSO).{0,140}(?:FERRAMENTA|SOFTWARE|PLATAFORMA)',obj) and 'EXPORTACAO' in obj and v9.REC_MODEL.search(n):return ['LICENCIAMENTO']
    if re.search(r'ACOMPANHAMENTO.{0,80}(?:OPERACOES|EXPORTACAO|CONTROLE)',obj) and v9.REC_MODEL.search(n):return ['ACOMPANHAMENTO']
    if v9.REC_MODEL.search(n) and 'EXPORTACAO' in obj and re.search(r'\b(?:FERRAMENTA|SOFTWARE|PLATAFORMA|SISTEMA)\b',obj) and re.search(r'\bACESSO\s+A\s+FERRAMENTA\b|\bMODALIDADE DE INSTALACAO\b.{0,100}SAAS',n) and not re.search(r'ACOMPANHAMENTO|DF[- ]?E|NFS[- ]?E|RETROATIV',obj):return ['LICENCIAMENTO']
    return []

def contextual(text, filename, svc, old):
    f=v6.obj(old.get('finance_v2')); package=v6.obj(old.get('billing_v12'))
    if package.get('kind') in ('annual_package','recurring_with_extra') or f.get('pricing_terms_v10') or (f.get('billing_mode')=='historical_bundle_one_time' and f.get('components') and f.get('service_amount')):return None
    parts,pre=sections(text); main='\n'.join(parts); n=norm(main); alln=norm(clean(text)); name=norm(filename).replace('_',' ')
    if not main:return None
    model_point=bool(re.search(r'MODELO\s*[“"\']?PRESTACAO\s+DE\s+SERVICO|PAGAMENTO\s+UNICO|VALOR\s+UNICO',alln))
    result={'kind':'review','state':'unresolved','monthly':None,'one_time':None,'components':[],'warnings':[],'evidence':[],'prefixed':pre}
    def done(kind,state,rows,**kw):
        return {**result,'kind':kind,'state':state,'evidence':rows[:30],**kw}
    # Explicit scenario/percentage rules already supported by V10 take priority.
    if svc==['RETROATIVAS']:
        try:prior_result,reason=v10.scenarios(text,svc)
        except Exception:prior_result=None
        if prior_result:
            ff=prior_result['finance']
            return done('one_time','identified',[ev(e['valor'],e['trecho'],'cenario') for e in ff.get('one_time_evidence',[])],one_time=prior_result['one_time_amount'],finance=ff)
        # Only new rescue for legacy one-off where no conditional/discount ambiguity.
        direct=labelled(main,'(?:'+POINT_HEAD+'|INVESTIMENTO|VALOR TOTAL DO SERVICO|VALOR DO INVESTIMENTO|VALOR DO SERVICO)', 'preco_servico',True)
        total_rows=[ev(v,s,'total_declarado') for v,s in v10.candidates(clean(main),r'\b(?:O\s+)?(?:VALOR\s+TOTAL\s+DO\s+INVESTIMENTO|INVESTIMENTO\s+TOTAL)\s*(?:SERA\s+DE|E\s+DE|:|DE)\s*'+MONEY)]
        # Modern tax table: final row is accepted only in a single-service proposal.
        taxes,conf=v2.tax_rows(text.replace('\f','\n').split('\n'))
        finals=[ev(a,r['item']+' | '+r['valor'],'valor_final_tributos') for r in taxes if norm(r['item'])=='VALOR FINAL' for a in v2.cash_values(r['valor'],True)] if not conf else []
        fees=v6.conditional_fee(text); fee=f.get('implantation_amount')
        if ALT.search(n) or fees or fee is not None or re.search(r'\b(?:IMPLANTACAO|CRIACAO DO AMBIENTE|INSTALACAO)\b',n):
            # A final service-specific label is usable even with setup, but do not
            # infer a total or discount without the previous scenario logic.
            strong=[r for r in direct if re.search(r'VALOR FINAL|COM DESCONTO|CONSULTAS?\s+(?:AS NOTAS\s+)?RETROATIVAS',norm(r['text']))]
            if not ALT.search(n) and one(strong):return done('one_time','identified',strong,one_time=one(strong))
            return done('one_time','review',direct+total_rows+finals,warnings=['Condições de desconto ou implantação ainda precisam ser associadas ao valor do serviço.']) if direct or total_rows or finals else None
        tabs=[t for t in v9.parsed_tables(main) if 'MENSALIDADE' not in norm(t.get('heading',''))]
        is_net=bool(re.search(r'\b(?:FIXO LIQUIDO|TOTAL LIQUIDO|GROSS-UP)\b',n))
        table_rows=[ev(t['table_total'],str(t.get('heading',''))+' TOTAL '+str(t['table_quantity']),'tabela_servico') for t in tabs] if not is_net else []
        rows=direct+total_rows+finals+table_rows
        vals=values(rows)
        if len(vals)==1 and (direct or total_rows or finals or (model_point and table_rows)):
            return done('one_time','identified',rows,one_time=vals[0],table=tabs[0] if len(tabs)==1 else None)
        if rows:return done('one_time','review',rows,warnings=['Valores pontuais não conciliados; nenhuma tarifa presumida.'])
        return None
    # Independent DF-e and NFS-e recurring service amounts. Do not add merely
    # because two modules are mentioned: require a declared combined total or
    # explicit addition, otherwise show separate candidates / plans.
    if len(svc)>1 and set(svc)<=v9.MONTHLY:
        comps=[]; rows=[]; ambiguous=False
        for code in svc:
            lab=SERVICE[code]
            pat=r'(?:(?:MENSALIDADE|INVESTIMENTO\s+MENSAL|VALOR\s+MENSAL|SERVICO\s+DE|MODULO)\s*(?:D[EO]\s+)?'+lab+r'|'+lab+r'\s*(?:[-–:]\s*)?(?:MENSALIDADE|VALOR\s+MENSAL)?)'
            hits=labelled(main,pat,'componente_'+code)
            # Section headings without prices (e.g. 1.6.1 Módulo DF-e) followed
            # by a local monthly label. A bare title outside investment isn't evidence.
            headings=[]
            for mm in re.finditer(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(?:MODULO\s+|SERVICO\s+DE\s+|CAPTURA\s+(?:E\s+GESTAO\s+)?DE\s+)?('+ '|'.join('(?:'+v+')' for v in SERVICE.values())+r')\s*$',n,re.M):
                for c,rx in SERVICE.items():
                    if re.fullmatch(rx,mm[1]):headings.append((mm.start(),mm.end(),c));break
            for j,(start,end,c) in enumerate(headings):
                if c!=code:continue
                sub=main[end:headings[j+1][0] if j+1<len(headings) else len(main)]
                # Explicit total monthly line applies to the entire bundle, not last module.
                sub=re.split(r'(?im)^\s*(?:MENSALIDADE\s+TOTAL|TOTAL\s+MENSAL|INVESTIMENTO\s+MENSAL\s+TOTAL|MENSALIDADE\s+CONJUNTA)\b',sub,maxsplit=1)[0]
                hits+=labelled(sub,r'(?:MENSALIDADE(?:\s+FINAL)?|INVESTIMENTO MENSAL|VALOR MENSAL)','componente_'+code)
            if hits:
                rows+=hits
                if one(hits) is None:ambiguous=True
                else:comps.append({'service':code,'label':LABELS[code],'amount':one(hits),'billing':'monthly'})
        combined=labelled(main,r'(?:MENSALIDADE\s+TOTAL|TOTAL\s+MENSAL|INVESTIMENTO\s+MENSAL\s+TOTAL|VALOR\s+TOTAL\s+MENSAL|MENSALIDADE\s+CONJUNTA)','total_mensal')
        # A generic quoted monthly price can be the bundle total only when it
        # exactly corroborates the independently identified component sum.
        if not combined and len(comps)==len(svc) and not ambiguous:
            generic=labelled(main,r'(?:INVESTIMENTO MENSAL|MENSALIDADE FINAL|VALOR MENSAL)','total_mensal')
            matching=[r for r in generic if difference(r['value'],total(x['amount'] for x in comps))<=Decimal('.01')]
            if len(values(generic))==1 and matching:combined=matching
        rows+=combined; c=one(combined)
        alt=bool(ALT.search(n))
        if len(comps)==len(svc) and not ambiguous and not alt and c is not None and difference(total(x['amount'] for x in comps),c)<=Decimal('.01'):
            return done('monthly_components','identified',rows,monthly=c,components=comps)
        if len(comps)==len(svc) and not ambiguous and not alt and not combined and re.search(r'\b(?:SOMA|SOMATORIO)\s+D[AO]S?\s+(?:VALORES|MENSALIDADES)|\bTOTAL\s+DOS\s+DOIS\s+MODULOS',n):
            return done('monthly_components','identified',rows,monthly=total(x['amount'] for x in comps),components=comps,warnings=['Total obtido pela soma dos componentes explicitamente cumulativos.'])
        if rows:return done('monthly_components','review',rows,components=comps,warnings=['Preços por módulo localizados; confirmar se são cumulativos ou alternativas.'])
        return None
    # Historical bundle with two one-off services, not recurring accompaniment.
    if set(svc)=={'RETROATIVAS','ACOMPANHAMENTO'} or ('ACOMP' in name and 'RETROATIV' in name and model_point):
        try:res,_=v9.bundled_retroactive(text,['RETROATIVAS','ACOMPANHAMENTO'])
        except Exception:res=None
        if res:
            r=res['finance'];return done('one_time_components','identified',[ev(e['valor'],e['trecho'],'composicao') for e in r['one_time_evidence']],one_time=res['one_time_amount'],components=r['components'],finance=r)
        # The filename and an investment section explicitly priced as retroactive
        # can establish a one-off even if old templates omit 'prestação de serviço'.
        point_investment=bool(re.search(r'ESTIMATIVA\s+(?:DO|DE)\s+VALOR\s+DO\s+SERVICO|CONSULTAS?\s+RETROATIVAS',n))
        if not (model_point or ('ACOMP' in name and 'RETROATIV' in name and point_investment)) or v9.EXPLICIT_OTHER_MODEL.search(n):return None
        rets=labelled(main,r'(?:CONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?|VALOR\s+(?:DAS\s+CONSULTAS\s+RETROATIVAS|DO\s+SERVICO\s+DE\s+CONSULTAS\s+RETROATIVAS))','retroativas')
        ac=labelled(main,r'(?:SERVICO\s+DE\s+ACOMPANHAMENTO(?:\s+RETROATIVO|\s+E\s+COBRANCA)?|VALOR\s+(?:ESTIMADO\s+)?(?:DO|PARA\s+O)\s+SERVICO\s+DE\s+(?:ACOMPANHAMENTO|COBRANCA)(?:\s+E\s+REGISTRO\s+DA\s+INFORMACAO\s+NA\s+PLATAFORMA)?)','acompanhamento')
        tot=[ev(v,s,'total_pontual') for v,s in v10.candidates(clean(main),r'\b(?:INVESTIMENTO\s+TOTAL|VALOR\s+TOTAL\s+DOS\s+SERVICOS)(?:\s+NESTE\s+CASO)?\s*(?:SERA\s+DE|E\s+DE|:|DE)\s*'+MONEY)]
        component_tables={}
        headers=[]
        for match in re.finditer(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?(CONSULTAS?\s+(?:AS\s+NOTAS\s+)?RETROATIVAS?|(?:SERVICO\s+DE\s+)?ACOMPANHAMENTO(?:\s+RETROATIVO|\s+E\s+COBRANCA)?)\s*$',n,re.M):
            headers.append((match.start(),match.end(),'RETROATIVAS' if 'RETROATIV' in match[1] and 'ACOMPANHAMENTO' not in match[1] else 'ACOMPANHAMENTO'))
        for j,(_,end,c) in enumerate(headers):
            sub=main[end:headers[j+1][0] if j+1<len(headers) else len(main)]
            tables=[t for t in v9.parsed_tables(sub) if 'MENSALIDADE' not in norm(t.get('heading',''))]
            if len(tables)!=1 or re.search(r'FIXO LIQUIDO|TOTAL LIQUIDO|GROSS-UP',norm(sub)):continue
            tb=tables[0];component_tables[c]=tb
            current=rets if c=='RETROATIVAS' else ac
            if not current:current.append(ev(tb['table_total'],tb['heading']+' | total '+tb['table_quantity']+' NFs','tabela_'+c))
        r,a,t=one(rets),one(ac),one(tot)
        if r is not None and a is not None and t is not None and difference(total([r,a]),t)<=Decimal('.01') and not ALT.search(n):
            comps=[{'service':'RETROATIVAS','label':'Consultas retroativas','billing':'one_time','amount':r},{'service':'ACOMPANHAMENTO','label':'Acompanhamento retroativo','billing':'one_time','amount':a}]
            for comp in comps:
                tb=component_tables.get(comp['service'])
                if tb and tb['table_total']==comp['amount']:comp.update(tb)
            return done('one_time_components','identified',rets+ac+tot,one_time=t,components=comps)
        if rets or ac or tot:return done('one_time_components','review',rets+ac+tot,warnings=['Composição pontual localizada; falta comprovar os valores de cada serviço e o total.'])
        return None
    if len(svc)!=1 or svc[0] not in v9.MONTHLY or model_point:return None
    # Unconditional explicit monthly discount: distinguish gross, discount and final.
    # If conditions/plans are mentioned, keep both candidates, never infer acceptance.
    def role_hits(pattern,kind):
        return [ev(v,t,kind) for v,t in v10.candidates(n,r'(?m)^\s*[➢•▪✓\-–—]?\s*'+pattern+SEP+MONEY+r'\s*[.;]?\s*$')]
    discount=role_hits(r'(?:DESCONTO(?:\s+MENSAL)?|VALOR\s+DO\s+DESCONTO)','desconto')
    gross=role_hits(r'(?:MENSALIDADE|INVESTIMENTO MENSAL|VALOR MENSAL)\s+(?:ORIGINAL|SEM DESCONTO|ANTES DO DESCONTO)','mensal_original')
    final=role_hits(r'(?:MENSALIDADE|INVESTIMENTO MENSAL|VALOR MENSAL)\s+(?:COM DESCONTO|APOS O DESCONTO|FINAL)','mensal_final')
    if one(discount) is not None and one(gross) is not None and one(final) is not None and Decimal(one(gross))-Decimal(one(discount))==Decimal(one(final)) and not re.search(r'\b(?:CASO|CONDICIONAD[OA]|OPCAO|ALTERNATIVA|PLANO|VIGENCIA|PRIMEIR[OA]S?\s+\d+\s+MESES)\b',n):
        return done('monthly','identified',gross+discount+final,monthly=one(final),discount=one(discount),gross=one(gross))
    audit=v11.monthly_audit(text,svc,f)
    if audit:
        d=audit.get('declared_values',[]);t=audit.get('table_values',[])
        evidence=[ev(e['valor'],e['trecho'],e['tipo']) for e in audit.get('evidence',[])]
        if len(d)==len(t)==1 and difference(d[0],t[0])==Decimal('.01') and not ALT.search(n):
            return done('monthly','rounding',evidence,monthly=d[0],table_amount=t[0],table=audit.get('table'),warnings=['Diferença de R$ 0,01 entre preço declarado e tabela; possível arredondamento.'])
        if audit.get('state')=='identified':return done('monthly','identified',evidence,monthly=audit['value'],table=audit.get('table'))
        if audit.get('state')=='declared_table_conflict' and len(d)==len(t)==1:
            return done('monthly','declared_conflict',evidence,monthly=d[0],table_amount=t[0],table=audit.get('table'),warnings=['Preço declarado e tabela-base são diferentes; não é possível confirmar a modalidade contratada.'])
        if len(d)>1:return done('monthly','review',evidence,warnings=['Mais de um preço mensal declarado; distinguir desconto, opção ou vigência.'])
    # No global monetary guessing. Explicit service labels in investment section.
    label=SERVICE[svc[0]]
    rows=labelled(main,MONTH_HEAD,'mensalidade',True)+labelled(main,r'(?:SERVICO\s+DE\s+)?'+label,'mensalidade_servico')
    if one(rows) is not None and not ALT.search(n) and (v9.REC_MODEL.search(alln) or re.search(r'MENSALIDADE|MENSAL',n)) and not re.search(r'\b(?:FIXO LIQUIDO|TOTAL LIQUIDO|GROSS-UP)\b',n):
        return done('monthly','identified',rows,monthly=one(rows))
    if rows:return done('monthly','review',rows,warnings=['Preços mensais não associados a uma condição inequívoca.'])
    return None

def plan(data,root):
    metas={int(x['proposal_id']):x for x in data['metadata']}; docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[];report=[];pending=[];targeted=[];counts=collections.Counter(); root=root.resolve()
    for p in data['proposals']:
        pid=int(p['id']); m=metas.get(pid); title=m.get('title','') if m else ''; warnings=[]; excerpt=[]; audit=None; meta={};core={}; before_month=m.get('monthly_amount') if m else None;before_once=m.get('one_time_amount') if m else None
        try:
            if not m or len(docs[pid])!=1:raise ValueError('Metadados ou documento histórico único indisponível.')
            d=docs[pid][0]; path=(root/d['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root) or sha256(path)!=d['sha256'] or m['document_sha256']!=d['sha256']:raise ValueError('Hash ou caminho divergente; preservado.')
            text=text_of(path);counts['documentos_lidos']+=1
            if len(clean(text))<100:raise ValueError('Documento sem texto suficiente; requer leitura manual.')
            old=v6.obj(m.get('evidence')); f=v6.obj(old.get('finance_v2')); ident=v6.obj(old.get('identity_v3')); oldsvc=list_json(m.get('services')); psvc=list_json(p.get('service_codes')); svc=classify(text,d['original_name'],oldsvc or psvc)
            if oldsvc and psvc and set(oldsvc)!=set(psvc):warnings.append('Serviços do cadastro e histórico divergem; preservados.')
            if svc and not oldsvc:meta['services']=svc
            if svc and not psvc:core['service_codes']=svc
            if not svc:warnings.append('Serviço não identificado.')
            dt=issue(text,d['original_name']); sig=signature(text,p,m,ident)
            if dt['state']=='missing' and not m.get('issued_on') and not p.get('issued_on'):warnings.append('Data de emissão não localizada.')
            if dt['state']=='conflict':warnings.append('Datas explícitas divergentes: '+ ' / '.join(dt['candidates']))
            elif dt['state']=='explicit_with_other_dates':warnings.append('Data de emissão explicitamente rotulada; outras datas do documento: '+ ' / '.join(dt['candidates']))
            if dt['date']:
                if any(str(x)[:10]!=dt['date'] for x in (m.get('issued_on'),p.get('issued_on')) if x):warnings.append('Data anteriormente cadastrada diverge; preservada.')
                else:
                    if not m.get('issued_on'):meta['issued_on']=dt['date']
                    if not p.get('issued_on'):core['issued_on']=dt['date']
            if sig:
                for record,patch,k,field in [(m,meta,'responsible_name','name'),(m,meta,'responsible_email','email'),(p,core,'sales_responsible','name')]:
                    val=sig.get(field);existing=record.get(k)
                    if val and sign.missing(existing):patch[k]=val
                    elif val and not (sign.compatible(existing,val) if field=='name' else str(existing).lower()==val.lower()):warnings.append('Responsável/e-mail anterior diverge da assinatura; cadastro preservado, evidência exibida.')
            price=contextual(text,d['original_name'],svc,old)
            if price and oldsvc and psvc and set(oldsvc)!=set(psvc):price['state']='previous_review'
            if price:
                warnings+=price['warnings']; counts['preco_'+price['state']]+=1
                if price['state'] in ('identified','rounding'):
                    for mk,pk,field in [('monthly_amount','monthly_final','monthly'),('one_time_amount','one_time_final','one_time')]:
                        val=price.get(field)
                        if val is None:continue
                        olds=[amount(x) for x in (m.get(mk),p.get(pk)) if x is not None]
                        if any(x is None or (x!=val and not (price['state']=='rounding' and difference(x,val)<=Decimal('.01'))) for x in olds):
                            warnings.append('Valor cadastrado diverge da evidência nova; preservado.');price['state']='previous_review';continue
                        if m.get(mk) is None:meta[mk]=val
                        if p.get(pk) is None:core[pk]=val
                    if price['kind']=='one_time_components' and (m.get('monthly_amount') is not None or p.get('monthly_final') is not None):warnings.append('Valor mensal anterior não removido; composição pontual exibida separadamente.')
            # Recover volumes only if explicitly grounded; use prior reader's robust
            # consensus, do not derive annual from rounded monthly number.
            volumes={}; extra_finance={}
            try:
                base=v6.enrich(text,d['original_name'],svc,old)['finance']
                for key in ('base_quantity','annual_quantity','reference_year','annual_basis'):
                    if f.get(key) is None and base.get(key) is not None:volumes[key]=base[key]
                if price and price.get('table') and f.get('base_quantity') is None and price['table'].get('table_quantity') is not None:volumes.setdefault('base_quantity',price['table']['table_quantity'])
                if f.get('implantation_amount') is None:
                    parts,_=sections(text)
                    # The generic label scanner excludes setup labels; use a strict
                    # full labelled row in the investment area instead.
                    setup=[ev(v,t,'implantacao') for v,t in v10.candidates(norm('\n'.join(parts)),r'(?m)^\s*[➢•▪✓\-–—]?\s*(?:IMPLANTACAO|INSTALACAO\s*/\s*CONFIGURACAO\s*/\s*TREINAMENTO|CRIACAO DO AMBIENTE|TAXA DE IMPLANTACAO)'+SEP+MONEY+r'\s*[.;]?\s*$')]
                    fee=one(setup)
                    if fee is not None and not ALT.search(norm('\n'.join(parts))):
                        extra_finance={'implantation_amount':fee,'implantation_state':'identified','implantation_mode':'conditional' if v6.conditional_fee(text) else 'stated','implantation_evidence':[{'valor':r['value'],'trecho':r['text']} for r in setup]}
            except Exception:pass
            if volumes.get('annual_quantity') is not None and p.get('recurring_total') is None and svc and set(svc)<=v9.MONTHLY:
                q=str(volumes['annual_quantity']);
                if q.isdigit() and int(q)<=2147483647:core['recurring_total']=int(q)
            stored_name=words(m.get('responsible_name') or p.get('sales_responsible'))
            legal_name=words(ident.get('client_legal_name') or p.get('client_name'))
            suspect_customer=bool(stored_name and len(stored_name.split())>=2 and stored_name in legal_name)
            if not sig and (sign.missing(m.get('responsible_name')) or suspect_customer):warnings.append('Responsável não confirmado pela assinatura; nome de empresa não comprova autoria.')
            billing=v6.obj(old.get('billing_v12')); special=billing.get('kind') in ('annual_package','recurring_with_extra') or bool(f.get('pricing_terms_v10'))
            point=svc==['RETROATIVAS'] or f.get('billing_mode')=='historical_bundle_one_time' or (price and price['kind'].startswith('one_time'))
            resolved=price and price['state'] in ('identified','rounding') and (price['monthly'] is not None or price['one_time'] is not None)
            if not special and not resolved and (price and price['state'] in ('review','declared_conflict','previous_review') or (before_once is None if point else before_month is None) or (not point and f.get('monthly_state') in ('conflict','previous_review')) or (point and f.get('one_time_state') in ('conflict','previous_review'))):
                warnings.append('Valor comercial precisa de conferência.')
            audit={'version':13,'source':'document_sha256','issue':dt,'signature':sig,'suspect_customer_name':suspect_customer,'price':price,'volumes':volumes,'extra_finance':extra_finance,'warnings':uniq(warnings)}
            # No-op detection; not overwrite previous namespaces or existing non-null values.
            if meta or core or old.get('review_v13')!=audit:rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':d['sha256'],'meta':meta,'core':core,'audit':audit})
            if warnings or pid in {504,503,497,494,489,484,478,464,462,461,460,488,480} or ('513' in title and 'TERRA ROXA' in norm(p.get('client_name',''))):
                # Include section context, not only currency lines. This is a private report.
                chunks,_=sections(text); excerpt=[clean(l)[:350] for l in '\n'.join(chunks).splitlines() if clean(l)][:150]
            counts['mensalidades_preenchidas']+=('monthly_amount' in meta); counts['pontuais_preenchidos']+=('one_time_amount' in meta);counts['datas_preenchidas']+=('issued_on' in meta);counts['servicos_preenchidos']+=('services' in meta)
        except Exception as exc:
            counts['falhas_preservadas']+=1;warnings.append(f'{type(exc).__name__}: {str(exc)[:250]}')
        detail={'id':pid,'proposta':title,'motivos':uniq(warnings),'audit':audit,'trechos_investimento':excerpt}
        if warnings:pending.append(detail)
        if pid in {504,503,497,494,489,484,478,464,462,461,460,488,480} or ('513' in title and 'TERRA ROXA' in norm(p.get('client_name',''))):targeted.append(detail)
        report.append([pid,title,before_month,meta.get('monthly_amount',before_month),before_once,meta.get('one_time_amount',before_once),audit['price']['state'] if audit and audit['price'] else '', '; '.join(uniq(warnings))])
    return {'version':13,'rows':rows,'summary':{'total_historico':len(data['proposals']),**counts,'atualizacoes':len(rows),'registros_com_avisos':len(pending)},'pending':pending,'targeted':targeted},report

def main():
    a=argparse.ArgumentParser();a.add_argument('--export',type=Path,required=True);a.add_argument('--root',type=Path,required=True);a.add_argument('--output',type=Path,required=True);args=a.parse_args();p,report=plan(json.loads(args.export.read_text()),args.root);folder=args.output.parent
    for path,value in [(args.output,p),(folder/'resumo.json',p['summary']),(folder/'pendencias.json',p['pending']),(folder/'casos_dos_prints.json',p['targeted'])]:path.write_text(json.dumps(value,ensure_ascii=False,indent=2));path.chmod(0o600)
    with (folder/'revisao.csv').open('w',encoding='utf-8-sig',newline='') as out:
        w=csv.writer(out,delimiter=';');w.writerow(['id','proposta','mensal_anterior','mensal_nova','pontual_anterior','pontual_novo','estado','avisos']);w.writerows([["'"+x if isinstance(x,str) and x[:1] in ('=','+','-','@','\t','\r') else x for x in r] for r in report])
    with ZipFile(folder/'Auditoria_Propostas_V13.zip','w',ZIP_DEFLATED) as z:
        for name in ('resumo.json','revisao.csv','pendencias.json','casos_dos_prints.json'):z.write(folder/name,name)
    print(json.dumps(p['summary'],ensure_ascii=False,indent=2))
if __name__=='__main__':main()
