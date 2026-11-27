#!/usr/bin/env python3
"""Read-only HubSpot attachment scan. Token via stdin, never saved or logged."""
import collections
import hashlib
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

STAGES = {'contractsent': 'Aceite da proposta', '14249606': 'Contrato assinado', '14249607': 'Início do teste', 'closedwon': 'Negócio fechado'}
KINDS = ('notes', 'emails', 'calls', 'meetings', 'tasks')
MAX_BYTES = 100 * 1024 * 1024

class ApiError(Exception):
    def __init__(self, code, endpoint):
        self.code = code
        super().__init__(f'HTTP {code} em {endpoint}')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class HubSpot:
    def __init__(self, token):
        if not token: raise ValueError('Token HubSpot não configurado')
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect())

    def request(self, path, body=None):
        if not path.startswith('/') or path.startswith('//'): raise ValueError('Endpoint inválido')
        url = 'https://api.hubapi.com' + path
        headers = {'Authorization': 'Bearer ' + self.token, 'Accept':'application/json'}
        if body is not None: headers['Content-Type'] = 'application/json'
        for attempt in range(5):
            time.sleep(.12)
            try:
                with self.opener.open(urllib.request.Request(url, data=None if body is None else json.dumps(body).encode(), headers=headers), timeout=45) as r:
                    return json.loads(r.read())
            except urllib.error.HTTPError as e:
                if e.code not in (429, 500, 502, 503, 504) or attempt == 4:
                    raise ApiError(e.code,path.split('?')[0]) from None
                try: delay = float(e.headers.get('Retry-After','0'))
                except ValueError: delay = 0
                time.sleep(min(60,max(delay,2**attempt)))
            except (urllib.error.URLError, TimeoutError) as e:
                if attempt == 4: raise ApiError('rede',path.split('?')[0]) from None
                time.sleep(2**attempt)
        raise AssertionError('retry')

    def pages(self,path,params=None):
        params=dict(params or {}); seen=set()
        while True:
            q=path+'?'+urllib.parse.urlencode(params)
            data=self.request(q)
            results=data.get('results')
            if not isinstance(results,list): raise ValueError('Resposta paginada inválida: '+path)
            yield from results
            cursor=data.get('paging',{}).get('next',{}).get('after')
            if cursor is None: break
            cursor=str(cursor)
            if cursor in seen: raise ValueError('Cursor repetido: '+path)
            seen.add(cursor); params['after']=cursor

    def deals(self):
        # List, rather than capped CRM search: no 10,000-result ceiling.
        for d in self.pages('/crm/v3/objects/deals',{'limit':100,'properties':'dealname,dealstage,pipeline','archived':'false'}):
            p=d.get('properties',{})
            if p.get('pipeline')=='default' and p.get('dealstage') in STAGES and not d.get('archived',False):
                yield {'id':numeric_id(d['id']),'name':str(p.get('dealname') or ''),'stage':p['dealstage']}

    def current_stage(self,deal):
        d=self.request(f'/crm/v3/objects/deals/{numeric_id(deal)}?properties=dealstage,pipeline&archived=false')
        p=d.get('properties',{})
        return p.get('dealstage') if p.get('pipeline')=='default' and not d.get('archived',False) else None

    def activities(self,deal,kind):
        for a in self.pages(f'/crm/v4/objects/deals/{numeric_id(deal)}/associations/{kind}',{'limit':500}):
            yield numeric_id(a['toObjectId'])

    def batch_activities(self,kind,ids):
        for i in range(0,len(ids),100):
            group=ids[i:i+100]
            data=self.request(f'/crm/v3/objects/{kind}/batch/read',{'properties':['hs_attachment_ids'],'inputs':[{'id':x} for x in group]})
            rows=data.get('results')
            if not isinstance(rows,list): raise ValueError('Batch de atividades inválido')
            returned=set()
            for row in rows:
                rid=numeric_id(row['id']); returned.add(rid)
                if rid not in group: raise ValueError('Atividade fora da consulta')
                if not row.get('archived',False): yield rid,attachment_ids(row.get('properties',{}).get('hs_attachment_ids'))
            if set(group)!=returned: raise ValueError('Atividades ausentes na resposta: '+kind)

    def file(self,fid):
        fid=numeric_id(fid)
        try: meta=self.request(f'/files/v3/files/{fid}')
        except ApiError as e:
            if e.code not in (403,404): raise
            meta={}  # hidden files may still be accessible by signed URL
        ext=str(meta.get('extension') or '').lower().lstrip('.')
        name=str(meta.get('name') or '')
        if not ext and '.' in name: ext=name.rsplit('.',1)[-1].lower()
        if ext and ext not in ('pdf','doc','docx'): return {'name':name,'extension':ext,'skipped':True}
        if meta.get('archived') or meta.get('deleted'): raise ValueError('Arquivo arquivado/excluído')
        size=meta.get('size')
        if size is not None and int(size)>MAX_BYTES: raise ValueError('Arquivo excede limite de 100 MiB')
        try: signed=self.request(f'/files/v3/files/{fid}/signed-url')
        except ApiError as e:
            if e.code not in (400,403,404) or not str(meta.get('access','')).startswith('PUBLIC_') or not meta.get('url'): raise
            signed={'url':meta['url'],'name':name,'extension':ext,'size':size}
        ext=str(signed.get('extension') or ext).lower().lstrip('.')
        name=str(signed.get('name') or name or ('arquivo-'+fid))
        if ext and ext not in ('pdf','doc','docx'): return {'name':name,'extension':ext,'skipped':True}
        if signed.get('size') is not None and int(signed['size'])>MAX_BYTES: raise ValueError('Arquivo excede limite de 100 MiB')
        digest=download_hash(signed.get('url'))
        return {'name':name,'extension':ext,'sha256':digest}


def numeric_id(v):
    s=str(v)
    if not re.fullmatch(r'[1-9]\d{0,29}',s): raise ValueError('ID inválido')
    return s


def attachment_ids(v):
    if v is None or v=='': return []
    if not isinstance(v,str): raise ValueError('Formato de hs_attachment_ids inesperado')
    return list(dict.fromkeys(numeric_id(x.strip()) for x in re.split(r'[;,]',v) if x.strip()))


def allowed_url(url):
    if not isinstance(url,str): return False
    u=urllib.parse.urlsplit(url); host=(u.hostname or '').lower()
    # A signed URL is obtained only from the authenticated Files API. Never send the token to the CDN.
    suffixes=('hubapi.com','hubspot.com','hubspot.net','hubspotusercontent.com','hubspotusercontent-na1.net','hubspotusercontent-eu1.net','hubspotusercontent00.net','hubspotusercontent10.net','hsusercontent.com','amazonaws.com','cloudfront.net')
    return u.scheme=='https' and not u.username and not u.password and u.port in (None,443) and (any(host==x or host.endswith('.'+x) for x in suffixes) or re.search(r'(?:^|\.)hubspotusercontent(?:[0-9]+|-[a-z0-9]+)\.net$',host) is not None)


def download_hash(url):
    opener=urllib.request.build_opener(NoRedirect())
    for _ in range(6):
        if not allowed_url(url): raise ValueError('Domínio de download não permitido; revisar URL assinada')
        try:
            with opener.open(urllib.request.Request(url,headers={'Accept':'application/octet-stream,*/*'}),timeout=60) as r:
                h=hashlib.sha256(); n=0; prefix=b''
                while True:
                    chunk=r.read(1024*1024)
                    if not chunk: break
                    if not prefix: prefix=chunk[:1024]
                    n+=len(chunk)
                    if n>MAX_BYTES: raise ValueError('Arquivo excede limite de 100 MiB')
                    h.update(chunk)
                if not (b'%PDF-' in prefix or prefix.startswith(b'PK\x03\x04') or prefix.startswith(bytes.fromhex('d0cf11e0a1b11ae1'))): raise ValueError('Resposta não é PDF/DOC/DOCX')
                return h.hexdigest()
        except urllib.error.HTTPError as e:
            if e.code in (301,302,303,307,308) and e.headers.get('Location'):
                url=urllib.parse.urljoin(url,e.headers['Location']); continue
            raise ApiError(e.code,'download privado') from None
        except (urllib.error.URLError,TimeoutError): raise ApiError('rede','download privado') from None
    raise ValueError('Redirecionamentos demais')


def scan(api, documents):
    local=collections.defaultdict(list)
    for d in documents: local[d['sha256']].append(d)
    deals=list(api.deals()); activity_to_deals={k:collections.defaultdict(set) for k in KINDS}
    errors=[]; files_by_deal=collections.defaultdict(dict); counts=collections.Counter()
    def issue(scope,ident,exc): errors.append({'scope':scope,'id':ident,'error':str(exc)[:350]})
    for i,d in enumerate(deals,1):
        for kind in KINDS:
            try:
                for aid in api.activities(d['id'],kind): activity_to_deals[kind][aid].add(d['id'])
            except (ApiError,ValueError) as e: issue('associations:'+kind,d['id'],e)
        if i%20==0: print(f'Associações de {i}/{len(deals)} negócios consultadas',file=sys.stderr,flush=True)
    for kind,lookup in activity_to_deals.items():
        ids=list(lookup)
        for i in range(0,len(ids),100):
            group=ids[i:i+100]
            try:
                for aid,fids in api.batch_activities(kind,group):
                    for did in lookup[aid]:
                        for fid in fids:
                            files_by_deal[did].setdefault(fid,{'activity_type':kind,'activity_id':aid})
            except (ApiError,ValueError) as e: issue('activities:'+kind,','.join(group[:3]),e)
    cache={}; matches=[]; unmatched=[]; conflicts=[]
    by_doc=collections.defaultdict(list)
    for d in deals:
        fids=files_by_deal[d['id']]
        if not fids: unmatched.append({'deal_id':d['id'],'name':d['name'],'reason':'Nenhum anexo localizado nas atividades acessíveis'})
        for fid,activity in fids.items():
            if fid not in cache:
                try: cache[fid]=api.file(fid)
                except (ApiError,ValueError) as e: cache[fid]=None;issue('file',fid,e)
            f=cache[fid]
            if f is None or f.get('skipped'): continue
            counts['attachments_checked']+=1
            docs=local.get(f['sha256'],[])
            pids={x['proposal_id'] for x in docs}
            if len(pids)>1:
                conflicts.append({'deal_id':d['id'],'file_id':fid,'reason':'Mesmo SHA-256 em propostas locais distintas','proposal_ids':sorted(pids)});continue
            if not docs:
                unmatched.append({'deal_id':d['id'],'name':d['name'],'file_id':fid,'file_name':f['name'],'reason':'Anexo sem arquivo idêntico no Prospector'});continue
            for doc in docs:
                row={**doc,**activity,'deal_id':d['id'],'deal_name':d['name'],'stage':d['stage'],'file_id':fid,'file_name':f['name']}
                by_doc[doc['document_id']].append(row)
    for doc_id,rows in by_doc.items():
        # Multiple eligible deals may contain the same proposal. Count the proposal once; retain every proven link.
        seen=set()
        for row in rows:
            key=(row['deal_id'],row['file_id'])
            if key not in seen: matches.append(row);seen.add(key)
    current={}
    for row in matches:
        did=row['deal_id']
        if did not in current:
            try: current[did]=api.current_stage(did)
            except (ApiError,ValueError) as e: current[did]=None;issue('stage',did,e)
    valid=[]
    for row in matches:
        if current[row['deal_id']]==row['stage']:
            valid.append(row)
        else:
            conflicts.append({'deal_id':row['deal_id'],'document_id':row['document_id'],'reason':'Etapa alterada ou indisponível durante a conferência'})
    matches=valid
    return {'version':29,'checked_at':datetime.now(timezone.utc).isoformat(),'eligible_deals':len(deals),'stage_counts':dict(collections.Counter(d['stage'] for d in deals)),'deals':deals,'attachments_checked':counts['attachments_checked'],'matches':matches,'unmatched':unmatched,'conflicts':conflicts,'errors':errors,'complete':not errors}

if __name__=='__main__':
    try:
        data=json.load(sys.stdin); result=scan(HubSpot(data['token']),data['documents']); print(json.dumps(result,ensure_ascii=False))
    except Exception as e:
        # No exception containing a signed URL or token is printed.
        print('ERRO scanner V29: '+(str(e) if isinstance(e,(ApiError,ValueError)) else type(e).__name__),file=sys.stderr);sys.exit(1)
