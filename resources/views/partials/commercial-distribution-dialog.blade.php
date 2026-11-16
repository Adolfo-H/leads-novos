{{-- L05 / V61 — prévia somente leitura; nada é gravado antes da confirmação. --}}
@if ($distributionPreview !== null)
    <section class="ecgm-distribution-preview ecgm61-distribution-preview" aria-labelledby="ecgm-preview-title">
        <header class="ecgm-preview-head ecgm61-panel-heading">
            <div>
                <span class="ecgm-eyebrow">PRÉVIA DA RODADA</span>
                <h3 id="ecgm-preview-title">Como a carteira ficará</h3>
                <p>Prévia de balanceamento · simulação antes de alterar os responsáveis.</p>
            </div>
            <div class="ecgm61-preview-volume">
                <span>Leads previstos</span>
                <strong>{{ $number($distributionPreview['planned']) }}</strong>
                <small>de {{ $number($distributionPreview['available_before']) }} disponíveis</small>
            </div>
        </header>

        <div class="ecgm61-table-wrap" tabindex="0" role="region" aria-label="Prévia da distribuição por vendedor">
            <table class="ecgm61-table ecgm61-distribution-table">
                <thead>
                    <tr>
                        <th scope="col">Vendedor</th>
                        <th scope="col">Carteira atual</th>
                        <th scope="col">A receber</th>
                        <th scope="col">Após distribuição</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($distributionPreview['users'] as $previewUser)
                        <tr wire:key="ecgm61-preview-{{ $previewUser['user_id'] }}">
                            <th scope="row">
                                <span class="ecgm61-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::initials((string) $previewUser['name'], true) }}</span>
                                {{ $previewUser['name'] }}
                            </th>
                            <td>{{ $number($previewUser['starting_load']) }}</td>
                            <td><span class="ecgm61-distribution-gain">+ {{ $number($previewUser['assigned_now']) }}</span></td>
                            <td><strong>{{ $number($previewUser['ending_load']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="ecgm61-preview-stats" aria-label="Resultado estimado da distribuição">
            <span>Diferença atual <strong>{{ $number($distributionPreview['balance_before']['spread']) }}</strong></span>
            <span>Diferença prevista <strong>{{ $number($distributionPreview['balance_after']['spread']) }}</strong></span>
            <span>Restariam sem responsável <strong>{{ $number($distributionPreview['remaining_after']) }}</strong></span>
        </div>
        <p class="ecgm-preview-note ecgm61-safety-note">
            A prévia não grava atribuições. Confirmar revalida elegibilidade e carga, e altera somente o responsável interno do Prospector — não modifica proprietários, negócios ou etapas no HubSpot.
        </p>
    </section>
@endif
