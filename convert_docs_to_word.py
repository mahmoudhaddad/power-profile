"""
Converts POWER_PROFILE_COMPLETE_DOCUMENTATION.md to a Word (.docx) file.
Handles: headings, bold, tables, code blocks, bullet lists, horizontal rules.
"""

from docx import Document
from docx.shared import Pt, RGBColor, Inches, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml.ns import qn
from docx.oxml import OxmlElement
import re
import os

MD_PATH  = r"e:\graduation project\power-profile\POWER_PROFILE_COMPLETE_DOCUMENTATION.md"
OUT_PATH = r"e:\graduation project\power-profile\Power_Profile_Complete_Documentation.docx"

# ── Colour palette ────────────────────────────────────────────────────────────
NAVY    = RGBColor(0x1E, 0x3A, 0x8A)
TEAL    = RGBColor(0x0D, 0x94, 0x88)
DARK    = RGBColor(0x1F, 0x29, 0x37)
GREY_BG = RGBColor(0xF3, 0xF4, 0xF6)
WHITE   = RGBColor(0xFF, 0xFF, 0xFF)
CODE_BG = RGBColor(0xF8, 0xF8, 0xF8)


# ── Helpers ───────────────────────────────────────────────────────────────────

def set_cell_bg(cell, rgb: RGBColor):
    tc   = cell._tc
    tcPr = tc.get_or_add_tcPr()
    shd  = OxmlElement('w:shd')
    hex_color = f"{rgb[0]:02X}{rgb[1]:02X}{rgb[2]:02X}"
    shd.set(qn('w:val'),   'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'),  hex_color)
    tcPr.append(shd)


def set_para_bg(para, rgb: RGBColor):
    pPr = para._p.get_or_add_pPr()
    shd = OxmlElement('w:shd')
    hex_color = f"{rgb[0]:02X}{rgb[1]:02X}{rgb[2]:02X}"
    shd.set(qn('w:val'),   'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'),  hex_color)
    pPr.append(shd)


def add_hr(doc):
    para = doc.add_paragraph()
    pPr  = para._p.get_or_add_pPr()
    pb   = OxmlElement('w:pBdr')
    bot  = OxmlElement('w:bottom')
    bot.set(qn('w:val'),  'single')
    bot.set(qn('w:sz'),   '6')
    bot.set(qn('w:space'),'1')
    bot.set(qn('w:color'),'1E3A8A')
    pb.append(bot)
    pPr.append(pb)
    para.paragraph_format.space_before = Pt(0)
    para.paragraph_format.space_after  = Pt(0)


def apply_inline(run, text):
    """Apply bold/italic/code inline markers (simplified)."""
    run.text = text


def add_inline_para(doc, line, style=None):
    """Add a paragraph with inline bold (**text**) and code (`text`) support."""
    para = doc.add_paragraph(style=style) if style else doc.add_paragraph()
    para.paragraph_format.space_after = Pt(4)

    # Split on **bold** and `code` markers
    pattern = r'(\*\*[^*]+\*\*|`[^`]+`)'
    parts   = re.split(pattern, line)
    for part in parts:
        if part.startswith('**') and part.endswith('**'):
            run      = para.add_run(part[2:-2])
            run.bold = True
        elif part.startswith('`') and part.endswith('`'):
            run           = para.add_run(part[1:-1])
            run.font.name = 'Courier New'
            run.font.size = Pt(9)
            run.font.color.rgb = RGBColor(0xC7, 0x25, 0x4E)
        else:
            para.add_run(part)
    return para


# ── Document setup ────────────────────────────────────────────────────────────

def setup_document() -> Document:
    doc = Document()

    # Page margins
    for section in doc.sections:
        section.top_margin    = Cm(2.0)
        section.bottom_margin = Cm(2.0)
        section.left_margin   = Cm(2.5)
        section.right_margin  = Cm(2.5)

    # Default paragraph font
    style = doc.styles['Normal']
    style.font.name = 'Calibri'
    style.font.size = Pt(10)
    style.font.color.rgb = DARK

    # Heading styles
    h_defs = [
        ('Heading 1', 18, NAVY,  True,  True),
        ('Heading 2', 14, TEAL,  True,  True),
        ('Heading 3', 12, NAVY,  True,  False),
        ('Heading 4', 11, DARK,  True,  False),
    ]
    for name, size, color, bold, upper in h_defs:
        s = doc.styles[name]
        s.font.name  = 'Calibri'
        s.font.size  = Pt(size)
        s.font.bold  = bold
        s.font.color.rgb = color
        s.paragraph_format.space_before = Pt(14 if name == 'Heading 1' else 10)
        s.paragraph_format.space_after  = Pt(4)

    # List bullet style
    try:
        lb = doc.styles['List Bullet']
        lb.font.size = Pt(10)
        lb.paragraph_format.space_after = Pt(2)
    except KeyError:
        pass

    # Code style (custom)
    try:
        code_style = doc.styles.add_style('CodeBlock', 1)
        code_style.base_style = doc.styles['Normal']
        code_style.font.name  = 'Courier New'
        code_style.font.size  = Pt(8.5)
        code_style.paragraph_format.space_after  = Pt(0)
        code_style.paragraph_format.space_before = Pt(0)
        code_style.paragraph_format.left_indent  = Cm(0.5)
    except Exception:
        pass

    return doc


# ── Title page ────────────────────────────────────────────────────────────────

def add_title_page(doc):
    doc.add_paragraph()
    doc.add_paragraph()

    title = doc.add_paragraph()
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = title.add_run('POWER PROFILE')
    r.font.name  = 'Calibri'
    r.font.size  = Pt(32)
    r.font.bold  = True
    r.font.color.rgb = NAVY

    sub = doc.add_paragraph()
    sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r2 = sub.add_run('Complete Technical Documentation')
    r2.font.name  = 'Calibri'
    r2.font.size  = Pt(18)
    r2.font.color.rgb = TEAL

    doc.add_paragraph()
    meta_lines = [
        'Graduation Project — Electrical Load Analysis System',
        'Author: Ahmed Zoher',
        'Generated: June 7, 2026',
        'Stack: Laravel 13 · React 18 · SQLite · IEC 60364-8-1',
    ]
    for line in meta_lines:
        p = doc.add_paragraph()
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        r3 = p.add_run(line)
        r3.font.size = Pt(11)
        r3.font.color.rgb = RGBColor(0x6B, 0x72, 0x80)

    doc.add_page_break()


# ── Main parser ───────────────────────────────────────────────────────────────

def parse_markdown(doc: Document, md_path: str):
    with open(md_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    in_code    = False
    code_lines = []
    in_table   = False
    table_rows = []
    i          = 0

    def flush_table():
        nonlocal in_table, table_rows
        if not table_rows:
            in_table   = False
            table_rows = []
            return

        # Filter separator rows (---|---...)
        data_rows = [r for r in table_rows if not re.match(r'^\s*\|?[-: |]+\|?\s*$', r)]
        if not data_rows:
            in_table   = False
            table_rows = []
            return

        parsed = []
        for row in data_rows:
            row = row.strip()
            if row.startswith('|'):
                row = row[1:]
            if row.endswith('|'):
                row = row[:-1]
            cells = [c.strip() for c in row.split('|')]
            parsed.append(cells)

        if not parsed:
            in_table   = False
            table_rows = []
            return

        max_cols = max(len(r) for r in parsed)
        # Pad all rows to same width
        for r in parsed:
            while len(r) < max_cols:
                r.append('')

        tbl = doc.add_table(rows=len(parsed), cols=max_cols)
        tbl.style = 'Table Grid'
        tbl.alignment = WD_TABLE_ALIGNMENT.LEFT

        for ri, row in enumerate(parsed):
            for ci, cell_text in enumerate(row):
                cell = tbl.cell(ri, ci)
                # Clean inline markdown from cell text
                clean = re.sub(r'\*\*([^*]+)\*\*', r'\1', cell_text)
                clean = re.sub(r'`([^`]+)`', r'\1', clean)
                para  = cell.paragraphs[0]
                para.clear()
                run = para.add_run(clean)
                run.font.size = Pt(9)
                if ri == 0:
                    run.bold = True
                    set_cell_bg(cell, NAVY)
                    run.font.color.rgb = WHITE
                elif ri % 2 == 0:
                    set_cell_bg(cell, GREY_BG)

        doc.add_paragraph()
        in_table   = False
        table_rows = []

    def flush_code():
        nonlocal in_code, code_lines
        if not code_lines:
            in_code    = False
            code_lines = []
            return

        # Grey background box
        p_top = doc.add_paragraph()
        p_top.paragraph_format.space_after  = Pt(0)
        p_top.paragraph_format.space_before = Pt(6)
        set_para_bg(p_top, CODE_BG)

        for cl in code_lines:
            try:
                p = doc.add_paragraph(style='CodeBlock')
            except Exception:
                p = doc.add_paragraph()
                p.paragraph_format.left_indent = Cm(0.5)
                rf = p.add_run(cl.rstrip('\n'))
                rf.font.name = 'Courier New'
                rf.font.size = Pt(8.5)
                set_para_bg(p, CODE_BG)
                continue
            set_para_bg(p, CODE_BG)
            rf = p.add_run(cl.rstrip('\n'))
            rf.font.color.rgb = RGBColor(0x1F, 0x29, 0x37)

        p_bot = doc.add_paragraph()
        p_bot.paragraph_format.space_before = Pt(0)
        p_bot.paragraph_format.space_after  = Pt(6)
        set_para_bg(p_bot, CODE_BG)

        in_code    = False
        code_lines = []

    while i < len(lines):
        raw  = lines[i]
        line = raw.rstrip('\n')
        i   += 1

        # ── Code fence ───────────────────────────────────────────────────────
        if line.strip().startswith('```'):
            if in_code:
                flush_code()
            else:
                if in_table:
                    flush_table()
                in_code = True
            continue

        if in_code:
            code_lines.append(line)
            continue

        # ── Horizontal rule ──────────────────────────────────────────────────
        if re.match(r'^-{3,}$', line.strip()):
            if in_table:
                flush_table()
            add_hr(doc)
            continue

        # ── Table rows ───────────────────────────────────────────────────────
        if '|' in line and line.strip().startswith('|'):
            if not in_table:
                in_table = True
            table_rows.append(line)
            continue
        else:
            if in_table:
                flush_table()

        stripped = line.strip()

        # ── Skip blank / doc title (already on title page) ───────────────────
        if not stripped:
            continue

        # ── Headings ─────────────────────────────────────────────────────────
        hm = re.match(r'^(#{1,4})\s+(.*)', stripped)
        if hm:
            level = len(hm.group(1))
            text  = hm.group(2)
            # Strip inline code from headings
            text  = re.sub(r'`([^`]+)`', r'\1', text)
            text  = re.sub(r'\*\*([^*]+)\*\*', r'\1', text)
            style = f'Heading {min(level, 4)}'
            p = doc.add_paragraph(text, style=style)
            if level == 1:
                p.alignment = WD_ALIGN_PARAGRAPH.LEFT
            continue

        # ── Bullet lists ─────────────────────────────────────────────────────
        bm = re.match(r'^[-*]\s+(.*)', stripped)
        if bm:
            content = bm.group(1)
            try:
                p = doc.add_paragraph(style='List Bullet')
            except Exception:
                p = doc.add_paragraph()
            p.paragraph_format.left_indent  = Cm(0.6)
            p.paragraph_format.space_after  = Pt(2)
            # inline bold/code
            pattern = r'(\*\*[^*]+\*\*|`[^`]+`)'
            parts   = re.split(pattern, content)
            for part in parts:
                if part.startswith('**') and part.endswith('**'):
                    r2 = p.add_run(part[2:-2]); r2.bold = True
                elif part.startswith('`') and part.endswith('`'):
                    r2 = p.add_run(part[1:-1])
                    r2.font.name = 'Courier New'
                    r2.font.size = Pt(9)
                    r2.font.color.rgb = RGBColor(0xC7, 0x25, 0x4E)
                else:
                    p.add_run(part)
            continue

        # ── Numbered list ────────────────────────────────────────────────────
        nm = re.match(r'^\d+\.\s+(.*)', stripped)
        if nm:
            content = nm.group(1)
            try:
                p = doc.add_paragraph(style='List Number')
            except Exception:
                p = doc.add_paragraph()
            p.paragraph_format.left_indent = Cm(0.6)
            p.paragraph_format.space_after = Pt(2)
            pattern = r'(\*\*[^*]+\*\*|`[^`]+`)'
            parts   = re.split(pattern, content)
            for part in parts:
                if part.startswith('**') and part.endswith('**'):
                    r2 = p.add_run(part[2:-2]); r2.bold = True
                elif part.startswith('`') and part.endswith('`'):
                    r2 = p.add_run(part[1:-1])
                    r2.font.name = 'Courier New'
                    r2.font.size = Pt(9)
                    r2.font.color.rgb = RGBColor(0xC7, 0x25, 0x4E)
                else:
                    p.add_run(part)
            continue

        # ── Block quote / Note ───────────────────────────────────────────────
        qm = re.match(r'^>\s*(.*)', stripped)
        if qm:
            p = doc.add_paragraph()
            p.paragraph_format.left_indent  = Cm(1.0)
            p.paragraph_format.space_after  = Pt(4)
            r2 = p.add_run(qm.group(1))
            r2.italic = True
            r2.font.color.rgb = RGBColor(0x6B, 0x72, 0x80)
            continue

        # ── Regular paragraph ────────────────────────────────────────────────
        # Skip the duplicate document title line
        if stripped == '# POWER PROFILE — COMPLETE PROJECT DOCUMENTATION':
            continue

        add_inline_para(doc, stripped)

    # Flush any remaining table/code
    if in_table:
        flush_table()
    if in_code:
        flush_code()


# ── Run ───────────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    print("Setting up document...")
    doc = setup_document()

    print("Adding title page...")
    add_title_page(doc)

    print("Parsing markdown and building Word content...")
    parse_markdown(doc, MD_PATH)

    print(f"Saving to {OUT_PATH}...")
    doc.save(OUT_PATH)

    size_mb = os.path.getsize(OUT_PATH) / (1024 * 1024)
    print(f"\nDone!  {OUT_PATH}")
    print(f"File size: {size_mb:.2f} MB")
    print(f"Paragraphs: {len(doc.paragraphs)}")
