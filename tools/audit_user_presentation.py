#!/usr/bin/env python3
"""Inventory all first-party presentation paths and locate review candidates.

Candidates are evidence for human review, not automatic findings. Serialization
inside scripts, hidden fields and integration contracts is deliberately retained.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PREFIXES = ('app/', 'bootstrap/', 'config/', 'lang/', 'routes/',
            'resources/views/', 'public/js/', 'public/css/')
PATTERNS = {
    'debug_output': re.compile(r'(?<![\w.])(?:dd|dump|var_dump|print_r)\s*\(|console\.(?:log|debug)\s*\('),
    'raw_json_display': re.compile(r'<pre\b[^\n]*|\{\{\s*json_encode\('),
    'exception_sink': re.compile(r'(?:abort\(|with\([\'"](?:error|warning)|[\'"]message[\'"]\s*=>).*?\$\w+->getMessage\('),
    'native_js_error_sink': re.compile(r'(?:textContent\s*=|notify\(|Notify\().*?\b(?:e|err|error)\.message'),
    'raw_internal_value': re.compile(r'\{\{[^\n]*?(?:sha256|snapshot|catalog_version|->hash|->revision)[^\n]*?\}\}'),
    'status_fallback': re.compile(r'\{\{[^\n]*(?:ucfirst|strtoupper)\([^\n]*(?:status|type|operation)[^\n]*\}\}'),
}


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def scan(path: str, text: str) -> list[dict]:
    candidates = []
    for line_no, line in enumerate(text.splitlines(), 1):
        stripped = line.strip()
        if stripped.startswith(('//', '/*', '*', '<!--', '{{--')):
            continue
        for kind, pattern in PATTERNS.items():
            if pattern.search(line):
                candidates.append({'file': path, 'line': line_no, 'kind': kind,
                                   'text': stripped[:600]})
    return candidates


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--baseline', default='bf73a66')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    tracked = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', args.baseline], cwd=ROOT, text=True).splitlines()
    current = [str(p.relative_to(ROOT)) for prefix in PREFIXES
               for p in (ROOT / prefix).rglob('*') if p.is_file()]
    names = sorted({p for p in tracked + current
                    if p.startswith(PREFIXES) and Path(p).suffix in ('.php', '.js', '.css')
                    and '/vendor/' not in p and not p.startswith('bootstrap/cache/')})
    entries, before, after = [], [], []
    for name in names:
        old = subprocess.run(['git', 'show', args.baseline + ':' + name], cwd=ROOT, capture_output=True).stdout if name in tracked else b''
        path = ROOT / name
        new = path.read_bytes() if path.exists() else b''
        old_text, new_text = old.decode('utf-8', errors='replace'), new.decode('utf-8', errors='replace')
        before.extend(scan(name, old_text))
        after.extend(scan(name, new_text))
        entries.append({'file': name, 'lines_before': len(old_text.splitlines()),
                        'lines_after': len(new_text.splitlines()),
                        'sha256_before': digest(old) if old else None,
                        'sha256_after': digest(new) if new else None,
                        'changed': old != new})
    report = {'baseline': args.baseline, 'files_scanned': len(entries),
              'lines_scanned': sum(v['lines_after'] for v in entries),
              'files': entries, 'candidates_before': before, 'candidates_after': after,
              'method': 'Whole-source static scan plus module-specific manual review; candidates require contextual disposition.'}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'files_scanned': len(entries), 'lines_scanned': report['lines_scanned'],
                      'candidates_before': len(before), 'candidates_after': len(after)}))


if __name__ == '__main__':
    main()
