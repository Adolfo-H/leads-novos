#!/usr/bin/env python3
"""Historical licensing bundles; no new tariff, CRM write or document modification."""
import argparse
import collections
import csv
import json
import re
from decimal import Decimal
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile
from extract_history import clean, norm, list_json, sha256, text_of
import history_legacy_v6 as v6
import history_signature_v10 as signature
import history_scenarios_v10 as v10
import history_audit_v11 as v11

M = r'(?:R\$\s*)?(\d{1,3}(?:\.\d{3})+,\d{2}|\d{1,9},\d{2})(?!\d)'
BULLET = r'^\s*[➢•▪✓\-–—]?\s*'
MONTHS = r'(?:JANEIRO|FEVEREIRO|MARCO|ABRIL|MAIO|JUNHO|JULHO|AGOSTO|SETEMBRO|OUTUBRO|NOVEMBRO|DEZEMBRO)'

def money(v):
    return v6.money(v)

def cash(s):
    return v10.money_br(s)

def hits(text, pattern, line=False):
    found=[]
    for match in re.finditer(pattern, norm(text), re.M if line else 0):
        value=cash(match[1]); snippet=clean(match.group())
        if value is not None and (value,snippet) not in found:found.append((value,snippet))
    return found

def unique(rows):
    vals=list(dict.fromkeys(x[0] for x in rows))
    return vals[0] if len(vals)==1 else None

def total(*values):
    return money(sum((Decimal(v) for v in values),Decimal(0)))

def objective(text):
    n=norm(clean(text))
    m=re.search(r'\bOBJETIVO\b(.*?)(?:DOCUMENTOS DE REFERENCIA|ESCOPO,? RESPONSABILIDADES|\Z)',n)
    return m[1][:3500] if m else ''

def licensing(text):
    n=norm(clean(text)); o=objective(text)
    return bool(re.search(r'FORNECER\s+(?:A\s+)?FERRAMENTA|DISPONIBILIZAR\s+(?:A\s+)?FERRAMENTA',o)
        and 'EXPORTACAO' in o and re.search(r'\b(?:SAAS|CLOUD COMPUTING)\b',n)
        and re.search(r'MODELO\s*[“"\']?\s*(?:MENSALIDADE|PAGAMENTO ANUAL)',n)
        and not re.search(r'\b(?:ACOMPANHAMENTO|NFS[- ]?E|DF[- ]?E|RETROATIV\w*)\b',o))

def main_investment(text):
    # Avoid DOCX's missing section numbers and PDF page-break whitespace.
    # Preserve rows so a charge cannot leak from the next column or simulation.
    m=re.search(r'^\s*(?:\d+(?:\.\d+)*\.?\s+)?INVESTIMENTO DO PROJETO\s*$',norm(text),re.M)
    if not m:return ''
    section=text[m.end():]
    stop=re.search(r'^\s*(?:SIMULACAO DO INVESTIMENTO|(?:\d+(?:\.\d+)*\.?\s+)?MODALIDADE POS\s*[-–]?\s*FIXADA|LIMITACAO DE IMPORTACAO|EXEMPLO DO CALCULO|ATENCIOSAMENTE)\b',norm(section),re.M)
    return section[:stop.start()] if stop else section

def quantity(text,pattern):
    vals=[]
    for m in re.finditer(pattern,norm(clean(text))):
        try:vals.append(int(m[1].replace('.','')))
        except ValueError:pass
    vals=list(dict.fromkeys(vals))
    return vals[0] if len(vals)==1 else None

def flexible_table(chunk, amount, base):
    """Legacy Word tables lose blank cells on extraction. Recover only an
    unambiguous printed table with exact subtotal and quantity reconciliation."""
    n=norm(chunk); start=re.search(r'^\s*ESTIMATIVA DE MENSALIDADE\s*$',n,re.M)
    if not start:return None
    rows=[]; printed=None
    for line in chunk[start.end():].splitlines():
        cells=clean(norm(line)).replace('R$','').split()
        if not cells:continue
        if cells[0]=='TOTAL':
            if len(cells)==3 and re.fullmatch(r'\d[\d.]*',cells[1]):printed=(cells[1].replace('.',''),cash(cells[2]));break
            return None
        if cells[0]!='FAIXA':continue
        if len(cells)<6 or not cells[1].isdigit() or int(cells[1])!=len(rows)+1:return None
        number,minimum=cells[1:3]; rest=cells[3:]
        if not re.fullmatch(r'\d[\d.]*',minimum):return None
        maximum='—'
        if rest and re.fullmatch(r'\d[\d.]*',rest[0]):maximum=rest.pop(0)
        fixed='—'
        if number=='1':
            if len(rest)!=4:return None
            fixed,unit,qty,subtotal=rest
        else:
            if len(rest)!=3:return None
            unit,qty,subtotal=rest
        if any(v not in ('-','—','–') and cash(v) is None for v in (fixed,unit,subtotal)):return None
        if qty not in ('-','—','–') and not re.fullmatch(r'\d[\d.]*',qty):return None
        rows.append({'faixa':number,'min':minimum,'max':maximum,'fixo':fixed,'unitario':unit,'quantidade':qty,'subtotal':subtotal})
    if not printed or not rows or printed[1]!=amount or base is None or printed[0]!=str(base):return None
    if sum(int(x['quantidade'].replace('.','')) for x in rows if x['quantidade'] not in ('-','—','–'))!=base:return None
    if sum((Decimal(cash(x['subtotal'])) for x in rows if x['subtotal'] not in ('-','—','–')),Decimal(0))!=Decimal(amount):return None
    return {'heading':'ESTIMATIVA DE MENSALIDADE','bands':rows,'table_total':amount,'table_quantity':str(base)}

def resolve(text,filename=''):
    if not licensing(text):return None,None
    main=main_investment(text)
    if not main:return None,'Licenciamento: seção de investimento não localizada.'
    n=norm(clean(main)); alln=norm(clean(text))
    ann=bool(re.search(r'MODELO\s*[“"\']?\s*PAGAMENTO ANUAL|MODALIDADE PAGAMENTO ANUAL',alln))
    implant=hits(main,BULLET+r'(?:INSTALACAO\s*/\s*CONFIGURACAO\s*/\s*TREINAMENTO|IMPLANTACAO)\s*:\s*'+M+r'\s*\.?\s*$',True)
    retro=hits(main,BULLET+r'CONSULTAS\s+RETROATIVAS\s*:\s*'+M+r'\s*\.?\s*$',True)
    imported=hits(main,BULLET+r'IMPORTACAO\s+(?:DAS|DE)\s+NOTAS\s+FISCAIS[^\n\r]{0,180}?\s*[:–—-]\s*'+M+r'\s*\.?\s*$',True)
    initial=hits(n,r'\bINVESTIMENTO INICIAL\s+(?:SERA DE|E DE|DE)\s*'+M)
    monthly=hits(main,BULLET+r'INVESTIMENTO MENSAL\s*:\s*'+M+r'\s*\.?\s*$',True)
    monthly+=hits(n,r'\bINVESTIMENTO MENSAL\s+(?:SERIA DE|SERA DE|E DE)\s*'+M)
    annual=hits(n,r'\bINVESTIMENTO ANUAL\s+(?:TOTALIZARA|SERA DE|E DE|DE)\s*'+M)
    yearlines=hits(main,BULLET+r'INVESTIMENTO\s+DE\s+'+MONTHS+r'\s+DE\s+20\d{2}\s+(?:A|ATE)\s+'+MONTHS+r'\s+DE\s+20\d{2}\s*:\s*'+M+r'\s*\.?\s*$',True)
    singleperiod=hits(main,BULLET+r'INVESTIMENTO\s+DE\s+'+MONTHS+r'\s+DE\s+20\d{2}\s*:\s*'+M+r'\s*\.?\s*$',True)
    # If optional wording is present near monetary clauses, leave the bundle for review.
    if re.search(r'\b(?:DESCONTO|IMPLANTACAO CONDICIONAL|CASO NAO SEJA CONTRATAD[OA]|SE NAO FOR CONTRATAD[OA])\b',n):
        return None,'Condições de cobrança adicionais exigem revisão.'
    mon,fee,ret,imp,init=map(unique,(monthly,implant,retro,imported,initial))
    q=quantity(text,r'NUMERO\s+MEDIO\s+DE\s+(\d[\d.]*)\s*/\s*MES')
    aq=quantity(text,r'TOTAL\s+DE\s+APROXIMADAMENTE\s+(\d[\d.]*)\s*\(')
    rq=quantity(text,r'CONSULTAS\s+DAS\s+NOTAS\s+FISCAIS\s+RETROATIVAS.{0,180}?TOTALIZANDO\s+(\d[\d.]*)\s+DOCUMENTOS')
    # Pricing tables with merged cells in Word are often not aligned. Reuse
    # a table only if the previous parser validates printed totals/subtotals.
    tables=__import__('history_values_v9').parsed_tables(main)
    corroborated=[t for t in tables if t.get('table_total')==mon and (q is None or t.get('table_quantity')==str(q))]
    # A missing service label alone is also recoverable, without touching finance.
    basic={'version':12,'kind':'licensing_only','services':['LICENCIAMENTO'],'monthly_reference':None,'annual_amount':None,'initial_total':None,'implantation':None,'retroactive':None,'historical_import':None,'extra_period':None,'one_off_total':None,'monthly_quantity':q,'annual_quantity':aq,'retro_quantity':None,'payment_days':None,'evidence':[],'warnings':[]}
    if not ann and not (retro or imported or initial and (fee or mon)):
        return basic,None
    if not ann and not retro and not imported:
        return basic,None
    if not (mon and fee is not None and init) or (retro and ret is None) or (imported and imp is None) or (retro and imported):
        return basic,'Licenciamento identificado; composição financeira exige conferência.'
    if ann:
        yearly=unique(annual+yearlines); extra=unique(singleperiod)
        # Explicit annual price plus corresponding period line, monthly reference,
        # extra period and other fees must reconcile with stated initial total.
        if yearly is None or ret is None or extra is None or imp is not None or total(fee,ret,yearly,extra)!=init:
            return basic,'Pagamento anual identificado, mas composição do total não reconciliada.'
        if Decimal(mon)*12 != Decimal(yearly):return basic,'Referência mensal difere do pacote anual; verificar condições.'
        kind='annual_package';oneoff=total(fee,ret)
        pay=re.search(r'PRAZO DE PAGAMENTO\s*:\s*O PAGAMENTO ACIMA DEVE SER REALIZADO DE FORMA UNICA.{0,240}?PRAZO DE (\d{1,3}) DIAS',n)
        evidence=monthly+implant+retro+initial+annual+yearlines+singleperiod
        warnings=[]
        limits=[]
        for lm in re.finditer(r'(?:LIMITADO A IMPORTACAO DE ATE|ULTRAPASSE O LIMITE DE)\s*(\d[\d.]*)',alln):
            limits.append(int(lm[1].replace('.','')))
        if len(set(limits))>1:warnings.append('Limites de importação distintos no documento: '+ ' / '.join(str(x) for x in sorted(set(limits)))+'. Conferir antes de contratar.')
    else:
        yearly=extra=None;pay=None;warnings=[]
        if total(fee,ret or imp)!=init:return basic,'Implantação e serviço avulso não reconciliam com total inicial.'
        kind='recurring_with_extra';oneoff=init;evidence=monthly+implant+retro+imported+initial
    out={**basic,'kind':kind,'services':['LICENCIAMENTO']+(['RETROATIVAS'] if ret is not None else []),'monthly_reference':mon,'annual_amount':yearly,'initial_total':init,'implantation':fee,'retroactive':ret,'historical_import':imp,'extra_period':extra,'one_off_total':oneoff,'retro_quantity':rq,'payment_days':int(pay[1]) if pay else None,'post_fixed':bool(re.search(r'MODALIDADE POS\s*[-–]?\s*FIXADA',alln)),'pre_fixed':bool(re.search(r'MODALIDADE PRE\s*[-–]?\s*FIXADA',alln)),'evidence':[{'amount':a,'text':s[:350]} for a,s in evidence], 'warnings':warnings}
    if len(corroborated)==1:out['table']=corroborated[0]
    elif not corroborated:
        t=flexible_table(main,mon,q)
        if t:out['table']=t
    nums=v10.cover_numbers(text)
    file_short=re.search(r'PROPOSTA\s+COMERCIAL\s*[-_]\s*(\d{1,7})(?=\D|$)',norm(filename))
    if len(nums)==1 and file_short and file_short[1]!=nums[0].split('/')[-1]:
        out['warnings'].append('Número do arquivo '+file_short[1]+' difere do número da capa EC '+nums[0]+'. Numeração preservada.')
    return out,None

def generate(data,root):
    metas={int(x['proposal_id']):x for x in data['metadata']};docs=collections.defaultdict(list)
    for d in data['documents']:docs[int(d['proposal_id'])].append(d)
    rows=[];reports=[];pending=[];counts=collections.Counter()
    for p in data['proposals']:
        pid=int(p['id']);m=metas.get(pid); title=m.get('title','') if m else ''; warnings=[]; fields={}; core={}; b=None
        try:
            if not m or len(docs[pid])!=1:raise ValueError('Documento histórico único ou metadados ausentes.')
            d=docs[pid][0];path=(root/d['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or sha256(path)!=d['sha256'] or m['document_sha256']!=d['sha256']:raise ValueError('Hash ou caminho divergente.')
            text=text_of(path);counts['lidos']+=1
            b,reason=resolve(text,d.get('original_name',''))
            if not b:
                counts['fora_do_escopo_v12']+=1
                if reason:pending.append({'id':pid,'proposta':title,'motivos':[reason]})
                reports.append([pid,title,'preservado',','.join(list_json(m.get('services'))),m.get('monthly_amount'),None,None,None,None,None,reason or 'Sem alteração V12'])
                continue
            if reason:warnings.append(reason)
            old=v6.obj(m.get('evidence')); codes=list_json(m.get('services')); pc=list_json(p.get('service_codes'))
            # A valid existing classification must not be replaced by a heuristic.
            if any(set(c)-set(b['services']) for c in (codes,pc)):
                raise ValueError('Serviço existente incompatível; preservado.')
            if b['kind']=='licensing_only' and codes and pc:
                counts['licenciamento_ja_classificado']+=1
                if warnings:pending.append({'id':pid,'proposta':title,'motivos':warnings})
                reports.append([pid,title,'preservado',','.join(codes),m.get('monthly_amount'),None,None,None,None,None,'; '.join(warnings)])
                continue
            if codes!=b['services']:fields['services']=b['services']
            if pc!=b['services']:core['service_codes']=b['services']
            if b['kind']!='licensing_only':
                counts[b['kind']]+=1;warnings+=b['warnings']
                # Monthly field is a reference for annual packages, not monthly billing.
                # Only fill empty financial fields; retain divergent/confirmed data.
                for field,src,value in [('monthly_amount',m,b['monthly_reference']),('one_time_amount',m,b['one_off_total']),('monthly_final',p,b['monthly_reference']),('one_time_final',p,b['one_off_total'])]:
                    old_value=money(src.get(field)); target=fields if src is m else core
                    if src.get(field) is None:target[field]=value
                    elif old_value!=value:warnings.append('Cadastro anterior '+field+' = '+str(src.get(field))+'; preservado. Composição original exibida separadamente.')
                for field,q in [('recurring_total',b['annual_quantity']),('retro_total',b['retro_quantity'])]:
                    if q is not None and p.get(field) is None:core[field]=q
                    elif q is not None and str(p.get(field))!=str(q):warnings.append('Volume anterior '+field+' diverge; preservado.')
            sm,sc,sig,err=signature.recover(text,p,m)
            if err:warnings.append(err)
            if sig:fields.update(sm);core.update(sc)
            b['warnings']=list(dict.fromkeys(warnings))
            if fields or core or old.get('billing_v12')!=b:
                rows.append({'proposal_id':pid,'expected_core':p['fingerprint'],'expected_meta':m['fingerprint'],'document_sha256':d['sha256'],'meta':fields,'core':core,'billing':b,'signature':sig})
            counts['classificados']+=1
        except Exception as exc:
            warnings.append(str(exc)[:220]);counts['falhas_ou_conflitos']+=1
        if warnings:pending.append({'id':pid,'proposta':title,'motivos':warnings})
        if b:reports.append([pid,title,b['kind'],','.join(b['services']),b['monthly_reference'],b['implantation'],b['retroactive'],b['historical_import'],b['annual_amount'],b['initial_total'],'; '.join(warnings)])
        else:reports.append([pid,title,'conferir','',None,None,None,None,None,None,'; '.join(warnings)])
    return {'version':12,'rows':rows,'summary':{'total_historico':len(data['proposals']),**counts,'atualizacoes':len(rows),'pendencias_v12':len(pending)},'pending':pending},reports

def main():
    a=argparse.ArgumentParser();a.add_argument('--export',type=Path,required=True);a.add_argument('--root',type=Path,required=True);a.add_argument('--output',type=Path,required=True);args=a.parse_args()
    plan,report=generate(json.loads(args.export.read_text()),args.root);folder=args.output.parent
    for p,v in [(args.output,plan),(folder/'resumo.json',plan['summary']),(folder/'pendencias.json',plan['pending'])]:p.write_text(json.dumps(v,ensure_ascii=False,indent=2));p.chmod(0o600)
    with (folder/'revisao.csv').open('w',encoding='utf-8-sig',newline='') as f:
        w=csv.writer(f,delimiter=';');w.writerow(['id','proposta','modelo','servicos','mensal_referencia','implantacao','retroativas','importacao','anual','total_inicial','avisos']);w.writerows([["'"+x if isinstance(x,str) and x[:1] in ('=','+','-','@','\t','\r') else x for x in r] for r in report])
    with ZipFile(folder/'Auditoria_Propostas_V12.zip','w',ZIP_DEFLATED) as z:
        for name in ('resumo.json','pendencias.json','revisao.csv'):z.write(folder/name,name)
    print(json.dumps(plan['summary'],ensure_ascii=False,indent=2))
if __name__=='__main__':main()
