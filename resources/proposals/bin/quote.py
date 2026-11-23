#!/usr/bin/env python3
"""Deterministic pricing adapter for the unmodified official ExportControl generator.
Input/output JSON through stdin/stdout; does not issue documents or assign proposal IDs.
"""
import importlib.util
import json
import sys
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

CORE_PATH = Path(__file__).resolve().parents[1] / 'official' / '20_GERADOR_DOCUMENTOS_EXPORTCONTROL.py'
spec = importlib.util.spec_from_file_location('ec_proposal_official_core', CORE_PATH)
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)

SERVICES = ('LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS')

def calculate(data):
    selected = list(dict.fromkeys(data['services']))
    if not selected or any(code not in SERVICES for code in selected):
        raise ValueError('Serviço sem tabela e modelo oficial aprovado')
    configs = data['prices']
    if set(configs) != set(SERVICES):
        raise ValueError('As três tabelas base são obrigatórias para reajustes cumulativos')
    control = data['adjustments']
    effective, audit = core.effective_service_prices(configs, control, data['price_date'])
    rates = {name: Decimal(str(value)) for name, value in data['rates'].items()}
    if any(rate < 0 for rate in rates.values()) or sum(rates.values()) >= Decimal('100'):
        raise ValueError('Perfil tributário não aprovado ou inválido')
    license_status = str(data.get('license_status') or 'NAO_ATIVO')
    if 'ACOMPANHAMENTO' in selected and 'LICENCIAMENTO' not in selected and license_status != 'ATIVO':
        raise ValueError('Acompanhamento exige Licenciamento ativo ou contratação conjunta')
    if 'RETROATIVAS' in selected and license_status == 'SERA_CONTRATADO_NESTE_PEDIDO' and 'LICENCIAMENTO' not in selected:
        raise ValueError('Não há Licenciamento incluído no pedido')
    recurring = int(data.get('recurring_total') or 0)
    retro = int(data.get('retro_total') or 0)
    monthly_average = None
    if any(code in selected for code in ('LICENCIAMENTO', 'ACOMPANHAMENTO')):
        if recurring < 1:
            raise ValueError('Informe notas dos últimos 12 meses para serviços recorrentes')
        monthly_average = int((Decimal(recurring) / 12).quantize(Decimal('1'), rounding=ROUND_HALF_UP))
    if 'RETROATIVAS' in selected and retro < 1:
        raise ValueError('Informe a quantidade de consultas retroativas do período')
    item_results = {}
    monthly_final = Decimal('0')
    one_time_final = Decimal('0')
    for service in selected:
        quantity = retro if service == 'RETROATIVAS' else monthly_average
        liquid, tiers = core.tier_calc(quantity, effective[service]['faixas'])
        result = core.gross_up(liquid, rates)
        recurring_service = service != 'RETROATIVAS'
        month = result['final'] if recurring_service else Decimal('0')
        once = result['final'] if not recurring_service else Decimal('0')
        implementation = Decimal('0')
        if service == 'LICENCIAMENTO':
            implementation = core.gross_up(core.q2(liquid * Decimal('4')), rates)['final']
        elif service == 'RETROATIVAS' and license_status == 'NAO_ATIVO' and 'LICENCIAMENTO' not in selected:
            implementation = core.gross_up(effective[service]['implantacao_sem_licenciamento']['valor_liquido'], rates)['final']
        monthly_final += month
        one_time_final += once + implementation
        item_results[service] = {
            'quantity': quantity,
            'net': str(liquid), 'final': str(result['final']),
            'monthly': str(month), 'one_time': str(once), 'implementation': str(implementation),
            'tax_total': str(result['tax_total']),
            'tiers': [{'tier': t['tier'], 'min': t['min'], 'max': t['max'],
                       'applied': t['applied'], 'fixed': str(t.get('fixed', 0)),
                       'unit': str(t.get('unit', 0)), 'subtotal': str(t['subtotal'])} for t in tiers],
        }
    return {
        'price_date': data['price_date'],
        'monthly_average': monthly_average,
        'monthly_final': str(core.q2(monthly_final)),
        'one_time_final': str(core.q2(one_time_final)),
        'items': item_results,
        'adjustment_audit': audit,
        'tax_rates': {k: str(v) for k,v in rates.items()},
    }

if __name__ == '__main__':
    try:
        inp = json.loads(sys.stdin.read())
        print(json.dumps(calculate(inp), ensure_ascii=False, default=str))
    except Exception as exc:
        print(json.dumps({'error': str(exc)}, ensure_ascii=False))
        sys.exit(1)
