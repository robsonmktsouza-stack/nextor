"""Offline parser regression tests; no network and no actual tax determination."""

import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import parse_official_benefits as parser


class OfficialBenefitsParserTest(unittest.TestCase):
    header = ["Código", "CST 00", "CST 40", "DATA INÍCIO", "DATA FIM",
              "Descrição", "Dispositivo legal", "Observação", "Aplica ao Simples Nacional"]

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / "source"
        self.path.write_bytes(b"official-source-fixture")

    def extract(self, tables, uf="SP", historical=False):
        with patch.object(parser, "worksheet_tables", return_value=tables):
            return parser.extract(self.path, "xlsx", uf, "fixture", "2026-10-26", historical)

    def row(self, code="SP010010", start="01/02/2026", end=None):
        return [code, "", "SIM", start, end, "Descrição da publicação de teste",
                "Dispositivo expresso da publicação de teste", "Condição particular do benefício", "SIM"]

    def test_explicit_dates_and_cst_are_preserved_without_csosn_inference(self):
        result = self.extract([("Sheet", [self.header, self.row()])])
        row = result["entries"][0]
        self.assertEqual("2026-02-01", row["valid_from"])
        self.assertEqual(["40"], row["parameters"]["icms_csts"])
        self.assertTrue(row["parameters"]["applies_to_simples"])
        self.assertNotIn("csosns", row["parameters"])
        self.assertNotIn("automatic_application", row["parameters"])

    def test_snapshot_date_does_not_fill_missing_individual_validity(self):
        result = self.extract([("Sheet", [self.header, self.row(start=None)])])
        row = result["entries"][0]
        self.assertIsNone(row["valid_from"])
        self.assertFalse(row["parameters"]["automatic_application"])
        self.assertEqual("2026-10-26", row["parameters"]["snapshot_date"])

    def test_explicit_revocation_row_disables_earlier_full_row(self):
        result = self.extract([("Sheet", [self.header, self.row(code="GO811023"),
                                          ["GO811023", "REVOGADO"]])], uf="GO")
        row = result["entries"][0]
        self.assertEqual("revoked", row["status"])
        self.assertFalse(row["parameters"]["automatic_application"])
        self.assertIsNone(row["valid_until"])

    def test_historical_duplicate_is_omitted_with_trace_and_other_codes_remain_blocked(self):
        rows = [self.header, self.row(code="RJ818367", end="30/04/2026"),
                self.row(code="RJ818367"), self.row(code="RJ810001")]
        result = self.extract([("RJ", rows)], uf="RJ", historical=True)
        self.assertEqual(["RJ810001"], [entry["code"] for entry in result["entries"]])
        self.assertEqual("RJ818367", result["excluded"][0]["code"])
        self.assertEqual("template", result["entries"][0]["status"])
        self.assertFalse(result["entries"][0]["parameters"]["automatic_application"])

    def test_current_duplicate_and_impossible_dates_are_rejected(self):
        with self.assertRaisesRegex(ValueError, "Repeated code"):
            self.extract([("Sheet", [self.header, self.row(), self.row()])])
        with self.assertRaisesRegex(ValueError, "Invalid individual validity date"):
            self.extract([("Sheet", [self.header, self.row(start="31/02/2026")])])

    def test_compact_rs_dates_are_calendar_dates_not_excel_serials(self):
        self.assertEqual("2016-10-01", parser.individual_date(1102016))
        self.assertEqual("2020-01-01", parser.individual_date(1012020))
        self.assertIsNone(parser.individual_date(45000))

    def test_pr_horizontal_pages_are_joined_including_conditions(self):
        header = ["CÓDIGO", "CST 40", "DT INÍCIO", "DT FINAL", "DESCRIÇÃO"]
        tables = [
            ("page-1", [header, ["PR810001", "SIM", "01/02/2026", "", "Isenção do RICMS/2017"]]),
            ("page-2", [header, ["PR810002", "SIM", "01/02/2026", "", "Isenção do RICMS/2017"]]),
            ("page-3", [["OBSERVAÇÃO"], ["Exclusivamente nas condições da hipótese legal."]]),
            ("page-4", [["OBSERVAÇÃO"], ["Não aplicar em operações excluídas pela legislação."]]),
        ]
        with patch.object(parser, "pdf_tables", return_value=tables):
            result = parser.extract(self.path, "pdf", "PR", "fixture", "2026-10-26")
        self.assertEqual(2, len(result["entries"]))
        self.assertIn("Exclusivamente nas condições da hipótese legal.", result["entries"][0]["conditions"])
        self.assertIn("page-3", result["entries"][0]["parameters"]["source_row"])


if __name__ == "__main__":
    unittest.main()
