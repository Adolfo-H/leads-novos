#!/usr/bin/env python3
"""Local-only, bounded extraction for manual review. No network, no SMTP."""
import csv
import io
import json
import os
import re
import resource
import subprocess
import sys
import zipfile
import xml.etree.ElementTree as ET
from pathlib import PurePosixPath

MAX_FILE = 8 * 1024 * 1024
MAX_EXPANDED = 48 * 1024 * 1024
MAX_ROWS = 5000
MAX_COLS = 128
MAX_CELL = 512
MAX_OUTPUT = 3 * 1024 * 1024
EMAIL = re.compile(r"(?<![\w.+-])[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+(?![\w-]|\.[\w-])")
MAIL_HEADERS = {'email', 'e-mail', 'e_mail', 'e mail', 'mail', 'email address', 'email_address', 'endereco de email', 'endereço de e-mail', 'endereco_email', 'email corporativo', 'email pessoal'}

class ImportErrorSafe(Exception):
    pass


def decode(raw):
    if raw.startswith((b'\xff\xfe', b'\xfe\xff')):
        return raw.decode('utf-16')
    try:
        return raw.decode('utf-8-sig')
    except UnicodeDecodeError:
        return raw.decode('cp1252', errors='replace')


def text_table(text):
    # Text-only sources preserve no inferred association with names/companies.
    matches = EMAIL.findall(text)
    if len(matches) > MAX_ROWS:
        raise ImportErrorSafe('too_many_rows')
    return {'columns': ['email'], 'rows': [[v] for v in matches], 'kind': 'text'}


def table(raw_rows):
    rows = [[str(v or '').strip()[:MAX_CELL] for v in row] for row in raw_rows if any(str(v or '').strip() for v in row)]
    if not rows:
        raise ImportErrorSafe('empty_file')
    width = max(map(len, rows))
    if width > MAX_COLS or len(rows) > MAX_ROWS + 1:
        raise ImportErrorSafe('too_many_rows')
    first = rows[0]
    # Headerless lists are retained, not silently treated as a header.
    has_header = any(x.casefold() in MAIL_HEADERS for x in first) or not any(EMAIL.search(x) for x in first)
    cols = [(first[i] if i < len(first) and first[i] else f'Coluna {i+1}') if has_header else f'Coluna {i+1}' for i in range(width)]
    rows = rows[1:] if has_header else rows
    if len(rows) > MAX_ROWS:
        raise ImportErrorSafe('too_many_rows')
    # Empty columns are discarded; indexes stay consistent within returned table.
    indexes = [i for i in range(width) if any(i < len(row) and row[i] for row in rows)]
    if not indexes:
        raise ImportErrorSafe('empty_file')
    return {'columns': [cols[i][:120] for i in indexes], 'rows': [[row[i] if i < len(row) else '' for i in indexes] for row in rows], 'kind': 'table'}


def read_csv(raw):
    text = decode(raw)
    if '\x00' in text:
        raise ImportErrorSafe('invalid_file')
    sample = text[:32768]
    # Semicolon exports with dozens of empty columns must not fall back to comma.
    choices = []
    for delim in (';', ',', '\t', '|'):
        try:
            parsed = list(csv.reader(io.StringIO(sample), delimiter=delim))[:12]
            widths = [len(row) for row in parsed if row]
            if widths:
                choices.append((sum(w == widths[0] for w in widths) / len(widths) if widths[0] > 1 else 0, widths[0], delim))
        except csv.Error:
            continue
    delim = max(choices, default=(0, 0, ';'))[2]
    csv.field_size_limit(MAX_FILE)
    rows = []
    for row in csv.reader(io.StringIO(text, newline=''), delimiter=delim, strict=True):
        if any(v.strip() for v in row):
            rows.append(row)
        if len(rows) > MAX_ROWS + 1 or len(row) > MAX_COLS:
            raise ImportErrorSafe('too_many_rows')
    return table(rows)


def open_zip(path):
    z = zipfile.ZipFile(path)
    infos = z.infolist()
    if len(infos) > 2000 or sum(i.file_size for i in infos) > MAX_EXPANDED or any(i.flag_bits & 1 for i in infos):
        z.close()
        raise ImportErrorSafe('unsafe_archive')
    if len({i.filename for i in infos}) != len(infos):
        z.close()
        raise ImportErrorSafe('unsafe_archive')
    return z


def xml(z, name):
    data = z.read(name)
    if b'<!DOCTYPE' in data.upper() or b'<!ENTITY' in data.upper() or b'\x00' in data:
        raise ImportErrorSafe('unsafe_archive')
    return ET.fromstring(data)


S = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'
P = '{http://schemas.openxmlformats.org/package/2006/relationships}'
W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'


def read_xlsx(path):
    with open_zip(path) as z:
        book = xml(z, 'xl/workbook.xml')
        first = book.find(S+'sheets/'+S+'sheet')
        if first is None:
            raise ImportErrorSafe('empty_file')
        relation = first.get(R+'id')
        targets = [n.get('Target','') for n in xml(z, 'xl/_rels/workbook.xml.rels') if n.tag == P+'Relationship' and n.get('Id') == relation and n.get('TargetMode') != 'External']
        if len(targets) != 1:
            raise ImportErrorSafe('invalid_file')
        target = targets[0]
        name = target.lstrip('/') if target.startswith('/') else 'xl/'+target
        if '..' in PurePosixPath(name).parts or not name.startswith('xl/worksheets/'):
            raise ImportErrorSafe('invalid_file')
        shared = []
        if 'xl/sharedStrings.xml' in z.namelist():
            shared = [''.join(t.text or '' for t in item.iter(S+'t'))[:MAX_CELL] for item in xml(z,'xl/sharedStrings.xml').iter(S+'si')]
        rows = []
        for row in xml(z,name).iter(S+'row'):
            values = {}
            for cell in row.findall(S+'c'):
                address = cell.get('r','')
                m = re.fullmatch(r'([A-Z]+)[0-9]+', address)
                if not m:
                    raise ImportErrorSafe('invalid_file')
                col = 0
                for c in m.group(1):
                    col = col*26+ord(c)-64
                value = ''
                if cell.find(S+'f') is None:  # Never calculate formulas or dereference links.
                    if cell.get('t') == 'inlineStr':
                        value = ''.join(t.text or '' for t in cell.iter(S+'t'))
                    else:
                        v = cell.findtext(S+'v','')
                        if cell.get('t') == 's':
                            idx = int(v)
                            if not 0 <= idx < len(shared):
                                raise ImportErrorSafe('invalid_file')
                            value = shared[idx]
                        else:
                            value = v
                if value.strip():
                    if col > MAX_COLS:
                        raise ImportErrorSafe('too_many_rows')
                    values[col-1] = value[:MAX_CELL]
            if values:
                rows.append([values.get(i,'') for i in range(max(values)+1)])
            if len(rows) > MAX_ROWS+1:
                raise ImportErrorSafe('too_many_rows')
        return table(rows)


def read_docx(path):
    with open_zip(path) as z:
        doc = xml(z, 'word/document.xml')
        # Preserve text across runs, separate paragraphs. No hyperlinks followed.
        return text_table('\n'.join(''.join(t.text or '' for t in p.iter(W+'t')) for p in doc.iter(W+'p')))


def extract(path, kind):
    if os.path.getsize(path) > MAX_FILE:
        raise ImportErrorSafe('too_large')
    if kind == 'csv':
        with open(path,'rb') as f:
            return read_csv(f.read())
    if kind == 'xlsx':
        return read_xlsx(path)
    if kind == 'docx':
        return read_docx(path)
    if kind == 'txt':
        with open(path,'rb') as f:
            return text_table(decode(f.read()))
    if kind in ('pdf','doc'):
        with open(path,'rb') as f:
            head = f.read(8)
        if (kind == 'pdf' and not head.startswith(b'%PDF-')) or (kind == 'doc' and head != bytes.fromhex('D0CF11E0A1B11AE1')):
            raise ImportErrorSafe('invalid_file')
        cmd = ['pdftotext','-enc','UTF-8','-layout',path,'-'] if kind=='pdf' else ['antiword',path]
        try:
            p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=25, check=True)
        except FileNotFoundError:
            raise ImportErrorSafe('missing_'+kind+'_tool') from None
        if len(p.stdout)>MAX_EXPANDED:
            raise ImportErrorSafe('too_large')
        return text_table(decode(p.stdout))
    raise ImportErrorSafe('unsupported_format')


def main():
    try:
        resource.setrlimit(resource.RLIMIT_AS, (512*1024*1024, 512*1024*1024))
        resource.setrlimit(resource.RLIMIT_CPU, (30, 35))
        if len(sys.argv)!=3:
            raise ImportErrorSafe('invalid_file')
        result = extract(sys.argv[1],sys.argv[2])
        out = json.dumps(result, ensure_ascii=False,separators=(',',':')).encode('utf-8')
        if len(out)>MAX_OUTPUT:
            raise ImportErrorSafe('too_large')
        sys.stdout.buffer.write(out)
    except ImportErrorSafe as e:
        print(str(e),file=sys.stderr); sys.exit(2)
    except Exception:
        # Never print file paths, data or exception details.
        print('invalid_file',file=sys.stderr); sys.exit(2)

if __name__=='__main__':
    main()
