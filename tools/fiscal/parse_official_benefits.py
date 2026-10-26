#!/usr/bin/env python3
"""Extract cBenef rows from official XLSX/HTML/PDF tables, without tax inference.

Development helper only: openpyxl, lxml and pdfplumber are optional workstation
dependencies. The ERP consumes the reviewed JSON package, never these libraries.
Only explicit SIM/S mappings and individual dates are recognized. Publication
dates are not substituted for legal validity. No percentages are extracted.
"""

from __future__ import annotations

import argparse
from datetime import date, datetime
import hashlib
import json
from pathlib import Path
import re
import unicodedata


def text(value):
    if value is None:
        return ""
    if isinstance(value, (date, datetime)):
        return value.strftime("%Y-%m-%d")
    return " ".join(str(value).replace("\xa0", " ").split())


def normalized(value):
    return "".join(c for c in unicodedata.normalize("NFD", text(value).lower())
                   if unicodedata.category(c) != "Mn")


def individual_date(value):
    if isinstance(value, (date, datetime)):
        return value.strftime("%Y-%m-%d")
    raw = text(value)
    # The official SVRS/RS workbook uses unformatted DDMMYYYY numeric
    # literals (e.g. 1102016 for 01/10/2016), rather than Excel serial dates.
    # Five-digit Excel serials are deliberately not interpreted here.
    if re.fullmatch(r"\d{7,8}", raw):
        raw = raw.zfill(8)
        try:
            return datetime.strptime(raw, "%d%m%Y").date().isoformat()
        except ValueError:
            raise ValueError(f"Invalid compact individual validity date: {raw}")
    for pattern, fmt in [(r"\d{1,2}/\d{1,2}/\d{4}", "%d/%m/%Y"),
                         (r"\d{4}-\d{2}-\d{2}", "%Y-%m-%d")]:
        if re.fullmatch(pattern, raw):
            try:
                return datetime.strptime(raw, fmt).date().isoformat()
            except ValueError:
                raise ValueError(f"Invalid individual validity date: {raw}")
    return None


def worksheet_tables(path):
    from openpyxl import load_workbook
    book = load_workbook(path, data_only=True)
    return [(sheet.title, list(sheet.values)) for sheet in book]


def html_tables(path):
    from lxml import html
    raw = Path(path).read_bytes()
    try:
        source = raw.decode("utf-8")
    except UnicodeDecodeError:
        source = raw.decode("cp1252")
    # The DF publication concatenates two HTML documents. Browsers display both;
    # parsing the first document alone would silently omit its entire annex.
    source = re.sub(r"</?(?:html|body)(?:\s[^>]*)?>", "", source, flags=re.I)
    document = html.fromstring(source)
    for node in document.xpath("//script|//style|//del|//strike|//s|//*[contains(@style,'line-through')]"):
        node.drop_tree()
    result = []
    for number, table in enumerate(document.xpath("//table"), 1):
        rows = [[text(cell.text_content()) for cell in row.xpath("./th|./td")]
                for row in table.xpath("./tr|./tbody/tr|./thead/tr")]
        result.append((f"table-{number}", rows))
    return result


def pdf_tables(path):
    import pdfplumber
    result = []
    with pdfplumber.open(path) as document:
        for number, page in enumerate(document.pages, 1):
            for table_number, table in enumerate(page.extract_tables(), 1):
                result.append((f"page-{number}-table-{table_number}", table))
    return result


def header_columns(row):
    values = [normalized(cell) for cell in row]
    csts = {i: re.match(r"cst\s*(\d{2})(?:\b|\s)", value).group(1)
            for i, value in enumerate(values) if re.match(r"cst\s*(\d{2})(?:\b|\s)", value)}
    if not csts:
        return None
    code = next((i for i, value in enumerate(values)
                 if value in ("codigo", "cbenef", "codigo do beneficio fiscal")), None)
    description = next((i for i, value in enumerate(values)
                        if value.startswith("descricao") or value.startswith("objeto/descricao")), None)
    if code is None or description is None:
        return None
    return {"code": code, "description": description, "csts": csts,
            "from": next((i for i, value in enumerate(values)
                          if value in ("dt inicio", "data inicio", "vigencia inicio")), None),
            "until": next((i for i, value in enumerate(values)
                           if value in ("dt final", "data final", "data fim", "vigencia fim")), None),
            "legal": next((i for i, value in enumerate(values)
                           if value in ("dispositivo", "dispositivo legal", "capitulacao legal", "legislacao")), None),
            "observation": next((i for i, value in enumerate(values) if value.startswith("observacao")), None),
            "simples": next((i for i, value in enumerate(values)
                             if "simples" in value and ("aplica" in value)), None)}


def extract(path, kind, uf, source_id, snapshot_date, historical=False):
    tables = {"xlsx": worksheet_tables, "html": html_tables, "pdf": pdf_tables}[kind](path)
    if uf == "PR" and kind == "pdf" and len(tables) % 2 == 0:
        half = len(tables) // 2
        left, right = tables[:half], tables[half:]
        if left and header_columns(left[0][1][0]) and normalized(right[0][1][0][0]) == "observacao":
            joined = []
            for (left_label, left_rows), (right_label, right_rows) in zip(left, right):
                if len(left_rows) != len(right_rows):
                    raise ValueError("PR horizontal print pages have different row counts; manual review required")
                joined.append((left_label + "+" + right_label,
                               [list(a) + list(b) for a, b in zip(left_rows, right_rows)]))
            tables = joined
    entries = []
    columns = None
    previous = None
    excluded = []
    for location, rows in tables:
        if len(location) == 2 and location.isupper() and location != uf:
            continue
        for row_number, row in enumerate(rows, 1):
            detected = header_columns(row)
            if detected:
                columns = detected
                continue
            if columns is None:
                continue
            def value(key):
                i = columns.get(key)
                return row[i] if i is not None and i < len(row) else None
            code = text(value("code"))
            description = text(value("description"))
            if re.fullmatch(re.escape(uf) + r"[0-9]{5,8}", code) and len(row) == 2 and normalized(row[1]) == "revogado":
                matches = [entry for entry in entries if entry["code"] == code]
                if len(matches) != 1:
                    raise ValueError(f"Revocation without unique original row: {code}")
                matches[0]["status"] = "revoked"
                matches[0]["parameters"]["automatic_application"] = False
                matches[0]["conditions"].append("Código expressamente identificado como REVOGADO na publicação oficial consolidada.")
                continue
            if not re.fullmatch(re.escape(uf) + r"[0-9]{5,8}", code):
                # PDF continuation rows can span pages, but not new code rows.
                if code == "" and previous and description and len(row) > 10:
                    previous["description"] += " " + description
                elif code and code not in ("NULO", "SEM CBENEF", "SEM cBenef", "Não preencher", "-"):
                    excluded.append({"location": location, "row": row_number, "code": code})
                continue
            if not description:
                raise ValueError(f"Missing official description: {uf} {code} {location} row {row_number}")
            from_date = individual_date(value("from"))
            until_date = individual_date(value("until"))
            observation = text(value("observation"))
            if not until_date:
                match = re.search(r"(?:vigorou at[eé]|utiliza[cç][aã]o n[aã]o permitida a partir de)\s*(\d{2}/\d{2}/\d{4})", observation, re.I)
                # An "a partir de" prohibition is not an inclusive final date.
                # Keep that wording as a condition instead of subtracting a day.
                if match and "vigorou" in match.group().lower():
                    until_date = individual_date(match.group(1))
            legal = text(value("legal"))
            if not legal and re.search(r"\b(?:art\.|RICMS|lei|conv[eê]nio|constitui[cç][aã]o)\b", description, re.I):
                legal = description
            csts = [cst for index, cst in columns["csts"].items()
                    if index < len(row) and normalized(row[index]) in ("sim", "s")]
            parameters = {"icms_csts": csts, "cst_mapping_verified": True,
                          "legal_reference": legal,
                          "source_row": f"{location}:{row_number}", "snapshot_date": snapshot_date,
                          "requires_operation_legal_review": True}
            if historical or not from_date or not legal or not csts:
                parameters["automatic_application"] = False
            simples = normalized(value("simples"))
            if simples in ("sim", "s", "nao", "n"):
                parameters["applies_to_simples"] = simples in ("sim", "s")
            if historical:
                parameters.update({"historical_snapshot": True, "requires_current_legal_review": True})
            for name in ("from", "until"):
                raw = text(value(name))
                if raw and individual_date(value(name)) is None:
                    parameters[f"source_{name}_condition"] = raw
            conditions = [condition for condition in (legal, observation) if condition]
            conditions.append("A seleção depende da hipótese legal, condições, UF, regime e data da operação; o catálogo não concede o benefício.")
            if historical:
                conditions.append("Publicação histórica: vigência atual não confirmada; indisponível para aplicação automática.")
            entry = {"kind": "benefit", "code": code, "description": description,
                     "source_id": source_id, "valid_from": from_date, "valid_until": until_date,
                     "uf": uf, "regime": None, "status": "template" if historical else "reference",
                     "conditions": conditions, "parameters": parameters}
            entries.append(entry)
            previous = entry
    seen = set()
    ambiguous = set()
    for entry in entries:
        identity = (entry["code"], entry["valid_from"])
        if identity in seen:
            if historical:
                ambiguous.add(entry["code"])
            else:
                raise ValueError(f"Repeated code/start date requires manual source review: {identity}")
        seen.add(identity)
        if entry["valid_from"] and entry["valid_until"] and entry["valid_from"] > entry["valid_until"]:
            raise ValueError(f"Reversed official validity for {entry['code']}")
    if ambiguous:
        entries = [entry for entry in entries if entry["code"] not in ambiguous]
        excluded += [{"code": code, "reason": "conflicting code/start date in historical publication; no version inferred"}
                     for code in sorted(ambiguous)]
    if not entries:
        raise ValueError("No cBenef rows extracted; source may be a rejection page or unrecognized layout")
    return {"entries": entries, "excluded": excluded,
            "source_sha256": hashlib.sha256(Path(path).read_bytes()).hexdigest()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--format", choices=["xlsx", "html", "pdf"], required=True)
    parser.add_argument("--uf", required=True)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--snapshot-date", required=True)
    parser.add_argument("--historical", action="store_true")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    result = extract(args.input, args.format, args.uf, args.source_id, args.snapshot_date, args.historical)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"uf": args.uf, "entries": len(result["entries"]), "source_sha256": result["source_sha256"]}))


if __name__ == "__main__":
    main()
