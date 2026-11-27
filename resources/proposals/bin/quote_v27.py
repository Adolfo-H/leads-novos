#!/usr/bin/env python3
"""Extend the unchanged official quote.py; all monetary arithmetic uses Decimal."""
from copy import deepcopy
from decimal import Decimal, ROUND_HALF_UP
import json
import sys

from quote import calculate as legacy_quote, core, SERVICES as LEGACY

NEW = ('DFE', 'NFSE')
ALL = LEGACY + NEW
D = Decimal


def average(total):
    return int((D(total) / 12).quantize(D('1'), rounding=ROUND_HALF_UP))


def effective_tables(data):
    configs = data['prices']
    legacy = {s: configs[s] for s in LEGACY}
    adjusted, audit = core.effective_service_prices(legacy, data['adjustments'], data['price_date'])
    events = core.active_commercial_adjustments(data['adjustments'], data['price_date'])
    for service in NEW:
        if service not in configs:
            continue
        cfg = deepcopy(configs[service])
        for _, _, event, rate in events:
            # The 2025 DFE adjustment is already in the original table. Only later events apply.
            if service == 'DFE' and event['vigencia_inicio'] <= '2025-10-10':
                continue
            for tier in cfg['faixas']:
                for k in ('fixed', 'unit'):
                    tier[k] = str(core.q2(D(str(tier[k])) * (1 + rate / 100)))
        adjusted[service] = cfg
        audit['precos_liquidos_efetivos'][service] = {'faixas': cfg['faixas']}
    rates = {k: D(str(v)) for k, v in data['rates'].items()}
    if set(rates) != {'ISS','PIS','COFINS','IBS','CBS'} or any(not r.is_finite() or r < 0 for r in rates.values()) or sum(rates.values()) >= 100:
        raise ValueError('Perfil tributário inválido')
    return adjusted, audit, rates


def integer(data, key, minimum, maximum):
    value = data.get(key)
    if isinstance(value, bool) or value is None or not str(value).isdigit():
        raise ValueError('Informe '+key)
    n = int(value)
    if not minimum <= n <= maximum:
        raise ValueError(f'{key}: permitido {minimum}–{maximum}')
    return n


def calculate(data):
    effective, audit, rates = effective_tables(data)
    if data.get('mode') == 'tables':
        minimums = {}
        for s, cfg in effective.items():
            net = D(str(cfg['faixas'][0]['fixed']))
            minimums[s] = {'net': str(net), 'final': str(core.gross_up(net, rates)['final'])}
            if s == 'LICENCIAMENTO':
                minimums[s]['implementation'] = str(core.gross_up(core.q2(net*4), rates)['final'])
            elif s == 'RETROATIVAS':
                fee = cfg['implantacao_sem_licenciamento']['valor_liquido']
                minimums[s]['implementation'] = str(core.gross_up(fee, rates)['final'])
        return {'effective': effective, 'adjustment_audit': audit, 'tax_rates': data['rates'], 'minimums':minimums}
    selected = list(dict.fromkeys(data['services']))
    if not selected or any(s not in ALL or s not in effective for s in selected):
        raise ValueError('Serviço sem tabela aprovada na data-base')
    old = [s for s in selected if s in LEGACY]
    result = legacy_quote({**data, 'services':old, 'prices':{s:data['prices'][s] for s in LEGACY}}) if old else {
        'price_date':data['price_date'], 'monthly_average':None, 'monthly_final':'0.00','one_time_final':'0.00','items':{},'tax_rates':{k:str(v) for k,v in rates.items()}}
    monthly = D(result['monthly_final'])
    for service in (s for s in selected if s in NEW):
        prefix = service.lower()
        count = integer(data, prefix+'_cnpj_count', 1, 350 if service=='DFE' else 100000)
        annual = integer(data, prefix+'_total', 0 if service=='DFE' else 1, 100000000)
        avg = average(annual)
        tiers = effective[service]['faixas']
        if service == 'DFE':
            matching = [t for t in tiers if t['min'] <= count <= t['max']]
            if len(matching) != 1 or any(D(str(t['unit'])) != 0 for t in tiers):
                raise ValueError('Faixa DF-e inválida ou sem cobertura; revise tabela')
            net = D(str(matching[0]['fixed']))
            rows = [{**t, 'applied': count if t is matching[0] else 0, 'subtotal':net if t is matching[0] else D('0.00')} for t in tiers]
            qty = count
        else:
            if avg < 1:
                raise ValueError('Média NFS-e abaixo de 1: tabela não define preço para zero; confirme a base')
            net, rows = core.tier_calc(avg, tiers)
            qty = avg
        g = core.gross_up(net, rates)
        result['items'][service] = {'quantity':qty,'quantity_unit':'cnpj' if service=='DFE' else 'nfse_month',
            'annual_total':annual,'cnpj_count':count,'monthly_average':avg,
            'net':str(net),'final':str(g['final']),'monthly':str(g['final']),'one_time':'0.00','implementation':'0.00',
            'implementation_state':'not_priced','tax_total':str(g['tax_total']),'taxes':{k:str(v) for k,v in g['taxes'].items()},
            'tiers':[{**t,'fixed':str(t['fixed']),'unit':str(t['unit']),'subtotal':str(t['subtotal'])} for t in rows]}
        monthly += g['final']
    result['items'] = {s:result['items'][s] for s in selected}
    result['monthly_final'] = str(core.q2(monthly))
    result['adjustment_audit'] = audit
    return result


if __name__ == '__main__':
    try:
        print(json.dumps(calculate(json.load(sys.stdin)), ensure_ascii=False, default=str))
    except Exception as exc:
        print(json.dumps({'error':str(exc)},ensure_ascii=False)); sys.exit(1)
