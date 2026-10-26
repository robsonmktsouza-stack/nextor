#!/usr/bin/env python3
"""Extract cStat messages from pdftotext -layout official NF-e NT tables.
The message column is isolated from the business-rule column. Codes aren't
classified as authorizations/rejections by this source extractor.
"""
import argparse, hashlib, json, pathlib, re, subprocess
ROW = re.compile(r'(?<![\d.])(?P<code>\d{3,4})\s+(?:(?P<effect>Rej\.?|Alerta|Denega[cç][aã]o)\s+)?(?P<message>(?:Rejei[cç][aã]o|Denegado|Denega[cç][aã]o|Alerta)\s*:.*)',re.I)
STATUS = re.compile(r'^\s*(?P<code>\d{3,4})\s+(?P<effect>Autoriza[cç][aã]o)\s+(?P<message>Autorizado.+)$',re.I)
RULE = re.compile(r'^\s*([A-Za-z0-9_.]+-\d{1,3})\s+')

def extract(text, source_id):
    entries={}
    for page_number,page in enumerate(text.split('\f'),1):
        lines=page.splitlines()
        for i,line in enumerate(lines):
            match=ROW.search(line) or STATUS.search(line)
            if not match:continue
            code=match.group('code')
            col=match.start('message')
            parts=[match.group('message').strip()]
            for other in lines[i+1:]:
                if not other.strip() or ROW.search(other) or STATUS.search(other):break
                tail=other[col:].strip() if len(other)>col else ''
                if not tail or re.search(r'Página \d|Descrição Erro|^NT \d',tail) or re.match(r'(?:Rejeição|Denegação|Alerta)\s*:',tail):break
                # Continuation starts on the same column (allow PDF one-space drift).
                # Reject a tail that starts midway through a word from another column.
                if col and len(other)>col and other[col-1:col+1].strip()==other[col-1:col+1] and len(other[col-1:col+1])==2:
                    break
                parts.append(tail)
            message=' '.join(parts)
            message=re.sub(r'\s+',' ',message).strip()
            rule=RULE.match(line)
            ref={'source_id':source_id,'page':page_number}
            if rule:ref['rule']=rule.group(1)
            if match.groupdict().get('effect'):ref['effect']=match.group('effect')
            entry=entries.setdefault(code,{'message':message,'source_ids':[source_id],'references':[]})
            if ref not in entry['references']:entry['references'].append(ref)
            if entry['message']!=message:
                entry.setdefault('variants',[])
                if message not in entry['variants']:entry['variants'].append(message)
                # A compact final code/message table is more reliable than a narrow
                # validation column (e.g. NF-e split as NF- e in the latter).
                if col < 20:
                    previous=entry['message'];entry['message']=message
                    entry['variants']=[v for v in entry['variants'] if v!=message]
                    if previous not in entry['variants']:entry['variants'].append(previous)
    return entries

def document_version(filename, text):
    # A textual "Versão 3" may describe the QR Code instead of the NT.
    match = re.search(r'v(\d+(?:[._]\d+)+[a-z]?)', filename, re.I)
    if not match:
        match = re.search(r'(?:Versão|Vers[ãa]o)\s+(\d+(?:\.\d+)+[a-z]?)', text[:1200], re.I)
    return match.group(1).replace('_', '.') if match else None


def build(manifest, source_dir):
    source_dir = pathlib.Path(source_dir)
    sources=[];codes={};stats=[]
    # RTC 2024.002 replaced by NT2025.002: retained for historical diagnosis;
    # load in ascending NT order so revised documents replace old text.
    for item in sorted(manifest,key=lambda d:d.get('nt','')):
        if 'error' in item:continue
        nt=item['nt']
        filename=item.get('filename', item.get('file', ''))
        if pathlib.Path(filename).name != filename or not filename.lower().endswith('.pdf'):
            raise ValueError('Invalid source PDF filename')
        path=source_dir/filename
        raw=path.read_bytes()
        if hashlib.sha256(raw).hexdigest()!=item['sha256']:
            raise ValueError('Official source SHA-256 mismatch: '+filename)
        if not raw.startswith(b'%PDF'):
            raise ValueError('Official source is not a PDF: '+filename)
        text=subprocess.check_output(['pdftotext','-layout',str(path),'-'],text=True)
        version = document_version(filename, text)
        sid='nfe-nt-'+nt.replace('.','-')
        source={'id':sid,'title':'NF-e Nota Técnica '+nt,'url':item['url'],'version':version,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}
        extracted=extract(text,sid)
        stats.append({'nt':nt,'codes':len(extracted)})
        if not extracted:continue
        sources.append(source)
        for code,entry in extracted.items():
            if code not in codes:codes[code]=entry;continue
            old=codes[code]
            messages=[]
            for m in [old['message']]+old.get('variants',[])+[entry['message']]+entry.get('variants',[]):
                if m not in messages:messages.append(m)
            entry['source_ids']=list(dict.fromkeys(old['source_ids']+entry['source_ids']))
            entry['references']=old['references']+entry['references']
            if len(messages)>1:entry['variants']=[m for m in messages if m!=entry['message']]
            codes[code]=entry
    return {'sources':sources,'codes':dict(sorted(codes.items(),key=lambda x:int(x[0]))),'stats':stats}

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', required=True, type=pathlib.Path)
    parser.add_argument('--source-dir', required=True, type=pathlib.Path)
    parser.add_argument('--output', required=True, type=pathlib.Path)
    args = parser.parse_args()
    result = build(json.loads(args.manifest.read_text()), args.source_dir)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'sources': len(result['sources']), 'codes': len(result['codes']), 'stats': result['stats']}, ensure_ascii=False))
