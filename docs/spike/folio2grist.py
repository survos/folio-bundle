#!/usr/bin/env python3
"""SPIKE -- folio -> Grist. Proof of concept, NOT production.

This is the throwaway script that proved the projection described in
../plan-record-store-projection.md. It is kept because the plan cites its exact
behavior and measurements; the real implementation is PHP in folio-bundle,
calling survos/grist-bundle (which already covers schema, SQL, forms, webhooks
and attachments) over survos/record-store-bundle's portable contracts.

Verified against real artifacts on 2026-08-26:
    dc/0p096w19r.folio       21,069 rows / 3 tables / 13k links  in 12.8s
    dc/ht24xg10q.folio       14,775 rows / 3 tables / 23.8k links in  9.6s
    curatescape/scioto.folio    173 rows / 4 tables / 72 links
    mus/fortepan.folio          999 rows / 1 table

Usage (needs a Grist API key in ./grist.key next to this script):
    python3 folio2grist.py path/to/x.folio DocName

Known-good behaviors worth porting, each of which cost real debugging:
  * tables per CORE, not per dto_type -- `link` is core-to-core
  * batch on payload BYTES; Grist 413s well under 500 rows with large text
  * a facet's cardinality decides Choice vs Text; 5,000 options is not a facet
  * reverse links are free as a Grist formula, and are Grist-ONLY
  * folio natural keys stay as FolioId/LocalId columns; never synthesise identity

Reader note: this opens the source read-only, but does NOT pass immutable=1, so
it leaves -wal/-shm behind on a WAL-mode folio -- see mono issue on folio close().
"""
import json, os, re, sqlite3, sys, urllib.request, urllib.error

SP  = os.path.dirname(os.path.abspath(__file__))
KEY = open(os.path.join(SP,'grist.key')).read().strip()
API = 'http://localhost:8484/api'

def call(m,p,pl=None):
    d=json.dumps(pl).encode() if pl is not None else None
    r=urllib.request.Request(API+p,data=d,method=m,
        headers={'Authorization':'Bearer '+KEY,'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(r,timeout=600) as x:
            b=x.read().decode(); return json.loads(b) if b.strip() else None
    except urllib.error.HTTPError as e:
        raise SystemExit(f'{m} {p} -> {e.code}\n{e.read().decode()[:700]}')


def chunks(items, budget=900_000):
    """Yield batches under Grist's request-size cap. Rows vary wildly in size
    (a denseSummary vs a person's name), so batch on bytes, not row count."""
    cur, size = [], 0
    for it in items:
        n = len(json.dumps(it, ensure_ascii=False))
        if cur and (size + n > budget or len(cur) >= 500):
            yield cur; cur, size = [], 0
        cur.append(it); size += n
    if cur: yield cur

ident = lambda n: (lambda s: ('c_'+s) if not s[:1].isalpha() else s)(re.sub(r'[^A-Za-z0-9_]','_',n))
def gtype(php, facet, card):
    php=(php or '').lstrip('?')
    if php=='int': return 'Int'
    if php=='float': return 'Numeric'
    if php=='bool': return 'Bool'
    if php=='array': return 'ChoiceList' if facet and card and card<=500 else 'Text'
    if facet and card and 0<card<=200: return 'Choice'
    return 'Text'

def convert(path, doc_name):
    c=sqlite3.connect(f'file:{path}?mode=ro',uri=True); c.row_factory=sqlite3.Row
    cores=[r['name'][4:] for r in c.execute(
        "select name from schema_table where kind='core' order by row_count desc")]
    dto_by_core={}
    for r in c.execute("select * from schema_table where kind='dto'"):
        # dto table names are dto_<core>_<x> or dto_<x>; match via a probe row
        dto_by_core.setdefault(r['dto_type'], r['id'])

    # which core does each dto_type belong to? ask the data.
    core_of={}
    for r in c.execute("select distinct dto_type, substr(id, instr(id,':')+1) s from item"):
        core_of[r['dto_type']] = r['s'].split(':')[0]

    ws=[w for w in call('GET','/orgs/survos/workspaces') if w['name']=='Home'][0]
    for d in ws['docs']:
        if d['name']==doc_name: call('DELETE',f"/docs/{d['id']}")
    DOC=call('POST',f"/workspaces/{ws['id']}/docs",{'name':doc_name}); D=f'/docs/{DOC}'
    print(f"{path}\n -> doc {DOC}")

    # ---- build one table per core -----------------------------------------
    core_props={}
    for core in cores:
        types=[t for t,cc in core_of.items() if cc==core]
        if not types: continue
        props={}
        for t in types:
            tid=dto_by_core.get(t)
            if not tid: continue
            for p in c.execute("select * from schema_property where table_id=? order by position",(tid,)):
                props.setdefault(p['name'], dict(p))
        core_props[core]=(types, props)

    made=[]
    for core,(types,props) in core_props.items():
        ph=",".join("?"*len(types))
        card={}
        for n,p in props.items():
            e=f"json_extract(dto_data,'$.{n}')"
            try:
                if (p['type'] or '').lstrip('?')=='array':
                    card[n]=c.execute(f"select count(distinct value) from item, json_each({e}) "
                                      f"where dto_type in ({ph}) and json_valid({e})",types).fetchone()[0]
                else:
                    card[n]=c.execute(f"select count(distinct {e}) from item where dto_type in ({ph})",
                                      types).fetchone()[0]
            except sqlite3.Error: card[n]=None
        cols=[{'id':'FolioId','fields':{'label':'folio id','type':'Text',
                'description':'Natural key from the folio artifact (item.id)'}},
              {'id':'LocalId','fields':{'label':'local id','type':'Text'}},
              {'id':'Label','fields':{'label':'Label','type':'Text'}},
              {'id':'DtoType','fields':{'label':'Type','type':'Choice',
                'widgetOptions':json.dumps({'choices':sorted(types)})}}]
        for n,p in props.items():
            if n=='id': continue
            f={'label':p['label'] or n,'type':gtype(p['type'],p['facet'],card.get(n))}
            if p['description']: f['description']=p['description']
            cols.append({'id':ident(n),'fields':f})
        tname=ident(core.capitalize())
        call('POST',D+'/tables',{'tables':[{'id':tname,'columns':cols}]})
        made.append((core,tname,types,props,card))
    call('POST',D+'/apply',[['RemoveTable','Table1']])

    # choices need declaring before values arrive
    for core,tname,types,props,card in made:
        ph=",".join("?"*len(types)); upd=[]
        for n,p in props.items():
            if n=='id': continue
            gt=gtype(p['type'],p['facet'],card.get(n))
            if gt not in ('Choice','ChoiceList'): continue
            e=f"json_extract(dto_data,'$.{n}')"
            if (p['type'] or '').lstrip('?')=='array':
                vals=[r[0] for r in c.execute(f"select distinct value from item, json_each({e}) "
                      f"where dto_type in ({ph}) and json_valid({e}) order by 1",types)]
            else:
                vals=[r[0] for r in c.execute(f"select distinct {e} from item where dto_type in ({ph}) order by 1",types)]
            vals=[str(v) for v in vals if v not in (None,'')]
            if vals: upd.append({'id':ident(n),'fields':{'widgetOptions':json.dumps({'choices':vals})}})
        if upd: call('PATCH',D+f'/tables/{tname}/columns',{'columns':upd})

    # ---- rows --------------------------------------------------------------
    rowid={}   # (core, local_id) -> grist rowId
    for core,tname,types,props,card in made:
        ph=",".join("?"*len(types)); recs=[]; locals_=[]
        for row in c.execute(f"select id,local_id,label,dto_type,dto_data from item "
                             f"where dto_type in ({ph})",types):
            data=json.loads(row['dto_data'] or '{}')
            f={'FolioId':row['id'],'LocalId':row['local_id'],
               'Label':row['label'] or '','DtoType':row['dto_type']}
            for n,p in props.items():
                if n=='id': continue
                v=data.get(n)
                if v is None: continue
                gt=gtype(p['type'],p['facet'],card.get(n))
                if gt=='ChoiceList':
                    f[ident(n)]=['L']+[str(x) for x in (v if isinstance(v,list) else [v])]
                elif isinstance(v,(list,dict)): f[ident(n)]=json.dumps(v,ensure_ascii=False)
                else: f[ident(n)]=v
            recs.append({'fields':f}); locals_.append(row['local_id'])
        out=[]
        for batch in chunks(recs):
            out+=call('POST',D+f'/tables/{tname}/records',{'records':batch})['records']
        for lid,rec in zip(locals_,out): rowid[(core,lid)]=rec['id']
        print(f"   {tname:<10} {len(recs):>6} rows, {len(props)} props, types={types}")

    # ---- links become RefList columns --------------------------------------
    tbl_of={core:t for core,t,_,_,_ in made}
    for lt in c.execute("select * from link_type"):
        lc,rc,code = lt['left_core'], lt['right_core'], lt['code']
        if lc not in tbl_of or rc not in tbl_of: continue
        colname=ident(code.title().replace('_',''))
        call('POST',D+f'/tables/{tbl_of[lc]}/columns',{'columns':[
            {'id':colname,'fields':{'label':code,'type':f'RefList:{tbl_of[rc]}'}}]})
        agg={}
        for l in c.execute("select left_id,right_id from link where link_type_id=?",(lt['id'],)):
            a=rowid.get((lc,l['left_id'])); b=rowid.get((rc,l['right_id']))
            if a and b: agg.setdefault(a,[]).append(b)
        upd=[{'id':k,'fields':{colname:['L']+v}} for k,v in agg.items()]
        for batch in chunks(upd):
            call('PATCH',D+f'/tables/{tbl_of[lc]}/records',{'records':batch})
        # the reverse direction, as a formula -- free, and folio names it for us
        rev=ident((lt['reverse_code'] or ('rev_'+code)).title().replace('_',''))
        call('POST',D+f'/tables/{tbl_of[rc]}/columns',{'columns':[
            {'id':rev,'fields':{'label':lt['reverse_code'] or ('rev_'+code),
              'type':f'RefList:{tbl_of[lc]}','isFormula':True,
              'formula':f'{tbl_of[lc]}.lookupRecords({colname}=CONTAINS($id))'}}]})
        print(f"   link {lc}.{code} -> {rc}: {len(agg)} left rows linked; reverse '{rev}' as formula")

    print(f" -> http://localhost:8484/o/survos/{DOC}/{doc_name}")
    return DOC

if __name__=='__main__':
    doc=convert(sys.argv[1], sys.argv[2])
    open(os.path.join(SP,'dc.doc'),'w').write(doc)
