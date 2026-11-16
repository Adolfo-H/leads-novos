{{-- EC_WA_INLINE_REVIEW_V20: revisão no próprio detalhe, sem consultar WhatsApp. --}}
<div class="wa20-inline-review" data-test="wa20-inline-review">
    <style>
        .wa20-inline-review{margin:14px 0 18px;padding:16px;border:1px solid #b6dfd9;border-radius:12px;background:#f7fcfb;color:#0c3545}
        .wa20-inline-review h3{font-size:14px;font-weight:750;margin:0 0 5px}
        .wa20-inline-review p{font-size:12px;color:#5b7b8a;line-height:1.5;margin:0 0 13px}
        .wa20-inline-review label{display:block;color:#164c5b;font-size:12px;font-weight:650;margin:10px 0 5px}
        .wa20-inline-review input,.wa20-inline-review textarea{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;background:#fff;border:1px solid #c7ddd9;color:#16384a;font-size:13px}
        .wa20-inline-review textarea{resize:vertical;min-height:64px}
        .wa20-inline-review .wa20-buttons{display:flex;flex-wrap:wrap;gap:9px;margin-top:12px}
        .wa20-inline-review button{padding:10px 14px;border:1px solid #a8d5ce;border-radius:8px;background:white;color:#087d72;font-size:12px;font-weight:700;cursor:pointer}
        .wa20-inline-review button.wa20-save{background:#008e80;border-color:#008e80;color:#fff}
        .wa20-inline-review button.wa20-invalid{color:#a34b38;border-color:#e7bcb2}
        .wa20-inline-review button:disabled{opacity:.6;cursor:not-allowed}
        .wa20-inline-review .wa20-error{margin:6px 0 0;color:#ad4037;font-size:12px}
    </style>
    <h3>Corrigir este telefone aqui</h3>
    <p>Informe ou confira o número correto e salve. A correção atualiza os vínculos no índice local, sem modificar os cadastros de origem nem confirmar a existência do WhatsApp.</p>
    <label for="wa20-corrected-{{ $selected->id }}">Telefone correto, com DDD</label>
    <input id="wa20-corrected-{{ $selected->id }}" type="tel" wire:model="inlineCorrectedPhone"
        placeholder="Ex.: +55 (41) 99999-0000" maxlength="45" autocomplete="off">
    @error('inlineCorrectedPhone') <p class="wa20-error" role="alert">{{ $message }}</p> @enderror
    <label for="wa20-note-{{ $selected->id }}">Observação (opcional)</label>
    <textarea id="wa20-note-{{ $selected->id }}" wire:model="inlineCorrectionNote" maxlength="300" rows="2"
        placeholder="Ex.: telefone conferido com a empresa ou em fonte atualizada"></textarea>
    <div class="wa20-buttons">
        <button type="button" class="wa20-save" wire:click="saveInlinePhoneCorrection"
            wire:confirm="Você conferiu o telefone correto? Os vínculos desse número serão atualizados no índice local. A origem será preservada."
            wire:loading.attr="disabled" wire:target="saveInlinePhoneCorrection,markInlinePhoneInvalid">
            Salvar correção e atualizar vínculos
        </button>
        <button type="button" class="wa20-invalid" wire:click="markInlinePhoneInvalid"
            wire:confirm="Marcar este formato como inválido para revisão? Nenhum número será inventado."
            wire:loading.attr="disabled" wire:target="saveInlinePhoneCorrection,markInlinePhoneInvalid">
            Marcar formato inválido
        </button>
    </div>
    <p style="margin-top:10px;margin-bottom:0">Se o número sugerido já tiver consulta ou confirmação, o sistema impedirá uma fusão que poderia misturar empresas diferentes.</p>
</div>
