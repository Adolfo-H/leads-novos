/* ExportControl — melhoria visual progressiva e não-invasiva.
 * Não move nós Livewire, não chama endpoints, não dispara mensagens e não muda o
 * resultado da verificação. Apenas adiciona atributos de estilo e atalhos de rolagem.
 */
(()=>{
  'use strict';
  const PAGE='/contatos/verificacao-whatsapp';
  const SECTIONS=[
    {key:'summary', labels:['Saiba quais números já foram conferidos.','Controle de qualidade dos contatos']},
    {key:'monitor', labels:['Monitoramento das verificações']},
    {key:'accounts', labels:['Contas conectadas e execução']},
    {key:'recent', labels:['Últimos números consultados']},
    {key:'filters', labels:['Atalhos de conferência']},
    {key:'queue', labels:['Fila de conferência']},
    {key:'detail', labels:['Telefone selecionado']}
  ];
  const METRICS=[
    {label:'Celulares',key:'cellphones'},
    {label:'Telefones fixos',key:'landlines'},
    {label:'Revisar formato',key:'review'},
    {label:'Confirmados',key:'confirmed'},
    {label:'Pendentes válidos',key:'pending'},
    {label:'Celulares cadastrados',key:'registered'},
    {label:'Já consultados',key:'queried'},
    {label:'Não consultados',key:'remaining'},
    {label:'Encontrados',key:'found'},
    {label:'Não localizados',key:'notfound'}
  ];
  const normalize=s=>String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,' ').trim().toLowerCase();
  const ownText=el=>Array.from(el.childNodes).filter(n=>n.nodeType===3).map(n=>n.textContent||'').join(' ');
  const findLeaf=phrase=>{
    const target=normalize(phrase);
    const candidates=document.querySelectorAll('main h1, main h2, main h3, main h4, main h5, main p, main span, main small, main strong, main label, main div');
    for(const el of candidates){
      const txt=normalize(ownText(el));
      if(txt===target || (txt.startsWith(target) && txt.length<target.length+12))return el;
    }
    return null;
  };
  function candidatePanel(el, maxWidth=20000){
    if(!el)return null;
    const main=el.closest('main') || document.querySelector('main');
    if(!main)return null;
    const mRect=main.getBoundingClientRect();
    let candidate=null;
    for(let node=el.parentElement; node&&node!==main; node=node.parentElement){
      const st=getComputedStyle(node), rect=node.getBoundingClientRect();
      if(rect.width<170 || rect.width>maxWidth)continue;
      const hasBorder=parseFloat(st.borderTopWidth)>=1 && st.borderTopStyle!=='none';
      const hasShadow=st.boxShadow && st.boxShadow!=='none';
      if((hasBorder || hasShadow) && rect.width<=mRect.width+10 && rect.height>=55){
        candidate=node; break;
      }
    }
    return candidate;
  }
  function markSection(key,labels){
    const marker=labels.map(findLeaf).find(Boolean);
    if(!marker)return null;
    const panel=candidatePanel(marker);
    if(!panel)return null;
    panel.dataset.ecwaSection=key;
    panel.id=panel.id||'ecwa-'+key;
    return panel;
  }
  function markMetrics(){
    const rows=new Map();
    for(const m of METRICS){
      const marker=findLeaf(m.label);
      if(!marker)continue;
      const card=candidatePanel(marker,420);
      if(!card || card.hasAttribute('data-ecwa-section'))continue;
      card.dataset.ecwaMetric=m.key;
      const descendants=card.querySelectorAll('span,div,strong,p,h2,h3,h4');
      for(const d of descendants){
        if(d===marker)continue;
        const text=normalize(ownText(d));
        if(/^\d[\d.,]*$/.test(text) && text.length<15){d.dataset.ecwaMetricValue='';break;}
      }
      const parent=card.parentElement;
      if(parent)rows.set(parent,(rows.get(parent)||0)+1);
    }
    for(const [parent,total] of rows){
      const n=Array.from(parent.children).filter(c=>c.hasAttribute('data-ecwa-metric')).length;
      if(total>=4 && n>=4)parent.dataset.ecwaMetricRow='';
    }
  }
  function markQueueRows(){
    const queue=document.querySelector('[data-ecwa-section="queue"]');
    if(!queue)return;
    const rows=queue.querySelectorAll('tr,[role="row"]');
    if(rows.length>=3)for(const row of rows){
      const t=normalize(row.textContent);
      if(/\+55|celular|telefone/.test(t))row.dataset.ecwaQueueItem='';
    }
  }
  const toolbarId='ecwa-shortcuts-v7';
  function createToolbar(){
    let bar=document.getElementById(toolbarId);
    if(bar)return;
    const markers=[['summary','Resumo'],['monitor','Andamento'],['accounts','Contas'],['recent','Histórico'],['queue','Fila'],['detail','Detalhes']];
    const available=markers.filter(([id])=>document.querySelector(`[data-ecwa-section="${id}"]`));
    if(available.length<3)return;
    bar=document.createElement('nav');bar.id=toolbarId;bar.className='ecwa-toolbar';
    bar.setAttribute('aria-label','Ir para seção da verificação');
    const label=document.createElement('span');label.className='ecwa-toolbar-label';label.textContent='Ir para';bar.append(label);
    for(const [id,text] of available){
      const b=document.createElement('button');b.type='button';b.textContent=text;
      b.addEventListener('click',()=>{
        const sec=document.querySelector(`[data-ecwa-section="${id}"]`);
        if(sec)sec.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
      });
      bar.append(b);
    }
    document.body.append(bar);
  }
  let scheduled=false;
  function apply(){
    scheduled=false;
    if(!location.pathname.includes(PAGE)){
      document.body.classList.remove('ecwa-verify-page');
      document.getElementById(toolbarId)?.remove();
      return;
    }
    const main=document.querySelector('main');
    if(!main)return;
    document.body.classList.add('ecwa-verify-page');
    for(const sec of SECTIONS)markSection(sec.key,sec.labels);
    markMetrics();markQueueRows();createToolbar();
  }
  function schedule(){
    if(scheduled)return;scheduled=true;
    requestAnimationFrame(apply);
  }
  const observer=new MutationObserver(mutations=>{
    // Ignora alterações feitas pelo próprio aprimoramento visual.
    if(mutations.some(m=>m.type==='childList'&&Array.from(m.addedNodes).some(n=>n.nodeType===1 && n.id!==toolbarId)))schedule();
  });
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{schedule();observer.observe(document.body,{subtree:true,childList:true});});
  else{schedule();observer.observe(document.body,{subtree:true,childList:true});}
  document.addEventListener('livewire:navigated',schedule);
  document.addEventListener('livewire:initialized',schedule);
  window.addEventListener('pageshow',schedule);
})();
