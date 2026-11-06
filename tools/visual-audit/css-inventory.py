#!/usr/bin/env python3
"""Conservative first-party CSS/cascade inventory, with no runtime dependency.

This lexical reader understands the repository's ordinary declaration rules,
grouping at-rules, strings, comments, functions and keyframes. It is not a CSS
conformance validator: browser computed-style tests remain the source of truth.
Duplicates are candidates for review, never an automatic deletion instruction.
"""
from __future__ import annotations

import argparse
from collections import Counter, defaultdict
from dataclasses import dataclass
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
CRITICAL = {'height', 'min-height', 'max-height', 'border', 'border-radius',
            'color', 'background', 'box-shadow', 'padding', 'font', 'font-size',
            'font-family', 'font-weight', 'line-height'}
PRINT_PATH = re.compile(r'(?:nfce-danfe|quote-pdf|receipts/print|pdv/receipt)', re.I)


def normalize(value: str) -> str:
    """Collapse whitespace/comments outside quoted values only."""
    result, quote, i, space = [], None, 0, False
    while i < len(value):
        char = value[i]
        if quote:
            result.append(char)
            if char == '\\' and i + 1 < len(value):
                i += 1
                result.append(value[i])
            elif char == quote:
                quote = None
        elif value.startswith('/*', i):
            end = value.find('*/', i + 2)
            i = len(value) if end < 0 else end + 1
            space = True
        elif char in '\"\'':
            if space and result:
                result.append(' ')
            space, quote = False, char
            result.append(char)
        elif char.isspace():
            space = True
        else:
            if space and result:
                result.append(' ')
            space = False
            result.append(char)
        i += 1
    return ''.join(result).strip()


def boundary(text: str, start: int, end: int, delimiters: str) -> int:
    quote, parens, brackets, i = None, 0, 0, start
    while i < end:
        char = text[i]
        if quote:
            if char == '\\':
                i += 1
            elif char == quote:
                quote = None
        elif text.startswith('/*', i):
            close = text.find('*/', i + 2, end)
            i = end if close < 0 else close + 1
        elif char in '\"\'':
            quote = char
        elif char == '(':
            parens += 1
        elif char == ')':
            parens -= 1
        elif char == '[':
            brackets += 1
        elif char == ']':
            brackets -= 1
        elif not parens and not brackets and char in delimiters:
            return i
        i += 1
    return end


def close_block(text: str, start: int, end: int) -> int:
    depth, i = 1, start
    while i < end:
        pos = boundary(text, i, end, '{}')
        if pos == end:
            return end
        depth += 1 if text[pos] == '{' else -1
        if depth == 0:
            return pos
        i = pos + 1
    return end


@dataclass
class Rule:
    file: str
    line: int
    context: tuple[str, ...]
    selector: str
    declarations: list[dict]


def read_declarations(text: str, start: int, end: int) -> list[dict]:
    values, i = [], start
    while i < end:
        finish = boundary(text, i, end, ';')
        piece = normalize(text[i:finish])
        if ':' in piece:
            name, value = piece.split(':', 1)
            name = name.strip().lower()
            if re.fullmatch(r'--[\w-]+|[a-z-]+', name):
                important = re.search(r'!\s*important\s*$', value, re.I)
                values.append({'property': name, 'value': value[:important.start()].strip() if important else value.strip(),
                               'important': bool(important), 'line': text.count('\n', 0, i) + 1})
        i = finish + 1
    return values


def parse_css(text: str, file: str, context: tuple[str, ...] = (), start: int = 0,
              end: int | None = None) -> tuple[list[Rule], list[dict]]:
    rules, warnings, end = [], [], len(text) if end is None else end
    i = start
    while i < end:
        while i < end and text[i].isspace():
            i += 1
        if text.startswith('/*', i):
            finish = text.find('*/', i + 2, end)
            if finish < 0:
                warnings.append({'file': file, 'line': text.count('\n', 0, i) + 1, 'message': 'Unclosed comment'})
                break
            i = finish + 2
            continue
        stop = boundary(text, i, end, '{;')
        if stop == end:
            break
        prelude = normalize(text[i:stop])
        if text[stop] == ';':
            i = stop + 1
            continue
        close = close_block(text, stop + 1, end)
        if close == end:
            warnings.append({'file': file, 'line': text.count('\n', 0, stop) + 1, 'message': 'Unclosed block'})
            break
        line = text.count('\n', 0, i) + 1
        if prelude.startswith('@') and not re.match(r'@(?:font-face|page|property|counter-style)\b', prelude, re.I):
            nested, errors = parse_css(text, file, context + (prelude,), stop + 1, close)
            rules.extend(nested)
            warnings.extend(errors)
        else:
            rules.append(Rule(file, line, context, prelude, read_declarations(text, stop + 1, close)))
        i = close + 1
    return rules, warnings


def tracked(ref: str) -> dict[str, str]:
    names = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', ref,
                                     '--', 'public/css', 'resources/views'], cwd=ROOT, text=True).splitlines()
    return {name: subprocess.check_output(['git', 'show', ref + ':' + name], cwd=ROOT).decode('utf-8')
            for name in names if name.endswith(('.css', '.blade.php'))}


def working_tree() -> dict[str, str]:
    return {str(path.relative_to(ROOT)): path.read_text()
            for folder in ('public/css', 'resources/views') for path in (ROOT / folder).rglob('*')
            if path.is_file() and path.name.endswith(('.css', '.blade.php'))}


def analyze(files: dict[str, str]) -> dict:
    rules, parse_warnings, inline, sources, layout_order = [], [], [], [], []
    for name, text in files.items():
        sources.append({'file': name, 'lines': len(text.splitlines()), 'sha256': hashlib.sha256(text.encode()).hexdigest()})
        if name.endswith('.css'):
            found, errors = parse_css(text, name)
            rules.extend(found)
            parse_warnings.extend(errors)
        else:
            styles = list(re.finditer(r'<style\b[^>]*>(.*?)</style\s*>', text, re.I | re.S))
            attributes = list(re.finditer(r'\bstyle\s*=\s*("[^"]*"|\'[^\']*\')', text, re.I))
            if styles or attributes:
                inline.append({'file': name, 'print_document': bool(PRINT_PATH.search(name)),
                               'style_blocks': [{'line': text.count('\n', 0, x.start()) + 1,
                                                 'characters': len(x.group(1))} for x in styles],
                               'style_attributes': [{'line': text.count('\n', 0, x.start()) + 1,
                                                    'value': x.group(1)[1:-1]} for x in attributes]})
            for index, style in enumerate(styles):
                prefix = '\n' * text.count('\n', 0, style.start(1))
                found, errors = parse_css(prefix + style.group(1), name + '#style-' + str(index + 1))
                rules.extend(found)
                parse_warnings.extend(errors)
            if name.startswith(('resources/views/layouts/', 'resources/views/auth/')):
                assets = [{'file': match.group(1), 'line': text.count('\n', 0, match.start()) + 1}
                          for match in re.finditer(r"asset\(['\"](css/[^'\"]+)['\"]\)", text)]
                if assets:
                    layout_order.append({'file': name, 'stylesheets': assets,
                                         'style_stack_line': next((i for i, line in enumerate(text.splitlines(), 1)
                                                                   if "@stack('styles')" in line), None)})
    exact, same_property, globals_, within = defaultdict(list), defaultdict(list), defaultdict(list), []
    properties, important, important_uses = 0, 0, []
    for rule in rules:
        body = tuple((d['property'], d['value'], d['important']) for d in rule.declarations)
        if body:
            exact[(rule.file, rule.context, rule.selector, body)].append(rule.line)
        seen = {}
        for declaration in rule.declarations:
            properties += 1
            important += declaration['important']
            prop = declaration['property']
            value = declaration['value'] + (' !important' if declaration['important'] else '')
            if declaration['important']:
                print_only = bool(PRINT_PATH.search(rule.file)) or any('print' in part for part in rule.context)
                appearance = prop in CRITICAL or prop.startswith(('border-', 'font-', 'padding-', 'background-'))
                important_uses.append({'file': rule.file, 'line': declaration['line'], 'selector': rule.selector,
                                       'context': list(rule.context), 'property': prop, 'value': declaration['value'],
                                       'review_severity': 'medium' if appearance and not print_only else 'low',
                                       'reason': 'Preserve fiscal print geometry' if print_only else
                                       'May bypass the shared component contract; inspect computed styles' if appearance else
                                       'Layout, visibility or interaction contract; inspect before changing'})
            signature = (prop, value)
            if signature in seen:
                within.append({'file': rule.file, 'selector': rule.selector, 'context': list(rule.context),
                               'property': prop, 'value': value, 'earlier_line': seen[signature],
                               'later_line': declaration['line']})
            seen[signature] = declaration['line']
            if prop in CRITICAL or prop.startswith(('border-', 'font-', 'padding-', 'background-')):
                same_property[(rule.file, rule.context, rule.selector, prop)].append({'line': declaration['line'], 'value': value})
            if rule.selector == ':root' and prop.startswith(('--ui-', '--lumeron-')):
                globals_[prop].append({'file': rule.file, 'line': declaration['line'], 'context': list(rule.context), 'value': value})
    duplicates = [{'file': key[0], 'context': list(key[1]), 'selector': key[2], 'lines': lines,
                   'declarations': [{'property': d[0], 'value': d[1], 'important': d[2]} for d in key[3]]}
                  for key, lines in exact.items() if len(lines) > 1]
    conflicts = [{'file': key[0], 'context': list(key[1]), 'selector': key[2], 'property': key[3], 'values': values}
                 for key, values in same_property.items() if len({v['value'] for v in values}) > 1]
    duplicates.sort(key=lambda x: (x['file'], x['lines'][0]))
    conflicts.sort(key=lambda x: (x['file'], x['values'][0]['line']))
    count = {'stylesheets': sum(name.endswith('.css') for name in files),
             'blade_files': sum(name.endswith('.blade.php') for name in files),
             'rules': len(rules), 'declarations': properties, 'important_declarations': important,
             'exact_duplicate_rule_groups': len(duplicates),
             'redundant_exact_rule_instances': sum(len(v['lines']) - 1 for v in duplicates),
             'same_rule_duplicate_declarations': len(within),
             'same_selector_appearance_conflicts': len(conflicts),
             'inline_screen_style_blocks': sum(len(v['style_blocks']) for v in inline if not v['print_document']),
             'inline_screen_style_attributes': sum(len(v['style_attributes']) for v in inline if not v['print_document']),
             'parse_warnings': len(parse_warnings)}
    return {'counts': count, 'sources': sources, 'exact_duplicate_rules': duplicates,
            'important_declarations': important_uses,
            'same_rule_duplicate_declarations': within, 'same_selector_appearance_conflicts': conflicts,
            'inline_styles': inline, 'global_token_definitions': dict(sorted(globals_.items())),
            'duplicate_global_token_definitions': {k: v for k, v in globals_.items() if len(v) > 1},
            'layout_stylesheet_order': layout_order, 'parse_warnings': parse_warnings}


def checks(data: dict) -> dict:
    tokens = data['global_token_definitions']
    owners = sorted({item['file'] for locations in tokens.values() for item in locations})
    central = 'public/css/ui-tokens.css'
    order = []
    for layout in data['layout_stylesheet_order']:
        if not layout['file'].startswith('resources/views/layouts/'):
            continue
        files = [item['file'] for item in layout['stylesheets']]
        if 'css/ui-refinement.css' not in files:
            continue
        before = [f for f in files if f != 'css/commercial-readonly.css']
        refinement_line = next(x['line'] for x in layout['stylesheets'] if x['file'] == 'css/ui-refinement.css')
        order.append({'file': layout['file'],
                      'tokens_before_components': bool(files) and files[0] == central.replace('public/', ''),
                      'central_after_module_stylesheets': before[-1] == 'css/ui-refinement.css',
                      'central_after_style_stack': layout['style_stack_line'] is None or refinement_line > layout['style_stack_line']})
    return {'central_token_file': central, 'central_token_file_present': central in owners,
            'all_global_ui_tokens_have_one_owner': owners == [central],
            'global_ui_token_owners': owners,
            'duplicate_global_ui_token_count': len(data['duplicate_global_token_definitions']),
            'layouts': order}


def self_test() -> None:
    sample = '.x{content:"};A  B";height:38px} @media screen{.x{height:40px}.x{height:40px}} @media print{.x{height:20mm}}'
    rules, errors = parse_css(sample, 'fixture.css')
    assert not errors and len(rules) == 4
    assert rules[0].declarations[0]['value'] == '"};A  B"'
    result = analyze({'fixture.css': sample})
    assert result['counts']['exact_duplicate_rule_groups'] == 1
    assert result['counts']['same_selector_appearance_conflicts'] == 0
    assert normalize('a /* comment */ b') == 'a b'
    assert normalize('"a  b"') == '"a  b"'
    assert boundary('url("data:a;b");x', 0, 18, ';') == 15
    assert analyze({'fixture.css': '.x{height:38px;height:38px}'})['counts']['same_rule_duplicate_declarations'] == 1
    print('CSS inventory lexical/context tests passed')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', default='5e81321')
    parser.add_argument('--output', type=Path)
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()
    if args.self_test:
        self_test()
        if not args.output:
            return
    if args.output is None:
        parser.error('--output is required unless --self-test is used')
    before, after = analyze(tracked(args.baseline)), analyze(working_tree())
    report = {'baseline': args.baseline, 'parser': 'Conservative dependency-free lexical scanner; browser validation is separate',
              'interpretation': 'Same file, exact selector and identical media/supports context only. Conflicts and !important are review candidates, not automatic defects. Printed documents and purposeful responsive profiles remain separate.',
              'before': before, 'after': after, 'centralization_checks': checks(after)}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'before': before['counts'], 'after': after['counts'],
                      'centralization_checks': report['centralization_checks']}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
