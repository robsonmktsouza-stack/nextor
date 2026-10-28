#!/usr/bin/env python3
"""Build the interpretation catalog from official MOC/NT extracts.

Developer tool only; the ERP consumes the bundled JSON without Python or PDF
programs. All input source hashes are retained. This catalog describes replies;
it never enables emission, correction, cancellation or retry.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path


def clean_message(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def extract_codes_table(text: str, start: str, stop: str | None = None) -> dict:
    """Read explicit final message tables, never infer numeric validation codes."""
    offset = text.rfind(start)
    if offset < 0:
        raise ValueError(f"Official message table not found: {start}")
    block = text[offset + len(start):]
    if stop:
        end = block.find(stop)
        if end < 0:
            raise ValueError(f"Official message table terminator not found: {stop}")
        block = block[:end]
    entries: dict = {}
    current = None
    for line in block.splitlines():
        if not line.strip() or re.search(r"Página\s+\d+\s*/", line):
            continue
        if re.match(r"\s*(?:Nota Fiscal Eletrônica|MOC 7\.0|NT 20\d\d\.|Projeto|CÓD(?:IGO)?\s|4\.4\.)", line):
            continue
        match = re.match(r"^\s{0,34}(\d{3,4})\s+(\S.*)$", line)
        if match:
            current = match[1]
            message = clean_message(match[2])
            if current in entries and entries[current] != message:
                # MOC142 repeats the contingency warning in both outcome and
                # rejection tables. Preserve the explicitly labelled rejection.
                if message.startswith("Rejeição:"):
                    entries[current] = message
            else:
                entries[current] = message
        elif current is not None:
            entries[current] = clean_message(entries[current] + " " + line)
    if not entries:
        raise ValueError("Official message table is empty")
    return entries


def source(identifier: str, title: str, url: str, version: str, pdf: Path) -> dict:
    if not pdf.read_bytes().startswith(b"%PDF-"):
        raise ValueError(f"Not a PDF: {pdf.name}")
    return {"id": identifier, "title": title, "url": url, "version": version,
            "sha256": hashlib.sha256(pdf.read_bytes()).hexdigest(),
            "filename": pdf.name}


def merge_codes(target: dict, codes: dict, source_id: str | None = None) -> None:
    """Later verified technical documents take precedence; keep old wording."""
    for code, record in codes.items():
        if not re.fullmatch(r"[1-9]\d{2,3}", code):
            raise ValueError(f"Invalid cStat: {code}")
        item = ({"message": record, "source_ids": [source_id], "references": []}
                if isinstance(record, str) else dict(record))
        item["message"] = clean_message(item["message"])
        if not item["message"] or not item.get("source_ids"):
            raise ValueError(f"Missing message or source for cStat {code}")
        variants = []
        for variant in item.get("variants", []):
            value = ({"message": clean_message(variant), "source_ids": item["source_ids"][:]}
                     if isinstance(variant, str) else variant)
            if value not in variants:
                variants.append(value)
        previous = target.get(code)
        if previous:
            item["source_ids"] = list(dict.fromkeys(previous["source_ids"] + item["source_ids"]))
            item["references"] = previous.get("references", []) + item.get("references", [])
            for variant in previous.get("variants", []):
                if variant not in variants:
                    variants.append(variant)
            if previous["message"] != item["message"]:
                old = {"message": previous["message"], "source_ids": previous["source_ids"]}
                if old not in variants:
                    variants.append(old)
        if variants:
            item["variants"] = variants
        target[code] = item


def build(directory: Path, fragments: list[Path]) -> dict:
    sources = []
    codes: dict = {}
    definitions = [
        ("moc7", "MOC 7.0, Anexo I, tabelas 4.4.1 a 4.4.3", "https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=1&nomeArquivo=moc7-anexo-i-leiaute-e-rv.pdf", "7.00 — novembro/2020", "nfe-moc7-official", "4.4. Lista das Regras de Validação", "OBS.:"),
        ("nt2023-001", "NT 2023.001 — Tributação monofásica de combustíveis", "https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=3&nomeArquivo=NT2023.001_v1.51_Tributa%C3%A7%C3%A3oMonof%C3%A1sicaCombust%C3%ADveis.pdf", "1.51 — maio/2024", "fiscal-nt2023.001v1.51", "5. Novos códigos de Rejeição", "6. Sobre o DANFE"),
        ("nt2024-001", "NT 2024.001 — CRT=4 (MEI), Denegação", "https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=3&nomeArquivo=NT2024.001-CRT-MEI.pdf", "1.00 — abril/2024", "fiscal-nt2024.001", "5. Códigos de Rejeição", None),
    ]
    for identifier, title, url, version, basename, start, stop in definitions:
        pdf = directory / (basename + ".pdf")
        text = (directory / (basename + ".txt")).read_text(encoding="utf-8")
        sources.append(source(identifier, title, url, version, pdf))
        merge_codes(codes, extract_codes_table(text, start, stop), identifier)
        for item in codes.values():
            if item["source_ids"][-1] == identifier:
                item["references"].append({"source_id": identifier, "section": start})
    for fragment in fragments:
        data = json.loads(fragment.read_text(encoding="utf-8"))
        known = {item["id"] for item in sources}
        for item in data["sources"]:
            if item["id"] in known:
                raise ValueError(f"Repeated source: {item['id']}")
            sources.append(item)
            known.add(item["id"])
        merge_codes(codes, data["codes"])
    known = {item["id"] for item in sources}
    for code, record in codes.items():
        if set(record["source_ids"]) - known:
            raise ValueError(f"Unknown source for cStat {code}")
    return {"version": "2026.10.26", "consulted_at": "2026-10-26",
            "scope": "NF-e 55 e NFC-e 65: interpretação de retornos, sem autorizar operações fiscais",
            "sources": sources, "codes": dict(sorted(codes.items(), key=lambda pair: int(pair[0])))}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-dir", type=Path, required=True)
    parser.add_argument("--fragment", type=Path, action="append", default=[])
    parser.add_argument("--nt-manifest", type=Path,
                        help="Official NT files/URLs/SHA; PDFs and pdftotext -layout extracts must exist in source-dir")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    fragments = args.fragment[:]
    if args.nt_manifest:
        from extract_sefaz_nt import build as build_nt
        manifest = json.loads(args.nt_manifest.read_text(encoding="utf-8"))
        import tempfile
        with tempfile.TemporaryDirectory(prefix="lumeron-sefaz-catalog-") as temp:
            fragment = Path(temp) / "nt-codes.json"
            fragment.write_text(json.dumps(build_nt(manifest, args.source_dir), ensure_ascii=False), encoding="utf-8")
            result = build(args.source_dir, fragments + [fragment])
    else:
        result = build(args.source_dir, fragments)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({"codes": len(result["codes"]), "sources": len(result["sources"]),
                      "sha256": hashlib.sha256(args.output.read_bytes()).hexdigest()}))


if __name__ == "__main__":
    main()
