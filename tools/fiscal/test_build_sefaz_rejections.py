import unittest

from build_sefaz_rejections import extract_codes_table, merge_codes


class OfficialSefazRejectionParserTest(unittest.TestCase):
    def test_explicit_table_joins_message_without_headers_or_footer(self):
        text = "Summary\nSTART\n CÓD MOTIVOS\n 486 Rejeição: Não informado\n     o Grupo de Autorização\n Página 143 / 153\n\fNota Fiscal Eletrônica\nMOC 7.0 — cabeçalho\n 487 Rejeição: Escritório não cadastrado\nSTOP"
        self.assertEqual({"486": "Rejeição: Não informado o Grupo de Autorização",
                          "487": "Rejeição: Escritório não cadastrado"},
                         extract_codes_table(text, "START", "STOP"))

    def test_table_not_found_fails_instead_of_importing_legal_numbers(self):
        with self.assertRaises(ValueError):
            extract_codes_table("999 Rejeição: exemplo fora da tabela", "START")
        with self.assertRaises(ValueError):
            extract_codes_table("START\n100 Autorizado", "START", "STOP")

    def test_processing_status_is_preserved_without_fabricating_rejection(self):
        self.assertEqual("Autorizado o uso da NF-e",
                         extract_codes_table("START\n 100 Autorizado o uso da NF-e", "START")["100"])

    def test_later_verified_source_keeps_previous_wording(self):
        result = {}
        merge_codes(result, {"302": "Uso Denegado: Irregularidade fiscal do destinatário"}, "moc7")
        merge_codes(result, {"302": {"message": "Rejeição: Irregularidade fiscal do destinatário",
                                      "source_ids": ["nt2024-001"], "references": [{"page": 16}]}})
        self.assertEqual(["moc7", "nt2024-001"], result["302"]["source_ids"])
        self.assertTrue(result["302"]["message"].startswith("Rejeição:"))
        self.assertTrue(result["302"]["variants"][0]["message"].startswith("Uso Denegado:"))
        self.assertEqual([{"page": 16}], result["302"]["references"])

    def test_invalid_code_or_missing_source_is_rejected(self):
        with self.assertRaises(ValueError):
            merge_codes({}, {"x486": "Rejeição"}, "moc7")
        with self.assertRaises(ValueError):
            merge_codes({}, {"486": {"message": "Rejeição", "source_ids": []}})


if __name__ == "__main__":
    unittest.main()
