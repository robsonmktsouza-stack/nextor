#!/usr/bin/env python3
"""Developer-only normalization of the cited RN/AC public official snapshots.

This is a reviewed, bounded extraction, not an automatic discovery of legal
entitlement. No network access, tax-rate inference or changes to an ERP database.
"""

import argparse
import hashlib
import json
import re
import subprocess
from html.parser import HTMLParser
from pathlib import Path


DATE = "2026-10-26"
REVIEWED_RN_SHA256 = "f2d13d50e1464f25309c7b5dad3f546c871c2f9758caf742aad79b8658ccb613"
REVIEWED_AC_SHA256 = "e381476b380138154b3d3748b6c7c7dc448ec5444e09f30e9fc71611a6dc143a"
STATES = ["AC", "AL", "AP", "AM", "CE", "MA", "MT", "MS", "PA", "PB", "PE", "PI", "RN", "RO", "RR", "SE", "TO"]
DOMAINS = {
    "AC": "ac.gov.br", "AL": "sefaz.al.gov.br", "AP": "ap.gov.br", "AM": "am.gov.br",
    "CE": "sefaz.ce.gov.br", "MA": "sefaz.ma.gov.br", "MT": "mt.gov.br", "MS": "ms.gov.br",
    "PA": "pa.gov.br", "PB": "sefaz.pb.gov.br", "PE": "sefaz.pe.gov.br", "PI": "sefaz.pi.gov.br",
    "RN": "rn.gov.br", "RO": "ro.gov.br", "RR": "rr.gov.br", "SE": "se.gov.br", "TO": "to.gov.br",
}


class PlainText(HTMLParser):
    def __init__(self):
        super().__init__()
        self.parts = []

    def handle_data(self, value):
        self.parts.append(value)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rn-pdf", type=Path, required=True)
    parser.add_argument("--ac-html", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    rn_bytes = args.rn_pdf.read_bytes()
    ac_bytes = args.ac_html.read_bytes()
    if hashlib.sha256(rn_bytes).hexdigest() != REVIEWED_RN_SHA256 or hashlib.sha256(ac_bytes).hexdigest() != REVIEWED_AC_SHA256:
        raise SystemExit("O arquivo difere do snapshot revisado. Revisar a norma e a transcrição antes de alterar os hashes aprovados.")
    if not rn_bytes.startswith(b"%PDF"):
        raise SystemExit("A fonte RN não é PDF.")
    rn_text = subprocess.check_output(["pdftotext", "-f", "28", "-l", "28", "-raw", str(args.rn_pdf), "-"], text=True)
    rn_codes = sorted(set(re.findall(r"\bRN\d{6}\b", rn_text)))
    if rn_codes != ["RN010001", "RN020001", "RN020002", "RN020003"] or "01/10/2025" not in rn_text:
        raise SystemExit("O recorte RN mudou. Revisão da tabela é necessária; não gerar códigos por aproximação.")
    ac_parser = PlainText()
    ac_parser.feed(ac_bytes.decode("utf-8"))
    ac_text = " ".join(" ".join(ac_parser.parts).split())
    if not all(value in ac_text for value in ["AC30002", "11.045", "11.119", "cBenef", "20"]):
        raise SystemExit("A fonte AC mudou ou não contém a norma/código esperados.")

    sources = [
        {
            "id": "rn-doe-portaria-sei-970-2025",
            "url": "https://webdisk.diariooficial.rn.gov.br/Jornal/12025-09-23.pdf",
            "reference": "Portaria-SEI nº 970, de 22/09/2025, arts. 1º e 2º e Anexo Único; DOE/RN de 23/09/2025, nº 16.001, página 28",
            "version": "Redação original publicada em 23/09/2025; não é consolidação de alterações posteriores",
            "consulted_at": DATE,
            "sha256": hashlib.sha256(rn_bytes).hexdigest(),
            "scope": "RN",
            "conditions": [
                "Os quatro códigos e a data inicial são os expressamente publicados no Anexo Único.",
                "A fonte não apresenta correspondência por CST, CSOSN ou CRT; não inferir tais vínculos.",
                "A existência do código não reconhece direito ao benefício nem cria regra tributária ativa.",
            ],
        },
        {
            "id": "ac-sefaz-decreto-11045-2022",
            "url": "https://sefaz.ac.gov.br/2021/?p=16727",
            "reference": "Decreto/AC nº 11.045, de 27/04/2022, arts. 2º, 5º e 6º; alteração pelo Decreto nº 11.119, de 19/09/2022",
            "version": "Texto oficial com alterações do Decreto nº 11.119/2022",
            "consulted_at": DATE,
            "sha256": hashlib.sha256(ac_bytes).hexdigest(),
            "scope": "AC",
            "conditions": [
                "O art. 5º prescreve literalmente AC30002 e CST 20; não acrescentar zeros ao código.",
                "A duração é condicionada aos Convênios ICMS 126/2013 e 19/2022. Não foi validada a cadeia completa de prorrogações.",
                "Registro mantido como modelo indisponível para aplicação automática: vigência integral e compatibilidade do código de sete caracteres precisam de confirmação.",
            ],
        },
    ]
    descriptions = [
        ("RN010001", "Isenção", "Dispensa do ICMS nas saídas internas de produtos comestíveis, resultantes do abate de gado bovino, bufalino, suíno, equino, caprino, ovino, asinino e muar, efetuadas por estabelecimento abatedor que atenda a legislação sanitária estadual ou federal, bem como nas operações internas subsequentes", "Decreto nº 31.825/2022 - RICMS/RN, art. 590"),
        ("RN020001", "Crédito presumido", "Crédito presumido de 100% (cem por cento), nas saídas internas de óleo diesel e biodiesel destinadas a empresas ou consórcio de empresas de ônibus responsáveis pela exploração de serviço de transporte público de passageiros na região metropolitana de Natal definida na Lei Complementar Estadual nº 152, de 16 de janeiro de 1997", "Decreto nº 31.825/2022 - RICMS/RN, Anexo 03, art. 28, inciso I"),
        ("RN020002", "Crédito presumido", "Crédito presumido de 80% (oitenta por cento), nas operações internas com óleo diesel e biodiesel destinadas a empresas concessionárias ou permissionárias de transporte coletivo de passageiros, municipal ou intermunicipal", "Decreto nº 31.825/2022 - RICMS/RN, Anexo 03, art. 28, inciso II"),
        ("RN020003", "Crédito presumido", "Crédito presumido do ICMS em montante equivalente a 100% (cem por cento) do valor do imposto incidente nas saídas de óleo diesel fornecido por distribuidora de combustíveis, como tal definida pela Agência Nacional do Petróleo, Gás Natural e Biocombustíveis (ANP) do Ministério de Minas e Energia e devidamente credenciada neste Estado, a ser consumido por embarcações pesqueiras nacionais que estejam registradas no Rio Grande do Norte", "Decreto nº 31.825/2022 - RICMS/RN, Anexo 03, art. 29"),
    ]
    entries = []
    for index, (code, benefit_type, description, reference) in enumerate(descriptions, 1):
        entries.append({
            "kind": "benefit", "code": code, "description": description, "uf": "RN", "regime": None,
            "valid_from": "2025-10-01", "valid_until": None, "status": "reference",
            "source_id": sources[0]["id"],
            "conditions": [
                reference,
                "Aplicar somente quando satisfeitos os requisitos materiais, cadastrais e operacionais da legislação referenciada.",
                "Não há correspondência CST/CSOSN/CRT publicada nesta tabela. Seleção automática exige fundamento complementar validado.",
                "Término não informado na tabela original; isso não prova vigência indefinida nem ausência de alterações posteriores.",
            ],
            "parameters": {
                "benefit_type": benefit_type, "legal_reference": reference, "icms_csts": [],
                "cst_mapping_verified": False, "automatic_application": False,
                "source_row": f"Anexo Único, linha {index}, página 28", "snapshot_date": DATE,
                "source_valid_from": "01/10/2025", "source_valid_until": "",
            },
        })
    entries.append({
        "kind": "benefit", "code": "AC30002", "description": "Redução de base de cálculo em saídas interestaduais de gado bovino, nas condições do Decreto/AC nº 11.045/2022",
        "uf": "AC", "regime": None, "valid_from": "2022-04-28", "valid_until": None, "status": "template",
        "source_id": sources[1]["id"],
        "conditions": [
            "Decreto nº 11.045/2022, arts. 2º, 5º e 6º, com alterações do Decreto nº 11.119/2022.",
            "Os animais devem ser originários da produção interna e cumprir a documentação, declaração e destinos previstos na norma.",
            "Código AC30002 e CST 20 transcritos literalmente do art. 5º. Não criar AC030002 por preenchimento de zeros.",
            "A vigência depende dos Convênios ICMS 126/2013 e 19/2022; sem prorrogações comprovadas e validação do formato XML, permanece indisponível para aplicação automática.",
        ],
        "parameters": {
            "benefit_type": "Redução de base de cálculo", "legal_reference": "Decreto/AC nº 11.045/2022, art. 5º, I e III, e art. 6º",
            "icms_csts": ["20"], "cst_mapping_verified": True, "automatic_application": False,
            "layout_code_verified": False, "full_validity_verified": False, "source_row": "Art. 5º, I e III",
            "snapshot_date": DATE, "source_valid_from": "DOE/AC de 28/04/2022; art. 6º",
        },
    })
    coverage = {"by_uf": {}}
    attempts = []
    for uf in STATES:
        coverage["by_uf"][uf] = {
            "status": "unavailable", "count": 0, "required_status": "unverified",
            "note": "Não foi localizada tabela pública oficial de cBenef com códigos e condições validáveis nesta pesquisa. Isso não significa dispensa nem inexistência de benefícios.",
        }
        attempts.append({
            "uf": uf, "date": DATE, "method": "web_search_official_domain",
            "query": f'"cBenef" site:{DOMAINS[uf]}',
            "result": "official_reference_located" if uf in ["RN", "AC"] else "no_official_code_table_confirmed",
        })
    coverage["by_uf"]["RN"] = {
        "status": "partial", "count": 4, "required_status": "conditional",
        "source_ids": [sources[0]["id"]], "snapshot_date": DATE,
        "note": "Quatro linhas da Portaria-SEI 970/2025 original, vigente a partir de 01/10/2025 para benefícios nela relacionados. Sem tabela CST/CSOSN nem consolidação de alterações posteriores.",
    }
    coverage["by_uf"]["AC"] = {
        "status": "partial", "count": 1, "required_status": "conditional_unverified_current_effects",
        "source_ids": [sources[1]["id"]], "snapshot_date": DATE,
        "note": "Um código textual prescrito no art. 5º do Decreto 11.045/2022; modelo bloqueado por formato e vigência condicionada não verificados integralmente. Não é uma tabela estadual completa.",
    }
    output = {"sources": sources, "entries": entries, "coverage": coverage, "research_attempts": attempts}
    args.output.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"states": len(STATES), "sources": len(sources), "entries": len(entries),
                      "references": 4, "blocked_templates": 1, "sha256": hashlib.sha256(args.output.read_bytes()).hexdigest()}))


if __name__ == "__main__":
    main()
