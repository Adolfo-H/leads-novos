{{-- EC_CAMPAIGN_UI_V25: the five placeholders actually supported by CampaignContent. --}}
@php
    $ecV25Variables = [
        'saudacao' => ['Saudação automática', 'Olá, Ana!', 'Olá!'],
        'primeiro_nome' => ['Primeiro nome', 'Ana', 'Olá'],
        'nome' => ['Nome completo', 'Ana Silva', 'Contato'],
        'empresa' => ['Empresa', 'Cerealista Exemplo', 'sua empresa'],
        'cargo' => ['Cargo', 'Gerente fiscal', 'profissional'],
    ];
@endphp
<div data-ec-v25-variables style="padding:16px;border:1px solid #dbe7e9;border-radius:12px;background:#f8fbfb;margin:10px 0 16px;color:#173c43">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px">
        <strong style="font-size:14px;color:#123b40">Personalize sua mensagem</strong>
        <small style="font-size:11px;color:#51727b">5 variáveis disponíveis · assunto e mensagem</small>
    </div>
    <p style="font-size:12px;color:#51727b;margin:0 0 12px">Selecione o assunto ou a mensagem e clique em uma variável para inseri-la. O sistema substitui os marcadores por dados do contato durante o envio.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:8px">
        @foreach ($ecV25Variables as $ecV25Key => $ecV25Info)
            <button type="button" data-ec-v25-token="{{ '{'.'{'.$ecV25Key.'}'.'}' }}"
                onclick="const f=this.closest('form');if(!f)return;const t=f.querySelector(f.dataset.ecV25Field==='subject'?'input[name=subject]':'textarea[name=body]');if(!t)return;const a=t.selectionStart??t.value.length,b=t.selectionEnd??a,s=this.dataset.ecV25Token;if(t.maxLength>=0&&t.value.length-b+a+s.length>t.maxLength){t.focus();return;}t.setRangeText(s,a,b,'end');t.dispatchEvent(new Event('input',{bubbles:true}));t.focus();"
                style="text-align:left;display:block;min-width:0;padding:10px 12px;border:1px solid #cce3df;border-radius:9px;background:white;color:#123b40;cursor:pointer;line-height:1.45;font:inherit"
                title="Inserir {{ $ecV25Info[0] }}">
                <code style="display:block;font-weight:700;font-size:12px;color:#087c70;overflow-wrap:anywhere">{{ '{'.'{'.$ecV25Key.'}'.'}' }}</code>
                <strong style="display:block;font-size:12px;margin-top:3px">{{ $ecV25Info[0] }}</strong>
                <small style="display:block;font-size:11px;color:#51727b;overflow-wrap:anywhere">Ex.: {{ $ecV25Info[1] }} · sem dado: {{ $ecV25Info[2] }}</small>
            </button>
        @endforeach
    </div>
    <p style="margin:12px 0 0;font-size:11px;color:#51727b">Dica: use <code>&#123;&#123;saudacao&#125;&#125;</code> sozinho no início para não repetir “Olá”.</p>
</div>
