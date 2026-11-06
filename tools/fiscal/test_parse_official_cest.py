"""Regression checks against the archived national CONFAZ source, never state ST tables."""
import importlib.util
import sys
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('parse_official_cest',Path(__file__).with_name('parse_official_cest.py'))
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class OfficialCestParserTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.result=module.parse(ROOT/'resources/fiscal/sources/confaz-cest-2026-10-26.txt',
            ROOT/'resources/fiscal/sources/confaz-cest-107-2026-10-26.txt')

    def test_all_25_goods_annexes_and_current_vs_future_vs_revoked_counts(self):
        c=self.result['coverage']
        self.assertEqual(25,c['annexes'])
        self.assertEqual(1054,c['consolidated_items'])
        self.assertEqual(1054,c['unique_codes'])
        self.assertEqual(1041,c['current_national_codes_at_consultation'])
        self.assertEqual(10,c['revoked_codes'])
        self.assertEqual(5,c['future_wordings'])
        self.assertEqual(1058,len(self.result['entries']))
        self.assertEqual(25,len({e['parameters']['annex']for e in self.result['entries']}))

    def test_independent_scan_includes_integer_item_labels_and_every_official_goods_code(self):
        import re
        lines=module.read_lines(ROOT/'resources/fiscal/sources/confaz-cest-2026-10-26.txt')
        annex=None;codes=set()
        for text in lines.values():
            match=re.fullmatch(r'ANEXO ([IVXLCDM]+)',text)
            if match:annex=match.group(1)
            if annex=='XXVII':break
            if annex in [None,'I']:continue
            cells=[p.strip()for p in text.split('|')]
            if len(cells)>2 and re.fullmatch(r'\d{2}\.\d{3}\.\d{2}',cells[1]):
                codes.add(cells[1].replace('.',''))
        self.assertEqual(codes,{e['code']for e in self.result['entries']})
        monitor=next(e for e in self.result['entries']if e['code']=='2112600')
        self.assertEqual('126.0',monitor['parameters']['item'])
        self.assertTrue(monitor['description'])

    def test_107_2026_future_classification_excludes_sp_and_keeps_older_wording(self):
        rows=[e for e in self.result['entries']if e['code']=='2104600']
        self.assertEqual(3,len(rows))
        future=next(e for e in rows if e['valid_from']=='2026-11-01')
        self.assertEqual(['SP'],future['parameters']['excluded_ufs'])
        self.assertIn('21.046.01',future['description'])
        older=next(e for e in rows if e['uf']is None and e['valid_until']=='2026-10-31')
        self.assertNotIn('21.046.01',older['description'])
        sp=next(e for e in rows if e['uf']=='SP')
        self.assertIsNone(sp['valid_until'])
        self.assertEqual(older['description'],sp['description'])
        added=next(e for e in self.result['entries']if e['code']=='2105101')
        self.assertEqual('2026-11-01',added['valid_from'])
        self.assertEqual(['SP'],added['parameters']['excluded_ufs'])

    def test_revoked_codes_remain_auditable_with_actual_prior_description_and_end_date(self):
        row=next(e for e in self.result['entries']if e['code']=='1704908')
        self.assertEqual('revoked',row['status'])
        self.assertEqual('2020-09-30',row['valid_until'])
        self.assertEqual('2020-10-01',row['parameters']['revoked_from'])
        self.assertIn('Massas alimentícias',row['description'])
        self.assertTrue(row['parameters']['historical_wordings'])

    def test_multiline_ncm_and_explicit_chapter_ranges_are_preserved_without_fixing_official_typos(self):
        water=next(e for e in self.result['entries']if e['code']=='0300300')
        self.assertEqual(['22011000','22019000'],water['parameters']['ncm_prefixes'])
        chapters=next(e for e in self.result['entries']if e['code']=='2806200')
        self.assertEqual(['13']+[str(i)for i in range(15,24)],chapters['parameters']['ncm_prefixes'])
        typo=next(e for e in self.result['entries']if e['code']=='2004000')
        self.assertIn('926.90.90',typo['parameters']['source_ncm_expression'])
        self.assertEqual('manual',typo['parameters']['ncm_link_validation'])
        self.assertEqual([],typo['parameters']['ncm_prefixes'])

    def test_next_item_does_not_inherit_another_items_effective_date(self):
        common=next(e for e in self.result['entries']if e['code']=='2104800')
        self.assertIsNone(common['valid_from'])
        self.assertIsNone(common['parameters']['current_wording_annotation'])
        self.assertFalse(module.applies('Nova redação dada ao item 46.0 do Anexo XX pelo Convênio ICMS 107/26, efeitos a partir de 01.11.26.','XX','48.0'))

    def test_no_tax_rate_or_st_incidence_is_generated(self):
        for row in self.result['entries']:
            self.assertFalse(row['parameters']['automatic_application'])
            self.assertNotIn('mva',row['parameters'])
            self.assertNotIn('icms_rate',row['parameters'])
            self.assertNotIn('st_applies',row['parameters'])

if __name__=='__main__':unittest.main()
