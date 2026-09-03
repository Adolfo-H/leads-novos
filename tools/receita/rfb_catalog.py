import os
import re
from urllib.parse import unquote
from xml.etree import ElementTree as ET

import requests


WEBDAV_BASE = os.environ.get(
    "RFB_WEBDAV_BASE",
    "https://arquivos.receitafederal.gov.br/public.php/webdav",
)

CNPJ_PATH = os.environ.get(
    "RFB_CNPJ_PATH",
    "Dados/Cadastros/CNPJ",
)

SHARE_TOKEN = os.environ.get(
    "RFB_SHARE_TOKEN",
    "gn672Ad4CF8N6TK",
)

DAV_NAMESPACE = {
    "d": "DAV:",
}

PERIOD_PATTERN = re.compile(
    r"/(\d{4}-\d{2})/?$"
)


class RfbCatalogError(RuntimeError):
    pass


class RfbCatalogClient:
    def __init__(self) -> None:
        self.session = requests.Session()

        self.session.auth = (
            SHARE_TOKEN,
            "",
        )

        self.session.headers.update(
            {
                "User-Agent":
                    "ProspectorExportControl/1.0",
            }
        )

    def latest_period(self) -> str:
        periods = self.periods()

        if not periods:
            raise RfbCatalogError(
                "Nenhum período YYYY-MM foi encontrado na base da Receita."
            )

        return max(periods)

    def periods(self) -> list[str]:
        periods = []

        for entry in self._propfind(
            CNPJ_PATH
        ):
            href = unquote(
                entry["href"]
            )

            match = PERIOD_PATTERN.search(
                href
            )

            if match:
                periods.append(
                    match.group(1)
                )

        return sorted(
            set(periods)
        )

    def files(
        self,
        period: str,
    ) -> list[dict]:
        if not re.fullmatch(
            r"\d{4}-\d{2}",
            period,
        ):
            raise ValueError(
                "O período deve estar no formato YYYY-MM."
            )

        files = []

        path = (
            f"{CNPJ_PATH}/{period}"
        )

        for entry in self._propfind(
            path
        ):
            href = unquote(
                entry["href"]
            )

            name = (
                href.rstrip("/")
                .rsplit("/", 1)[-1]
            )

            if not name.lower().endswith(
                ".zip"
            ):
                continue

            files.append(
                {
                    "name":
                        name,

                    "size":
                        entry["size"],
                }
            )

        return sorted(
            files,
            key=lambda item:
                item["name"],
        )

    def manifest(
        self,
        period: str | None = None,
    ) -> dict:
        selected_period = (
            period
            or self.latest_period()
        )

        files = self.files(
            selected_period
        )

        total_bytes = sum(
            file["size"] or 0
            for file in files
        )

        categories = {
            "empresas": 0,
            "estabelecimentos": 0,
            "socios": 0,
            "simples": 0,
            "dominios": 0,
            "outros": 0,
        }

        for file in files:
            normalized = (
                file["name"]
                .lower()
            )

            if normalized.startswith(
                "empresas"
            ):
                categories[
                    "empresas"
                ] += 1

            elif normalized.startswith(
                "estabelecimentos"
            ):
                categories[
                    "estabelecimentos"
                ] += 1

            elif normalized.startswith(
                "socios"
            ):
                categories[
                    "socios"
                ] += 1

            elif normalized.startswith(
                "simples"
            ):
                categories[
                    "simples"
                ] += 1

            elif any(
                normalized.startswith(
                    prefix
                )
                for prefix in [
                    "cnaes",
                    "municipios",
                    "naturezas",
                    "paises",
                    "qualificacoes",
                    "motivos",
                ]
            ):
                categories[
                    "dominios"
                ] += 1

            else:
                categories[
                    "outros"
                ] += 1

        return {
            "period":
                selected_period,

            "files_count":
                len(files),

            "total_bytes":
                total_bytes,

            "total_gb":
                round(
                    total_bytes
                    / 1024**3,
                    2,
                ),

            "categories":
                categories,

            "files":
                files,
        }

    def _propfind(
        self,
        path: str,
    ) -> list[dict]:
        url = (
            f"{WEBDAV_BASE}/"
            f"{path.strip('/')}/"
        )

        try:
            response = self.session.request(
                "PROPFIND",
                url,
                headers={
                    "Depth": "1",
                },
                timeout=30,
            )

        except requests.RequestException as exception:
            raise RfbCatalogError(
                "Falha de conexão com o catálogo da Receita."
            ) from exception

        if response.status_code == 401:
            raise RfbCatalogError(
                "O token público do compartilhamento da Receita não foi aceito."
            )

        if response.status_code == 404:
            raise RfbCatalogError(
                "O caminho da base CNPJ não foi encontrado no compartilhamento da Receita."
            )

        try:
            response.raise_for_status()
        except requests.RequestException as exception:
            raise RfbCatalogError(
                f"Erro HTTP {response.status_code} ao consultar a Receita."
            ) from exception

        try:
            root = ET.fromstring(
                response.content
            )
        except ET.ParseError as exception:
            raise RfbCatalogError(
                "A Receita retornou uma resposta WebDAV inválida."
            ) from exception

        result = []

        for item in root.findall(
            "d:response",
            DAV_NAMESPACE,
        ):
            href = item.findtext(
                "d:href",
                default="",
                namespaces=DAV_NAMESPACE,
            )

            length = item.find(
                ".//d:getcontentlength",
                DAV_NAMESPACE,
            )

            size = None

            if (
                length is not None
                and length.text
            ):
                try:
                    size = int(
                        length.text
                    )
                except ValueError:
                    size = None

            result.append(
                {
                    "href":
                        href,

                    "size":
                        size,
                }
            )

        return result
