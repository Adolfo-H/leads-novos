(()=>{
    function init(){
        document.querySelectorAll('[data-ec-import-upload]').forEach(form=>{
            if(form.dataset.ready) return;
            form.dataset.ready='1';
            const file=form.querySelector('input[type=file]');
            const paste=form.querySelector('textarea[name=paste]');
            const buttons=[...form.querySelectorAll('[data-import-format]')];
            const fileArea=form.querySelector('[data-import-file-area]');
            const pasteArea=form.querySelector('[data-import-paste-area]');
            function choose(key){
                buttons.forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.importFormat===key)));
                const text=key==='paste';
                fileArea.hidden=text; pasteArea.hidden=!text;
                file.disabled=text; paste.disabled=!text;
                file.accept={sheet:'.xlsx,.xls,.csv',word:'.docx,.doc',pdf:'.pdf'}[key]||'.txt';
            }
            buttons.forEach(b=>b.addEventListener('click',()=>choose(b.dataset.importFormat)));
            // Progressive enhancement: without JS both inputs remain available.
            choose('sheet');
        });
        document.querySelectorAll('[data-ec-import-review]').forEach(form=>{
            if(form.dataset.ready) return;
            form.dataset.ready='1';
            const checks=[...form.querySelectorAll('[data-import-select]')].filter(check=>!check.disabled);
            const master=form.querySelector('[data-import-select-all]');
            const counter=form.querySelector('[data-import-selected-count]');
            function update(){
                checks.forEach(check=>{
                    const proof=check.closest('tr').querySelector('[data-import-proof]');
                    if(proof){proof.required=check.checked; proof.disabled=!check.checked;}
                });
                const count=checks.filter(check=>check.checked).length;
                if(counter) counter.textContent=count+' de '+checks.length+' marcados';
                if(master){master.disabled=checks.length===0;master.checked=checks.length>0&&count===checks.length;master.indeterminate=count>0&&count<checks.length;}
            }
            checks.forEach(check=>check.addEventListener('change',update));
            if(master) master.addEventListener('change',()=>{
                checks.forEach(check=>{check.checked=master.checked;});
                update();
            });
            update();
        });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
    else init();
    document.addEventListener('livewire:navigated',init);
})();
