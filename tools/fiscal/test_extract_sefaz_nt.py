import importlib.util, unittest, pathlib, tempfile, hashlib
spec=importlib.util.spec_from_file_location('extract',str(pathlib.Path(__file__).with_name('extract_sefaz_nt.py')));mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
class ExtractTest(unittest.TestCase):
 def test_column_continuation_excludes_business_rule(self):
  first='I01-10 Regra legal 999 Obrig. 1234 '
  text=first+'Rejeição: NCM do produto\n'+'       regra não deve aparecer'.ljust(len(first))+'incompatível [nItem: 999]\n\n'
  result=mod.extract(text,'s')
  self.assertEqual(result['1234']['message'],'Rejeição: NCM do produto incompatível [nItem: 999]')
  self.assertEqual(result['1234']['references'][0]['rule'],'I01-10')
 def test_effect_is_preserved(self):
  result=mod.extract('E01-10 Regra Obrig. 1234 Alerta Rejeição: CNPJ irregular','s')
  self.assertEqual(result['1234']['references'][0]['effect'],'Alerta')
 def test_authorization_is_preserved_not_rejection(self):
  result=mod.extract('  120 Autorização Autorizado o uso da NF-e, com alerta','s')
  self.assertEqual(result['120']['message'],'Autorizado o uso da NF-e, com alerta')
 def test_no_six_digit_event_is_treated_as_cstat(self):
  self.assertEqual(mod.extract(' 110111 Rejeição: Evento cancelamento','s'),{})
 def test_different_message_without_code_not_appended(self):
  result=mod.extract('  491 Rejeição: Evento inválido\n      Rejeição: Tipo de Evento inválido','s')
  self.assertEqual(result['491']['message'],'Rejeição: Evento inválido')
class VersionTest(unittest.TestCase):
 def test_qr_code_version_is_not_technical_note_version(self):
  self.assertEqual(mod.document_version('NT2025.001_v1.01 - NFCe_qrCode_3.pdf','Versão 3'), '1.01')
 def test_dotted_header_fallback_is_supported(self):
  self.assertEqual(mod.document_version('NT_2024.003.pdf','Nota Técnica Versão 1.00'), '1.00')
class SourceIntegrityTest(unittest.TestCase):
 def test_changed_official_pdf_is_refused_before_external_conversion(self):
  with tempfile.TemporaryDirectory() as tmp:
   pathlib.Path(tmp,'official.pdf').write_bytes(b'%PDF changed source')
   manifest=[{'nt':'2026.008','filename':'official.pdf','sha256':'0'*64}]
   with self.assertRaisesRegex(ValueError,'SHA-256 mismatch'):
    mod.build(manifest,pathlib.Path(tmp))
 def test_source_path_cannot_leave_download_directory(self):
  with self.assertRaisesRegex(ValueError,'filename'):
   mod.build([{'nt':'2026.008','filename':'../escape.pdf','sha256':'0'*64}],pathlib.Path('.'))
if __name__=='__main__':unittest.main()

