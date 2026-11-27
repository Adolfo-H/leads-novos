{{-- EC_CAMPAIGN_CANCEL_V25: native accessible dialog, same protected HTTP POST. --}}
<form method="POST" action="{{ route('campaigns.ops-v94.cancel', ['campaign' => $selected->id]) }}" class="ec-v25-cancel-form">
    @csrf
    <button type="button" class="ec68-danger-button" onclick="this.closest('form').querySelector('dialog').showModal()">Cancelar campanha</button>
    <dialog class="ec-v25-cancel-dialog" aria-labelledby="ec-v25-cancel-title-{{ $selected->id }}" aria-describedby="ec-v25-cancel-desc-{{ $selected->id }}">
        <div class="ec-v25-cancel-content">
            <span class="ec-v25-cancel-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg></span>
            <p class="ec-v25-cancel-kicker">CAMPANHAS / CONFIRMAÇÃO</p>
            <h2 id="ec-v25-cancel-title-{{ $selected->id }}">Cancelar esta campanha?</h2>
            <p id="ec-v25-cancel-desc-{{ $selected->id }}">@if($selected->status === 'draft') O rascunho será cancelado. Nenhum novo disparo será iniciado. @else Os envios que ainda não começaram serão cancelados. Tentativas em andamento e e-mails já enviados não podem ser desfeitos. @endif O histórico será preservado.</p>
            <div class="ec-v25-cancel-summary"><small>Campanha #{{ $selected->id }}</small><strong>{{ $selected->name }}</strong></div>
            <div class="ec-v25-cancel-actions">
                <button type="button" autofocus class="ec-v25-cancel-back" onclick="this.closest('dialog').close()">Voltar</button>
                <button type="submit" class="ec-v25-cancel-confirm">Confirmar cancelamento</button>
            </div>
        </div>
    </dialog>
    <noscript><p>Ative o JavaScript para abrir a confirmação de cancelamento.</p></noscript>
</form>
<style>
.ec-v25-cancel-dialog:not([open]){display:none!important}
.ec-v25-cancel-dialog{position:fixed;inset:0;margin:auto;width:min(460px,calc(100% - 32px));max-height:calc(100% - 32px);overflow:auto;border:1px solid #d9e5e8;border-radius:16px;padding:0;background:white;color:#0b3347;box-shadow:0 20px 70px #071f3a44;text-align:left;z-index:1000}
.ec-v25-cancel-dialog::backdrop{background:#081f32a0}
.ec-v25-cancel-content{padding:26px;line-height:1.5}
.ec-v25-cancel-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:12px;background:#fff0ed;color:#ad4038;margin-bottom:15px}
.ec-v25-cancel-kicker{font-size:10px;font-weight:800;letter-spacing:.12em;color:#66828f;margin:0 0 4px}
.ec-v25-cancel-content h2{font-size:23px;font-weight:800;letter-spacing:-.03em;margin:0 0 9px;color:#0b3347}
.ec-v25-cancel-content p:not(.ec-v25-cancel-kicker){font-size:13px;color:#536e78;margin:0 0 16px}
.ec-v25-cancel-summary{border:1px solid #e1e9ed;border-radius:9px;background:#f6f9fa;padding:12px 14px;margin-bottom:20px;overflow-wrap:anywhere}
.ec-v25-cancel-summary small,.ec-v25-cancel-summary strong{display:block}.ec-v25-cancel-summary small{font-size:11px;color:#66828f}.ec-v25-cancel-summary strong{font-size:14px;color:#123b40;margin-top:3px}
.ec-v25-cancel-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:9px}
.ec-v25-cancel-actions button{cursor:pointer;border-radius:8px;padding:11px 14px;font-size:12px;font-weight:750;border:1px solid #cfdfe8;line-height:1.3}
.ec-v25-cancel-back{background:white;color:#365d73}.ec-v25-cancel-confirm{background:#ad4038!important;color:white!important;border-color:#ad4038!important}
.ec-v25-cancel-actions button:focus-visible{outline:2px solid #087c70;outline-offset:2px}
</style>
