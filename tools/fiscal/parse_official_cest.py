#!/usr/bin/env python3
"""Normalize the official CONFAZ CV142/18 consolidated text, not state ST tables.

The input is an archived numbered text extraction from the official CONFAZ page.
All lines 0..3004 must be present. No rates or presumption of ST are generated.
This deliberately retains unknown individual initial dates as null and archives
historical wording in parameters rather than inventing period boundaries.

python3 tools/fiscal/parse_official_cest.py --input resources/fiscal/sources/confaz-cest-2026-10-26.txt --amendment resources/fiscal/sources/confaz-cest-107-2026-10-26.txt --output /tmp/national-cest.json
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

URL='https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18'
AMENDMENT='https://www.confaz.fazenda.gov.br/legislacao/convenios/2026/CV107_26'
CONSULTED='2026-10-26'
SOURCE='confaz-cest-national-142-18'
START_ROW=re.compile(r'^\s*(\d+(?:\.\d+)?)\.?\s*\|\s*(\d{2}\.\d{3}\.\d{2}|REVOGADO)\s*\|')
NOTE=re.compile(r'^(?:x?Redação|Nova redação|Acrescid[oa]s?|Revogad[oa]s?)',re.I)
CHANGE=re.compile(r'^(?:Nova redação|Acrescid[oa]s?|Revogad[oa]s?)',re.I)
CODE=re.compile(r'^\d{2}\.\d{3}\.\d{2}$')


def strip_link(text: str) -> str:
    return re.sub(r'【[^†]+†([^】]+)】',r'\1',text).replace('\u200b','').replace('\xa0',' ').strip()


def read_lines(path: Path) -> dict[int,str]:
    lines={int(n):strip_link(text) for n,text in re.findall(r'^L(\d+): ?(.*)$',path.read_text(encoding='utf-8'),re.M)}
    if set(lines)!=set(range(3005)):
        raise ValueError('Incomplete official source: numbered lines 0..3004 are required.')
    if 'CONVÊNIO ICMS 142/18' not in lines[205] or '107/26' not in lines[220]:
        raise ValueError('Unexpected official consolidation/version. Revalidate parser before updating.')
    return lines


def iso_date(token: str) -> str:
    token=token.rstrip('.')
    d,m,y=token.split('.')
    if len(y)==2:y='20'+y
    return dt.date(int(y),int(m),int(d)).isoformat()


def item_normalized(item: str) -> str:
    item=item.rstrip('.')
    return item if '.' in item else item+'.0'


def applies(note: str, annex: str, item: str) -> bool:
    if not note or not re.search(r'Anexo\s+'+annex+r'\b',note,re.I):return False
    target=re.search(r'(?:item|itens)\s+(.+?)\s*(?:(?:do|ao|no)\s+)?Anexo',note,re.I)
    if not target:
        if re.search(r'\b(?:item|itens)\b',note,re.I):return False
        return bool(re.search(r'(?:ao|o|do)\s+Anexo\s+'+annex+r'\b',note,re.I))
    expr=target.group(1)
    nums=[item_normalized(n)for n in re.findall(r'\d+(?:\.\d+)?',expr)]
    for a,b in re.findall(r'(\d+(?:\.\d+)?)\s*(?:a|até)\s*(\d+(?:\.\d+)?)',expr):
        a=item_normalized(a).split('.');b=item_normalized(b).split('.')
        if a[0]==b[0]:nums += [a[0]+'.'+str(i) for i in range(int(a[1]),int(b[1])+1)]
        elif a[1]==b[1]=='0':nums += [str(i)+'.0' for i in range(int(a[0]),int(b[0])+1)]
    return item_normalized(item) in nums


def initial_date(note: str,annex: str,item: str) -> str|None:
    if not applies(note,annex,item):return None
    match=re.search(r'efeitos?\s+a partir de\s+(\d{1,2}\.\d{1,2}\.\d{2,4})',note,re.I)
    return iso_date(match.group(1))if match else None


def ncm_links(expression: str) -> tuple[list[str],str]:
    if re.match(r'^Capítulos?\s+',expression,re.I):
        chapter_expression=re.sub(r'^Capítulos?\s+','',expression,flags=re.I)
        chapters=[int(n)for n in re.findall(r'\b\d{2}\b',chapter_expression)]
        for a,b in re.findall(r'(\d{2})\s+a\s+(\d{2})',chapter_expression):
            chapters+=list(range(int(a),int(b)+1))
        residue=re.sub(r'\d{2}|[\s,;]+|\b[ea]\b','',chapter_expression)
        if chapters and not residue:
            return [f'{n:02d}'for n in sorted(set(chapters))],'prefix'
    tokens=re.findall(r'(?<![\d.])(?:\d{4}(?:\.\d{1,2}){0,2}|\d{2})(?![\d.])',expression)
    residue=expression
    for token in tokens:residue=residue.replace(token,' ',1)
    residue=re.sub(r'[\s,;/]+|\be\b','',residue)
    prefixes=list(dict.fromkeys(re.sub(r'\D','',token)for token in tokens))if not residue else []
    return prefixes,'prefix' if prefixes else 'manual'


def parse(source: Path, amendment: Path) -> dict:
    lines=read_lines(source)
    amendment_text=strip_link(amendment.read_text(encoding='utf-8'))
    if 'não se aplica ao Estado de São Paulo' not in amendment_text or '09.09.26' not in amendment_text:
        raise ValueError('Official CV107/26 scope/publication evidence missing.')
    # Publication 09/09/2026 + clause four: first day of second subsequent month.
    future_effect='2026-11-01'
    segment_names={}
    for n in range(543,571):
        p=[v.strip()for v in lines[n].split('|')]
        if len(p)==3 and re.fullmatch(r'\d{2}',p[0])and re.fullmatch(r'\d{2}',p[2]):segment_names[p[2]]=p[1]
    groups=defaultdict(list);annex=None;latest_change='';latest_annotation='';annex_titles={}
    n=571
    while n<2323:
        text=lines[n]
        match=re.fullmatch(r'ANEXO ([IVXLCDM]+)',text)
        if match:
            annex=match.group(1);latest_change='';latest_annotation=''
            after=[lines[i]for i in range(n+1,min(n+6,2323))if lines[i]]
            annex_titles[annex]=next((a for a in after if a.upper()==a and '|'not in a),'')
        if CHANGE.match(text):latest_change=text;latest_annotation=text
        elif NOTE.match(text):latest_annotation=text
        match=START_ROW.match(text)
        if match and annex:
            start=n;combined=text
            # The text renderer splits cells with multiple NCM positions over lines.
            while len(combined.split('|'))<4 and n+1<2323:
                candidate=lines[n+1]
                if NOTE.match(candidate)or START_ROW.match(candidate)or candidate.startswith('ANEXO '):break
                n+=1;combined+=' '+candidate
            parts=[p.strip()for p in combined.split('|')]
            if len(parts)<4:raise ValueError(f'Missing row description at source line {start}')
            item=item_normalized(parts[0]);code=parts[1];ncm=parts[2];description=' | '.join(parts[3:]).strip(' |')
            groups[annex,item].append({'code':code,'ncm':ncm,'description':description,
                'source_line':start,'annotation':latest_annotation,
                'change_annotation':latest_change,'valid_from':initial_date(latest_change,annex,item)})
        n+=1
    if len(groups)!=1054:raise ValueError(f'Unexpected consolidated item count: {len(groups)}; review required.')
    entries=[];revoked=0;future_count=0;manual=0;base_conditions=[
        'Classificação nacional de mercadorias, Anexos II a XXVI do Convênio ICMS 142/2018. Não determina incidência de ICMS-ST em nenhuma UF.',
        'Examinar cumulativamente NCM vigente, descrição, segmento, condições e legislação/acordo específico da operação; CEST ou prefixo NCM isolado não estabelece ST.',
        'Não contém MVA, alíquota, redução, benefício, obrigação de retenção ou determinação automática de tributação.',
    ]
    def entry(row,annex,item,history,status='reference',until=None,uf=None):
        nonlocal manual
        prefixes,link=ncm_links(row['ncm']);manual+=int(link=='manual')
        code=row['code'];from_date=row['valid_from']
        parameters={'display_code':code,'segment_code':code[:2],'segment':segment_names.get(code[:2],annex_titles[annex]),
            'annex':annex,'item':item,'ncm_prefixes':prefixes,'ncm_link_validation':link,
            'source_ncm_expression':row['ncm'],'automatic_application':False,
            'source_line':row['source_line'],'current_wording_annotation':row['change_annotation'] if applies(row['change_annotation'],annex,item)else None,
            'historical_wordings':history,'unknown_initial_effective_date':from_date is None}
        return {'kind':'cest','code':code.replace('.',''),'description':row['description'],
            'source_id':SOURCE,'valid_from':from_date,'valid_until':until,'uf':uf,'regime':None,
            'status':status,'conditions':base_conditions.copy(),'parameters':parameters}
    for (annex,item),rows in groups.items():
        latest=rows[0]
        history=[{'code':r['code'],'ncm_expression':r['ncm'],'description':r['description'],
            'source_line':r['source_line'],'annotation':r['annotation']}for r in rows[1:]]
        is_revoked=latest['code']=='REVOGADO'or latest['description'].upper()=='REVOGADO'
        if is_revoked:
            revoked+=1
            previous=next((r for r in rows[1:]if CODE.match(r['code'])and r['description'].upper()!='REVOGADO'),None)
            if not previous:raise ValueError(f'Revoked item without historical code/description {annex}/{item}')
            revoked_from=initial_date(latest['change_annotation'],annex,item)
            if not revoked_from:raise ValueError(f'Revocation effective date unknown {annex}/{item}')
            end=(dt.date.fromisoformat(revoked_from)-dt.timedelta(days=1)).isoformat()
            record=entry({**previous,'valid_from':None},annex,item,history,'revoked',end)
            record['parameters']['revoked_from']=revoked_from
            record['parameters']['revocation_annotation']=latest['change_annotation']
            entries.append(record);continue
        if latest['valid_from']and latest['valid_from']>CONSULTED:
            if latest['valid_from']!=future_effect or '107/26'not in latest['change_annotation']:
                raise ValueError(f'Future amendment needs explicit scope review: {annex}/{item}')
            future=entry(latest,annex,item,history)
            future['parameters']['excluded_ufs']=['SP']
            future['parameters']['scope_exception_source']=AMENDMENT
            future['conditions'].append('Alteração do Convênio ICMS 107/2026: efeitos em 01/11/2026, sem aplicação ao Estado de São Paulo (cláusulas terceira e quarta).')
            entries.append(future);future_count+=1
            older=next((r for r in rows[1:]if CODE.match(r['code'])and r['code']==latest['code']),None)
            if older:
                # The older row explicitly states effects until 31/10/26.
                if '31.10.26'not in older['annotation']:raise ValueError('Future predecessor end date not proven')
                older={**older,'valid_from':None}
                entries.append(entry(older,annex,item,history,until='2026-10-31'))
                sp=entry(older,annex,item,history,uf='SP')
                sp['parameters']['scope_exception_source']=AMENDMENT
                sp['conditions'].append('São Paulo conserva esta redação por exclusão expressa da cláusula terceira do Convênio ICMS 107/2026; isso não comprova sujeição ao ICMS-ST paulista.')
                entries.append(sp)
        else:entries.append(entry(latest,annex,item,history))
    identities=[(e['kind'],e['code'],e['uf'],e['valid_from'])for e in entries]
    if len(identities)!=len(set(identities)):raise ValueError('Duplicate fiscal identity')
    current=[e for e in entries if e['status']=='reference' and (e['valid_from']is None or e['valid_from']<=CONSULTED)
        and (e['valid_until']is None or e['valid_until']>=CONSULTED) and e['uf']is None]
    source_metadata={'id':SOURCE,'url':URL,'reference':'Convênio ICMS 142/2018, cláusulas segunda a sétima e Anexos I a XXVI; classificação nacional CEST/NCM/descrição.',
        'version':'Texto consolidado oficial até Convênio ICMS 107/2026, consultado em 26/10/2026',
        'consulted_at':CONSULTED,'sha256':hashlib.sha256(source.read_bytes()).hexdigest(),
        'sha256_representation':'Extração textual numerada arquivada da página oficial; não é hash do HTML original.',
        'scope':'BR','conditions':base_conditions+[
            'Consolidação completa dos 25 anexos de mercadorias (II a XXVI): todas as linhas atuais, revogadas e futuras, com redações anteriores preservadas para auditoria.',
            'Datas iniciais individuais permanecem null quando a anotação oficial do item não estabelece a vigência; não são inferidas a partir da data da consulta.',
            'A alteração 107/2026 possui exceção expressa para SP e efeitos a partir de 01/11/2026; classificações nacionais futuras excluem SP e conservam redação específica paulista onde necessário.',
        ]}
    amendment_metadata={'id':'confaz-cest-amendment-107-26','url':AMENDMENT,
        'reference':'Convênio ICMS 107/2026, cláusulas primeira a quarta — alterações do Anexo XX; exclusão expressa de São Paulo.',
        'version':'Publicado no DOU em 09/09/2026; efeitos em 01/11/2026, exceto SP',
        'consulted_at':CONSULTED,'sha256':hashlib.sha256(amendment.read_bytes()).hexdigest(),
        'sha256_representation':'Extração textual arquivada da consulta oficial; não é hash do HTML original.','scope':'BR, exceto SP',
        'conditions':['Cláusula terceira exclui São Paulo de todo o disposto neste convênio.',
            'Cláusula quarta determina efeitos no primeiro dia do segundo mês subsequente ao da publicação.']}
    return {'sources':[source_metadata,amendment_metadata],'entries':entries,
        'coverage':{'status':'complete','scope':'national_classification','annexes':25,'consolidated_items':len(groups),
            'unique_codes':len(set(e['code']for e in entries)),'current_national_codes_at_consultation':len(set(e['code']for e in current)),
            'revoked_codes':revoked,'future_wordings':future_count,'state_exception_wordings':sum(e['uf']is not None for e in entries),
            'count':len(entries),'manual_ncm_links':manual,
            'note':'Classificação nacional CEST integral dos Anexos II a XXVI; não é catálogo de incidência, MVA, alíquotas ou regimes de ST por UF. Vigências individuais desconhecidas permanecem explícitas.'},
        'research_attempts':[{'url':URL,'method':'HTTPS direto pelo ambiente','result':'HTTP 503 em 26/10/2026'},
            {'url':URL,'method':'Ferramenta de navegação conectada; página oficial completa, linhas 0 a 3004','result':'Consulta bem-sucedida; captura textual integral arquivada'},
            {'url':AMENDMENT,'method':'Link oficial 107/26 na consolidação do Convênio 142/18','result':'Consulta oficial bem-sucedida; cláusulas 1 a 4 verificadas'}]}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input',type=Path,required=True)
    parser.add_argument('--amendment',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    result=parse(args.input,args.amendment)
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(result['coverage'],ensure_ascii=False))

if __name__=='__main__':main()
