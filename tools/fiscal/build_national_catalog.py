#!/usr/bin/env python3
"""Combine reviewed official extracts into an immutable full fiscal snapshot.

This tool does not download, approve tax treatments, or change company data.
Supply the outputs of the official CFOP/CEST/benefit normalizers after review.
"""
import argparse
import copy
import hashlib
import json
from pathlib import Path

UFS = 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split()


def read(path):
    return json.loads(Path(path).read_text(encoding='utf-8'))


def dump(path, value):
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    Path(path).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def combine(base, cfop, cest, benefits, version, consulted):
    result = copy.deepcopy(base)
    result['version'] = version
    result['consulted_at'] = consulted
    sources = {source['id']: source for source in result['sources']}
    extracts = [cfop, cest, *benefits]
    for extract in extracts:
        for source in extract['sources']:
            previous = sources.get(source['id'])
            if previous is not None and previous != source:
                raise ValueError('Source ID has conflicting provenance: ' + source['id'])
            sources[source['id']] = source
    result['sources'] = list(sources.values())

    replacement_state_cest = {(entry['code'], entry.get('uf'), entry.get('regime'))
                              for entry in cest['entries'] if entry.get('uf')}
    entries = []
    for entry in result['entries']:
        if entry['kind'] == 'cfop':
            continue
        if entry['kind'] == 'cest':
            identity = (entry['code'], entry.get('uf'), entry.get('regime'))
            if identity in replacement_state_cest:
                continue
            # This is preservation of a prior state reference, not a declaration
            # that the underlying legislation or tax treatment was revoked.
            entry['status'] = 'template'
            entry.setdefault('parameters', {})['legacy_state_reference'] = True
            entry.setdefault('conditions', []).append(
                'Referência estadual do pacote anterior preservada para consulta. '
                'A classificação nacional e as exceções expressas publicadas no pacote atual prevalecem.')
        entries.append(entry)

    entries.extend(copy.deepcopy(cfop['entries']))
    for entry in copy.deepcopy(cest['entries']):
        if entry.get('parameters', {}).get('ncm_link_validation') == 'manual':
            entry['parameters']['requires_manual_ncm_review'] = True
        entries.append(entry)
    for extract in benefits:
        entries.extend(copy.deepcopy(extract['entries']))
    result['entries'] = entries

    result['coverage']['cfop'] = copy.deepcopy(cfop['coverage']['cfop'])
    cest_coverage = copy.deepcopy(cest['coverage'])
    if 'cest' in cest_coverage:
        cest_coverage = cest_coverage['cest']
    cest_coverage['scope'] = 'BR'
    cest_coverage['snapshot_date'] = consulted
    result['coverage']['cest'] = cest_coverage
    for kind in ('ncm', 'origin', 'crt', 'csosn', 'icms_cst', 'pis_cofins_cst', 'ipi_cst',
                 'ibs_cbs_cst', 'ibs_cbs_class'):
        coverage = result['coverage'].get(kind)
        if isinstance(coverage, dict) and coverage.get('status') == 'complete':
            coverage.setdefault('scope', 'BR')
    by_uf = {}
    for extract in benefits:
        coverage = extract.get('coverage', {})
        coverage = coverage.get('benefit', coverage)
        for uf, scope in coverage.get('by_uf', {}).items():
            if uf not in UFS:
                raise ValueError('Unknown state in official benefit coverage: ' + uf)
            if uf in by_uf:
                raise ValueError('Overlapping state research: ' + uf)
            by_uf[uf] = copy.deepcopy(scope)
    for uf in UFS:
        by_uf.setdefault(uf, {'status': 'not_bundled', 'count': 0,
                             'note': 'Não foi incorporada tabela estadual validada. Não implica dispensa de cBenef.'})
    result['coverage']['benefit'] = {
        'status': 'partial', 'scope': 'UF', 'count': sum(entry['kind'] == 'benefit' for entry in entries),
        'snapshot_date': consulted, 'by_uf': {uf: by_uf[uf] for uf in UFS},
        'note': 'Tabelas estaduais têm fontes, versões e condições próprias. Cobertura nacional de CFOP/CEST '
                'não constitui catálogo completo de benefícios, MVA, alíquotas ou incidência de ST por UF.'}

    # Every old code/scope remains identifiable, even where a reference is
    # replaced by a complete national definition or retained only historically.
    old = {(e['kind'], e['code'], e.get('uf'), e.get('regime')) for e in base['entries']}
    new = {(e['kind'], e['code'], e.get('uf'), e.get('regime')) for e in entries}
    missing = old - new
    if missing:
        raise ValueError('Update omits previous catalog identities: ' + repr(sorted(missing)))
    identities = [(e['kind'], e['code'], e.get('uf'), e.get('regime'), e.get('valid_from')) for e in entries]
    if len(set(identities)) != len(identities):
        counts = {}
        for key in identities:
            counts[key] = counts.get(key, 0) + 1
        raise ValueError('Duplicate record identities: ' + repr([k for k, v in counts.items() if v > 1]))
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True)
    parser.add_argument('--cfop', required=True)
    parser.add_argument('--cest', required=True)
    parser.add_argument('--benefits', action='append', default=[])
    parser.add_argument('--version', default='2026.10.26.1')
    parser.add_argument('--consulted-at', default='2026-10-26')
    parser.add_argument('--output', required=True)
    parser.add_argument('--pointer')
    args = parser.parse_args()
    result = combine(read(args.base), read(args.cfop), read(args.cest),
                     [read(path) for path in args.benefits], args.version, args.consulted_at)
    dump(args.output, result)
    digest = hashlib.sha256(Path(args.output).read_bytes()).hexdigest()
    if args.pointer:
        dump(args.pointer, {'version': args.version, 'file': Path(args.output).name, 'sha256': digest})
    print(json.dumps({'version': args.version, 'records': len(result['entries']), 'sha256': digest}, ensure_ascii=False))


if __name__ == '__main__':
    main()
