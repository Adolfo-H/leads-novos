#!/usr/bin/env python3
"""Read private historical documents; produce a financial-only review (no DB writes)."""
import argparse
import collections
import csv
import json
import re
import subprocess
from decimal import Decimal
from pathlib import Path

from extract_history import text_of, sha256, norm, clean, list_json

MONEY = re.compile(r'R\$\s*(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})(?!\d|[.,]\d)', re.I)
BARE = re.compile(r'^\s*(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})\s*$')
COUNT = re.compile(r'^\s*(\d{1,3}(?:\.\d{3})+|\d+)\s*(?:NFS?\b|NFS-E\b|NOTAS\b|DOCUMENTOS\b|DF-?E\b|CT-?E\b)?\s*$', re.I)
MONTHLY = [
    (100, r'\b(?:MENSALIDADE\s+(?:FINAL|COM\s+(?:OS\s+)?(?:TRIBUTOS|IMPOSTOS))|(?:VALOR|TOTAL|PRECO|INVESTIMENTO)\s+MENSAL\s+FINAL|VALOR\s+FINAL\s+MENSAL)\b'),
    (80, r'\b(?:VALOR\s+(?:DA\s+)?MENSALIDADE|MENSALIDADE\s+(?:TOTAL|FIXA)|(?:VALOR|TOTAL|PRECO|INVESTIMENTO|CUSTO)\s+MENSAL|VALOR\s+(?:A\s+SER\s+)?PAGO\s+MENSALMENTE)\b'),
    (65, r'\bMENSALIDADE\b'),
]
POINT = [
    (100, r'\bVALOR\s+(?:FINAL|TOTAL)\s+(?:DO\s+SERVICO|DAS\s+CONSULTAS\s+RETROATIVAS|RETROATIVO)\b'),
    (85, r'\b(?:INVESTIMENTO|VALOR|PAGAMENTO)\s+UNIC[OA]\b|\bVALOR\s+(?:TOTAL\s+)?(?:DAS\s+)?RETROATIVAS\b'),
]
IMPL = [(90, r'\b(?:VALOR|TAXA|CUSTO|INVESTIMENTO)\s+(?:DA\s+|DE\s+)?IMPLANTACAO\b'), (70,r'^IMPLANTACAO\b')]
NET = [(100, r'\b(?:TOTAL\s+LIQUIDO\s+MENSAL|VALOR\s+LIQUIDO\s+MENSAL|MENSALIDADE\s+LIQUIDA|VALOR\s+MENSAL\s+LIQUIDO)\b'), (60,r'^VALOR\s+LIQUIDO\b')]
BASE = [(100, r'\b(?:BASE\s+MENSAL|MEDIA\s+MENSAL|MEDIA\s+DE\s+REFERENCIA|QUANTIDADE\s+MENSAL)\b'),(95,r'\b(?:BASE\s+TOTAL|NOTAS\s+NO\s+PERIODO|QUANTIDADE\s+TOTAL)\b')]
BAD = re.compile(r'\b(?:LIQUID[OA]S?|UNITARI[OA]S?|MINIM[OA]S?|MAXIM[OA]S?|EXCEDENT\w*|DESCONTO|ECONOMIA|PROPORCIONAL|ANTECIPAD\w*|PARCELAS?|REAJUST\w*|PRIMEIRA\s+MENSALIDADE|POR\s+NF|POR\s+NOTA|A\s+PARTIR\s+DE|SEM\s+(?:TRIBUTOS|IMPOSTOS)|ANTES\s+D[OE]S?\s+(?:TRIBUTOS|IMPOSTOS)|ATE\s+R\$)\b')
SECTION = re.compile(r'^(?:INVESTIMENTO|CONDICOES COMERCIAIS|MEMORIA DE CALCULO|FORMACAO (?:DA MENSALIDADE|DO VALOR)|PRECIFICACAO|TABELA DE PRECOS)\s*$')
END = re.compile(r'^(?:ESCOPO(?: DO SERVICO)?|RESUMO EXECUTIVO|OBJETIVO|RESPONSABILIDADES|ASSINATURA|ACEITE)\s*$')


def cells(line):
    """Preserve starting x-coordinate in pdftotext -layout output."""
    return [(m.start(),m.group().rstrip()) for m in re.finditer(r'\S+(?:[ \t]{1,2}\S+)*',line.rstrip().expandtabs(8))]


def decimal(v):
    d=Decimal(v.replace('.','').replace(',','.'))
    return format(d,'.2f') if 0<=d<=Decimal('999999999.99') else None


def cash_values(s, bare=False):
    values=[decimal(m[1]) for m in MONEY.finditer(s)]
    if not values and bare and (m:=BARE.fullmatch(s)): values=[decimal(m[1])]
    return [v for v in values if v is not None]


def count_values(s, bare=False):
    m=COUNT.fullmatch(s)
    if not m: return []
    if not bare and not re.search(r'[A-Za-z]',s): return []
    return [m[1].replace('.','')]


def best(candidates):
    if not candidates: return None, 'missing'
    rank=max(x['prioridade'] for x in candidates)
    values={x['valor'] for x in candidates if x['prioridade']==rank}
    return (next(iter(values)), 'identified') if len(values)==1 else (None,'conflict')


def evidence(lines, patterns, extractor=cash_values, reject_bad=True):
    """Scan cells, including headings followed by blank lines. Do not cross pages/columns."""
    out=[]
    for i,line in enumerate(lines):
        for pos,cell in cells(line):
            n=norm(cell)
            if reject_bad and BAD.search(n): continue
            matched=[]
            for rank,pat in patterns:
                for label in re.finditer(pat,n):
                    matched.append((rank,label))
            if not matched: continue
            rank,label=max(matched,key=lambda x:x[0])
            suffix=cell[label.end():].strip(' :–—-\t')
            # Direct value after label, allow simple linking words only, not prose mentioning an example.
            amounts=extractor(suffix, True)
            if amounts:
                first=MONEY.search(suffix)
                prefix=norm(suffix[:first.start()]).strip(' :–—-') if first else ''
                if prefix not in ('','DE','E DE','SERA DE','SERA','E','FIXA DE','COM TRIBUTOS','COM IMPOSTOS','TOTAL DE','NO VALOR DE'):
                    continue
                if len(set(amounts))==1 and not (reject_bad and BAD.search(norm(suffix))):
                    out.append({'valor':amounts[0],'trecho':clean(cell)[:500],'prioridade':rank,'linha':i+1})
                elif len(set(amounts))>1:
                    for value in set(amounts): out.append({'valor':value,'trecho':clean(cell)[:500],'prioridade':rank,'linha':i+1})
                continue
            # For heading/value cards, require label-only cell (or a colon) and align next content by column.
            if suffix: continue
            prefix=norm(cell[:label.start()]).strip(' :–—-')
            if prefix and prefix not in ('A','O','TOTAL'): continue
            next_cells=[(x,c) for x,c in cells(line) if x>pos]
            # Some older Word documents align the label and amount in adjacent cells.
            if next_cells and len(next_cells)==1:
                value=next_cells[0][1]
                if (MONEY.fullmatch(value) or BARE.fullmatch(value)) and extractor is cash_values:
                    for v in extractor(value,True): out.append({'valor':v,'trecho':clean(cell)+' | '+clean(value),'prioridade':rank,'linha':i+1})
                    continue
            right=next_cells[0][0] if next_cells else None
            for j in range(i+1,min(i+10,len(lines))):
                if '\f' in lines[j]: break
                nxt=cells(lines[j])
                if not nxt: continue
                # If same column has no content, allow other columns until a real heading/value arrives.
                eligible=[(p,c) for p,c in nxt if abs(p-pos)<=12 and (right is None or p<right-2)]
                if not eligible:
                    # Do not jump over a new section or a different table.
                    if SECTION.match(norm(lines[j]).strip()) or END.match(norm(lines[j]).strip()): break
                    continue
                p,value=min(eligible,key=lambda x:abs(x[0]-pos))
                values=extractor(value,True)
                if values and not (reject_bad and BAD.search(norm(value))):
                    for v in set(values): out.append({'valor':v,'trecho':(clean(cell)+' | '+clean(value))[:500],'prioridade':rank,'linha':i+1})
                # First aligned nonblank cell is the only candidate.
                break
    return list({(x['valor'],x['trecho'],x['prioridade']):x for x in out}.values())[:80]


def table_rows(lines):
    """Recognize the seven-column progressive band tables, preserving original numbers."""
    result=[]; active=0
    for i,l in enumerate(lines):
        n=norm(l)
        if 'FAIXA' in n and 'SUBTOTAL' in n and ('MIN' in n or 'MAX' in n):
            active=60; continue
        if not active: continue
        active-=1
        if not l.strip(): continue
        if re.search(r'\bTOTAL\s+LIQUIDO\b',n) or SECTION.match(n.strip()) or END.match(n.strip()) or '\f' in l:
            active=0; continue
        parts=[v.strip() for _,v in cells(l)]
        if len(parts)!=7 or not re.fullmatch(r'\d{1,2}',parts[0]): continue
        if not all(re.fullmatch(r'[\d.]+',v) for v in (parts[1],parts[5])): continue
        if not re.fullmatch(r'[\d.]+|SEM LIMITE|ACIMA',norm(parts[2])): continue
        if any(len(cash_values(parts[k], True))!=1 for k in (3,4,6)): continue
        r=dict(zip(['faixa','min','max','fixo','unitario','quantidade','subtotal'],parts))
        if r not in result: result.append(r)
    # Two different tables in same document must not be merged (different scenarios/services).
    if len({r['faixa'] for r in result})!=len(result): return [],True
    return result[:30],False


def tax_rows(lines):
    rows=[]
    for l in lines:
        parts=[v.strip() for _,v in cells(l)]
        if len(parts)<3: continue
        label=norm(parts[0])
        if label not in ('ISS','ISSQN','PIS','COFINS','IBS','CBS','VALOR LIQUIDO','VALOR FINAL'): continue
        if not re.fullmatch(r'\d+(?:,\d+)?\s*%|[-–—]',parts[1]): continue
        if len(cash_values(parts[2],True))!=1: continue
        row={'item':parts[0],'aliquota':parts[1],'valor':parts[2]}
        if row not in rows: rows.append(row)
    if len({norm(r['item']) for r in rows})!=len(rows): return [],True
    return rows,False


def conditions(lines):
    out={}
    labels={'VALIDADE':'validade','PAGAMENTO':'pagamento','FATURAMENTO':'faturamento','DEPENDENCIA':'dependencia','PRAZO':'prazo'}
    for i,l in enumerate(lines):
        for pos,cell in cells(l):
            for label,key in labels.items():
                if key in out: continue
                n=norm(cell)
                if n==label:
                    parts=[]
                    for j in range(i+1,min(i+7,len(lines))):
                        if '\f' in lines[j]: break
                        if not lines[j].strip():
                            if parts: break
                            continue
                        options=[v for p,v in cells(lines[j]) if abs(p-pos)<=9]
                        if not options: break
                        s=options[0]
                        if norm(s) in labels or SECTION.match(norm(s)) or END.match(norm(s)) or re.match(r'^OBSERVAC',norm(s)): break
                        parts.append(s)
                        if len(' '.join(parts))>350: break
                    val=clean(' '.join(parts))
                elif n.startswith(label+':'): val=clean(cell.split(':',1)[1])
                else: continue
                if val and len(val)<=400: out[key]=val
        m=re.match(r'\s*(?:OBSERVA[CÇ][OÕ]ES COMERCIAIS|CONDI[CÇ][AÃ]O ESSENCIAL)\s*:\s*(.*)',l,re.I)
        if m:
            key='observacoes' if 'OBSERVA' in norm(l)[:30] else 'condicao'
            parts=[m[1]]
            for j in range(i+1,min(i+7,len(lines))):
                if not lines[j].strip() or '\f' in lines[j]: break
                if re.search(r'@|EXPORTCONTROL LTDA|PROPOSTA_COMERCIAL',norm(lines[j])): break
                parts.append(lines[j])
            value=clean(' '.join(parts))[:1000]
            if value and key not in out: out[key]=value
    return out


def compact_memory(lines):
    # Only commercial sections and actual money/table evidence, not whole scope.
    selected=[]; active=False
    for l in lines:
        n=norm(l).strip()
        if '\f' in l: active=False; continue
        if SECTION.match(n): active=True
        if END.match(n): active=False
        if re.search(r'EXPORTCONTROL LTDA\s*·|PROPOSTA_COMERCIAL_EC_|^\s*EMITIDA\s*$',n): continue
        if not l.strip(): continue
        if active or MONEY.search(l):
            selected.append('  |  '.join(v for _,v in cells(l)))
    return '\n'.join(selected)[:16000] or None


def parse(text, services):
    # split on newline only; keep form feed as explicit page barrier
    lines=text.replace('\f','\n\f\n').split('\n')
    retro=services==['RETROATIVAS']
    recurring=any(s in {'LICENCIAMENTO','ACOMPANHAMENTO','DFE','NFSE'} for s in services)
    mc=evidence(lines,MONTHLY)
    pc=evidence(lines,POINT + ([(65,r'^VALOR\s+TOTAL\b')] if retro else []))
    ic=evidence(lines,IMPL)
    # Recognize amount followed directly by /mês or "mensais", but exclude table unit values and examples.
    for i,l in enumerate(lines):
        for _,c in cells(l):
            if BAD.search(norm(c)): continue
            for m in MONEY.finditer(c):
                tail=norm(c[m.end():])
                if re.match(r'^\s*(?:/\s*MES\b|POR\s+MES\b|MENSAIS\b|MENSALMENTE\b)',tail):
                    v=decimal(m[1]);
                    if v is not None: mc.append({'valor':v,'trecho':clean(c)[:500],'prioridade':70,'linha':i+1})
    bands,band_conflict=table_rows(lines); taxes,tax_conflict=tax_rows(lines)
    # Tax-table final is contextual corroboration, not a generic currency guess.
    final_tax=[r for r in taxes if norm(r['item'])=='VALOR FINAL']
    monthly_context=any(re.search(r'\b(?:MENSALIDADE|TOTAL LIQUIDO MENSAL)\b',norm(l)) for l in lines)
    if len(final_tax)==1:
        value=cash_values(final_tax[0]['valor'],True)[0]
        if recurring and not retro and monthly_context and not ic and not pc and not mc:
            mc.append({'valor':value,'trecho':'Valor final da tabela de tributos; contexto mensal','prioridade':55,'linha':0})
        elif retro and not pc:
            pc.append({'valor':value,'trecho':'Valor final da tabela de tributos; serviço retroativo','prioridade':55,'linha':0})
    monthly,mstate=best(mc)
    if retro: monthly,mstate=None,'not_applicable'
    # If distinct one-time categories exist, do not present a sum that was not in the document.
    point,pstate=best(pc if retro else ic+pc)
    if not retro and recurring and pc and not ic:
        # "Valor final do serviço" may be the very same monthly fee, not a second charge.
        point,pstate=None,'missing'
    elif ic and pc and not retro:
        point,pstate=best(ic)
    if retro and not pc: point,pstate=best(ic) if not ic else (None,'missing')
    net_candidates=evidence(lines,NET,reject_bad=False)
    for r in taxes:
        if norm(r['item'])=='VALOR LIQUIDO':
            net_candidates.append({'valor':cash_values(r['valor'],True)[0],'trecho':r['item']+' | '+r['valor'],'prioridade':90,'linha':0})
    for l in lines:
        cc=cells(l)
        if cc and re.fullmatch(r'TOTAL LIQUIDO(?: MENSAL)?',norm(cc[0][1])) and len(cc)>=2:
            vals=cash_values(cc[-1][1],True)
            if len(vals)==1: net_candidates.append({'valor':vals[0],'trecho':clean(l),'prioridade':100,'linha':0})
    net,nstate=best(net_candidates)
    base,bstate=best(evidence(lines,BASE,extractor=count_values,reject_bad=False))
    f={'version':2,'monthly_state':mstate,'one_time_state':pstate,'net_amount':net,'base_quantity':base,
       'base_label':'Base total de documentos' if retro else 'Base de referência (documentos)',
       'bands':bands,'taxes':taxes,'conditions':conditions(lines),'band_conflict':band_conflict,'tax_conflict':tax_conflict,
       'monthly_evidence':mc[:40],'one_time_evidence':(pc+ic)[:40]}
    return monthly,point,f,compact_memory(lines)


def generate(data, root):
    rows=[];errors=[];counts=collections.Counter()
    docs=collections.defaultdict(list)
    for d in data['documents']: docs[int(d['proposal_id'])].append(d)
    for i,meta in enumerate(data['metadata'],1):
        id=int(meta['proposal_id']); dd=docs[id]
        try:
            if len(dd)!=1: raise ValueError('Esperado um documento histórico por registro')
            doc=dd[0]; path=(root/doc['storage_path']).resolve(strict=True)
            if not path.is_relative_to(root.resolve()) or not path.is_file(): raise ValueError('Caminho inválido')
            if sha256(path)!=doc['sha256'] or doc['sha256']!=meta['document_sha256']: raise ValueError('Hash divergente')
            text=text_of(path)
            if len(clean(text))<40: raise ValueError('Documento sem texto extraível; exige conferência do original')
            svc=list_json(meta['services'])
            month,once,f,raw=parse(text,svc)
            old_evidence=meta.get('evidence')
            old_evidence=json.loads(old_evidence) if isinstance(old_evidence,str) else old_evidence
            if not isinstance(old_evidence,dict): old_evidence={}
            # Avoid silently losing an already identified historical amount: retain only if still
            # present in source and no new conflicting candidates; disclose weaker provenance.
            actual=set(cash_values(text))
            for key,state,new in [('monthly_amount','monthly_state',month),('one_time_amount','one_time_state',once)]:
                previous=meta.get(key)
                if new is None and f[state]=='missing' and previous is not None:
                    previous=format(Decimal(str(previous)),'.2f')
                    if previous in actual and not (key=='one_time_amount' and previous==month):
                        if key=='monthly_amount': month=previous
                        else: once=previous
                        f[state]='previous_review'
            # Previous values and full V1 evidence remain in the batch's DB snapshot, not rendered as raw text.
            ev={**old_evidence,'finance_v2':f}
            rows.append({'proposal_id':id,'expected_fingerprint':meta['fingerprint'],'document_sha256':doc['sha256'],
                         'monthly_amount':month,'one_time_amount':once,'calculation':raw,'evidence':ev})
            counts['mensalidades_identificadas']+=month is not None
            counts['valores_pontuais_identificados']+=once is not None
            counts['mensalidades_recuperadas']+=month is not None and meta.get('monthly_amount') is None
            counts['mensalidades_conflitantes']+=f['monthly_state']=='conflict'
            counts['mensalidades_anteriores_revisar']+=f['monthly_state']=='previous_review'
            counts['mensalidades_pendentes']+=month is None and f['monthly_state']!='not_applicable'
            counts['sem_mensalidade_aplicavel']+=f['monthly_state']=='not_applicable'
            counts['tabelas_faixas']+=bool(f['bands']); counts['tabelas_tributos']+=bool(f['taxes'])
        except (OSError,ValueError,subprocess.SubprocessError,ArithmeticError,RuntimeError) as e:
            errors.append({'proposal_id':id,'error':str(e)[:300]})
        except Exception as e:
            # A malformed Word/PDF cannot destroy existing metadata or block other records.
            errors.append({'proposal_id':id,'error':type(e).__name__+': '+str(e)[:200]})
        if i%50==0: print(f'Analisados {i}/{len(data["metadata"])}',flush=True)
    summary={'propostas_historicas':data['total'],'com_metadados_v1':len(data['metadata']),'revisados':len(rows),'falhas_preservadas':len(errors),**counts}
    return {'version':2,'summary':summary,'errors':errors,'rows':rows}


def main():
    p=argparse.ArgumentParser();p.add_argument('--export',required=True,type=Path);p.add_argument('--root',required=True,type=Path);p.add_argument('--output',required=True,type=Path);a=p.parse_args()
    data=json.loads(a.export.read_text()); review=generate(data,a.root)
    a.output.write_text(json.dumps(review,ensure_ascii=False,indent=2)); print(json.dumps(review['summary'],ensure_ascii=False,indent=2))
    with a.output.with_name('revisao_financeira.csv').open('w',newline='',encoding='utf-8-sig') as f:
        w=csv.writer(f,delimiter=';');w.writerow(['proposal_id','mensalidade','valor_pontual','situacao_mensalidade','situacao_pontual','faixas','tributos'])
        for r in review['rows']:
            x=r['evidence']['finance_v2'];w.writerow([r['proposal_id'],r['monthly_amount'],r['one_time_amount'],x['monthly_state'],x['one_time_state'],len(x['bands']),len(x['taxes'])])
    a.output.with_name('resumo.json').write_text(json.dumps(review['summary'],ensure_ascii=False,indent=2))
    if not review['rows']: raise SystemExit('Nenhum registro válido para atualização.')

if __name__=='__main__': main()
