#!/usr/bin/env python3
"""Normalize the official NF-e CFOP XLSX without third-party Python packages.

Reads only the known workbook parts; never executes macros or extracts files.
Every code, description, note, date and indicator comes from a workbook cell.
The output is a catalog fragment for reviewed release assembly, not tax rules.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import re
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
HEADERS = ["CFOP", "Início de vigência", "Fim de vigência", "indNFe", "indComunica",
           "indTransp", "indDevol", "indRetor", "indAnula", "indRemes", "indComb",
           "indExcIBSCBS", "Título do CFOP", "Descrição (nota explicativa)"]
SOURCE_ID = "nfe-cfop-national-20260904"
DEFAULT_URL = "https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM="
DEFAULT_NAME = "IT_2023_002_v2_10_Tabela_CFOP_Descrição.xlsx"


def iso_day(value: str) -> str:
    day = dt.date.fromisoformat(value)
    if day.isoformat() != value:
        raise ValueError("Invalid ISO date: " + value)
    return value


def excel_day(value: str) -> str | None:
    if not value:
        return None
    # This official workbook uses the Excel 1900 date system, integer day values.
    if not re.fullmatch(r"[0-9]+", value):
        raise ValueError("Unexpected non-integer date cell: " + value)
    serial = int(value)
    if not 61 <= serial <= 2958465:
        raise ValueError("Unsupported Excel date serial: " + value)
    return (dt.date(1899, 12, 30) + dt.timedelta(days=serial)).isoformat()


def rows_from_xlsx(path: Path) -> tuple[list[list[str]], str | None]:
    with zipfile.ZipFile(path) as archive:
        if sum(info.file_size for info in archive.infolist()) > 32 * 1024 * 1024:
            raise ValueError("Workbook exceeds the 32 MiB uncompressed limit")
        workbook = ET.fromstring(archive.read("xl/workbook.xml"))
        properties = workbook.find("s:workbookPr", NS)
        if properties is not None and properties.get("date1904") in {"1", "true"}:
            raise ValueError("Workbook uses a date system not supported by this release parser")
        sheets = workbook.findall("s:sheets/s:sheet", NS)
        if len(sheets) != 1 or sheets[0].get("name") != "CFOP":
            raise ValueError("Expected one official sheet named CFOP")
        strings_xml = ET.fromstring(archive.read("xl/sharedStrings.xml"))
        strings = ["".join(item.itertext()) for item in strings_xml.findall("s:si", NS)]
        worksheet = ET.fromstring(archive.read("xl/worksheets/sheet1.xml"))
        output = []
        for row in worksheet.findall("s:sheetData/s:row", NS):
            values = [""] * len(HEADERS)
            for cell in row.findall("s:c", NS):
                address = cell.get("r", "")
                match = re.fullmatch(r"([A-N])[0-9]+", address)
                # Excel may preserve formatting-only cells far beyond column N.
                if not match and cell.find("s:v", NS) is None and cell.find("s:is", NS) is None and cell.find("s:f", NS) is None:
                    continue
                if not match:
                    raise ValueError("Unexpected populated cell outside the reviewed schema: " + address)
                if cell.find("s:f", NS) is not None:
                    raise ValueError("Formula cells require explicit review: " + address)
                element = cell.find("s:v", NS)
                value = element.text if element is not None and element.text is not None else ""
                cell_type = cell.get("t")
                if cell_type == "s":
                    value = strings[int(value)]
                elif cell_type == "inlineStr":
                    value = "".join(cell.find("s:is", NS).itertext())
                elif cell_type not in {None, "n", "str"}:
                    raise ValueError("Unsupported cell type at " + address)
                values[ord(match.group(1)) - ord("A")] = value.strip()
            if any(values):
                output.append(values)
        metadata = ET.fromstring(archive.read("docProps/core.xml"))
        modified = metadata.find("{http://purl.org/dc/terms/}modified")
        return output, modified.text if modified is not None else None


def normalize(path: Path, *, consulted_at: str, source_url: str = DEFAULT_URL,
              source_name: str = DEFAULT_NAME, publication_date: str = "2026-09-04",
              expected_count: int | None = None) -> dict:
    iso_day(consulted_at)
    iso_day(publication_date)
    if not source_url.startswith("https://www.nfe.fazenda.gov.br/portal/"):
        raise ValueError("This parser is limited to the reviewed official NF-e portal source")
    rows, modified = rows_from_xlsx(path)
    if not rows or rows[0] != HEADERS:
        raise ValueError("The official workbook column schema changed; review the new source")
    records = []
    identities = set()
    for number, row in enumerate(rows[1:], 2):
        code, start, end = row[:3]
        if not re.fullmatch(r"[123567][0-9]{3}", code) or code in identities:
            raise ValueError("Invalid or duplicate CFOP in row " + str(number))
        identities.add(code)
        flags = {}
        for index, key in enumerate(HEADERS[3:12], 3):
            allowed = {"0", "1", "2"} if key == "indComb" else {"0", "1"}
            if row[index] not in allowed:
                raise ValueError("Invalid or missing indicator " + key + " in row " + str(number))
            flags[key] = int(row[index])
        title, note = row[12:14]
        if not title or not note:
            raise ValueError("Missing title or explanatory note in row " + str(number))
        first = excel_day(start)
        last_exclusive = excel_day(end)
        if first is None:
            raise ValueError("Missing initial validity date in row " + str(number))
        if last_exclusive is not None and last_exclusive <= first:
            raise ValueError("Invalid validity interval in row " + str(number))
        last_inclusive = ((dt.date.fromisoformat(last_exclusive) - dt.timedelta(days=1)).isoformat()
                          if last_exclusive else None)
        parameters = {**flags, "ind_nfe": flags["indNFe"] == 1,
                      "source_valid_until_exclusive": last_exclusive,
                      "source_row": number, "automatic_tax_application": False,
                      "operation_type": "inbound" if code[0] in "123" else "outbound",
                      "destination": "internal" if code[0] in "15" else
                                     ("interstate" if code[0] in "26" else "foreign"),
                      "indicator_effectivity": {"indExcIBSCBS": {
                          "homologation": "2026-09-01", "production": "2026-11-03",
                          "legal_reference": "IT 2023.002 v2.00 / NT 2026.007"}}}
        records.append({"kind": "cfop", "code": code, "description": title,
                        "source_id": SOURCE_ID, "valid_from": first,
                        "valid_until": last_inclusive, "uf": None, "regime": None,
                        "conditions": [note], "parameters": parameters, "status": "reference"})
    if expected_count is not None and len(records) != expected_count:
        raise ValueError("Source row count differs from the verified expected count")
    flags_count = {key: sum(item["parameters"][key] == 1 for item in records)
                   for key in HEADERS[3:12]}
    return {"sources": [{"id": SOURCE_ID, "url": source_url,
              "reference": "Portal Nacional da NF-e — Documentos/Diversos/Vigentes; tabela nacional CFOP com títulos, notas explicativas, vigências e indicadores de documentos fiscais",
              "version": source_name, "publication_date": publication_date,
              "consulted_at": consulted_at, "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
              "scope": "BR", "workbook_modified_at": modified,
              "conditions": ["Tabela nacional completa conforme arquivo vigente disponibilizado pelo Portal NF-e na data da consulta.",
                  "A presença de CFOP não determina CST, CSOSN, alíquota, benefício, responsabilidade por ST ou suporte integral da operação pelo emissor.",
                  "indNFe foi preservado e normalizado em ind_nfe booleano. Não existe coluna indNFCe nesta planilha; nenhuma foi inferida.",
                  "indComb mantém seus valores originais 0, 1 ou 2; não foi convertido em booleano.",
                  "indExcIBSCBS tem início técnico em homologação 01/09/2026 e produção 03/11/2026, conforme IT 2023.002 v2.00; não aplicar antecipadamente em produção.",
                  "A data do fim da vigência é o primeiro dia não aceito; valid_until foi convertido para o dia anterior, preservando o valor original em parameters." ]}],
            "entries": records,
            "coverage": {"cfop": {"status": "complete", "scope": "BR", "count": len(records),
                "snapshot_date": consulted_at, "publication_date": publication_date,
                "nfe_allowed_count": flags_count["indNFe"], "official_indicator_counts": flags_count,
                "note": "Todos os CFOP e campos da planilha oficial vigente v2.10, publicada em 04/09/2026. Não representa o histórico completo de códigos excluídos das versões anteriores."}},
            "research_attempts": [{"url": "https://www.nfe.fazenda.gov.br/portal/principal.aspx",
                "result": "ASP.NET cookie detection redirect resolved by retaining the public cookie jar; HTTP200."},
                {"url": "https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=/NJarYc9nus=",
                 "result": "HTTP200, Documentos/Diversos lists the reviewed workbook as vigente, publication04/09/2026."},
                {"url": source_url, "result": "HTTP200, XLSX attachment, full title/description and date/indicator columns verified."}]}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("workbook", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--consulted-at", required=True)
    parser.add_argument("--source-url", default=DEFAULT_URL)
    parser.add_argument("--source-name", default=DEFAULT_NAME)
    parser.add_argument("--publication-date", default="2026-09-04")
    parser.add_argument("--expected-count", type=int)
    args = parser.parse_args()
    result = normalize(args.workbook, consulted_at=args.consulted_at,
                       source_url=args.source_url, source_name=args.source_name,
                       publication_date=args.publication_date, expected_count=args.expected_count)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"entries": len(result["entries"]), "source_sha256": result["sources"][0]["sha256"],
                      "nfe_allowed": result["coverage"]["cfop"]["nfe_allowed_count"]}))


if __name__ == "__main__":
    main()
