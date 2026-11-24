@php
    $brV13 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v);
    $isPointV13 = str_starts_with((string)($priceV13['kind'] ?? ''), 'one_time');
    $principalV13 = $isPointV13 ? ($priceV13['one_time'] ?? null) : ($priceV13['monthly'] ?? null);
    $isReviewV13 = in_array($priceV13['state'] ?? '', ['review','declared_conflict','previous_review','unresolved'],true);
@endphp
<div class="ecp-summary">
    <div class="ecp-value ecp-value-main">
        <span class="ecp-label">{{ $isPointV13 ? 'Valor do serviço · pagamento único' : (!empty($priceV13['prefixed']) ? 'Mensalidade pré-fixada' : 'Mensalidade / valor declarado') }}</span>
        <strong>{{ $principalV13 !== null ? $brV13($principalV13) : (!empty($priceV13['components']) ? 'Por serviço' : 'A conferir') }}@if(!$isPointV13 && $principalV13 !== null)<span class="ecp-sub" style="display:inline">/mês</span>@endif</strong>
        <span class="ecp-sub">{{ $isReviewV13 ? 'Evidência documental · contratação não presumida' : 'Conforme documento original' }}</span>
    </div>
    <div class="ecp-value"><span class="ecp-label">{{ $conditionalImplantation ? 'Implantação condicional' : 'Implantação' }}</span><strong>{{ $implantation !== null ? $brV13($implantation) : 'Não informada' }}</strong><span class="ecp-sub">{{ $conditionalImplantation ? 'Somente se ocorrer a condição' : 'Valor discriminado no documento' }}</span></div>
    <div class="ecp-value"><span class="ecp-label">{{ $isPointV13 ? 'Base total de documentos' : 'Base mensal de documentos' }}</span><strong>{{ isset($finance['base_quantity']) && is_numeric($finance['base_quantity']) ? number_format((float)$finance['base_quantity'],0,',','.') : 'Consultar composição' }}</strong><span class="ecp-sub">{{ count($priceV13['components'] ?? []) > 1 ? 'Volumes independentes por serviço, quando informados' : 'Base do documento' }}</span></div>
</div>
@if(!empty($priceV13['components']))
    <div class="ecp-scroll ecp-spaced"><table class="ecp-table"><thead><tr><th>Serviço</th><th>Modalidade</th><th class="ecp-number">Valor apresentado</th></tr></thead><tbody>
    @foreach($priceV13['components'] as $component)<tr><td>{{ $component['label'] }}</td><td>{{ $component['billing'] === 'monthly' ? 'Mensal' : 'Pontual' }}</td><td class="ecp-number">{{ $brV13($component['amount']) }}</td></tr>@endforeach
    </tbody></table></div>
@endif
