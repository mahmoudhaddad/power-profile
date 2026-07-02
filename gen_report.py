"""
Comprehensive Technical Report Generator for Power Profile Graduation Project
"""
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import cm, mm
from reportlab.lib import colors
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
    PageBreak, HRFlowable, KeepTogether
)
from reportlab.platypus.flowables import Flowable
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT, TA_JUSTIFY
from reportlab.graphics.shapes import Drawing, Line, Rect, String, Circle, Polygon
from reportlab.graphics import renderPDF
from reportlab.graphics.charts.barcharts import VerticalBarChart
from reportlab.graphics.charts.lineplots import LinePlot
import math

# ─── COLOUR PALETTE ────────────────────────────────────────────────────────────
C_BLUE      = colors.HexColor("#1a3c5e")
C_LIGHTBLUE = colors.HexColor("#2563eb")
C_ACCENT    = colors.HexColor("#0ea5e9")
C_GREEN     = colors.HexColor("#16a34a")
C_YELLOW    = colors.HexColor("#ca8a04")
C_RED       = colors.HexColor("#dc2626")
C_ORANGE    = colors.HexColor("#ea580c")
C_GREY      = colors.HexColor("#64748b")
C_LIGHTGREY = colors.HexColor("#f1f5f9")
C_DARKGREY  = colors.HexColor("#334155")
C_WHITE     = colors.white
C_BLACK     = colors.black

# ─── STYLES ────────────────────────────────────────────────────────────────────
styles = getSampleStyleSheet()

def S(name, **kw):
    return ParagraphStyle(name, **kw)

COVER_TITLE   = S("CoverTitle",   fontName="Helvetica-Bold",   fontSize=28, textColor=C_WHITE,     alignment=TA_CENTER, spaceAfter=6)
COVER_SUB     = S("CoverSub",     fontName="Helvetica",        fontSize=14, textColor=C_ACCENT,     alignment=TA_CENTER, spaceAfter=4)
COVER_INFO    = S("CoverInfo",    fontName="Helvetica",        fontSize=11, textColor=C_LIGHTGREY,  alignment=TA_CENTER, spaceAfter=3)

H1  = S("H1",  fontName="Helvetica-Bold",  fontSize=18, textColor=C_BLUE,      spaceBefore=18, spaceAfter=8,  leading=22)
H2  = S("H2",  fontName="Helvetica-Bold",  fontSize=14, textColor=C_LIGHTBLUE, spaceBefore=14, spaceAfter=6,  leading=18)
H3  = S("H3",  fontName="Helvetica-Bold",  fontSize=12, textColor=C_DARKGREY,  spaceBefore=10, spaceAfter=4,  leading=15)
H4  = S("H4",  fontName="Helvetica-BoldOblique", fontSize=11, textColor=C_GREY, spaceBefore=8, spaceAfter=3, leading=14)

BODY = S("Body", fontName="Helvetica", fontSize=10, textColor=C_BLACK, alignment=TA_JUSTIFY,
         spaceAfter=5, leading=15)
BODY_SMALL = S("BodySmall", fontName="Helvetica", fontSize=9, textColor=C_DARKGREY,
               alignment=TA_JUSTIFY, spaceAfter=4, leading=13)

EQ   = S("Eq",  fontName="Courier-Bold", fontSize=10, textColor=C_BLUE,
         alignment=TA_CENTER, spaceBefore=6, spaceAfter=6, leading=14,
         backColor=C_LIGHTGREY, borderPadding=(6,10,6,10))
EQ_BLOCK = S("EqBlock", fontName="Courier", fontSize=9, textColor=C_DARKGREY,
             alignment=TA_LEFT, spaceBefore=2, spaceAfter=2, leading=13,
             backColor=C_LIGHTGREY, borderPadding=(4,8,4,8))

CAPTION = S("Caption", fontName="Helvetica-Oblique", fontSize=9, textColor=C_GREY,
             alignment=TA_CENTER, spaceAfter=8)
BULLET  = S("Bullet",  fontName="Helvetica", fontSize=10, textColor=C_BLACK,
             leftIndent=14, spaceAfter=3, leading=14, bulletIndent=4)
NOTE    = S("Note", fontName="Helvetica-Oblique", fontSize=9, textColor=C_GREY,
             leftIndent=10, spaceAfter=4, leading=13)

# ─── HELPER FLOWABLES ──────────────────────────────────────────────────────────
def HR(color=C_BLUE, thickness=1.5):
    return HRFlowable(width="100%", thickness=thickness, color=color, spaceAfter=6, spaceBefore=2)

def sp(h=6):
    return Spacer(1, h)

def p(text, style=BODY):
    return Paragraph(text, style)

def h1(text): return Paragraph(text, H1)
def h2(text): return Paragraph(text, H2)
def h3(text): return Paragraph(text, H3)
def h4(text): return Paragraph(text, H4)
def eq(text): return Paragraph(text, EQ)
def eqb(text): return Paragraph(text, EQ_BLOCK)
def cap(text): return Paragraph(text, CAPTION)
def note(text): return Paragraph("ℹ " + text, NOTE)
def bullet(text): return Paragraph("• " + text, BULLET)

def section_header(number, title):
    return KeepTogether([
        HR(),
        Paragraph(f"{number}. {title}", H1),
        HR(color=C_ACCENT, thickness=0.5),
        sp(4),
    ])

def sub_header(number, title):
    return KeepTogether([
        Paragraph(f"{number}  {title}", H2),
        sp(2),
    ])

def table(data, col_widths=None, head_rows=1):
    t = Table(data, colWidths=col_widths, repeatRows=head_rows)
    ts = [
        ("BACKGROUND", (0,0), (-1, head_rows-1), C_BLUE),
        ("TEXTCOLOR",  (0,0), (-1, head_rows-1), C_WHITE),
        ("FONTNAME",   (0,0), (-1, head_rows-1), "Helvetica-Bold"),
        ("FONTSIZE",   (0,0), (-1,-1), 9),
        ("ROWBACKGROUNDS", (0, head_rows), (-1,-1), [C_WHITE, C_LIGHTGREY]),
        ("GRID",       (0,0), (-1,-1), 0.4, C_GREY),
        ("LEFTPADDING",(0,0), (-1,-1), 5),
        ("RIGHTPADDING",(0,0),(-1,-1), 5),
        ("TOPPADDING", (0,0), (-1,-1), 4),
        ("BOTTOMPADDING",(0,0),(-1,-1), 4),
        ("VALIGN",     (0,0), (-1,-1), "MIDDLE"),
        ("WORDWRAP",   (0,0), (-1,-1), True),
    ]
    t.setStyle(TableStyle(ts))
    return t

# ─── COVER PAGE ────────────────────────────────────────────────────────────────
class CoverPage(Flowable):
    def __init__(self):
        Flowable.__init__(self)
        self.width, self.height = A4

    def draw(self):
        c = self.canv
        w, h = self.width, self.height

        # Dark navy gradient background
        c.setFillColor(C_BLUE)
        c.rect(0, 0, w, h, fill=1, stroke=0)

        # Accent stripe
        c.setFillColor(C_LIGHTBLUE)
        c.rect(0, h * 0.38, w, 4, fill=1, stroke=0)
        c.rect(0, h * 0.36, w, 1, fill=1, stroke=0)

        # Bottom bar
        c.setFillColor(C_ACCENT)
        c.rect(0, 0, w, 50, fill=1, stroke=0)

        # Circuit-board decoration lines
        c.setStrokeColor(colors.HexColor("#2563eb"))
        c.setLineWidth(0.5)
        for i in range(10):
            y = 60 + i * 25
            c.line(0, y, 80, y)
            c.line(80, y, 80, y + 15)
            c.circle(80, y, 3, fill=1)

        # Title block
        c.setFillColor(C_WHITE)
        c.setFont("Helvetica-Bold", 32)
        c.drawCentredString(w/2, h * 0.72, "POWER PROFILE SYSTEM")
        c.setFont("Helvetica-Bold", 20)
        c.setFillColor(C_ACCENT)
        c.drawCentredString(w/2, h * 0.66, "Electrical Power System Design & Analysis Tool")

        c.setFillColor(C_LIGHTGREY)
        c.setFont("Helvetica", 12)
        c.drawCentredString(w/2, h * 0.60, "Comprehensive Technical Report")
        c.drawCentredString(w/2, h * 0.57, "Equations · Standards · Algorithms · Architecture")

        # Divider
        c.setStrokeColor(C_ACCENT)
        c.setLineWidth(2)
        c.line(w*0.2, h*0.54, w*0.8, h*0.54)

        # Info block
        infos = [
            ("Project Type:", "Graduation Project — Electrical Engineering"),
            ("Tech Stack:",   "Laravel 13 / PHP 8.4  +  React 18 / Vite"),
            ("Standards:",    "IEC 60364-8-1 · BS 7671 · PENRA · NEC · CIBSE"),
            ("Author:",       "Ahmed Zoher"),
            ("Date:",         "June 2026"),
        ]
        ystart = h * 0.50
        for label, val in infos:
            c.setFillColor(C_ACCENT)
            c.setFont("Helvetica-Bold", 10)
            c.drawString(w*0.18, ystart, label)
            c.setFillColor(C_WHITE)
            c.setFont("Helvetica", 10)
            c.drawString(w*0.38, ystart, val)
            ystart -= 18

        # Footer
        c.setFillColor(C_WHITE)
        c.setFont("Helvetica-Bold", 10)
        c.drawCentredString(w/2, 20, "CONFIDENTIAL — GRADUATION PROJECT REPORT")

    def wrap(self, *args):
        return (self.width, self.height)

# ─── PHASOR DIAGRAM FLOWABLE ───────────────────────────────────────────────────
class PhasorDiagram(Flowable):
    def __init__(self, width=200, height=200):
        Flowable.__init__(self)
        self.width = width
        self.height = height

    def draw(self):
        c = self.canv
        cx, cy = self.width/2, self.height/2
        r = min(cx, cy) - 20

        # Axes
        c.setStrokeColor(C_GREY)
        c.setLineWidth(0.5)
        c.line(cx - r - 5, cy, cx + r + 5, cy)
        c.line(cx, cy - r - 5, cx, cy + r + 5)

        # Circle
        c.setDash([2,2])
        c.circle(cx, cy, r, stroke=1, fill=0)
        c.setDash([])

        phases = [
            (0,   C_RED,   "Phase A"),
            (120, C_YELLOW,"Phase B"),
            (240, C_BLUE,  "Phase C"),
        ]
        for angle, col, label in phases:
            rad = math.radians(angle)
            x = cx + r * math.cos(rad)
            y = cy + r * math.sin(rad)
            c.setStrokeColor(col)
            c.setFillColor(col)
            c.setLineWidth(2)
            c.line(cx, cy, x, y)
            # Arrow head
            c.setLineWidth(0)
            # label
            lx = cx + (r+15)*math.cos(rad)
            ly = cy + (r+15)*math.sin(rad)
            c.setFont("Helvetica-Bold", 8)
            c.drawCentredString(lx, ly, label)

        c.setFont("Helvetica", 7)
        c.setFillColor(C_GREY)
        c.drawCentredString(cx, cy - r - 18, "3-Phase Phasor (120° separation)")

    def wrap(self, *args):
        return (self.width, self.height)

# ─── POWER TRIANGLE FLOWABLE ──────────────────────────────────────────────────
class PowerTriangle(Flowable):
    def __init__(self, width=260, height=160):
        Flowable.__init__(self)
        self.width = width
        self.height = height

    def draw(self):
        c = self.canv
        # Triangle vertices
        ox, oy = 30, 30
        pw = 180   # P (horizontal)
        qh = 100   # Q (vertical)
        # P vector
        c.setStrokeColor(C_GREEN)
        c.setLineWidth(2.5)
        c.line(ox, oy, ox+pw, oy)
        # Q vector
        c.setStrokeColor(C_RED)
        c.line(ox+pw, oy, ox+pw, oy+qh)
        # S vector
        c.setStrokeColor(C_BLUE)
        c.line(ox, oy, ox+pw, oy+qh)

        # Labels
        c.setFont("Helvetica-Bold", 10)
        c.setFillColor(C_GREEN)
        c.drawCentredString(ox+pw/2, oy-14, "P = Active Power (kW)")
        c.setFillColor(C_RED)
        c.drawString(ox+pw+5, oy+qh/2, "Q = Reactive (kVAR)")
        c.setFillColor(C_BLUE)
        ang = math.degrees(math.atan2(qh, pw))
        smid_x = ox + pw/2 - 12
        smid_y = oy + qh/2 + 10
        c.drawString(smid_x - 20, smid_y, "S = Apparent (kVA)")

        # Angle arc
        c.setStrokeColor(C_ORANGE)
        c.setLineWidth(1)
        c.arc(ox, oy, ox+30, oy+30, 0, ang)
        c.setFont("Helvetica", 9)
        c.setFillColor(C_ORANGE)
        c.drawString(ox+32, oy+8, "φ")

        # Right angle mark
        c.setStrokeColor(C_GREY)
        c.setLineWidth(0.5)
        sq = 8
        c.rect(ox+pw, oy, sq, sq, fill=0)

    def wrap(self, *args):
        return (self.width, self.height)

# ─── DISPATCH DIAGRAM ─────────────────────────────────────────────────────────
class DispatchDiagram(Flowable):
    def __init__(self, width=460, height=200):
        Flowable.__init__(self)
        self.width = width
        self.height = height

    def draw(self):
        c = self.canv
        boxes = [
            (20,  80, 80, 40, C_YELLOW, "Solar\nPV"),
            (130, 80, 80, 40, C_GREEN,  "Battery\nBESS"),
            (240, 80, 80, 40, C_BLUE,   "Utility\nGrid"),
            (350, 80, 80, 40, C_ORANGE, "Generator\nDiesel"),
            (185, 10, 80, 40, C_RED,    "Load\nDemand"),
        ]
        for bx, by, bw, bh, col, label in boxes:
            c.setFillColor(col)
            c.roundRect(bx, by, bw, bh, 6, fill=1, stroke=0)
            c.setFillColor(C_WHITE)
            c.setFont("Helvetica-Bold", 8)
            lines = label.split("\n")
            for i, ln in enumerate(lines):
                c.drawCentredString(bx+bw/2, by+bh/2+4 - i*10, ln)

        # Arrows to load
        c.setStrokeColor(C_GREY)
        c.setLineWidth(1.5)
        arrows = [
            (60, 80, 225, 50),   # Solar → Load
            (170, 80, 225, 50),  # Battery → Load
            (280, 80, 225, 50),  # Utility → Load
            (390, 80, 225, 50),  # Generator → Load
        ]
        for x1,y1,x2,y2 in arrows:
            c.line(x1, y1, x2, y2)
            # arrowhead
            c.setFillColor(C_GREY)
            ang = math.atan2(y2-y1, x2-x1)
            hs = 6
            pa = c.beginPath()
            pa.moveTo(x2, y2)
            pa.lineTo(x2 - hs*math.cos(ang-0.4), y2 - hs*math.sin(ang-0.4))
            pa.lineTo(x2 - hs*math.cos(ang+0.4), y2 - hs*math.sin(ang+0.4))
            pa.close()
            c.drawPath(pa, fill=1, stroke=0)

        # Solar → Battery
        c.setStrokeColor(C_YELLOW)
        c.setDash([3,2])
        c.line(60, 80, 170, 100)
        c.setDash([])

        # Labels
        c.setFont("Helvetica", 7)
        c.setFillColor(C_DARKGREY)
        c.drawCentredString(225, 155, "Priority Dispatch: Solar → Battery → Grid → Generator")
        c.setFillColor(C_YELLOW)
        c.drawString(100, 92, "charge")

    def wrap(self, *args):
        return (self.width, self.height)

# ─── SOLAR PROFILE DIAGRAM ────────────────────────────────────────────────────
class SolarProfile(Flowable):
    def __init__(self, width=400, height=130):
        Flowable.__init__(self)
        self.width = width
        self.height = height

    def draw(self):
        c = self.canv
        sunrise, sunset = 6.5, 18.5
        daylight = sunset - sunrise
        hours = list(range(25))
        W = self.width - 40
        H = self.height - 30
        ox, oy = 20, 15

        def hx(h): return ox + (h / 24) * W
        def wy(w): return oy + w * H

        # Axes
        c.setStrokeColor(C_GREY)
        c.setLineWidth(0.5)
        c.line(ox, oy, ox, oy+H)
        c.line(ox, oy, ox+W, oy)

        # Fill sinusoidal bell
        path = c.beginPath()
        path.moveTo(hx(0), oy)
        for h24 in range(241):
            h = h24 / 10.0
            mid = h + 0.05
            if mid <= sunrise or mid >= sunset:
                w = 0
            else:
                t = (mid - sunrise) / daylight
                w = math.sin(math.pi * t)
            path.lineTo(hx(h), oy + w * H)
        path.lineTo(hx(24), oy)
        path.close()
        c.setFillColor(colors.HexColor("#fef08a"))
        c.drawPath(path, fill=1, stroke=0)

        # Bell curve line
        c.setStrokeColor(C_YELLOW)
        c.setLineWidth(2)
        prev = None
        for h24 in range(241):
            h = h24 / 10.0
            mid = h + 0.05
            if mid <= sunrise or mid >= sunset:
                w = 0
            else:
                t = (mid - sunrise) / daylight
                w = math.sin(math.pi * t)
            pt = (hx(h), oy + w * H)
            if prev:
                c.line(prev[0], prev[1], pt[0], pt[1])
            prev = pt

        # Hour ticks
        c.setFont("Helvetica", 7)
        c.setFillColor(C_GREY)
        for hk in [0,6,12,18,24]:
            c.line(hx(hk), oy-3, hx(hk), oy+3)
            c.drawCentredString(hx(hk), oy-11, f"{hk}:00")

        # Annotations
        c.setFillColor(C_ORANGE)
        c.setFont("Helvetica-Bold", 8)
        c.drawCentredString(hx(12.5), oy + H + 5, "Peak Sun Hours (Sinusoidal Bell Profile)")
        c.setFillColor(C_BLUE)
        c.setFont("Helvetica", 7)
        c.drawString(hx(sunrise)+2, oy + H*0.1, f"Sunrise {sunrise}h")
        c.drawString(hx(sunset)-40, oy + H*0.1, f"Sunset {sunset}h")

    def wrap(self, *args):
        return (self.width, self.height)

# ─── ARCHITECTURE DIAGRAM ─────────────────────────────────────────────────────
class ArchDiagram(Flowable):
    def __init__(self, width=460, height=220):
        Flowable.__init__(self)
        self.width = width
        self.height = height

    def draw(self):
        c = self.canv
        layers = [
            (20,  170, 420, 35, C_LIGHTBLUE, "FRONTEND  —  React 18 + Vite + Tailwind CSS + Recharts", C_WHITE),
            (20,  120, 420, 35, C_GREEN,      "API LAYER  —  Laravel 13 Sanctum REST API (56 endpoints)", C_WHITE),
            (20,  70,  420, 35, C_ORANGE,     "SERVICE LAYER  —  DiversityFactor · SocketDemand · SolarIrradiance · SourceDispatch", C_WHITE),
            (20,  20,  420, 35, C_BLUE,       "DATA LAYER  —  SQLite + Eloquent ORM  |  NASA POWER API  |  Google OAuth 2.0", C_WHITE),
        ]
        for bx,by,bw,bh,col,label,tc in layers:
            c.setFillColor(col)
            c.roundRect(bx,by,bw,bh,5,fill=1,stroke=0)
            c.setFillColor(tc)
            c.setFont("Helvetica-Bold", 8)
            c.drawString(bx+8, by+bh/2-4, label)

        # Arrows between layers
        c.setStrokeColor(C_GREY)
        c.setLineWidth(1)
        for y in [155, 105, 55]:
            c.line(230, y, 230, y+15)
            # arrowhead
            c.setFillColor(C_GREY)
            p2 = c.beginPath()
            p2.moveTo(230, y+15)
            p2.lineTo(225, y+8)
            p2.lineTo(235, y+8)
            p2.close()
            c.drawPath(p2, fill=1, stroke=0)

        c.setFont("Helvetica-BoldOblique", 7)
        c.setFillColor(C_DARKGREY)
        c.drawCentredString(230, 210, "Full-Stack Architecture — 4 Layers")

    def wrap(self, *args):
        return (self.width, self.height)

# ─── BATTERY CHART ────────────────────────────────────────────────────────────
class BatterySOCDiagram(Flowable):
    def __init__(self, width=420, height=130):
        Flowable.__init__(self)
        self.width = width
        self.height = height

    def draw(self):
        c = self.canv
        # Simulate a SOC trace
        soc = [0.7, 0.68, 0.65, 0.63, 0.61, 0.59, 0.58,
               0.72, 0.85, 0.95, 1.00, 0.98, 0.90,
               0.78, 0.65, 0.55, 0.45, 0.40, 0.38,
               0.55, 0.68, 0.75, 0.72, 0.70]
        W = self.width - 40
        H = self.height - 30
        ox, oy = 30, 15

        # Background zones
        c.setFillColor(colors.HexColor("#dcfce7"))
        c.rect(ox, oy + H*0.5, W, H*0.5, fill=1, stroke=0)
        c.setFillColor(colors.HexColor("#fef9c3"))
        c.rect(ox, oy + H*0.2, W, H*0.3, fill=1, stroke=0)
        c.setFillColor(colors.HexColor("#fee2e2"))
        c.rect(ox, oy, W, H*0.2, fill=1, stroke=0)

        # Axes
        c.setStrokeColor(C_GREY)
        c.setLineWidth(0.5)
        c.line(ox, oy, ox, oy+H)
        c.line(ox, oy, ox+W, oy)

        # SOC line
        c.setStrokeColor(C_BLUE)
        c.setLineWidth(2)
        pts = [(ox + i/23*W, oy + soc[i]*H) for i in range(24)]
        for i in range(23):
            c.line(pts[i][0], pts[i][1], pts[i+1][0], pts[i+1][1])

        # Y ticks
        c.setFont("Helvetica", 7)
        c.setFillColor(C_GREY)
        for pct in [0,20,50,80,100]:
            y = oy + (pct/100)*H
            c.line(ox-3, y, ox, y)
            c.drawRightString(ox-4, y-3, f"{pct}%")

        # X ticks
        for h in [0,6,12,18,23]:
            x = ox + h/23*W
            c.line(x, oy-3, x, oy)
            c.drawCentredString(x, oy-11, f"{h}h")

        # Legend labels
        c.setFont("Helvetica", 7)
        c.setFillColor(C_GREEN);  c.drawString(ox+W+2, oy+H*0.75-3, "Good")
        c.setFillColor(C_YELLOW); c.drawString(ox+W+2, oy+H*0.35-3, "OK")
        c.setFillColor(C_RED);    c.drawString(ox+W+2, oy+H*0.10-3, "Low")
        c.setFillColor(C_BLUE);   c.setFont("Helvetica-Bold", 8)
        c.drawCentredString(ox+W/2, oy+H+6, "Battery State-of-Charge Trace (24 hours)")

    def wrap(self, *args):
        return (self.width, self.height)

# ─── MAIN STORY ────────────────────────────────────────────────────────────────
def build_story():
    story = []

    # ══ COVER — drawn via onFirstPage, just need a page break here ═══════════
    story.append(PageBreak())

    # ══ TABLE OF CONTENTS ═════════════════════════════════════════════════════
    story.append(h1("Table of Contents"))
    story.append(HR())
    toc_entries = [
        ("1", "Project Overview & Motivation",               ""),
        ("2", "System Architecture",                          ""),
        ("3", "Electrical Standards & Theory",               ""),
        ("4", "Diversity Factor System (IEC 60364-8-1)",     ""),
        ("5", "Socket / Outlet Demand Calculations",         ""),
        ("6", "Active, Reactive & Apparent Power Theory",    ""),
        ("7", "Power Factor Correction & Capacitor Sizing",  ""),
        ("8", "Motor Inrush Current (NEC / IEC 60947-4)",    ""),
        ("9", "3-Phase Load Balancing",                      ""),
        ("10","Solar PV Modelling & NASA POWER Integration", ""),
        ("11","Battery Energy Storage System (BESS)",        ""),
        ("12","Source Dispatch Algorithm",                   ""),
        ("13","24-Hour Load Profile Generation",             ""),
        ("14","Database Schema & Data Model",                ""),
        ("15","REST API Reference",                          ""),
        ("16","Frontend Architecture & UI Logic",            ""),
        ("17","Key Formulae Quick Reference",                ""),
        ("18","Summary & Conclusion",                        ""),
    ]
    toc_data = [["§", "Section Title"]] + [[n, t] for n,t,_ in toc_entries]
    story.append(table(toc_data, col_widths=[1*cm, 14*cm]))
    story.append(PageBreak())

    # ══ 1. PROJECT OVERVIEW ════════════════════════════════════════════════════
    story.append(section_header("1", "Project Overview & Motivation"))
    story.append(p(
        "Power Profile is a full-stack web application designed and developed as a graduation "
        "project in electrical engineering. Its primary purpose is to automate the complex, "
        "multi-step process of designing an electrical power supply system for multi-building "
        "complexes — a task that traditionally requires specialist knowledge and many hours of "
        "manual calculation."
    ))
    story.append(p(
        "The tool takes as input a hierarchical description of a site (project → buildings → "
        "floors → rooms → electrical components) and produces as output:"
    ))
    for item in [
        "Total demand (VA, kW, kVAR) with diversity factors applied per IEC 60364-8-1",
        "Power factor correction requirement and capacitor bank sizing",
        "24-hour load profile derived from usage schedules",
        "Solar PV generation profile using real NASA POWER satellite irradiance data",
        "Battery Energy Storage System (BESS) state-of-charge simulation",
        "Multi-source dispatch (Solar → Battery → Grid → Generator) hour-by-hour",
        "3-phase load balancing with optimal phase assignment",
        "PDF and structured backup/restore reports",
    ]:
        story.append(bullet(item))
    story.append(sp())

    story.append(p(
        "The engineering calculations are grounded in internationally accepted standards "
        "including <b>IEC 60364-8-1</b> (energy efficiency in buildings), "
        "<b>BS 7671</b> (UK wiring regulations), <b>CIBSE Guide C</b> "
        "(building services engineering), <b>PENRA</b> (Power & Energy Regulatory Authority "
        "power-factor targets), and <b>NEC Article 430</b> (motor branch circuit sizing). "
        "The solar model implements Spencer's declination formula and the classical "
        "hour-angle sunrise/sunset algorithm."
    ))

    story.append(sp(12))
    story.append(h2("1.1  Technology Stack"))
    tech_data = [
        ["Layer", "Technology", "Version", "Role"],
        ["Backend", "Laravel / PHP", "13 / 8.4", "REST API, business logic, auth"],
        ["Frontend", "React + Vite", "18 / 4.x", "Single-page application"],
        ["Styling", "Tailwind CSS", "3.x", "Utility-first responsive UI"],
        ["Charts", "Recharts", "2.x", "24-h load & dispatch visualisation"],
        ["Database", "SQLite", "3.x", "Relational data store"],
        ["Auth", "Laravel Sanctum + Google OAuth 2.0", "—", "Token-based API auth"],
        ["Solar data", "NASA POWER API", "v2", "Satellite GHI irradiance"],
        ["Routing", "React Router", "v6", "Client-side navigation"],
    ]
    story.append(table(tech_data, col_widths=[2.8*cm, 4.5*cm, 2.2*cm, 6*cm]))
    story.append(PageBreak())

    # ══ 2. SYSTEM ARCHITECTURE ════════════════════════════════════════════════
    story.append(section_header("2", "System Architecture"))
    story.append(p(
        "The application follows a classic client-server architecture with a clear "
        "four-layer separation of concerns. The React SPA communicates with a "
        "Laravel REST API exclusively via JSON over HTTP. All engineering calculations "
        "are performed server-side inside dedicated Service classes, keeping the "
        "frontend entirely presentation-focused."
    ))
    story.append(sp(6))
    story.append(ArchDiagram(width=460, height=220))
    story.append(cap("Figure 2.1 — Four-layer full-stack architecture"))
    story.append(sp(8))

    story.append(h2("2.1  Request Lifecycle"))
    for step in [
        "User interacts with a React page (e.g. opens Load Schedule).",
        "React component calls axios GET /api/projects/{id}/load-profile.",
        "Laravel route resolves to LoadProfileController@show.",
        "Controller calls DiversityFactorService, SocketDemandService, and SolarIrradianceService.",
        "SolarIrradianceService queries NASA POWER (or returns cached data).",
        "Controller aggregates all component data into 24-hour hourly arrays.",
        "JSON response returned; React stores in state and renders Recharts graphs.",
    ]:
        story.append(bullet(step))
    story.append(sp())

    story.append(h2("2.2  Project Hierarchy (Data Model Summary)"))
    story.append(p(
        "Every entity in the system forms a strict containment tree. "
        "Electrical components (loads) can be attached at any level of this tree. "
        "Diversity factors are then applied top-down according to which level "
        "the component sits at."
    ))
    hier_data = [
        ["Level", "Entity", "Multiplicity", "Electrical Meaning"],
        ["0", "Project", "1 per user", "Entire site / campus"],
        ["1", "Building", "1–N per project", "Individual structure"],
        ["2", "Floor", "1–N per building", "Storey"],
        ["3", "Room", "1–N per floor", "Space / zone"],
        ["4", "Component", "1–N per room/floor/building/project", "Individual load"],
    ]
    story.append(table(hier_data, col_widths=[1.2*cm, 3*cm, 3.5*cm, 7.5*cm]))
    story.append(PageBreak())

    # ══ 3. ELECTRICAL STANDARDS & THEORY ══════════════════════════════════════
    story.append(section_header("3", "Electrical Standards & Theory"))
    story.append(p(
        "The calculations implemented in this project draw on the following "
        "internationally recognised standards and references."
    ))
    std_data = [
        ["Standard / Reference", "Full Title", "Application in This System"],
        ["IEC 60364-8-1", "Low-voltage electrical installations — Energy efficiency", "Diversity factors by building type"],
        ["BS 7671:2018", "Requirements for Electrical Installations (IET Wiring Regs)", "Diversity factor source tables"],
        ["CIBSE Guide C", "Reference Data — Building Services Engineering", "Room coincidence factors"],
        ["PENRA", "Power and Energy Regulatory Authority — PF regulation", "Target power factor = 0.95"],
        ["NEC Article 430", "National Electrical Code — Motors", "125% motor inrush rule"],
        ["IEC 60947-4", "Low-voltage switchgear — Contactors and starters", "Motor inrush standard"],
        ["IEC 61675-3", "Solar radiation — Hour-angle and declination", "Sunrise/sunset computation"],
        ["Spencer (1971)", "Fourier series for solar declination", "Day-of-year declination formula"],
        ["NASA POWER v2", "Prediction of Worldwide Energy Resources", "Satellite GHI irradiance data"],
        ["IEC 60831", "Shunt power capacitors — General performance", "Capacitor bank configuration"],
    ]
    story.append(table(std_data, col_widths=[3.2*cm, 5.8*cm, 6.2*cm]))
    story.append(PageBreak())

    # ══ 4. DIVERSITY FACTORS ══════════════════════════════════════════════════
    story.append(section_header("4", "Diversity Factor System (IEC 60364-8-1)"))
    story.append(p(
        "In any real building, not all electrical loads operate simultaneously at full "
        "rated capacity. The <b>diversity factor</b> (DF) accounts for this statistical "
        "reality by scaling the sum of individual rated loads to a realistic maximum "
        "demand. IEC 60364-8-1 and BS 7671 provide DF tables by building type."
    ))

    story.append(h2("4.1  Mathematical Definition"))
    story.append(p("The general form of the diversity-weighted demand at a given aggregation level is:"))
    story.append(eq("P_demand = Σᵢ ( P_rated,ᵢ × DF_building × DF_room_type × DF_coincidence )"))
    story.append(p("where:"))
    story.append(bullet("P_rated,ᵢ   = rated apparent power of component i [VA]"))
    story.append(bullet("DF_building = tabulated factor for the building type (room-to-floor leg)"))
    story.append(bullet("DF_room_type = coincidence factor for the specific room type (0.25 – 1.00)"))
    story.append(bullet("DF_coincidence = 0.70 (project-level top-of-hierarchy factor, IEC 60364-8-1 §8.3)"))
    story.append(sp())

    story.append(h2("4.2  Hierarchical DF Application"))
    story.append(p(
        "The system applies diversity factors in a strict hierarchy. A component defined "
        "at a <i>room</i> level receives the full three-layer diversification; one defined "
        "at <i>project</i> level receives none (it is already at the top)."
    ))
    story.append(eqb(
        "Room component:     DF = DF_room_type × DF_floor_to_bldg × DF_room_to_floor × 0.70\n"
        "Floor component:    DF = DF_floor_to_bldg × 0.70\n"
        "Building component: DF = 0.70\n"
        "Project component:  DF = 1.00  (already at system level)\n"
        "CRITICAL priority:  DF = 1.00  (never diversified — always on)"
    ))
    story.append(sp())

    story.append(h2("4.3  Building-Type Diversity Factors"))
    df_bldg_data = [
        ["Building Type", "Room → Floor DF", "Floor → Building DF"],
        ["Residential House",       "0.60", "0.70"],
        ["Residential Apartment",   "0.65", "0.70"],
        ["Hotel",                   "0.65", "0.70"],
        ["Office",                  "0.85", "0.80"],
        ["Educational School",      "0.80", "0.80"],
        ["Educational University",  "0.85", "0.80"],
        ["Retail",                  "0.85", "0.85"],
        ["Hospital",                "0.90", "0.90"],
        ["Industrial",              "0.85", "0.85"],
        ["Mosque / Worship",        "0.80", "0.75"],
        ["Sports",                  "0.80", "0.80"],
        ["Generic / Default (IEC)", "0.90", "0.80"],
    ]
    story.append(table(df_bldg_data, col_widths=[6*cm, 4.5*cm, 4.5*cm]))
    story.append(note("Source: IEC 60364-8-1:2019, Table B.1 / BS 7671 Appendix 1 / CIBSE Guide C Table 14.4"))
    story.append(sp())

    story.append(h2("4.4  Room-Type Coincidence Factors"))
    story.append(p(
        "Within each building type, individual room types receive an additional "
        "coincidence factor reflecting typical simultaneous usage patterns."
    ))
    df_room_data = [
        ["Room Type", "Coincidence Factor", "Rationale"],
        ["Server Room",        "1.00", "Continuous operation — no diversity"],
        ["Operating Theatre",  "1.00", "Life-safety — no diversity"],
        ["Laboratory",         "0.90", "High utilisation, scheduled use"],
        ["Classroom / Lecture","0.85", "Group use during sessions"],
        ["Workshop / Retail",  "0.85", "High occupancy during open hours"],
        ["Open Office / Gym",  "0.80", "Moderate simultaneous use"],
        ["Commercial Kitchen", "0.75", "Staggered meal preparation"],
        ["Private Office / Prayer Hall", "0.75", "Individual or scheduled use"],
        ["Reception / Meeting","0.70", "Variable occupancy"],
        ["Corridor / Living",  "0.60", "Transient occupancy"],
        ["Residential Kitchen","0.55", "Short peak cooking periods"],
        ["Hotel Room",         "0.50", "Guests absent much of the time"],
        ["Bedroom",            "0.45", "Night-time use only"],
        ["Warehouse / Storage","0.30", "Infrequent access"],
        ["Bathroom",           "0.25", "Very short occupation periods"],
        ["Default",            "0.80", "IEC generic building default"],
    ]
    story.append(table(df_room_data, col_widths=[4.5*cm, 3.5*cm, 7.2*cm]))
    story.append(PageBreak())

    # ══ 5. SOCKET DEMAND ══════════════════════════════════════════════════════
    story.append(section_header("5", "Socket / Outlet Demand Calculations"))
    story.append(p(
        "Electrical socket outlets contribute a significant portion of building demand but "
        "are rarely all loaded simultaneously. The system implements a tiered demand "
        "factor schedule consistent with IEC 60364-5-52 and general industry practice."
    ))

    story.append(h2("5.1  Rated VA per Outlet"))
    story.append(eq("VA_per_outlet = 200 VA   (IEC standard residential/commercial outlet rating)"))
    story.append(sp())

    story.append(h2("5.2  Tiered Demand Factor"))
    story.append(p("For n outlets at a single point-of-supply, the demand is calculated as:"))
    story.append(eqb(
        "If n ≤ 10:\n"
        "   Demand = n × 200 × 1.00\n\n"
        "If 10 < n ≤ 20:\n"
        "   Demand = 10×200×1.00 + (n-10)×200×0.75\n\n"
        "If n > 20:\n"
        "   Demand = 10×200×1.00 + 10×200×0.75 + (n-20)×200×0.40\n\n"
        "General form:\n"
        "   Demand = min(n,10)×200 + min(max(n-10,0),10)×150 + max(n-20,0)×80"
    ))

    demand_tbl = [
        ["Outlet Range", "Unit Demand", "Demand Factor", "Reasoning"],
        ["1st – 10th outlets",  "200 VA each", "100%", "All likely in simultaneous use"],
        ["11th – 20th outlets", "150 VA each", "75%",  "Moderate probability of use"],
        ["21st outlet onwards", "80 VA each",  "40%",  "Statistically low probability"],
    ]
    story.append(table(demand_tbl, col_widths=[4.2*cm, 3*cm, 2.5*cm, 5.5*cm]))
    story.append(sp())

    story.append(h2("5.3  System-Size Coincidence Factor"))
    story.append(p(
        "When aggregating socket demand from many rooms, an additional coincidence "
        "factor is applied based on total system size:"
    ))
    coin_data = [
        ["System Total Demand", "Coincidence Factor"],
        ["< 50 kVA",       "1.00"],
        ["50 kVA – 250 kVA",  "0.92"],
        ["250 kVA – 1000 kVA","0.85"],
    ]
    story.append(table(coin_data, col_widths=[7*cm, 4*cm]))
    story.append(sp())

    story.append(h2("5.4  Hierarchical Socket Aggregation"))
    story.append(eqb(
        "Room demand   = tiered_demand(n_room_sockets)\n"
        "Floor demand  = tiered_demand(Σ room sockets + floor own sockets)\n"
        "Bldg demand   = tiered_demand(all floors) × coincidence_factor(system_kva)\n"
        "Project demand= tiered_demand(all buildings) × coincidence_factor(system_kva)"
    ))
    story.append(PageBreak())

    # ══ 6. POWER THEORY ═══════════════════════════════════════════════════════
    story.append(section_header("6", "Active, Reactive & Apparent Power Theory"))
    story.append(p(
        "All AC electrical loads are characterised by three distinct power quantities. "
        "Understanding their relationships is fundamental to sizing cables, transformers, "
        "generators, and power-factor correction equipment."
    ))

    story.append(h2("6.1  The Power Triangle"))
    story.append(PowerTriangle(width=280, height=180))
    story.append(cap("Figure 6.1 — Power triangle: P (active), Q (reactive), S (apparent)"))
    story.append(sp(8))

    story.append(h2("6.2  Core Power Equations"))
    eqs_power = [
        ("Apparent Power",   "S [VA] = √( P² + Q² )"),
        ("Active Power",     "P [W]  = S × cos(φ)"),
        ("Reactive Power",   "Q [VAR]= S × sin(φ)  =  P × tan(φ)"),
        ("Power Factor",     "PF     = cos(φ)  =  P / S"),
        ("Phase Angle",      "φ      = arccos(PF)"),
        ("Q from PF",        "Q      = P × tan( arccos(PF) )"),
        ("3-phase apparent", "S_3φ   = √3 × V_LL × I_L          [V_LL = 400 V]"),
        ("1-phase apparent", "S_1φ   = V_LN × I_L                [V_LN = 230 V]"),
        ("3-phase current",  "I_L    = S / (√3 × 400)            [A]"),
    ]
    for name, formula in eqs_power:
        row = [p(f"<b>{name}</b>", BODY_SMALL), eqb(formula)]
        story.append(table([["Quantity", "Formula"], row], col_widths=[4*cm, 11.2*cm]))
        story.append(sp(2))
    story.append(sp())

    story.append(h2("6.3  Per-Component Reactive Power"))
    story.append(p("For each electrical component i with rated apparent power S_i and power factor PF_i:"))
    story.append(eqb(
        "P_i   = S_i × PF_i\n"
        "φ_i   = arccos(PF_i)\n"
        "Q_i   = P_i × tan(φ_i)  =  S_i × sin( arccos(PF_i) )\n"
        "S_i   = √(P_i² + Q_i²)   ✓ (consistency check)"
    ))

    story.append(h2("6.4  System Aggregation (Vector Sum)"))
    story.append(p(
        "Individual component phasors are summed as complex numbers before computing "
        "the resultant power factor — scalar addition of VA values would overestimate "
        "S if loads have different power factors."
    ))
    story.append(eqb(
        "P_total  = Σ P_i     (scalars — always additive)\n"
        "Q_total  = Σ Q_i     (scalars for DF-weighted inductive loads)\n"
        "S_total  = √( P_total² + Q_total² )\n"
        "PF_total = P_total / S_total\n"
        "I_total  = S_total / (√3 × 400)   [3-phase]"
    ))
    story.append(PageBreak())

    # ══ 7. POWER FACTOR CORRECTION ════════════════════════════════════════════
    story.append(section_header("7", "Power Factor Correction & Capacitor Sizing"))
    story.append(p(
        "When the system power factor falls below the PENRA-mandated threshold of 0.85, "
        "shunt capacitor banks must be installed to inject leading reactive power (VAR) "
        "and raise the effective PF toward the target of 0.95. This reduces billing "
        "penalties, decreases line currents, and lowers I²R losses."
    ))

    story.append(h2("7.1  Reactive Power Deficit"))
    story.append(eqb(
        "φ_current = arccos( PF_current )            [current phase angle]\n"
        "φ_target  = arccos( 0.95 )  ≈  18.19°      [PENRA target]\n"
        "Q_current = P × tan( φ_current )            [kVAR absorbed by loads]\n"
        "Q_target  = P × tan( φ_target  )            [kVAR at PF = 0.95]\n"
        "Q_cap_needed = Q_current − Q_target         [kVAR to inject]"
    ))
    story.append(sp())

    story.append(h2("7.2  Capacitor Bank Sizing (Step Rounding)"))
    story.append(p(
        "Capacitor banks are manufactured in standard discrete steps of 0.5 kVAR. "
        "The required bank is rounded up to the nearest 0.5 kVAR step."
    ))
    story.append(eq("Q_bank = ⌈ Q_cap_needed / 0.5 ⌉ × 0.5   [kVAR]"))
    story.append(sp())

    story.append(h2("7.3  Per-Phase Capacitance — Delta (Δ) Configuration"))
    story.append(p(
        "The capacitors are connected in delta (Δ) across the 400 V 3-phase supply. "
        "In delta connection each capacitor sees the full line-to-line voltage of 400 V. "
        "Total reactive injection Q_bank is equally split among three phases."
    ))
    story.append(eqb(
        "Q_per_phase = Q_bank × 1000 / 3                [VAR per capacitor]\n\n"
        "For a capacitor across 400 V at 50 Hz:\n"
        "  I_cap = Q_per_phase / V_LL\n"
        "  X_c   = V_LL / I_cap  =  V_LL² / Q_per_phase\n"
        "  X_c   = 1 / (2π f C)\n\n"
        "Therefore:\n"
        "  C = Q_per_phase / (2π f V_LL²)                [Farads]\n"
        "  C = (Q_bank×1000/3) / (2 × π × 50 × 400²)    [Farads]\n"
        "  C_μF = C × 1×10⁶                              [microfarads]"
    ))
    story.append(sp())
    story.append(note("PENRA threshold: PF < 0.85 triggers mandatory correction. Target: PF = 0.95."))
    story.append(note("Capacitor step: 0.5 kVAR (standard manufactured increment, IEC 60831)."))
    story.append(note("Delta connection chosen because it provides the same reactive power as star (Y) "
                      "with 1/3 the capacitance per unit, reducing component count."))
    story.append(sp())

    story.append(h2("7.4  Correction Thresholds"))
    pfc_data = [
        ["Power Factor Range", "Status", "Action"],
        ["PF ≥ 0.95",        "Compliant",    "No correction needed"],
        ["0.85 ≤ PF < 0.95", "Warning",      "Improvement recommended"],
        ["PF < 0.85",        "Non-compliant","Capacitor bank mandatory (PENRA)"],
    ]
    story.append(table(pfc_data, col_widths=[4.5*cm, 3*cm, 7.7*cm]))
    story.append(PageBreak())

    # ══ 8. MOTOR INRUSH ═══════════════════════════════════════════════════════
    story.append(section_header("8", "Motor Inrush Current (NEC Article 430 / IEC 60947-4)"))
    story.append(p(
        "When an AC induction motor starts, it draws a starting (inrush) current "
        "typically 5–7× its full-load current for a duration of 0.5–10 seconds. "
        "NEC Article 430 and IEC 60947-4 both require that distribution boards and "
        "protective devices be sized for 125% of the largest motor's full-load current "
        "to ensure safe operation and prevent nuisance tripping."
    ))

    story.append(h2("8.1  NEC 125% Rule"))
    story.append(eqb(
        "Identify:   Motor_max_VA = max( VA_i ) for all motor components\n\n"
        "Inrush VA:  VA_inrush = Motor_max_VA × 1.25\n\n"
        "Addition:   ΔVA = VA_inrush − Motor_max_VA  =  0.25 × Motor_max_VA\n\n"
        "Apply to:   S_max (peak apparent power vector)\n"
        "            Q_max (peak reactive power vector)\n"
        "NOT to:     S_optimized (diversity-weighted demand)"
    ))
    story.append(p(
        "The 25% addition is applied only to the <i>maximum demand</i> vector "
        "used for short-circuit and cable sizing, not to the optimised (diversified) "
        "demand used for energy calculations. This correctly sizes protective devices "
        "without inflating the energy billing estimate."
    ))
    story.append(sp())

    story.append(h2("8.2  Motor Components in the Database"))
    story.append(p(
        "Component types are flagged with <b>is_motor = true</b> in the component type "
        "library. The system automatically identifies the largest motor VA in the project "
        "hierarchy at calculation time, so no manual intervention is required."
    ))
    story.append(PageBreak())

    # ══ 9. 3-PHASE BALANCE ════════════════════════════════════════════════════
    story.append(section_header("9", "Three-Phase Load Balancing"))
    story.append(p(
        "In a 3-phase 4-wire system (400 V L-L / 230 V L-N, 50 Hz), single-phase "
        "loads must be distributed across phases A, B, and C to minimise neutral "
        "current and reduce transformer losses. Excessive imbalance causes increased "
        "I²R losses, elevated neutral current, and potential transformer overheating."
    ))

    col1 = [PhasorDiagram(width=200, height=200)]
    col2_content = [
        h3("9.1  Phase Phasor Representation"),
        sp(4),
        p("Each phase in a balanced 3-phase system is displaced by 120°:"),
        eqb(
            "V_A = V_phase ∠  0°\n"
            "V_B = V_phase ∠ 120°\n"
            "V_C = V_phase ∠ 240°\n\n"
            "V_phase = 230 V (line-to-neutral)\n"
            "V_LL    = V_phase × √3 = 400 V"
        ),
        sp(4),
        p("The current on each phase from a single-phase load of apparent power S:"),
        eq("I_phase = S / V_LN = S / 230   [A]"),
    ]
    t2col = Table([[col1, col2_content]], colWidths=[6.5*cm, 9.5*cm])
    t2col.setStyle(TableStyle([("VALIGN", (0,0),(-1,-1),"TOP"), ("GRID",(0,0),(-1,-1),0,C_WHITE)]))
    story.append(t2col)
    story.append(sp())

    story.append(h2("9.2  Phasor Aggregation per Phase"))
    story.append(eqb(
        "For each phase X ∈ {A, B, C}:\n"
        "  I_X_phasor = Σ ( S_i / 230 ) ∠ angle_X    for all 1-phase loads on phase X\n\n"
        "  where angle_A = 0°, angle_B = 120°, angle_C = 240°\n\n"
        "  |I_X| = magnitude of the phasor sum for phase X\n\n"
        "Imbalance % = ( max|I| − min|I| ) / avg|I|  × 100"
    ))
    story.append(sp())

    story.append(h2("9.3  Imbalance Thresholds"))
    imb_data = [
        ["Imbalance %", "Severity", "Action"],
        ["< 10%",   "Acceptable",  "No action required"],
        ["10–20%",  "Warning",     "Redistribution recommended"],
        ["> 20%",   "Critical",    "Immediate rebalancing required"],
    ]
    story.append(table(imb_data, col_widths=[3*cm, 3*cm, 9.2*cm]))
    story.append(sp())

    story.append(h2("9.4  Greedy Optimal Phase Assignment Algorithm"))
    story.append(p(
        "When the user triggers 'Apply Optimal Phase Assignment', the backend executes "
        "a greedy algorithm that assigns each unphased 1-phase component to the "
        "currently most lightly-loaded phase:"
    ))
    story.append(eqb(
        "Sort components by VA descending  (largest loads first)\n\n"
        "For each component i in sorted order:\n"
        "  X* = argmin( |I_A|, |I_B|, |I_C| )   ← phase with lowest current\n"
        "  Assign component i → phase X*\n"
        "  Update |I_X*| += S_i / 230"
    ))
    story.append(p(
        "This greedy approach yields a near-optimal distribution in O(n log n) time. "
        "The 'largest first' ordering (a variant of First-Fit Decreasing bin-packing) "
        "has been shown to produce solutions within 11/9 of optimal in the worst case."
    ))
    story.append(PageBreak())

    # ══ 10. SOLAR PV MODELLING ════════════════════════════════════════════════
    story.append(section_header("10", "Solar PV Modelling & NASA POWER Integration"))
    story.append(p(
        "The system generates a realistic hourly solar generation profile for any "
        "geographic coordinate by combining NASA's satellite-derived Global Horizontal "
        "Irradiance (GHI) data with an analytical sinusoidal bell-curve model of "
        "daily irradiance variation."
    ))

    story.append(h2("10.1  Available Roof Area & PV Capacity"))
    story.append(p(
        "In practice, not all roof area is usable for PV panels due to obstructions, "
        "setbacks, maintenance walkways, and shading. A coverage ratio of 17% is used:"
    ))
    story.append(eqb(
        "Usable_area  = Roof_area_m² × 0.17\n\n"
        "Capacity_W   = Usable_area × STC_irradiance × PR_sizing\n"
        "             = Roof_area × 0.17 × 1000 W/m² × 0.75\n\n"
        "where:\n"
        "  STC_irradiance = 1000 W/m²  (Standard Test Conditions)\n"
        "  PR_sizing      = 0.75        (conservative performance ratio for sizing)\n"
        "  PR_energy      = 0.80        (performance ratio used in energy calculations)"
    ))
    story.append(note("The performance ratio accounts for inverter losses, temperature derating, "
                      "soiling, shading, and cable losses. Typical values: 0.75–0.85."))
    story.append(sp())

    story.append(h2("10.2  Solar Declination (Spencer's Equation, 1971)"))
    story.append(p(
        "The solar declination δ is the angle between the equatorial plane and the "
        "line from Earth's centre to the Sun. It varies from +23.45° (summer solstice) "
        "to −23.45° (winter solstice)."
    ))
    story.append(eqb(
        "doy  = day-of-year of the mid-month (e.g., January 15 → doy = 15)\n\n"
        "δ    = 23.45 × sin( 360/365 × (doy − 81) )   [degrees]\n\n"
        "      (Spencer 1971 simplified — full Fourier has 6 terms; this is ±0.3°)"
    ))
    story.append(sp())

    story.append(h2("10.3  Sunrise / Sunset — Hour-Angle Formula"))
    story.append(p(
        "The hour angle H at sunrise/sunset satisfies cos(H) = −tan(φ)·tan(δ), "
        "where φ is the site latitude and δ is the declination."
    ))
    story.append(eqb(
        "cos(H_ss) = −tan(φ_lat) × tan(δ)\n\n"
        "Special cases:\n"
        "  cos(H_ss) > +1.0  →  Polar night  (sun never rises)\n"
        "  cos(H_ss) < −1.0  →  Midnight sun (sun never sets)\n\n"
        "Otherwise:\n"
        "  H_ss_deg  = arccos( cos(H_ss) )          [degrees]\n"
        "  Sunrise   = 12:00 − H_ss_deg / 15        [hours local solar time]\n"
        "  Sunset    = 12:00 + H_ss_deg / 15        [hours local solar time]\n"
        "  Daylight  = Sunset − Sunrise              [hours]\n\n"
        "Note: divide by 15 because Earth rotates 15°/hour"
    ))
    story.append(sp())

    story.append(h2("10.4  Hourly Generation Profile (Sinusoidal Bell Curve)"))
    story.append(p(
        "The actual irradiance during daylight hours follows approximately a "
        "sinusoidal shape. The normalised daily energy equals PSH × PR:"
    ))
    story.append(eqb(
        "Peak_W = Capacity_W × PSH_kWh/m²/day × PR_energy × π\n"
        "          / ( 2 × Daylight_hours )\n\n"
        "[This ensures ∫₀²⁴ Output(t) dt = Capacity × PSH × PR (energy conservation)]\n\n"
        "For each hour h (0 – 23):\n"
        "  mid = h + 0.5   (mid-point of hour)\n\n"
        "  if mid ≤ Sunrise  or  mid ≥ Sunset:\n"
        "    Output[h] = 0 W\n"
        "  else:\n"
        "    t = (mid − Sunrise) / Daylight     ∈ [0, 1]\n"
        "    Output[h] = Peak_W × sin(π × t)"
    ))
    story.append(sp(8))
    story.append(SolarProfile(width=430, height=140))
    story.append(cap("Figure 10.1 — Sinusoidal daily solar generation profile (illustrative, latitude 30°N, June)"))
    story.append(sp())

    story.append(h2("10.5  Peak Sun Hours Lookup Table"))
    story.append(p(
        "The PSH table provides monthly average daily GHI (kWh/m²/day) at seven "
        "latitude bands. Values for intermediate latitudes are linearly interpolated."
    ))
    psh_data = [
        ["Lat\\Month","Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
        ["0°",  "5.5","5.8","6.0","5.9","5.6","5.5","5.5","5.7","5.9","5.8","5.5","5.4"],
        ["10°", "5.0","5.5","6.0","6.2","6.1","6.0","6.0","6.1","6.1","5.8","5.2","4.8"],
        ["20°", "4.5","5.2","5.9","6.4","6.5","6.4","6.4","6.4","6.1","5.6","4.8","4.3"],
        ["30°", "3.8","4.6","5.6","6.4","6.8","7.0","6.9","6.6","6.1","5.2","4.1","3.5"],
        ["40°", "2.9","3.8","5.0","6.1","6.9","7.3","7.1","6.5","5.6","4.4","3.2","2.5"],
        ["50°", "1.8","2.9","4.3","5.7","6.7","7.2","7.0","6.1","4.9","3.4","2.1","1.4"],
        ["60°", "0.8","1.8","3.4","5.1","6.4","7.0","6.8","5.6","4.0","2.4","1.1","0.5"],
    ]
    story.append(table(psh_data, col_widths=[1.2*cm]+[1.1*cm]*12))
    story.append(note("Southern hemisphere: season flipped by 6 months (July ↔ January, etc.)"))
    story.append(sp())

    story.append(h2("10.6  NASA POWER API Integration"))
    story.append(p(
        "When a project has GPS coordinates set, the system queries the NASA POWER "
        "REST API for real satellite-measured GHI at the representative day of each "
        "month (15th). This is more accurate than the static lookup table."
    ))
    nasa_data = [
        ["Parameter", "Value"],
        ["API Base URL", "https://power.larc.nasa.gov/api/temporal/hourly/point"],
        ["Parameter Code", "ALLSKY_SFC_SW_DWN  (All-sky surface shortwave downward irradiance)"],
        ["Temporal", "Hourly, representative day (15th of month)"],
        ["Units", "W/m²"],
        ["Accuracy", "±3% for monthly means"],
        ["Cache Duration", "30 days (historical satellite data is stable)"],
        ["Fallback", "Static PSH lookup table if API unavailable or coordinates absent"],
    ]
    story.append(table(nasa_data, col_widths=[3.5*cm, 11.7*cm]))
    story.append(PageBreak())

    # ══ 11. BESS ══════════════════════════════════════════════════════════════
    story.append(section_header("11", "Battery Energy Storage System (BESS)"))
    story.append(p(
        "The system models a battery bank of configurable chemistry, size, and age. "
        "Five battery chemistries are pre-configured with datasheet-accurate parameters. "
        "Capacity degrades with age and cycle count, modelled through an age factor."
    ))

    story.append(h2("11.1  Supported Battery Chemistries"))
    chem_data = [
        ["Chemistry", "DoD", "Round-trip η", "Charge C-rate", "Discharge C-rate", "Cycle Life", "Calendar Life"],
        ["Lead Acid (Flooded)", "50%", "80%", "0.10C", "0.20C", "500 cycles",  "5 years"],
        ["Lead Acid (AGM)",     "50%", "85%", "0.20C", "0.30C", "700 cycles",  "7 years"],
        ["Lead Acid (Gel)",     "50%", "85%", "0.15C", "0.25C", "800 cycles",  "8 years"],
        ["Lithium LFP",         "90%", "95%", "0.50C", "1.00C", "4000 cycles", "15 years"],
        ["Lithium NMC",         "80%", "93%", "0.50C", "1.00C", "2500 cycles", "10 years"],
    ]
    story.append(table(chem_data, col_widths=[3.5*cm,1.2*cm,2*cm,2.2*cm,2.5*cm,2.4*cm,2.4*cm]))
    story.append(note("DoD = Depth of Discharge. C-rate: 1C discharges the full capacity in 1 hour."))
    story.append(sp())

    story.append(h2("11.2  Capacity & Energy Calculations"))
    story.append(eqb(
        "Nominal_Capacity_kWh = (V_nominal × Ah_per_unit × quantity) / 1000\n\n"
        "Age_years = days_since_installation / 365.25\n\n"
        "Age_factor = max(0.70,  1.0 − Age_years × degradation_per_year_rate)\n"
        "  [70% is the industry-standard replacement threshold]\n\n"
        "Usable_Capacity_kWh = Nominal_kWh × DoD × Age_factor\n\n"
        "Available_Energy_kWh = Usable_kWh × SOC_current   [SOC ∈ 0..1]\n\n"
        "Max_Charge_Power_kW  = Nominal_kWh × C_rate_charge\n"
        "Max_Discharge_Power_kW = Nominal_kWh × C_rate_discharge\n\n"
        "Runtime_at_max_disch  = Usable_kWh / Max_Discharge_kW   [hours]\n"
        "Runtime_at_avg_disch  = Usable_kWh / (Max_Discharge_kW/2) [hours]"
    ))
    story.append(sp())

    story.append(h2("11.3  Health Status Classification"))
    health_data = [
        ["Age Factor Range", "Health Status", "Interpretation"],
        ["≥ 0.90",   "Good",     "Full rated performance, minimal degradation"],
        ["0.80–0.90","Fair",     "Noticeable capacity loss, continue monitoring"],
        ["0.70–0.80","Degraded", "Significant capacity loss, plan replacement"],
        ["< 0.70",   "Replace",  "Below industry threshold, immediate replacement"],
    ]
    story.append(table(health_data, col_widths=[3.5*cm, 2.5*cm, 9.2*cm]))
    story.append(sp(8))
    story.append(BatterySOCDiagram(width=420, height=130))
    story.append(cap("Figure 11.1 — Simulated 24-hour battery SOC trace with charging (morning solar) and discharge (evening)"))
    story.append(sp())

    story.append(h2("11.4  Solar System – Battery Pairing"))
    story.append(p(
        "Each battery can be paired with a named solar system. Surplus solar energy "
        "from a paired system is directed preferentially to its paired batteries before "
        "the common pool. This allows modelling of multiple isolated DC buses."
    ))
    story.append(PageBreak())

    # ══ 12. DISPATCH ALGORITHM ════════════════════════════════════════════════
    story.append(section_header("12", "Source Dispatch Algorithm"))
    story.append(p(
        "The dispatch algorithm runs hour by hour across a 24-hour window and "
        "determines, for each hour, how much power each source contributes to the load. "
        "The priority order follows economic and environmental logic: free solar first, "
        "then stored solar energy (batteries), then purchased grid power, and finally "
        "the most expensive and polluting option — diesel generation."
    ))
    story.append(sp(6))
    story.append(DispatchDiagram(width=460, height=200))
    story.append(cap("Figure 12.1 — Source dispatch priority diagram"))
    story.append(sp(8))

    story.append(h2("12.1  Dispatch Priority Logic (per hour h)"))
    story.append(eqb(
        "Demand_h = Load_kW[h]      (from load profile)\n\n"
        "Step 1: Solar covers load\n"
        "  Solar_used[h] = min(Solar_kW[h], Demand_h)\n"
        "  Remaining_h   = Demand_h − Solar_used[h]\n"
        "  Surplus_solar = Solar_kW[h] − Solar_used[h]\n\n"
        "Step 2: Solar charges batteries (paired first, then pool)\n"
        "  For each battery b paired to solar system s:\n"
        "    Headroom_b = (Usable_kWh_b − Current_kWh_b) × 1000  [W]\n"
        "    Charge_b   = min(Surplus/system, MaxChargeRate_b, Headroom_b / ηcharge_b)\n"
        "    SOC_b     += Charge_b × η_roundtrip / Nominal_kWh_b / 1000\n\n"
        "Step 3: Batteries discharge to cover remaining load\n"
        "  Ceil_W = min( ΣMaxDischargePower, Σ(Current_kWh×1000), ΣInverterCap )\n"
        "  Each battery contributes proportionally to its SOC:\n"
        "    Batt_share_b = Ceil_W × (Current_kWh_b / Σ Current_kWh)\n"
        "    Discharge_b  = min(Batt_share_b, MaxDischargePower_b, InverterCap_b)\n"
        "    SOC_b       -= Discharge_b / η_roundtrip / Nominal_kWh_b\n"
        "  Battery_discharged[h] = Σ Discharge_b / 1000  [kW]\n"
        "  Remaining_h           -= Battery_discharged[h]\n\n"
        "Step 4: Utility grid\n"
        "  Utility[h] = min(UtilityCapacity_kW, Remaining_h)\n"
        "  Remaining_h -= Utility[h]\n\n"
        "Step 5: Generator\n"
        "  Generator[h] = min(GeneratorCapacity_kW, Remaining_h)\n"
        "  Remaining_h -= Generator[h]\n\n"
        "Step 6: Opportunistic generator battery charging\n"
        "  if Generator[h] > 0 and GeneratorCapacity_kW > 0:\n"
        "    Gen_max_for_charge = GeneratorCapacity_kW × 0.85\n"
        "    Spare_gen = max(0, Gen_max_for_charge − Generator[h])\n"
        "    For each battery b:\n"
        "      AC_charge_b = min(share, MaxChargeRate_b × 1000)\n"
        "      DC_charge_b = AC_charge_b × η_AC_DC × η_roundtrip\n"
        "      SOC_b      += DC_charge_b / 1000 / Nominal_kWh_b\n"
        "      Generator[h] += AC_charge_b / 1000\n\n"
        "Unmet[h] = max(0, Remaining_h)   (shortfall — capacity insufficient)"
    ))
    story.append(sp())

    story.append(h2("12.2  Generator Efficiency Constants"))
    gen_data = [
        ["Constant", "Value", "Meaning"],
        ["GEN_OPTIMAL_MAX_LOAD", "0.85 (85%)", "Upper bound of efficient load band for diesel generators"],
        ["INV_EFF (AC→DC)",      "0.95 (95%)", "Efficiency of AC-to-DC conversion when charging from generator"],
        ["Battery round-trip η", "Chemistry-specific (80–95%)", "DC charging/discharging efficiency"],
    ]
    story.append(table(gen_data, col_widths=[4.5*cm, 4*cm, 6.7*cm]))
    story.append(sp())

    story.append(h2("12.3  Output Statistics (per month/simulation run)"))
    for stat in [
        "solar_kwh — Total solar energy generated and used [kWh]",
        "solar_self_consumption % — Solar energy used directly or stored, not curtailed",
        "battery_charged_kwh — Total energy stored in batteries [kWh]",
        "battery_efficiency_loss_kwh — Round-trip losses in BESS [kWh]",
        "utility_kwh — Energy drawn from the grid [kWh]",
        "generator_kwh — Energy produced by diesel generator [kWh]",
        "generator_loading_avg % — Average loading of generator (efficiency proxy)",
        "unmet_kwh — Unserved energy demand [kWh] (capacity gap alert)",
        "final_soc — Battery SOC at end of 24-hour window [0..1]",
    ]:
        story.append(bullet(stat))
    story.append(PageBreak())

    # ══ 13. LOAD PROFILE ══════════════════════════════════════════════════════
    story.append(section_header("13", "24-Hour Load Profile Generation"))
    story.append(p(
        "The load profile translates rated component powers and schedules into a "
        "time-resolved 24-hour demand curve. It enables dispatch simulation and "
        "visual insight into when loads are active."
    ))

    story.append(h2("13.1  Component Schedule Model"))
    story.append(p(
        "Each component carries three independent schedule dimensions:"
    ))
    sched_data = [
        ["Dimension", "Options", "Example"],
        ["usage_season",    "all / summer / winter / spring / fall",       "summer (HVAC cooling)"],
        ["usage_day_type",  "all / weekday / weekend",                     "weekday (office hours)"],
        ["usage_time_intervals", "List of {start, end} pairs (HH:MM)",    "[{08:00,13:00},{14:00,18:00}]"],
    ]
    story.append(table(sched_data, col_widths=[4*cm, 5.5*cm, 5.7*cm]))
    story.append(sp())

    story.append(h2("13.2  Hourly Active Power Calculation"))
    story.append(eqb(
        "peak_W_i = S_i × PF_i × Quantity_i × DF_i\n\n"
        "For each hour h (0 – 23):\n"
        "  P[h] = 0\n"
        "  for each component i:\n"
        "    if priority_i == 'critical':\n"
        "      P[h] += peak_W_i / 1000   [always on, 24 hours]\n"
        "    else:\n"
        "      for each interval [start, end] in usage_time_intervals_i:\n"
        "        if start < end:   # normal interval\n"
        "          if start ≤ h < end:  P[h] += peak_W_i / 1000\n"
        "        else:             # midnight-crossing interval (e.g. 22:00–06:00)\n"
        "          if h ≥ start or h < end:  P[h] += peak_W_i / 1000"
    ))
    story.append(sp())

    story.append(h2("13.3  Hourly Reactive Power Calculation"))
    story.append(eqb(
        "For each hour h (0 – 23):\n"
        "  Q[h] = 0\n"
        "  for each component i where PF_i < 1.0:\n"
        "    φ_i = arccos(PF_i)\n"
        "    q_i = peak_W_i × tan(φ_i) / 1000   [kVAR]\n"
        "    Add q_i to Q[h] for each hour where i is active"
    ))
    story.append(sp())

    story.append(h2("13.4  JSON API Response Structure"))
    story.append(eqb(
        '{\n'
        '  "hourly_kw":    [kW₀, kW₁, ..., kW₂₃],       // 24 values\n'
        '  "hourly_kvar":  [kVAR₀, ..., kVAR₂₃],         // 24 values\n'
        '  "hourly_solar_kw": [Solar₀, ..., Solar₂₃],    // 24 values\n'
        '  "solar_data_source": "nasa_power" | "static_lookup",\n'
        '  "components": [\n'
        '    {\n'
        '      "peak_w": float,\n'
        '      "power_factor": float,\n'
        '      "priority": "critical" | "essential" | "normal",\n'
        '      "usage_time_intervals": [{"start":"HH:MM","end":"HH:MM"}],\n'
        '      "usage_season": "all|summer|winter|spring|fall",\n'
        '      "usage_day_type": "all|weekday|weekend"\n'
        '    }\n'
        '  ]\n'
        '}'
    ))
    story.append(PageBreak())

    # ══ 14. DATABASE SCHEMA ════════════════════════════════════════════════════
    story.append(section_header("14", "Database Schema & Data Model"))

    story.append(h2("14.1  Entity Hierarchy Tables"))
    entity_data = [
        ["Table", "Key Columns", "Foreign Keys"],
        ["projects",  "id, user_id, name, building_type, location_lat, location_lng, solar_power, generator_power, work_days(JSON), working_season_intervals(JSON)", "user_id → users"],
        ["buildings", "id, project_id, name, type, area", "project_id → projects"],
        ["floors",    "id, building_id, name, area",      "building_id → buildings"],
        ["rooms",     "id, floor_id, type, name, area",   "floor_id → floors"],
    ]
    story.append(table(entity_data, col_widths=[2.5*cm, 8*cm, 4.7*cm]))
    story.append(sp())

    story.append(h2("14.2  Component Tables (4 levels)"))
    comp_data = [
        ["Table", "Unique Columns", "Shared Columns"],
        ["room_components",     "room_id",     "component_type_id, power(VA), phases, phase(A/B/C), power_factor, quantity, group_name, priority, usage_time_intervals(JSON), usage_season, usage_day_type"],
        ["floor_components",    "floor_id",    "(same as above)"],
        ["building_components", "building_id", "(same as above)"],
        ["project_components",  "project_id",  "(same as above)"],
    ]
    story.append(table(comp_data, col_widths=[3.5*cm, 2.5*cm, 9.2*cm]))
    story.append(sp())

    story.append(h2("14.3  Power Source Tables (Polymorphic)"))
    story.append(p(
        "Utility lines, generator lines, and sockets use Laravel polymorphic relations. "
        "A single table covers all hierarchy levels via <b>sourceable_type</b> + <b>sourceable_id</b>."
    ))
    poly_data = [
        ["Table", "Polymorphic Columns", "Other Columns"],
        ["utility_lines",   "utilitylineable_type, utilitylineable_id", "name, power(VA), phases"],
        ["generator_lines", "generatorlineable_type, generatorlineable_id", "name, power(VA), phases"],
        ["sockets",         "socketable_type, socketable_id", "phase_type, quantity, power(always 200)"],
    ]
    story.append(table(poly_data, col_widths=[3.5*cm, 4.5*cm, 7.2*cm]))
    story.append(sp())

    story.append(h2("14.4  Battery & Solar Tables"))
    batt_data = [
        ["Column", "Type", "Description"],
        ["name",                "string",  "User-friendly label"],
        ["chemistry",           "enum",    "lead_acid_flooded | agm | gel | lithium_lfp | lithium_nmc"],
        ["nominal_voltage_v",   "float",   "Nominal bank voltage [V]"],
        ["capacity_ah_per_unit","float",   "Capacity of each unit [Ah]"],
        ["quantity",            "integer", "Number of units in parallel"],
        ["installation_date",   "date",    "Used to calculate age and degradation"],
        ["depth_of_discharge",  "float",   "Usable fraction of nominal capacity (0..1)"],
        ["round_trip_efficiency","float",  "Charge + discharge efficiency combined (0..1)"],
        ["c_rate_charge",       "float",   "Max charge rate as fraction of capacity [C]"],
        ["c_rate_discharge",    "float",   "Max discharge rate [C]"],
        ["current_soc",         "float",   "Current state of charge (0..1)"],
        ["solar_system_id",     "FK|null", "Paired solar system (nullable)"],
        ["is_active",           "boolean", "Include in dispatch simulation"],
    ]
    story.append(table(batt_data, col_widths=[4*cm, 2.5*cm, 8.7*cm]))
    story.append(PageBreak())

    # ══ 15. REST API ══════════════════════════════════════════════════════════
    story.append(section_header("15", "REST API Reference"))
    story.append(p(
        "All 56 API endpoints are prefixed with <b>/api</b> and protected by "
        "Laravel Sanctum token authentication (except public endpoints noted). "
        "The API follows REST conventions with JSON request/response bodies."
    ))

    api_groups = [
        ("Projects", [
            ("GET",    "/projects",                       "List all user projects"),
            ("POST",   "/projects",                       "Create new project"),
            ("GET",    "/projects/{id}",                  "Get project detail"),
            ("PUT",    "/projects/{id}",                  "Update project"),
            ("DELETE", "/projects/{id}",                  "Delete project"),
            ("GET",    "/projects/{id}/total-power",      "Calculate total power demand"),
            ("GET",    "/projects/{id}/load-profile",     "Generate 24-h load profile"),
            ("GET",    "/projects/{id}/phase-balance",    "Calculate 3-phase balance"),
            ("GET",    "/projects/{id}/backup",           "Export full project backup"),
        ]),
        ("Buildings / Floors / Rooms", [
            ("GET",    "/projects/{id}/buildings",        "List buildings"),
            ("POST",   "/projects/{id}/buildings",        "Create building"),
            ("PUT",    "/buildings/{id}",                 "Update building"),
            ("DELETE", "/buildings/{id}",                 "Delete building"),
            ("GET",    "/buildings/{id}/total-power",     "Building power calculation"),
            ("POST",   "/buildings/{id}/apply-optimal-phase","Apply optimal phase balance"),
            ("GET",    "/buildings/{id}/floors",          "List floors"),
            ("GET",    "/floors/{id}/rooms",              "List rooms"),
        ]),
        ("Batteries & Solar", [
            ("GET",    "/projects/{id}/batteries",        "List batteries"),
            ("POST",   "/projects/{id}/batteries",        "Add battery bank"),
            ("GET",    "/batteries/{id}",                 "Get battery detail with computed metrics"),
            ("PUT",    "/batteries/{id}",                 "Update battery"),
            ("DELETE", "/batteries/{id}",                 "Remove battery"),
            ("POST",   "/batteries/{id}/reset-soc",       "Reset SOC to specified value"),
            ("POST",   "/batteries/{id}/runtime-at-load", "Calculate runtime at given load"),
            ("GET",    "/battery-chemistry-defaults",     "PUBLIC: Get chemistry presets"),
            ("GET",    "/projects/{id}/solar-systems",    "List solar systems"),
            ("POST",   "/projects/{id}/solar-systems",    "Add solar system"),
            ("PUT",    "/solar-systems/{id}",             "Update solar system"),
            ("DELETE", "/solar-systems/{id}",             "Remove solar system"),
        ]),
        ("Power Sources (Polymorphic)", [
            ("GET",    "/{entity}/{id}/utility-lines",    "List utility lines"),
            ("POST",   "/{entity}/{id}/utility-lines",    "Add utility line"),
            ("GET",    "/{entity}/{id}/generator-lines",  "List generator lines"),
            ("POST",   "/{entity}/{id}/generator-lines",  "Add generator line"),
            ("GET",    "/{entity}/{id}/sockets",          "List socket outlets"),
            ("POST",   "/{entity}/{id}/sockets",          "Add socket outlets"),
        ]),
        ("Admin (Public login)", [
            ("POST",   "/admin/login",                    "PUBLIC: Admin credential login"),
            ("GET",    "/admin/users",                    "List all users (admin)"),
            ("PUT",    "/admin/users/{id}",               "Edit user (admin)"),
            ("DELETE", "/admin/users/{id}",               "Delete user (admin)"),
        ]),
    ]

    for group_name, endpoints in api_groups:
        story.append(h3(group_name))
        ep_data = [["Method", "Endpoint", "Description"]] + endpoints
        story.append(table(ep_data, col_widths=[1.5*cm, 7*cm, 6.7*cm]))
        story.append(sp(4))
    story.append(PageBreak())

    # ══ 16. FRONTEND ARCHITECTURE ═════════════════════════════════════════════
    story.append(section_header("16", "Frontend Architecture & UI Logic"))
    story.append(p(
        "The frontend is a React 18 Single-Page Application (SPA) using React Router v6 "
        "for client-side navigation. State management is kept simple — component-local "
        "useState/useEffect with an AuthContext for global user session. "
        "All engineering results are rendered through Recharts charting components."
    ))

    story.append(h2("16.1  Page Inventory"))
    page_data = [
        ["Page Component", "Route", "Functionality"],
        ["LoginPage",          "/login",                    "Google OAuth 2.0 sign-in"],
        ["AdminLoginPage",     "/admin/login",              "Credential-based admin login"],
        ["DashboardPage",      "/dashboard",                "Projects list and quick stats"],
        ["CreateProjectPage",  "/projects/new",             "New project wizard"],
        ["ProjectPage",        "/projects/:id",             "Project overview, sources, members, batteries"],
        ["BuildingsPage",      "/projects/:id/buildings",   "Building list with power summaries"],
        ["BuildingPage",       "/buildings/:id",            "Floor list, components, reactive power panel"],
        ["FloorPage",          "/floors/:id",               "Room list, floor components"],
        ["RoomDetailPage",     "/rooms/:id",                "Room components and sockets editor"],
        ["LoadSchedulePage",   "/projects/:id/schedule",    "24-h load profile, dispatch charts, SOC trace"],
        ["PhaseBalancePage",   "/projects/:id/phase-balance","3-phase phasor display, optimal assignment"],
        ["AdminDashboardPage", "/admin",                    "User management table"],
    ]
    story.append(table(page_data, col_widths=[4.5*cm, 5*cm, 5.7*cm]))
    story.append(sp())

    story.append(h2("16.2  LoadSchedulePage — Key UI Sections"))
    story.append(p(
        "This is the most complex page (1042 lines). It fetches both the load profile "
        "and the dispatch schedule, then renders four tabbed views:"
    ))
    tabs_data = [
        ["Tab", "Charts / Widgets Shown", "Data Source API"],
        ["Load Profile",      "24-h area chart: load demand vs solar generation",               "/load-profile"],
        ["Sources",           "Stacked bar chart per source (Solar/Battery/Grid/Generator)",   "/schedule"],
        ["Combined Dispatch", "ComposedChart overlaying all sources + demand line",             "/schedule"],
        ["Battery SOC",       "Line chart of SOC trace + charge/discharge events",             "/schedule"],
    ]
    story.append(table(tabs_data, col_widths=[3*cm, 7*cm, 5.2*cm]))
    story.append(sp())

    story.append(h2("16.3  Key UI Components"))
    ui_comp_data = [
        ["Component", "Purpose"],
        ["PowerBanner",          "Displays S (VA), P (kW), Q (kVAR), PF, I (A) for any entity level"],
        ["ReactivePowerPanel",   "Shows kVAR breakdown, PF status, capacitor bank recommendation"],
        ["EntityComponents",     "Reusable form: add/edit/delete components with all fields"],
        ["EntityScheduleModal",  "Configure work hours, day types, season intervals per entity"],
        ["TimeScheduleModal",    "Manage time intervals with add/remove controls"],
        ["PowerSourcesBanner",   "Cards for each active source: utility, generator, solar capacity"],
        ["ProjectSidebar",       "Hierarchical tree: buildings → floors → rooms with links"],
        ["BackupChoiceModal",    "Select backup scope (project/building/floor/room) for export"],
        ["ProjectMembersModal",  "Add users by email, set roles (admin/main/normal)"],
    ]
    story.append(table(ui_comp_data, col_widths=[4.5*cm, 10.7*cm]))
    story.append(sp())

    story.append(h2("16.4  Recharts Configuration"))
    story.append(p(
        "All charts use <b>ResponsiveContainer width='100%'</b> so they adapt to "
        "any screen width. The primary chart type for the load profile is "
        "<b>ComposedChart</b> with <b>Area</b> (filled, for solar) overlaid with "
        "<b>Line</b> (for total load). The dispatch view uses a <b>StackedBarChart</b> "
        "with one bar segment per source type."
    ))
    story.append(PageBreak())

    # ══ 17. FORMULAE QUICK REFERENCE ══════════════════════════════════════════
    story.append(section_header("17", "Key Formulae Quick Reference"))

    fml_data = [
        ["Formula", "Equation", "Units"],
        ["Apparent Power",            "S = √(P² + Q²)",                               "VA"],
        ["Active Power",              "P = S × cos(φ)",                                "W"],
        ["Reactive Power",            "Q = P × tan(arccos(PF))",                       "VAR"],
        ["Power Factor",              "PF = P / S = cos(φ)",                           "—"],
        ["3-phase line current",      "I = S / (√3 × 400)",                            "A"],
        ["1-phase line current",      "I = S / 230",                                   "A"],
        ["Room component DF",         "DF = DF_room × DF_f2b × DF_r2f × 0.70",         "—"],
        ["Socket demand (n≤10)",      "D = n × 200",                                   "VA"],
        ["Socket demand (10<n≤20)",   "D = 2000 + (n-10)×150",                         "VA"],
        ["Socket demand (n>20)",      "D = 3500 + (n-20)×80",                          "VA"],
        ["Q_cap needed",              "Q_c = P×tan(arccos(PF_sys)) − P×tan(arccos(0.95))", "VAR"],
        ["Capacitor bank (rounded)",  "Q_bank = ⌈Q_c/500⌉×500",                        "VAR"],
        ["Delta capacitance",         "C = Q_bank/(3 × 2π × 50 × 400²)",               "F"],
        ["Motor inrush add",          "ΔVA = 0.25 × VA_largest_motor",                 "VA"],
        ["Solar capacity",            "P_cap = Area × 0.17 × 1000 × 0.75",             "W"],
        ["Solar declination",         "δ = 23.45 × sin(360/365×(doy−81))",             "°"],
        ["Hour angle sunrise",        "cos(H) = −tan(φ)×tan(δ)",                       "—"],
        ["Sunrise time",              "t_rise = 12 − arccos(cos(H))/15",               "h"],
        ["Solar output (hourly)",     "P[h] = P_peak × sin(π×(mid−rise)/daylight)",    "W"],
        ["Battery nominal capacity",  "E_nom = V × Ah × n / 1000",                     "kWh"],
        ["Battery age factor",        "f_age = max(0.70, 1−age_yr×degr_rate)",         "—"],
        ["Battery usable capacity",   "E_use = E_nom × DoD × f_age",                   "kWh"],
        ["Max discharge power",       "P_disc = E_nom × C_rate_discharge",              "kW"],
        ["Inverter AC→DC eff",        "η_AC_DC = 0.95",                                "—"],
        ["Phase imbalance %",         "Imb = (I_max−I_min)/I_avg × 100",               "%"],
    ]
    story.append(table(fml_data, col_widths=[5.5*cm, 6.5*cm, 3.2*cm]))
    story.append(PageBreak())

    # ══ 18. CONCLUSION ════════════════════════════════════════════════════════
    story.append(section_header("18", "Summary & Conclusion"))
    story.append(p(
        "Power Profile demonstrates the successful integration of rigorous electrical "
        "engineering standards with modern web technologies to produce a practical, "
        "accurate, and user-friendly power system design tool."
    ))

    story.append(h2("18.1  Achievements"))
    for item in [
        "Implemented IEC 60364-8-1 diversity factors with full building-type and room-type coverage across a 4-level entity hierarchy.",
        "Tiered socket demand model consistent with IEC 60364-5-52 demand factor schedules.",
        "Complete AC power triangle vector model — active (W), reactive (VAR), and apparent (VA) — with proper phasor aggregation.",
        "PENRA-compliant power factor correction with delta capacitor bank sizing to the nearest 0.5 kVAR step.",
        "NEC Article 430 / IEC 60947-4 motor inrush (125%) applied to maximum demand vectors.",
        "Solar PV model using Spencer's declination, hour-angle sunrise/sunset, and sinusoidal bell profile — calibrated by real NASA POWER satellite GHI data.",
        "Multi-chemistry BESS model with age-based degradation, SOC tracking, and C-rate constraints.",
        "Greedy hour-by-hour multi-source dispatch (Solar → BESS → Grid → Generator) with opportunistic generator charging.",
        "3-phase load balancing with greedy First-Fit Decreasing phase assignment algorithm.",
        "RESTful Laravel 13 API (56 endpoints, Sanctum auth, Google OAuth) consumed by React 18 SPA.",
        "Interactive 24-hour charts (Recharts) showing load profile, solar generation, dispatch breakdown, and SOC trace.",
    ]:
        story.append(bullet(item))
    story.append(sp())

    story.append(h2("18.2  Standards Compliance Summary"))
    comp_std_data = [
        ["Standard", "Feature", "Compliance"],
        ["IEC 60364-8-1", "Diversity factors",          "✓ Full table implemented"],
        ["BS 7671",       "Diversity factor source",    "✓ Cross-referenced"],
        ["CIBSE Guide C", "Room coincidence factors",   "✓ All room types covered"],
        ["PENRA",         "PF = 0.95 target",           "✓ Automatic correction sizing"],
        ["NEC Art. 430",  "Motor 125% rule",            "✓ Applied to all motor loads"],
        ["IEC 60947-4",   "Motor inrush",               "✓ Consistent with NEC"],
        ["IEC 60831",     "Capacitor delta config",     "✓ Δ sizing equation implemented"],
        ["NASA POWER v2", "GHI irradiance",             "✓ Real satellite API + caching"],
    ]
    story.append(table(comp_std_data, col_widths=[3.5*cm, 5*cm, 6.7*cm]))
    story.append(sp(12))

    story.append(HR(color=C_ACCENT))
    story.append(p(
        "<b>Power Profile</b> — Graduation Project Report<br/>"
        "Author: Ahmed Zoher &nbsp;|&nbsp; June 2026<br/>"
        "Stack: Laravel 13 / PHP 8.4 + React 18 / Vite + SQLite + NASA POWER<br/>"
        "Standards: IEC 60364-8-1 · BS 7671 · PENRA · NEC · CIBSE",
        BODY_SMALL
    ))

    return story

# ─── PAGE TEMPLATE (header/footer) ────────────────────────────────────────────
def draw_cover(canvas, doc):
    canvas.saveState()
    w, h = A4
    canvas.setFillColor(C_BLUE)
    canvas.rect(0, 0, w, h, fill=1, stroke=0)
    canvas.setFillColor(C_LIGHTBLUE)
    canvas.rect(0, h * 0.38, w, 4, fill=1, stroke=0)
    canvas.rect(0, h * 0.36, w, 1, fill=1, stroke=0)
    canvas.setFillColor(C_ACCENT)
    canvas.rect(0, 0, w, 50, fill=1, stroke=0)
    canvas.setFillColor(C_WHITE)
    canvas.setFont("Helvetica-Bold", 32)
    canvas.drawCentredString(w/2, h * 0.72, "POWER PROFILE SYSTEM")
    canvas.setFont("Helvetica-Bold", 18)
    canvas.setFillColor(C_ACCENT)
    canvas.drawCentredString(w/2, h * 0.65, "Electrical Power System Design & Analysis Tool")
    canvas.setFillColor(C_LIGHTGREY)
    canvas.setFont("Helvetica", 12)
    canvas.drawCentredString(w/2, h * 0.60, "Comprehensive Technical Report")
    canvas.drawCentredString(w/2, h * 0.57, "Equations · Standards · Algorithms · Architecture")
    canvas.setStrokeColor(C_ACCENT)
    canvas.setLineWidth(2)
    canvas.line(w*0.2, h*0.54, w*0.8, h*0.54)
    infos = [
        ("Project Type:", "Graduation Project — Electrical Engineering"),
        ("Tech Stack:",   "Laravel 13 / PHP 8.4  +  React 18 / Vite"),
        ("Standards:",    "IEC 60364-8-1 · BS 7671 · PENRA · NEC · CIBSE"),
        ("Author:",       "Ahmed Zoher"),
        ("Date:",         "June 2026"),
    ]
    ystart = h * 0.50
    for label, val in infos:
        canvas.setFillColor(C_ACCENT)
        canvas.setFont("Helvetica-Bold", 10)
        canvas.drawString(w*0.18, ystart, label)
        canvas.setFillColor(C_WHITE)
        canvas.setFont("Helvetica", 10)
        canvas.drawString(w*0.38, ystart, val)
        ystart -= 18
    canvas.setFillColor(C_WHITE)
    canvas.setFont("Helvetica-Bold", 10)
    canvas.drawCentredString(w/2, 20, "CONFIDENTIAL — GRADUATION PROJECT REPORT")
    canvas.restoreState()

def on_page(canvas, doc):
    canvas.saveState()
    w, h = A4
    if doc.page == 1:
        draw_cover(canvas, doc)
    if doc.page > 1:
        # Header bar
        canvas.setFillColor(C_BLUE)
        canvas.rect(0, h - 28, w, 28, fill=1, stroke=0)
        canvas.setFillColor(C_WHITE)
        canvas.setFont("Helvetica-Bold", 9)
        canvas.drawString(1.5*cm, h - 18, "POWER PROFILE — Technical Report")
        canvas.setFont("Helvetica", 9)
        canvas.drawRightString(w - 1.5*cm, h - 18, "Graduation Project · June 2026")

        # Footer
        canvas.setFillColor(C_LIGHTGREY)
        canvas.rect(0, 0, w, 22, fill=1, stroke=0)
        canvas.setFillColor(C_GREY)
        canvas.setFont("Helvetica", 8)
        canvas.drawString(1.5*cm, 7, "IEC 60364-8-1 · BS 7671 · PENRA · NEC · CIBSE · NASA POWER")
        canvas.drawRightString(w - 1.5*cm, 7, f"Page {doc.page}")

        # Accent left strip
        canvas.setFillColor(C_ACCENT)
        canvas.rect(0, 22, 4, h - 50, fill=1, stroke=0)

    canvas.restoreState()

# ─── BUILD PDF ────────────────────────────────────────────────────────────────
OUTPUT = r"e:\graduation project\power-profile\Power_Profile_Technical_Report.pdf"

doc = SimpleDocTemplate(
    OUTPUT,
    pagesize=A4,
    rightMargin=2*cm,
    leftMargin=2*cm,
    topMargin=2.5*cm,
    bottomMargin=2.2*cm,
    title="Power Profile — Technical Report",
    author="Ahmed Zoher",
    subject="Electrical Power System Design Tool",
)

story = build_story()
doc.build(story, onFirstPage=on_page, onLaterPages=on_page)
print(f"PDF generated: {OUTPUT}")
