from __future__ import annotations

import argparse
import base64
import json
from copy import deepcopy
import re
import shutil
import tempfile
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP, getcontext
from html import escape
from pathlib import Path

getcontext().prec = 40
D = Decimal
CENT = D("0.01")
ROOT = Path(__file__).resolve().parent


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def q2(x):
    return D(str(x)).quantize(CENT, rounding=ROUND_HALF_UP)


def br_num(x, places=2):
    q = D("1") if places == 0 else D("1." + "0" * places)
    s = f"{D(str(x)).quantize(q, rounding=ROUND_HALF_UP):,.{places}f}"
    return s.replace(",", "X").replace(".", ",").replace("X", ".")


def money(x):
    return "R$ " + br_num(x, 2)


def pct(x):
    return br_num(x, 2) + "%"


def int_br(x):
    return f"{int(x):,}".replace(",", ".")


def date_extenso(s):
    d = datetime.strptime(s, "%Y-%m-%d").date()
    meses = [
        "janeiro", "fevereiro", "março", "abril", "maio", "junho",
        "julho", "agosto", "setembro", "outubro", "novembro", "dezembro",
    ]
    return f"{d.day} de {meses[d.month - 1]} de {d.year}"


def data_uri(path: Path):
    if not path.exists():
        raise FileNotFoundError(f"Logo não encontrada: {path}")
    ext = path.suffix.lower()
    mime = "image/png" if ext == ".png" else "image/jpeg"
    return f"data:{mime};base64," + base64.b64encode(path.read_bytes()).decode("ascii")


def active_commercial_adjustments(control, price_date):
    """Return approved events valid for a proposal price date (not contract renewal)."""
    if control.get("status") != "APROVADO":
        raise ValueError("Controle anual de reajustes não aprovado")
    if control.get("abrangencia") != "TODOS_OS_SERVICOS_E_TODAS_AS_FAIXAS":
        raise ValueError("Reajustes devem abranger todos os serviços e faixas")
    if set(control.get("servicos_abrangidos", [])) != {"LICENCIAMENTO", "ACOMPANHAMENTO", "RETROATIVAS"}:
        raise ValueError("Abrangência do reajuste deve incluir os três serviços")
    base_date = datetime.strptime(price_date, "%Y-%m-%d").date()
    events = control.get("reajustes", [])
    if not isinstance(events, list):
        raise ValueError("Lista de reajustes inválida")
    seen = set()
    selected = []
    for idx, event in enumerate(events):
        key = str(event.get("id", "")).strip()
        if not key or key in seen:
            raise ValueError(f"ID de reajuste vazio ou duplicado: {key!r}")
        seen.add(key)
        if event.get("status") != "APROVADO":
            raise ValueError(f"Reajuste sem aprovação: {key}")
        if not str(event.get("motivo", "")).strip():
            raise ValueError(f"Reajuste sem justificativa interna: {key}")
        date = datetime.strptime(event["vigencia_inicio"], "%Y-%m-%d").date()
        rate = D(str(event["percentual"]))
        if not rate.is_finite() or rate <= 0 or rate > D("1000"):
            raise ValueError(f"Percentual inválido em {key}: {rate}")
        if date <= base_date:
            selected.append((date, idx, event, rate))
    selected.sort(key=lambda item: (item[0], item[1]))
    return selected


def effective_service_prices(base_prices, control, price_date):
    """Apply annual changes successively over each previously adjusted net price.

    Price files 06/07/08 are immutable. Each step is rounded in cents for fixed
    and unit fields; thus next year's adjustment uses the actually published
    prior year's effective price, never the unchanged base nor the gross-up.
    """
    required = {"LICENCIAMENTO", "ACOMPANHAMENTO", "RETROATIVAS"}
    if set(base_prices) != required:
        raise ValueError("Controle de reajustes exige as três tabelas de preços")
    prices = deepcopy(base_prices)
    selected = active_commercial_adjustments(control, price_date)
    multiplier = D("1")
    audit_events = []
    for _, _, event, rate in selected:
        factor = D("1") + rate / D("100")
        multiplier *= factor
        for service, price in prices.items():
            for tier in price["faixas"]:
                for key in ("fixed", "unit"):
                    if key in tier:
                        tier[key] = str(q2(D(str(tier[key])) * factor))
            if service == "RETROATIVAS":
                special = price["implantacao_sem_licenciamento"]
                special["valor_liquido"] = str(q2(D(str(special["valor_liquido"])) * factor))
        audit_events.append({
            "id": event["id"],
            "vigencia_inicio": event["vigencia_inicio"],
            "percentual": str(rate),
            "motivo": event["motivo"],
        })
    effective_snapshot = {
        service: {
            "faixas": [{
                "tier": tier["tier"],
                "min": tier["min"],
                "max": tier["max"],
                "fixed": str(tier["fixed"]),
                "unit": str(tier["unit"]),
            } for tier in price["faixas"]],
            **({"implantacao_sem_licenciamento_liquida": str(
                price["implantacao_sem_licenciamento"]["valor_liquido"]
            )} if service == "RETROATIVAS" else {}),
        } for service, price in prices.items()
    }
    return prices, {
        "data_base_precificacao": price_date,
        "versao_controle": control["versao"],
        "modelo": "REAJUSTES_SUCESSIVOS_E_COMPOSTOS_COM_ARREDONDAMENTO_POR_FAIXA",
        "reajustes_aplicados": audit_events,
        "fator_teorico_acumulado": str(multiplier),
        "precos_liquidos_efetivos": effective_snapshot,
        "observacao": "Tabela histórica preservada; cada evento altera os preços fixos e unitários em centavos. Tributos calculados depois do preço líquido.",
    }


def tier_calc(qty, tiers):
    total = D("0")
    rows = []
    for t in tiers:
        lo = int(t["min"])
        hi = int(t["max"]) if t["max"] is not None else None
        applied = max(0, qty - lo + 1)
        if hi is not None:
            applied = min(applied, hi - lo + 1)
        fixed = D(str(t.get("fixed", 0)))
        unit = D(str(t.get("unit", 0)))
        if fixed > 0:
            subtotal = fixed if qty >= lo else D("0")
            applied_show = min(qty, hi) if hi is not None and qty >= lo else (qty if qty >= lo else 0)
        else:
            subtotal = D(applied) * unit
            applied_show = applied
        subtotal = q2(subtotal)
        total += subtotal
        rows.append({**t, "applied": applied_show, "subtotal": subtotal})
    return q2(total), rows


def gross_up(liquid, rates):
    rate_pct = sum(rates.values(), D("0"))
    if any(v < 0 for v in rates.values()) or rate_pct >= 100:
        raise ValueError("Alíquotas inválidas")
    exact_final = D(str(liquid)) / (D("1") - rate_pct / D("100"))
    final = q2(exact_final)
    tax_total = final - D(str(liquid))
    taxes = {k: q2(exact_final * v / D("100")) for k, v in rates.items()}
    diff = tax_total - sum(taxes.values(), D("0"))
    nz = [k for k, v in rates.items() if v > 0]
    if diff and nz:
        taxes[max(nz, key=lambda k: taxes[k])] += diff
    return {"final": final, "tax_total": tax_total, "taxes": taxes, "rate_pct": rate_pct}


def replace_all(text, mapping):
    for k, v in mapping.items():
        text = text.replace(k, str(v))
    return text


def ensure_no_markers(text, name):
    rem = sorted(set(re.findall(r"\{\{[A-Z0-9_]+\}\}", text)))
    if rem:
        raise RuntimeError(f"{name}: marcadores pendentes: {rem}")


def commercial_contact(config, name):
    contacts = config["empresa_fornecedora"]["contatos_exibidos"]
    if name:
        for c in contacts:
            if c["nome"].lower() == name.lower():
                return c
    default = config["empresa_fornecedora"]["responsavel_comercial_padrao"]
    for c in contacts:
        if c["nome"] == default:
            return c
    return contacts[0]


def observations_html(text):
    if not text:
        return ""
    return '<div class="callout"><strong>Observações comerciais:</strong> ' + escape(text).replace("\n", "<br>") + "</div>"


def controls_html(items):
    if not items:
        return ""
    lis = "".join(
        f"<li><strong>{escape(x['razao_social'])}</strong> - CNPJ {escape(x['cnpj'])}</li>"
        for x in items
    )
    return (
        '<div class="controladas-box"><h3>Empresas controladas incluídas</h3>'
        '<p>A parte cliente declara possuir autorização e base legal para fornecer os dados necessários '
        "à execução dos serviços relativos às empresas abaixo:</p><ul>" + lis + "</ul></div>"
    )


def witnesses_html(items):
    if not items:
        return ""
    cells = []
    for idx, w in enumerate(items[:2], 1):
        cells.append(
            f'<div class="witness">{idx}. {escape(w.get("nome", ""))}<br>CPF: {escape(w.get("cpf", ""))}</div>'
        )
    while len(cells) < 2:
        cells.append('<div class="witness">&nbsp;</div>')
    return '<div class="witnesses"><strong>Testemunhas</strong><div class="witness-row">' + "".join(cells) + "</div></div>"


def signature_legal_text(has_witnesses: bool):
    base = (
        "E, por estarem justas e contratadas, as PARTES assinam este instrumento eletronicamente, "
        "reconhecendo a validade, a integridade e a vinculação do documento e de seus anexos, nos termos "
        "da legislação aplicável às assinaturas eletrônicas."
    )
    if has_witnesses:
        base += " As testemunhas abaixo também o assinam para os fins legais cabíveis."
    return base


def revision_token(data):
    value = str(data.get("condicoes", {}).get("revisao", "")).strip().upper()
    value = re.sub(r"[^A-Z0-9_-]", "", value)
    return value


def safe_slug(value: str):
    value = re.sub(r"[^A-Za-z0-9]+", "_", value.upper()).strip("_")
    return value or "CLIENTE"


def document_status(data, default="Documento emitido"):
    cond = data.get("condicoes", {})
    if cond.get("status_documento"):
        return str(cond["status_documento"])
    rev = revision_token(data)
    return f"{rev} - revisão visual" if rev else default


def template_map_common(data, config, logo_client):
    client = data["cliente"]
    cond = data.get("condicoes", {})
    contact = commercial_contact(config, cond.get("responsavel_comercial"))
    issue = data.get("data_emissao") or datetime.now().strftime("%Y-%m-%d")
    return {
        "{{LOGO_EXPORTCONTROL_DATA_URI}}": data_uri(ROOT / "18_LOGO_EXPORTCONTROL.jpg"),
        "{{LOGO_CLIENTE_DATA_URI}}": data_uri(logo_client),
        "{{RAZAO_SOCIAL_CLIENTE}}": escape(client["razao_social"]),
        "{{NOME_CONTATO}}": escape(client["responsavel_principal"]["nome"]),
        "{{EMAIL_CONTATO}}": escape(client["responsavel_principal"]["email"]),
        "{{DATA_EMISSAO_EXTENSO}}": date_extenso(issue),
        "{{DATA_DOCUMENTO_EXTENSO}}": date_extenso(issue),
        "{{VALIDADE_DIAS}}": str(cond.get("validade_dias", config["padroes"]["validade_proposta_dias"])),
        "{{PRAZO_PAGAMENTO_DIAS}}": str(cond.get("pagamento_dias", config["padroes"]["prazo_pagamento_dias_corridos"])),
        "{{RESPONSAVEL_COMERCIAL_NOME}}": escape(contact["nome"]),
        "{{RESPONSAVEL_COMERCIAL_CARGO}}": escape(contact["cargo"]),
        "{{RESPONSAVEL_COMERCIAL_EMAIL}}": escape(contact["emails"][0]),
        "{{OBSERVACOES_COMERCIAIS_HTML}}": observations_html(cond.get("observacoes", "")),
        "{{STATUS_DOCUMENTO}}": escape(document_status(data)),
        "{{STATUS_PROPOSTA}}": escape(revision_token(data) or "EMITIDA"),
    }


def fill_tiers(mapping, prefix, rows):
    for i, r in enumerate(rows, 1):
        vals = {
            "MIN": int_br(r["min"]),
            "MAX": "Sem limite" if r["max"] is None else int_br(r["max"]),
            "FIXO_LIQUIDO": money(r.get("fixed", 0)),
            "UNIT_LIQUIDO": money(r.get("unit", 0)),
            "QTD_APLICADA": int_br(r["applied"]),
            "SUBTOTAL_LIQUIDO": money(r["subtotal"]),
        }
        for k, v in vals.items():
            mapping[f"{{{{{prefix}_F{i}_{k}}}}}"] = v


def _wait_images(page):
    page.wait_for_function("Array.from(document.images).every(img => img.complete)", timeout=10000)


def _overflow_report(page):
    return page.eval_on_selector_all(
        ".proposal-page",
        "els => els.map((e,i)=>({index:i+1, cls:e.className, scroll:e.scrollHeight, client:e.clientHeight, overflow:e.scrollHeight-e.clientHeight}))",
    )


def render_html(html_path: Path, pdf_path: Path, kind="proposal", doc_id=""):
    from playwright.sync_api import sync_playwright

    chromium = shutil.which("chromium") or shutil.which("chromium-browser") or shutil.which("google-chrome")
    if not chromium:
        raise RuntimeError("Chromium não encontrado no ambiente")
    html_content = html_path.read_text(encoding="utf-8")
    pdf_path.parent.mkdir(parents=True, exist_ok=True)
    pdf_path.unlink(missing_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=chromium)
        if kind in ("proposal", "portfolio"):
            page = browser.new_page(viewport={"width": 1240, "height": 1754}, device_scale_factor=1)
            page.set_content(html_content, wait_until="load")
            _wait_images(page)
            report = _overflow_report(page)
            bad = [x for x in report if x["overflow"] > 2]
            if bad:
                browser.close()
                raise RuntimeError(f"{html_path.name}: overflow em páginas fixas: {bad}")
            page.pdf(
                path=str(pdf_path),
                print_background=True,
                prefer_css_page_size=True,
                margin={"top": "0", "right": "0", "bottom": "0", "left": "0"},
            )
            browser.close()
            return report

        tmp = Path(tempfile.mkdtemp(prefix="ec_contract_"))
        cover_pdf = tmp / "cover.pdf"
        body_pdf = tmp / "body.pdf"

        page = browser.new_page(viewport={"width": 1240, "height": 1754}, device_scale_factor=1)
        page.set_content(html_content, wait_until="load")
        _wait_images(page)
        page.add_style_tag(content="#document-content{display:none!important} body{background:#fff!important}")
        page.pdf(
            path=str(cover_pdf),
            print_background=True,
            prefer_css_page_size=True,
            margin={"top": "0", "right": "0", "bottom": "0", "left": "0"},
        )
        page.close()

        page = browser.new_page(viewport={"width": 1240, "height": 1754}, device_scale_factor=1)
        page.set_content(html_content, wait_until="load")
        _wait_images(page)
        page.add_style_tag(
            content=(
                "#document-cover{display:none!important} body{background:#fff!important;margin:0!important;} "
                "#document-content{display:block!important} "
                "@page{size:A4;margin:24mm 16mm 18mm 16mm!important}"
            )
        )
        header = (
            '<div style="width:100%;font-family:Arial;font-size:8px;color:#6e797a;padding:0 16mm;'
            'border-bottom:1px solid #d7e3e1;display:flex;justify-content:space-between">'
            '<span><b style="color:#47464b">EXPORT</b><span style="color:#889898">CONTROL</span></span>'
            f"<span>{escape(doc_id)}</span></div>"
        )
        footer = (
            '<div style="width:100%;font-family:Arial;font-size:8px;color:#6e797a;padding:0 16mm;'
            'border-top:1px solid #d7e3e1;display:flex;justify-content:space-between">'
            '<span>exportcontrol.com.br</span><span>Página <span class="pageNumber"></span> de '
            '<span class="totalPages"></span></span></div>'
        )
        page.pdf(
            path=str(body_pdf),
            format="A4",
            print_background=True,
            display_header_footer=True,
            header_template=header,
            footer_template=footer,
            margin={"top": "0", "right": "0", "bottom": "0", "left": "0"},
        )
        browser.close()

    merge_pdfs([cover_pdf, body_pdf], pdf_path)
    shutil.rmtree(tmp, ignore_errors=True)
    return []


def merge_pdfs(paths, output):
    import fitz

    output = Path(output)
    output.unlink(missing_ok=True)
    out = fitz.open()
    for p in paths:
        src = fitz.open(p)
        out.insert_pdf(src)
        src.close()
    out.save(output)
    out.close()


def proposal_id(service, year, number, rev=""):
    suffix = {
        "LICENCIAMENTO": "LICENCIAMENTO",
        "ACOMPANHAMENTO": "ACOMP",
        "RETROATIVAS": "RETROATIVAS",
    }[service]
    base = f"PROPOSTA_COMERCIAL_EC_{year}_{number}_{suffix}"
    return f"{base}_{rev}" if rev else base


def contract_id(service, year, number, rev=""):
    suffix = {
        "LICENCIAMENTO": "LICENCIAMENTO",
        "ACOMPANHAMENTO": "ACOMP",
        "RETROATIVAS": "RETROATIVAS",
    }[service]
    base = f"CONTRATO_EC_{year}_{number}_{suffix}"
    return f"{base}_{rev}" if rev else base


def _extract_proposal_sections(html, anchor):
    from bs4 import BeautifulSoup

    soup = BeautifulSoup(html, "html.parser")
    sections = soup.select("section.cover-page, section.proposal-page")
    if not sections:
        raise RuntimeError("Proposta sem páginas para consolidação")
    sections[0]["id"] = anchor
    for s in sections[1:]:
        s.attrs.pop("id", None)
    return "\n".join(str(s) for s in sections)


def create_proposals(data, outdir: Path):
    config = load_json(ROOT / "03_CONFIGURACAO_GERAL_AGENTE.json")
    tax = load_json(ROOT / "05_TRIBUTOS_ATUAIS.json")
    base_prices = {s: load_json(ROOT / f) for s, f in config["arquivos"]["precos"].items()}
    for s, cfg in base_prices.items():
        if cfg.get("status") != "APROVADO":
            raise RuntimeError(f"Tabela não aprovada: {s}")
    rates = {k: D(str(v)) for k, v in tax["aliquotas_percentuais"].items()}
    issue = data.get("data_emissao") or datetime.now().strftime("%Y-%m-%d")
    price_date = data.get("data_base_precificacao") or issue
    control_filename = config["arquivos"]["reajustes_anuais"]
    adjustments = load_json(ROOT / control_filename)
    prices, adjustment_audit = effective_service_prices(base_prices, adjustments, price_date)
    year = int(issue[:4])
    services = data["servicos"]
    nums = data["numeros"]
    rev = revision_token(data)
    client_logo = Path(data["cliente"]["logo"])
    common = template_map_common(data, config, client_logo)
    rate_total = sum(rates.values(), D("0"))
    common["{{ALIQUOTA_TOTAL}}"] = pct(rate_total)
    for k, v in rates.items():
        common[f"{{{{{k}_ALIQUOTA}}}}"] = pct(v)

    record = {
        "versao_gerador": "5.2-REAJUSTES-CUMULATIVOS",
        "revisao": rev,
        "data_emissao": issue,
        "controle_reajustes": adjustment_audit,
        "cliente": data["cliente"],
        "servicos": {},
        "pdfs": {},
        "htmls": {},
        "fontes": {
            "config": config["versao"],
            "tributos": tax["versao"],
            "precos_base": {s: base_prices[s]["versao"] for s in services},
            "precos": {s: prices[s]["versao"] for s in services},
            "controle_reajustes": adjustment_audit["versao_controle"],
        },
    }

    recurring = data.get("base_recorrente", {})
    qty = avg = None
    exact = None
    if any(s in services for s in ["LICENCIAMENTO", "ACOMPANHAMENTO"]):
        qty = int(recurring["quantidade_total_12_meses"])
        exact = D(qty) / D("12")
        avg = int(exact.quantize(D("1"), rounding=ROUND_HALF_UP))
        common.update(
            {
                "{{QTDE_NOTAS_ANUAL}}": int_br(qty),
                "{{PERIODO_REFERENCIA_INICIO}}": recurring["inicio"],
                "{{PERIODO_REFERENCIA_FIM}}": recurring["fim"],
                "{{MEDIA_MENSAL_EXATA}}": br_num(exact, 2),
                "{{MEDIA_MENSAL_NOTAS}}": int_br(avg),
            }
        )

    work = outdir / "HTML_FONTES"
    work.mkdir(parents=True, exist_ok=True)
    proposal_html_strings = {}
    proposal_paths = []

    for service in services:
        number = int(nums[service])
        pid = proposal_id(service, year, number, rev)
        m = dict(common)
        if service == "LICENCIAMENTO":
            liq, rows = tier_calc(avg, prices[service]["faixas"])
            impl = q2(liq * D("4"))
            g = gross_up(liq, rates)
            gi = gross_up(impl, rates)
            m.update(
                {
                    "{{ID_PROPOSTA_LICENCIAMENTO}}": pid,
                    "{{NUMERO_PROPOSTA_LICENCIAMENTO}}": str(number),
                    "{{NUMERO_DOCUMENTO}}": str(number),
                    "{{ID_DOCUMENTO}}": pid,
                    "{{VALOR_MENSAL_LIQUIDO}}": money(liq),
                    "{{VALOR_IMPLANTACAO_LIQUIDO}}": money(impl),
                    "{{VALOR_MENSAL_FINAL}}": money(g["final"]),
                    "{{VALOR_IMPLANTACAO_FINAL}}": money(gi["final"]),
                    "{{VALOR_TRIBUTOS_MENSAL}}": money(g["tax_total"]),
                    "{{VALOR_TRIBUTOS_IMPLANTACAO}}": money(gi["tax_total"]),
                }
            )
            for k in rates:
                m[f"{{{{{k}_VALOR_MENSAL}}}}"] = money(g["taxes"][k])
                m[f"{{{{{k}_VALOR_IMPLANTACAO}}}}"] = money(gi["taxes"][k])
            fill_tiers(m, "LIC", rows)
            template = "10_MODELO_PROPOSTA_LICENCIAMENTO.html"
            calc = {
                "numero": number,
                "id": pid,
                "quantidade_12_meses": qty,
                "periodo_inicio": recurring["inicio"],
                "periodo_fim": recurring["fim"],
                "media_exata": str(exact),
                "media_arredondada": avg,
                "valor_mensal_liquido": str(liq),
                "valor_mensal_final": str(g["final"]),
                "implantacao_liquida": str(impl),
                "implantacao_final": str(gi["final"]),
            }
        elif service == "ACOMPANHAMENTO":
            liq, rows = tier_calc(avg, prices[service]["faixas"])
            g = gross_up(liq, rates)
            lic_id = (
                proposal_id("LICENCIAMENTO", year, int(nums["LICENCIAMENTO"]), rev)
                if "LICENCIAMENTO" in nums
                else "LICENCIAMENTO ATIVO - CONFIRMADO"
            )
            m.update(
                {
                    "{{ID_PROPOSTA_ACOMPANHAMENTO}}": pid,
                    "{{NUMERO_PROPOSTA_ACOMPANHAMENTO}}": str(number),
                    "{{NUMERO_DOCUMENTO}}": str(number),
                    "{{ID_DOCUMENTO}}": pid,
                    "{{ID_PROPOSTA_LICENCIAMENTO_RELACIONADA}}": lic_id,
                    "{{VALOR_MENSAL_LIQUIDO}}": money(liq),
                    "{{VALOR_MENSAL_FINAL}}": money(g["final"]),
                    "{{VALOR_TRIBUTOS_MENSAL}}": money(g["tax_total"]),
                }
            )
            for k in rates:
                m[f"{{{{{k}_VALOR_MENSAL}}}}"] = money(g["taxes"][k])
            fill_tiers(m, "AC", rows)
            template = "12_MODELO_PROPOSTA_ACOMPANHAMENTO.html"
            calc = {
                "numero": number,
                "id": pid,
                "quantidade_12_meses": qty,
                "periodo_inicio": recurring["inicio"],
                "periodo_fim": recurring["fim"],
                "media_exata": str(exact),
                "media_arredondada": avg,
                "valor_mensal_liquido": str(liq),
                "valor_mensal_final": str(g["final"]),
                "licenciamento_relacionado": lic_id,
            }
        else:
            r = data["retroativas"]
            qtyr = int(r["quantidade_total"])
            liq, rows = tier_calc(qtyr, prices[service]["faixas"])
            g = gross_up(liq, rates)
            status = r["licenciamento_status"]
            block = ""
            impl_liq = D("0")
            impl_final = D("0")
            if status == "NAO_ATIVO":
                impl_liq = D(str(prices[service]["implantacao_sem_licenciamento"]["valor_liquido"]))
                gi = gross_up(impl_liq, rates)
                impl_final = gi["final"]
                block = (
                    '<div class="callout warn"><strong>Implantação específica sem Licenciamento:</strong> '
                    f"valor líquido {money(impl_liq)}; valor final {money(impl_final)}. "
                    "Este valor não se aplica quando o Licenciamento estiver ativo ou for contratado no mesmo pedido.</div>"
                )
            status_label = {
                "ATIVO": "Ativo",
                "NAO_ATIVO": "Não ativo",
                "SERA_CONTRATADO_NESTE_PEDIDO": "Contratado neste mesmo pedido",
            }[status]
            m.update(
                {
                    "{{ID_PROPOSTA_RETROATIVAS}}": pid,
                    "{{NUMERO_PROPOSTA_RETROATIVAS}}": str(number),
                    "{{NUMERO_DOCUMENTO}}": str(number),
                    "{{ID_DOCUMENTO}}": pid,
                    "{{QTDE_NOTAS_RETRO}}": int_br(qtyr),
                    "{{PERIODO_RETRO_INICIO}}": r["inicio"],
                    "{{PERIODO_RETRO_FIM}}": r["fim"],
                    "{{PRAZO_RETRO_TEXTO}}": r.get("prazo") or "A confirmar após validação da base",
                    "{{STATUS_LICENCIAMENTO_RETRO}}": status_label,
                    "{{VALOR_TOTAL_RETRO_LIQUIDO}}": money(liq),
                    "{{VALOR_TOTAL_RETRO_FINAL}}": money(g["final"]),
                    "{{VALOR_TRIBUTOS_RETRO}}": money(g["tax_total"]),
                    "{{BLOCO_IMPLANTACAO_RETRO_SEM_LICENCIAMENTO}}": block,
                }
            )
            for k in rates:
                m[f"{{{{{k}_VALOR_RETRO}}}}"] = money(g["taxes"][k])
            fill_tiers(m, "RET", rows)
            template = "14_MODELO_PROPOSTA_RETROATIVAS.html"
            calc = {
                "numero": number,
                "id": pid,
                "quantidade_total": qtyr,
                "periodo_inicio": r["inicio"],
                "periodo_fim": r["fim"],
                "valor_liquido": str(liq),
                "valor_final": str(g["final"]),
                "implantacao_liquida": str(impl_liq),
                "implantacao_final": str(impl_final),
                "licenciamento_status": status_label,
                "prazo": m["{{PRAZO_RETRO_TEXTO}}"],
            }

        html = replace_all((ROOT / template).read_text(encoding="utf-8"), m)
        ensure_no_markers(html, template)
        html_path = work / f"{pid}.html"
        html_path.write_text(html, encoding="utf-8")
        pdf_path = outdir / f"{pid}.pdf"
        overflow = render_html(html_path, pdf_path, "proposal", pid)
        proposal_paths.append(pdf_path)
        proposal_html_strings[service] = html
        record["servicos"][service] = calc
        record["pdfs"][service] = str(pdf_path)
        record["htmls"][service] = str(html_path)
        record["servicos"][service]["qa_overflow"] = overflow

    if services:
        cards = []
        for s in services:
            c = record["servicos"][s]
            label = {
                "LICENCIAMENTO": "Licenciamento",
                "ACOMPANHAMENTO": "Acompanhamento",
                "RETROATIVAS": "Consultas Retroativas",
            }[s]
            if s == "LICENCIAMENTO":
                value = money(c["valor_mensal_final"])
                sub = f"Mensal · implantação {money(c['implantacao_final'])} · proposta nº {c['numero']}"
            elif s == "ACOMPANHAMENTO":
                value = money(c["valor_mensal_final"])
                sub = f"Mensal · proposta nº {c['numero']}"
            else:
                value = money(c["valor_final"])
                sub = f"Valor total · proposta nº {c['numero']}"
            cards.append(
                f'<div class="metric highlight"><div class="label">{label}</div>'
                f'<div class="value">{value}</div><div class="sub">{sub}</div></div>'
            )

        full_sections = []
        anchors = {
            "LICENCIAMENTO": "licenciamento",
            "ACOMPANHAMENTO": "acompanhamento",
            "RETROATIVAS": "retroativas",
        }
        for s in services:
            full_sections.append(_extract_proposal_sections(proposal_html_strings[s], anchors[s]))

        title = f"APRESENTACAO_COMERCIAL_{safe_slug(data['cliente']['razao_social'])}_{rev or 'EMITIDA'}"
        mp = dict(common)
        mp.update(
            {
                "{{TITULO_APRESENTACAO}}": title,
                "{{LISTA_NUMEROS_PROPOSTAS}}": ", ".join(str(record["servicos"][s]["numero"]) for s in services),
                "{{NUMERO_DOCUMENTO}}": ", ".join(str(record["servicos"][s]["numero"]) for s in services),
                "{{ID_DOCUMENTO}}": title,
                "{{CARDS_SERVICOS_HTML}}": "".join(cards),
                "{{PROPOSTAS_COMPLETAS_HTML}}": "\n".join(full_sections),
            }
        )
        presentation_html = replace_all((ROOT / "16_MODELO_APRESENTACAO_PROPOSTAS.html").read_text(encoding="utf-8"), mp)
        ensure_no_markers(presentation_html, "16_MODELO_APRESENTACAO_PROPOSTAS.html")
        hp = work / f"{title}.html"
        hp.write_text(presentation_html, encoding="utf-8")
        record["html_apresentacao"] = str(hp)
        if len(services) >= 2:
            consolidated = outdir / f"{title}.pdf"
            render_html(hp, consolidated, "portfolio", title)
            record["pdf_consolidado"] = str(consolidated)

    audit = outdir / "REGISTRO_OPORTUNIDADE_E_CALCULOS_REV1.json"
    audit.write_text(json.dumps(record, ensure_ascii=False, indent=2), encoding="utf-8")
    return record


def create_contracts(data, outdir: Path):
    config = load_json(ROOT / "03_CONFIGURACAO_GERAL_AGENTE.json")
    record = load_json(Path(data["registro_propostas"]))
    services = data["aceites"]
    client = data["cliente"]
    issue = record["data_emissao"]
    year = int(issue[:4])
    rev = str(record.get("revisao", ""))
    logo_client = Path(client["logo"])
    sign_date = data.get("data_assinatura") or issue
    accept_date = data.get("data_aceite") or issue

    common_data = {
        "cliente": client,
        "data_emissao": sign_date,
        "condicoes": {
            **data.get("condicoes", {}),
            "revisao": rev,
            "status_documento": f"{rev} - documento para assinatura" if rev else "Documento para assinatura",
        },
    }
    common = template_map_common(common_data, config, logo_client)
    witnesses = client.get("testemunhas", [])
    common.update(
        {
            "{{DATA_ASSINATURA_EXTENSO}}": date_extenso(sign_date),
            "{{DATA_ACEITE_EXTENSO}}": date_extenso(accept_date),
            "{{DATA_INICIO_EXTENSO}}": date_extenso(sign_date),
            "{{NOME_FANTASIA_CLIENTE}}": escape(client.get("nome_fantasia", "")),
            "{{CNPJ_CLIENTE}}": escape(client.get("cnpj", "")),
            "{{ENDERECO_CLIENTE}}": escape(client.get("endereco_completo", "")),
            "{{CIDADE_UF_CLIENTE}}": escape(client.get("cidade_uf", "")),
            "{{REPRESENTANTE_LEGAL_NOME}}": escape(client.get("representante_legal", {}).get("nome", "")),
            "{{REPRESENTANTE_LEGAL_CPF}}": escape(client.get("representante_legal", {}).get("cpf", "")),
            "{{REPRESENTANTE_LEGAL_CARGO}}": escape(client.get("representante_legal", {}).get("cargo", "")),
            "{{EMAIL_FATURAMENTO_NOTIFICACOES}}": escape(client.get("email_faturamento_notificacoes", "")),
            "{{BLOCO_CONTROLADAS_HTML}}": controls_html(client.get("controladas", [])),
            "{{BLOCO_TESTEMUNHAS_HTML}}": witnesses_html(witnesses),
            "{{TEXTO_ASSINATURA_ELETRONICA}}": signature_legal_text(bool(witnesses)),
        }
    )

    work = outdir / "HTML_FONTES"
    work.mkdir(parents=True, exist_ok=True)
    pdfs = {}
    htmls = {}

    for service in services:
        calc = record["servicos"][service]
        number = int(calc["numero"])
        cid = contract_id(service, year, number, rev)
        m = dict(common)
        m.update({"{{ID_CONTRATO}}": cid, "{{NUMERO_DOCUMENTO}}": str(number), "{{ID_DOCUMENTO}}": cid})
        if service == "LICENCIAMENTO":
            m.update(
                {
                    "{{ID_PROPOSTA_LICENCIAMENTO}}": calc["id"],
                    "{{NUMERO_PROPOSTA_LICENCIAMENTO}}": str(number),
                    "{{VALOR_IMPLANTACAO_FINAL}}": money(calc["implantacao_final"]),
                    "{{VALOR_MENSAL_FINAL}}": money(calc["valor_mensal_final"]),
                    "{{QTDE_NOTAS_ANUAL}}": int_br(calc["quantidade_12_meses"]),
                    "{{MEDIA_MENSAL_NOTAS}}": int_br(calc["media_arredondada"]),
                    "{{PERIODO_REFERENCIA_INICIO}}": calc["periodo_inicio"],
                    "{{PERIODO_REFERENCIA_FIM}}": calc["periodo_fim"],
                }
            )
            template = "11_MODELO_CONTRATO_LICENCIAMENTO.html"
        elif service == "ACOMPANHAMENTO":
            m.update(
                {
                    "{{ID_PROPOSTA_ACOMPANHAMENTO}}": calc["id"],
                    "{{NUMERO_PROPOSTA_ACOMPANHAMENTO}}": str(number),
                    "{{ID_PROPOSTA_LICENCIAMENTO_RELACIONADA}}": calc["licenciamento_relacionado"],
                    "{{VALOR_MENSAL_FINAL}}": money(calc["valor_mensal_final"]),
                    "{{QTDE_NOTAS_ANUAL}}": int_br(calc["quantidade_12_meses"]),
                    "{{MEDIA_MENSAL_NOTAS}}": int_br(calc["media_arredondada"]),
                    "{{PERIODO_REFERENCIA_INICIO}}": calc["periodo_inicio"],
                    "{{PERIODO_REFERENCIA_FIM}}": calc["periodo_fim"],
                }
            )
            template = "13_MODELO_CONTRATO_ACOMPANHAMENTO.html"
        else:
            impl_final = D(str(calc.get("implantacao_final", "0")))
            if impl_final > 0:
                impl_clause = (
                    '<p class="clause"><span class="clause-no">6.3.1.</span><span class="clause-text">'
                    f"Além do valor do serviço, será devido o adicional final de implantação específica de {money(impl_final)}, "
                    f"conforme a {calc['id']}.</span></p>"
                )
            else:
                impl_clause = ""
            m.update(
                {
                    "{{ID_PROPOSTA_RETROATIVAS}}": calc["id"],
                    "{{NUMERO_PROPOSTA_RETROATIVAS}}": str(number),
                    "{{VALOR_TOTAL_RETRO_FINAL}}": money(calc["valor_final"]),
                    "{{QTDE_NOTAS_RETRO}}": int_br(calc["quantidade_total"]),
                    "{{PERIODO_RETRO_INICIO}}": calc["periodo_inicio"],
                    "{{PERIODO_RETRO_FIM}}": calc["periodo_fim"],
                    "{{PRAZO_RETRO_TEXTO}}": calc["prazo"],
                    "{{STATUS_LICENCIAMENTO_RETRO}}": calc["licenciamento_status"],
                    "{{TIPO_CERTIFICADO_RETRO}}": escape(
                        data.get("retroativas", {}).get("tipo_certificado", "certificado digital e-CNPJ tipo A1")
                    ),
                    "{{BLOCO_IMPLANTACAO_CONTRATO_RETRO}}": impl_clause,
                }
            )
            template = "15_MODELO_CONTRATO_RETROATIVAS.html"

        html = replace_all((ROOT / template).read_text(encoding="utf-8"), m)
        ensure_no_markers(html, template)
        hp = work / f"{cid}.html"
        hp.write_text(html, encoding="utf-8")
        base_pdf = outdir / f"_{cid}_SEM_ANEXO.pdf"
        render_html(hp, base_pdf, "contract", cid)
        proposal_pdf = Path(record["pdfs"][service])
        final = outdir / f"{cid}.pdf"
        merge_pdfs([base_pdf, proposal_pdf], final)
        base_pdf.unlink(missing_ok=True)
        pdfs[service] = str(final)
        htmls[service] = str(hp)

    contract_record = {
        "versao_gerador": "5.2-REAJUSTES-CUMULATIVOS",
        "revisao": rev,
        "data_aceite": accept_date,
        "data_assinatura": sign_date,
        "aceites": services,
        "pdfs": pdfs,
        "htmls": htmls,
        "registro_propostas": data["registro_propostas"],
    }
    (outdir / "REGISTRO_CONTRATOS_REV1.json").write_text(
        json.dumps(contract_record, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    return contract_record


def clean_output(path: Path):
    if path.exists():
        shutil.rmtree(path)
    path.mkdir(parents=True, exist_ok=True)


def main():
    ap = argparse.ArgumentParser(description="Gerador determinístico ExportControl V5.2 - reajustes comerciais cumulativos.")
    ap.add_argument("--input", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    ap.add_argument("--no-clean", action="store_true")
    args = ap.parse_args()
    data = load_json(args.input)
    if not args.no_clean:
        clean_output(args.out)
    else:
        args.out.mkdir(parents=True, exist_ok=True)
    if data["solicitacao"] == "GERAR_PROPOSTA":
        result = create_proposals(data, args.out)
    elif data["solicitacao"] == "GERAR_CONTRATO":
        result = create_contracts(data, args.out)
    else:
        raise ValueError("solicitacao inválida")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
