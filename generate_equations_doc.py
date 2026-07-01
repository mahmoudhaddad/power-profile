"""
Power Profile — Deep Equations & Logic Word Document Generator
Covers every formula, algorithm, theory, and purpose in the system.
"""
from docx import Document
from docx.shared import Pt, RGBColor, Inches, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

doc = Document()

for section in doc.sections:
    section.top_margin    = Cm(2.5)
    section.bottom_margin = Cm(2.5)
    section.left_margin   = Cm(3.0)
    section.right_margin  = Cm(2.5)

# ── Style helpers ─────────────────────────────────────────────────────────────
def h1(text):
    p = doc.add_heading(text, level=1)
    if p.runs: p.runs[0].font.color.rgb = RGBColor(0x1a,0x56,0xdb)
    return p

def h2(text):
    p = doc.add_heading(text, level=2)
    if p.runs: p.runs[0].font.color.rgb = RGBColor(0x1e,0x40,0xaf)
    return p

def h3(text):
    p = doc.add_heading(text, level=3)
    if p.runs: p.runs[0].font.color.rgb = RGBColor(0x15,0x6d,0x30)
    return p

def body(text):
    p = doc.add_paragraph(text)
    p.paragraph_format.space_after = Pt(6)
    return p

def eq(text):
    p = doc.add_paragraph()
    p.paragraph_format.left_indent = Inches(0.5)
    p.paragraph_format.space_before = Pt(4)
    p.paragraph_format.space_after  = Pt(4)
    r = p.add_run(text)
    r.font.name = 'Courier New'
    r.font.size = Pt(11)
    r.font.bold = True
    r.font.color.rgb = RGBColor(0x0a,0x3d,0x62)
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'),'clear'); shd.set(qn('w:color'),'auto'); shd.set(qn('w:fill'),'EBF5FB')
    p._p.get_or_add_pPr().append(shd)
    return p

def bullet(text, level=0):
    p = doc.add_paragraph(style='List Bullet')
    p.paragraph_format.left_indent = Inches(0.25 + level * 0.25)
    p.add_run(text)
    return p

def note(text):
    p = doc.add_paragraph()
    r = p.add_run("ℹ  " + text)
    r.italic = True; r.font.size = Pt(10)
    r.font.color.rgb = RGBColor(0x6c,0x35,0x79)
    return p

def example_header(text):
    p = doc.add_paragraph()
    r = p.add_run("▶  WORKED EXAMPLE — " + text)
    r.bold = True; r.font.size = Pt(10)
    r.font.color.rgb = RGBColor(0x7d,0x35,0x00)
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'),'clear'); shd.set(qn('w:color'),'auto'); shd.set(qn('w:fill'),'FFF3E0')
    p._p.get_or_add_pPr().append(shd)
    return p

def divider():
    doc.add_paragraph('─' * 90)

def page_break():
    doc.add_page_break()

# ══════════════════════════════════════════════════════════════════════════════
# COVER PAGE
# ══════════════════════════════════════════════════════════════════════════════
cover = doc.add_paragraph()
cover.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = cover.add_run("POWER PROFILE SYSTEM")
r.bold = True; r.font.size = Pt(32); r.font.color.rgb = RGBColor(0x1a,0x56,0xdb)

doc.add_paragraph()
sub = doc.add_paragraph()
sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
r2 = sub.add_run("Complete Equations, Logic, Theory & Purpose Guide")
r2.font.size = Pt(18); r2.font.color.rgb = RGBColor(0x4b,0x5e,0x80)

doc.add_paragraph()
auth = doc.add_paragraph()
auth.alignment = WD_ALIGN_PARAGRAPH.CENTER
r3 = auth.add_run("Ahmed Zoher  |  Graduation Project  |  June 2026")
r3.font.size = Pt(13); r3.italic = True

doc.add_paragraph()
desc = doc.add_paragraph()
desc.alignment = WD_ALIGN_PARAGRAPH.CENTER
r4 = desc.add_run(
    "This document explains — in plain English — every mathematical formula,\n"
    "every algorithm, and every design decision inside the Power Profile platform.\n"
    "Examples are provided for every non-obvious calculation.\n"
    "Standards referenced: IEC 60364-8-1 · PENRA · NEC Article 430 · IEC 60831 · ISO 8528"
)
r4.font.size = Pt(11); r4.font.color.rgb = RGBColor(0x44,0x44,0x44)
page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 1. PROJECT PURPOSE
# ══════════════════════════════════════════════════════════════════════════════
h1("1. What Is Power Profile and Why Does It Exist?")

body(
    "Power Profile is a web-based electrical load analysis platform. An engineer logs in, "
    "creates a project (a building complex, a hospital, a school, etc.), enters all the electrical "
    "loads room by room, and the system automatically computes: how much power the entire facility "
    "needs, what it costs to run, how solar panels and batteries can reduce those costs, "
    "and how to balance the loads fairly across the three phases of the electrical supply."
)
body(
    "Without a tool like this, engineers do these calculations by hand or in spreadsheets. "
    "That is error-prone, slow, and hard to update when equipment changes. Power Profile turns "
    "a project that takes days into one that takes hours, and it applies the correct electrical "
    "standards automatically — so the engineer does not need to remember every diversity factor "
    "table from IEC 60364-8-1 by heart."
)
body(
    "The system has four main computation engines, each building on the previous one:"
)
bullet("Power Engine — calculates total demand (kVA, kW, kVAR) with diversity, motor inrush, and reactive correction.")
bullet("Dispatch Engine — decides, hour by hour, which source (solar, battery, grid, generator) serves the load.")
bullet("Optimization Engine — slides shiftable loads (like water heaters or HVAC pre-cooling) to cheaper hours.")
bullet("Financial Engine — projects 25-year investment return, payback period, and levelized energy cost.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 2. THE POWER TRIANGLE
# ══════════════════════════════════════════════════════════════════════════════
h1("2. The Power Triangle — S, P, Q, and Power Factor")

h2("2.1 Why Three Types of Power?")
body(
    "Electrical power is not one single number. When you have loads that are not pure resistors "
    "(motors, transformers, fluorescent lights, air conditioners), the current and voltage are "
    "no longer in phase with each other. The current wave arrives slightly earlier or later than "
    "the voltage wave. This phase shift creates two distinct components of power:"
)
bullet("Active Power (P) — the real, useful work being done. Measured in Watts (W). This is what turns motors, heats resistors, and does actual work.")
bullet("Reactive Power (Q) — the power that just sloshes back and forth between the supply and the magnetic/electric fields of the load. It does no useful work but it still occupies capacity in cables and transformers. Measured in VAR (Volt-Ampere Reactive).")
bullet("Apparent Power (S) — the vector combination of both. This is what the supply equipment (cables, transformers, generators) actually needs to be rated for. Measured in VA (Volt-Amperes).")

h2("2.2 The Core Equations")
body("The three quantities form a right triangle. P is the horizontal side, Q is the vertical side, and S is the hypotenuse:")
eq("S = sqrt(P² + Q²)    [VA]")
eq("P = S × PF           [W]   (Active / Real Power)")
eq("Q = S × sin(φ)       [VAR] (Reactive Power)")
eq("PF = P / S = cos(φ)  [-]   (Power Factor, between 0.0 and 1.0)")
eq("φ  = arccos(PF)           (Phase angle between current and voltage)")
eq("Q  = P × tan(arccos(PF))  (Reactive from Active and PF)")

body(
    "Power Factor (PF) tells you how efficiently a load uses the apparent power it draws. "
    "A pure resistor (electric heater, incandescent bulb) has PF = 1.0: every VA drawn becomes a Watt of work. "
    "An induction motor at light load might have PF = 0.7: only 70 W of work per 100 VA drawn."
)

example_header("Power Triangle Calculation")
body("A 3-phase air conditioner draws 15 kW (active) at a power factor of 0.85.")
eq("φ  = arccos(0.85) = 31.79°")
eq("S  = P / PF = 15,000 / 0.85 = 17,647 VA  ≈  17.65 kVA")
eq("Q  = P × tan(φ) = 15,000 × tan(31.79°) = 15,000 × 0.6197 = 9,296 VAR  ≈  9.30 kVAR")
body("So the supply cable must be rated for 17.65 kVA, not 15 kW. That is 18% extra capacity wasted because of reactive current.")

h2("2.3 How Power Profile Stores Component Power")
body(
    "There is an important convention in the database: the 'power' column stores APPARENT power in VA, "
    "not watts. This is deliberate. Engineers rate equipment in kVA (transformers, UPS systems, generators "
    "are all kVA-rated). Converting from VA to W is easy with PF, but the reverse requires knowing PF."
)
eq("W  = VA × PF           (how the system derives watts from stored VA)")
eq("Q  = W × tan(arccos(PF))  (how reactive power is derived from watts and PF)")

note(
    "Critical exception: if a component has PF = 1.0 (pure resistor), then VA = W exactly. "
    "Always store the nameplate kVA of the device in the power column."
)

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 3. IEC 60364-8-1 DIVERSITY FACTORS
# ══════════════════════════════════════════════════════════════════════════════
h1("3. IEC 60364-8-1 Diversity Factors — Why Not Everything Runs at Once")

h2("3.1 The Core Idea")
body(
    "If you have 50 rooms each with 2,000 VA of equipment, the naive sum is 100,000 VA = 100 kVA. "
    "But in practice, not every room uses every piece of equipment simultaneously. "
    "The server room might always be at 100%, but a bedroom at 2 AM is likely using 10%."
)
body(
    "Diversity factors (also called demand factors or coincidence factors) are the fractions you "
    "multiply by to arrive at a realistic simultaneous demand. They come from measured data across "
    "many real buildings, standardized in IEC 60364-8-1 and CIBSE Guide C."
)

h2("3.2 The Four-Level Cascade")
body("Power Profile applies diversity at four levels, each multiplied together:")
eq("DF_effective = DF_room × DF_room_to_floor × DF_floor_to_building × DF_project")
body("Working outward from the smallest unit:")
bullet("Room level: each room type has its own factor (bedroom: 0.45, server room: 1.00, office: 0.80)")
bullet("Room→Floor: varies by building type (residential house: 0.60, hospital: 0.90)")
bullet("Floor→Building: varies by building type (residential house: 0.70, hospital: 0.90)")
bullet("Project level: fixed at 0.70 for all projects (the top-level coincidence across multiple buildings)")

h2("3.3 Per-Building-Type Factors")
body("Exact values used (from DiversityFactorService.php, sourced from IEC 60364-8-1 tables):")
eq("Residential house:       room→floor = 0.60,  floor→building = 0.70")
eq("Residential apartment:   room→floor = 0.65,  floor→building = 0.70")
eq("Hotel:                   room→floor = 0.65,  floor→building = 0.70")
eq("Office:                  room→floor = 0.85,  floor→building = 0.80")
eq("Educational (school):    room→floor = 0.80,  floor→building = 0.80")
eq("Educational (university):room→floor = 0.85,  floor→building = 0.80")
eq("Retail:                  room→floor = 0.85,  floor→building = 0.85")
eq("Hospital:                room→floor = 0.90,  floor→building = 0.90")
eq("Industrial:              room→floor = 0.85,  floor→building = 0.85")
eq("Mosque / Worship:        room→floor = 0.80,  floor→building = 0.75")
eq("Sports facility:         room→floor = 0.80,  floor→building = 0.80")
eq("Default (unknown type):  room→floor = 0.90,  floor→building = 0.80")

h2("3.4 Per-Room-Type Coincidence Factors")
eq("Server room:         1.00  (always fully loaded — IT equipment never sleeps)")
eq("Operating theatre:   1.00  (life safety, always design for 100%)")
eq("Laboratory:          0.90")
eq("Classroom:           0.85")
eq("Lecture hall:        0.85")
eq("Workshop:            0.85")
eq("Retail floor:        0.85")
eq("Open-plan office:    0.80")
eq("Gym / Sports hall:   0.80")
eq("Commercial kitchen:  0.75")
eq("Private office:      0.75")
eq("Prayer hall:         0.75")
eq("Reception / Lobby:   0.70")
eq("Meeting room:        0.70")
eq("Corridor:            0.60")
eq("Living room:         0.60")
eq("Residential kitchen: 0.55")
eq("Hotel room:          0.50")
eq("Bedroom:             0.45")
eq("Warehouse / Storage: 0.30")
eq("Bathroom:            0.25")

h2("3.5 Critical Loads Are Always 1.0")
body(
    "Components marked as 'critical' priority (emergency lighting, fire pumps, ICU equipment) "
    "are NEVER derated. Their diversity factor is always 1.0, regardless of which building type "
    "or room type they are in. The code explicitly checks: if priority == 'critical', use DF = 1.0."
)
eq("DF_effective = 1.0  (for critical loads, ignoring all table values)")

h2("3.6 Group-Max Rule for N+1 Redundancy")
body(
    "When multiple components share the same 'group_name' within the same location, they represent "
    "a redundant set — for example, two identical cooling units where only the largest will run at "
    "any one time (the other is standby). The system picks only the maximum VA component from the group."
)
eq("VA_group = max(VA of all components sharing the same group_name)")
body("This correctly models N+1 redundancy: you install 2 pumps but size the panel for 1.")

example_header("Full Diversity Calculation — Office Building")
body("Setup: residential apartment building with 3 bedrooms on 1 floor, each bedroom has a 2,000 VA TV and a 500 VA lamp.")
eq("Raw bedroom total  = (2000 + 500) × 3 = 7,500 VA  (per bedroom)")
eq("Room DF (bedroom)  = 0.45")
eq("Room→Floor DF (residential apartment) = 0.65")
eq("Floor→Building DF (residential apartment) = 0.70")
eq("Project DF         = 0.70")
eq("DF_effective = 0.45 × 0.65 × 0.70 × 0.70 = 0.1433")
eq("Diversified demand = 7,500 × 0.1433 = 1,074 VA  ≈  1.07 kVA")
body("Without diversity you would design for 7.5 kVA — 7× oversized for this group of rooms.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 4. SOCKET / OUTLET DEMAND FACTORS
# ══════════════════════════════════════════════════════════════════════════════
h1("4. Socket and Outlet Demand Factors")

h2("4.1 Why Sockets Are Different")
body(
    "General-purpose socket outlets are treated differently from named electrical equipment. "
    "You do not know in advance what will be plugged in — it could be a phone charger (5 W) or "
    "an electric kettle (2,000 W). So the system uses a standard rated VA per outlet and then "
    "applies demand factors that reflect the statistical likelihood of heavy simultaneous use."
)

h2("4.2 Per-Outlet Rating")
eq("VA_per_outlet = 200 VA  (standard assumed load per outlet)")
body("This is the connected capacity per outlet regardless of what is actually plugged in.")

h2("4.3 Three-Tier Demand Factor Ladder")
body("The demand factor depends on the total number of outlets, using a step function:")
eq("First 10 outlets:   100% demand  →  each contributes 200 × 1.00 = 200 VA")
eq("Next  10 outlets:    75% demand  →  each contributes 200 × 0.75 = 150 VA")
eq("All remaining:       40% demand  →  each contributes 200 × 0.40 =  80 VA")
body("Expressed as a single formula:")
eq("Demand(n) = min(n, 10) × 200 × 1.00")
eq("          + min(max(n-10, 0), 10) × 200 × 0.75")
eq("          + max(n-20, 0) × 200 × 0.40")

example_header("Socket Demand — 35 Outlets on a Floor")
eq("First  10: 10 × 200 × 1.00 = 2,000 VA")
eq("Next   10: 10 × 200 × 0.75 = 1,500 VA")
eq("Last   15: 15 × 200 × 0.40 = 1,200 VA")
eq("Total demand = 2,000 + 1,500 + 1,200 = 4,700 VA")
eq("Connected capacity = 35 × 200 = 7,000 VA")
eq("Effective demand factor = 4,700 / 7,000 = 0.671 (67.1%)")

h2("4.4 Building-Level Coincidence Factor")
body(
    "When multiple floors' socket demands are summed at building level, "
    "a further coincidence factor is applied based on the total building socket demand:"
)
eq("If total demand < 50 kVA:    CF = 1.00  (small building, use full demand)")
eq("If 50 kVA <= demand <= 250 kVA: CF = 0.92")
eq("If demand > 250 kVA:            CF = 0.85")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 5. REACTIVE POWER CORRECTION & CAPACITOR BANK SIZING
# ══════════════════════════════════════════════════════════════════════════════
h1("5. Reactive Power Correction and Capacitor Bank Sizing")

h2("5.1 Why Correct Power Factor?")
body(
    "A low power factor means your cables, transformers, and generators are carrying current "
    "that does no useful work. This wastes money in three ways: you pay for larger cables, "
    "your electricity tariff may include a reactive power penalty, and you need a bigger "
    "generator or transformer. In Jordan (PENRA) and most countries, a PF below 0.85 triggers "
    "financial penalties. The target in this system is PF ≥ 0.95."
)
body("A capacitor bank is the standard fix. Capacitors produce reactive power (leading VAR), "
     "which cancels out the inductive reactive power (lagging VAR) from motors and transformers.")

h2("5.2 Reactive Power to Remove")
body("First, calculate how much reactive power (Q) exists in the system and how much should remain at PF = 0.95:")
eq("Q_existing = P × tan(arccos(PF_current))    [VAR]")
eq("Q_target   = P × tan(arccos(0.95))          [VAR]")
eq("Q_cap      = Q_existing − Q_target          [VAR]  (the VAR the capacitors must supply)")

h2("5.3 Rounding to Standard Bank Steps")
body("Capacitor banks are manufactured in discrete steps of 0.5 kVAR. The bank size is rounded up:")
eq("Bank_kVAR = ceil(Q_cap_kVAR / 0.5) × 0.5    [kVAR]")
body("This ensures we never under-correct. Slightly over-correcting is acceptable (it may make PF slightly leading).")

h2("5.4 Capacitance Calculation — Delta (Δ) Configuration")
body(
    "The system assumes a delta (triangle) connection for the capacitor bank because it is the "
    "standard for 3-phase industrial installations. In delta, each capacitor sees the full "
    "line-to-line voltage (400 V in IEC systems), not the line-to-neutral voltage (230 V). "
    "This means each capacitor is 3× smaller in value compared to star (Y) connection."
)
eq("Q_per_phase = Q_cap_total / 3            [VAR]")
eq("C_phase     = Q_per_phase / (2π × f × V_LL²)  [Farads]")
eq("C_phase_uF  = C_phase × 10⁶             [microfarads]")
body("Where: f = 50 Hz (system frequency), V_LL = 400 V (line-to-line voltage).")

example_header("Capacitor Bank Sizing")
body("A project has P = 100 kW, PF = 0.78 (below the 0.85 threshold).")
eq("Q_existing = 100,000 × tan(arccos(0.78)) = 100,000 × 0.8020 = 80,200 VAR = 80.2 kVAR")
eq("Q_target   = 100,000 × tan(arccos(0.95)) = 100,000 × 0.3287 = 32,870 VAR = 32.87 kVAR")
eq("Q_cap      = 80,200 − 32,870 = 47,330 VAR = 47.33 kVAR")
eq("Bank_kVAR  = ceil(47.33 / 0.5) × 0.5 = ceil(94.66) × 0.5 = 95 × 0.5 = 47.5 kVAR")
eq("Q_per_phase = 47,500 / 3 = 15,833 VAR")
eq("C_phase = 15,833 / (2π × 50 × 400²) = 15,833 / 50,265,482 = 0.000315 F = 315 μF")

h2("5.5 Line Current Reduction")
body("The line current is derived from apparent power and system voltage:")
eq("I_3phase = S / (√3 × V_LL)   [A]  (three-phase system)")
eq("I_1phase = S / V_LN           [A]  (single-phase, V_LN = 230 V)")
body("After installing the capacitor bank:")
eq("S_after  = sqrt(P² + Q_net²)  where Q_net = Q_existing - Q_cap")
eq("I_after  = S_after / (√3 × 400)")
eq("Reduction% = (I_before - I_after) / I_before × 100")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 6. MOTOR INRUSH CURRENT — NEC ARTICLE 430 / IEC 60947-4
# ══════════════════════════════════════════════════════════════════════════════
h1("6. Motor Inrush Current — NEC Article 430 / IEC 60947-4")

h2("6.1 The Problem with Starting Motors")
body(
    "When an induction motor starts from rest, it briefly draws 6–8× its rated current "
    "for the first 1–3 seconds until it reaches operating speed. This is called the "
    "locked-rotor inrush current. If you size your cables, breakers, and transformers "
    "for the steady-state current, a motor start will trip the breaker or cause "
    "a voltage dip that affects all other loads."
)
body(
    "NEC Article 430 and IEC 60947-4 both require the single largest motor to be sized "
    "at 125% of its rated current for this reason. Only the largest motor matters — "
    "not all motors at once, because statistically they never all start at the same moment."
)

h2("6.2 The 125% Rule Applied")
eq("VA_sized = VA_rated × 1.25")
eq("Delta_VA = VA_rated × 0.25   (the extra 25% added on top of the normal sum)")
body("The system applies this only to the maximum load vector (max_va / max_w), not to the "
     "diversity-adjusted optimized load. This correctly captures the worst-case starting transient.")

eq("Delta_W = Delta_VA × PF_motor")
eq("Delta_Q = Delta_VA × sqrt(1 - PF²)")
eq("max_W  += Delta_W")
eq("max_Q  += Delta_Q")
eq("max_VA  = sqrt(max_W² + max_Q²)   (recomputed from updated W and Q)")

example_header("Motor Inrush")
body("Largest motor: 5,000 VA (5 kVA), PF = 0.85, quantity = 2.")
eq("Delta = 5,000 × 2 × 0.25 = 2,500 VA extra sizing")
eq("Delta_W = 2,500 × 0.85 = 2,125 W added to max_W")
eq("Delta_Q = 2,500 × sin(arccos(0.85)) = 2,500 × 0.527 = 1,317 VAR added to max_Q")
body("Without this rule a panel designed for steady-state would trip every time that motor starts.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 7. THREE-PHASE LOAD BALANCING
# ══════════════════════════════════════════════════════════════════════════════
h1("7. Three-Phase Load Balancing — Greedy First-Fit Decreasing")

h2("7.1 Why Balance Matters")
body(
    "A 3-phase supply has three conductors (A, B, C) each 120° apart. "
    "Single-phase loads (lights, computers, small appliances) connect between one phase and neutral. "
    "If all the single-phase loads end up on phase A, that phase carries 3× the current of B and C. "
    "This causes: overheating of the phase A cable, a large neutral current (which should ideally "
    "be zero in a perfectly balanced system), voltage unbalance that damages motors, and wasted "
    "transformer capacity."
)

h2("7.2 Imbalance Percentage Formula")
body("The imbalance is measured as the spread of phase currents relative to their average:")
eq("I_A = VA_A / 230,  I_B = VA_B / 230,  I_C = VA_C / 230  [A]")
eq("I_avg = (I_A + I_B + I_C) / 3")
eq("Imbalance% = (max(I_A,I_B,I_C) - min(I_A,I_B,I_C)) / I_avg × 100")
body("Thresholds:")
eq("< 10% → balanced (green)")
eq("10–20% → warning (amber) — investigate and redistribute")
eq("> 20% → critical (red) — immediate rebalancing required")

h2("7.3 Neutral Current — Phasor Sum Method")
body(
    "The neutral current is NOT simply the arithmetic difference of phase currents. "
    "It is the phasor (vector) sum, because each phase current has both a magnitude and an angle. "
    "For a purely resistive load (PF = 1), current is in phase with voltage. For an inductive "
    "load (PF < 1), current lags voltage by angle φ = arccos(PF)."
)
body("For each phase, the current phasor is:")
eq("θ_voltage_A = 0°,    θ_voltage_B = 120°,   θ_voltage_C = 240°")
eq("For each load on phase X:  |I| = VA / 230,   φ = arccos(PF)")
eq("θ_current = θ_voltage_X − φ   (inductive load: current lags voltage)")
eq("I_real = |I| × cos(θ_current)")
eq("I_imag = |I| × sin(θ_current)")
body("The total phasor for phase X is the sum of all load phasors on that phase:")
eq("I_X_real = Σ(|I_k| × cos(θ_k))")
eq("I_X_imag = Σ(|I_k| × sin(θ_k))")
eq("|I_X| = sqrt(I_X_real² + I_X_imag²)   [A, magnitude of phase X current]")
body("The neutral current is the phasor sum of all three phases:")
eq("I_N_real = I_A_real + I_B_real + I_C_real")
eq("I_N_imag = I_A_imag + I_B_imag + I_C_imag")
eq("|I_N| = sqrt(I_N_real² + I_N_imag²)   [A]")
note("In a perfectly balanced system (equal loads, equal PF), I_N = 0 exactly. "
     "Any imbalance in VA or PF produces a non-zero neutral current.")

h2("7.4 The Greedy First-Fit Decreasing Algorithm")
body(
    "The optimizer assigns each 'block' (a room, a floor panel, a socket circuit) to one "
    "of the three phases. It uses the Greedy First-Fit Decreasing (GFFD) strategy:"
)
bullet("Step 1 — Sort all blocks by VA in descending order (largest first). This gives the algorithm the best chance of a balanced result.")
bullet("Step 2 — For each block, assign it to whichever phase currently has the smallest total VA.")
bullet("Repeat for all blocks.")
body(
    "This is a well-known O(n log n) bin-packing heuristic. It does not always find the "
    "mathematically perfect solution, but for practical building loads (typically fewer than "
    "50 blocks) it consistently produces near-optimal results in milliseconds."
)

example_header("Phase Balancing — 4 Rooms")
body("Rooms sorted by VA: Room A = 8,000 VA, Room B = 6,000 VA, Room C = 5,000 VA, Room D = 4,500 VA")
body("Initial: Phase A=0, Phase B=0, Phase C=0")
eq("Assign Room A (8000): min phase = A → A=8000, B=0, C=0")
eq("Assign Room B (6000): min phase = B → A=8000, B=6000, C=0")
eq("Assign Room C (5000): min phase = C → A=8000, B=6000, C=5000")
eq("Assign Room D (4500): min phase = C → A=8000, B=6000, C=9500")
eq("Final: A=8000, B=6000, C=9500. Average=7833. Imbalance=(9500-6000)/7833×100 = 44.7%")
body("The algorithm would then try redistributing Room D differently. This shows why sorting "
     "largest-first matters: it leaves the smallest block to fill the gap.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 8. SOLAR PV MODELLING
# ══════════════════════════════════════════════════════════════════════════════
h1("8. Solar PV Modelling — STC, PSH, Performance Ratio, NASA POWER")

h2("8.1 Standard Test Conditions (STC)")
body(
    "Every solar panel has a nameplate power rating (e.g. '400 W'). This rating applies under "
    "Standard Test Conditions: irradiance exactly 1,000 W/m² (equivalent to full sun at sea level), "
    "cell temperature exactly 25°C, air mass AM 1.5 spectrum. Real conditions differ, so panels "
    "almost never deliver exactly their nameplate power."
)
eq("STC irradiance = 1,000 W/m²")
eq("At STC: Panel output = Nameplate rating  (e.g. 400 W panel outputs 400 W)")

h2("8.2 Peak Sun Hours (PSH)")
body(
    "PSH is not the number of hours of daylight. It is the equivalent number of hours of full "
    "STC-level irradiance (1,000 W/m²) that would deliver the same total daily energy as the "
    "actual variable solar profile."
)
eq("PSH = Total daily irradiance (kWh/m²/day)")
body("For example, a location might receive 6.5 PSH in June. That means the total irradiance "
     "that day equals 6.5 hours × 1,000 W/m² = 6,500 Wh/m².")
body("The PSH lookup table covers 7 latitude bands (0°, 10°, 20°, 30°, 40°, 50°, 60°) × 12 months. "
     "Values are interpolated linearly between bands. Southern hemisphere months are season-flipped "
     "by 6 months (e.g. July in southern hemisphere behaves like January in northern).")

h2("8.3 Performance Ratio (PR)")
body(
    "Real solar systems do not deliver nameplate × PSH kWh because of several loss mechanisms: "
    "inverter conversion losses (~3–5%), wiring resistance losses (~2%), temperature de-rating "
    "(panels lose ~0.4%/°C above 25°C, easily losing 15–20% in summer), soiling (dust on panels), "
    "and mismatch between panels in a string."
)
eq("PR = Actual energy delivered / (Nameplate kW × PSH)  [typically 0.75–0.85]")
body("Power Profile uses PR = 0.80 (80%) for hourly output calculations — a standard industry value "
     "for modern crystalline silicon panels in moderate climates.")

h2("8.4 Solar Capacity Estimation from Roof Area")
body("When no specific solar system is defined, the system estimates available capacity from building roof area:")
eq("Capacity_W = Area_m² × 0.17 × 1,000 × 0.75")
body("Where: 0.17 = 17% of roof is usable for panels (access paths, HVAC, structure), "
     "1,000 W/m² = STC irradiance, 0.75 = conservative PR for sizing.")

h2("8.5 Sunrise/Sunset Calculation — Spencer's Equation")
body(
    "To generate an hourly solar profile, the system first needs to know when the sun rises "
    "and sets at the project location. This uses classical astronomy (no internet required)."
)
body("Step 1 — Day of year for the 15th of each month:")
eq("DOY = cumulative_days_before_month + 15")
body("Step 2 — Solar declination (angle of sun above/below equator):")
eq("δ = 23.45° × sin(360° / 365 × (DOY − 81))   [degrees]")
body("Step 3 — Hour angle at sunrise/sunset:")
eq("cos(H_A) = -tan(latitude) × tan(δ)")
body("Step 4 — Sunrise and sunset (decimal hours):")
eq("Sunrise = 12.0 − H_A / 15   (solar noon is assumed at 12:00)")
eq("Sunset  = 12.0 + H_A / 15")
note("If cos(H_A) > 1: polar night (no sun at all). If cos(H_A) < -1: midnight sun (sun never sets).")

h2("8.6 Hourly Output Profile — Sinusoidal Bell Curve")
body(
    "The actual irradiance through the day follows an approximately sinusoidal curve, "
    "peaking at solar noon and falling to zero at sunrise/sunset. The system models this as:"
)
eq("t = (midpoint_hour − sunrise) / daylight_hours   [0.0 to 1.0]")
eq("Output(h) = Peak_W × sin(π × t)                 [W]")
body("The peak value is set so that the total energy (area under the curve) equals Capacity × PSH × PR:")
eq("Peak_W = Capacity_W × PSH × PR × π / (2 × daylight_hours)")
note("The factor π/2 comes from the integral of sin(πt) from 0 to 1 being exactly 2/π.")

h2("8.7 NASA POWER Integration (Primary Data Source)")
body(
    "When latitude and longitude are provided, the system calls NASA POWER API to get real "
    "satellite-derived hourly irradiance data (GHI = Global Horizontal Irradiance, in W/m²) "
    "for the 15th day of the requested month. This is far more accurate than the static PSH table "
    "(±3% vs ±10–15%)."
)
body("The conversion from GHI to panel output:")
eq("Output(h) = (GHI(h) / 1,000) × Capacity_W × PR")
body("Where GHI(h) is in W/m² and 1,000 is the STC reference irradiance. "
     "At GHI = 1,000 W/m², output = Capacity × PR exactly (STC conditions). "
     "At GHI = 500 W/m², output = 0.5 × Capacity × PR (linear scaling). "
     "Results are cached for 30 days because historical satellite data never changes.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 9. BATTERY ENERGY STORAGE SYSTEM (BESS)
# ══════════════════════════════════════════════════════════════════════════════
h1("9. Battery Energy Storage System (BESS) — SOC, C-Rate, Efficiency")

h2("9.1 Key Battery Parameters")
body("Every battery in the system is characterized by these parameters:")
bullet("Nominal capacity (kWh) — total energy storage at nameplate conditions.")
bullet("Depth of Discharge (DoD) — maximum fraction you can safely discharge. Over-discharging degrades battery life.")
bullet("Usable capacity (kWh) = Nominal × DoD. This is what you can actually use.")
bullet("Current available energy (kWh) = Usable × Current SOC (State of Charge, 0.0–1.0)")
bullet("Max charge power (kW) — maximum rate at which the battery can accept energy.")
bullet("Max discharge power (kW) — maximum rate at which the battery can deliver energy.")
bullet("Round-trip efficiency (RTE) — fraction of energy put in that comes back out. A 0.95 RTE means charging 100 Wh gives you 95 Wh on discharge.")
bullet("Rated cycle life — number of full charge-discharge cycles before capacity degrades to ~80%.")

h2("9.2 Battery Chemistry Presets")
body("Built-in presets (BatteryChemistryService):")
eq("Lead-Acid Flooded:  DoD=50%, RTE=80%, charge C=0.10, discharge C=0.20, cycles=500,  life=5yr")
eq("Lead-Acid AGM:      DoD=50%, RTE=85%, charge C=0.20, discharge C=0.30, cycles=700,  life=7yr")
eq("Lead-Acid Gel:      DoD=50%, RTE=85%, charge C=0.15, discharge C=0.25, cycles=800,  life=8yr")
eq("Lithium LFP:        DoD=90%, RTE=95%, charge C=0.50, discharge C=1.00, cycles=4000, life=15yr")
eq("Lithium NMC:        DoD=80%, RTE=93%, charge C=0.50, discharge C=1.00, cycles=2500, life=10yr")

h2("9.3 C-Rate — What Does It Mean?")
body(
    "C-rate is the charge or discharge rate relative to the battery capacity. "
    "A C-rate of 1.0 means you charge or discharge the full capacity in 1 hour. "
    "C-rate 0.5 means you take 2 hours. C-rate 2.0 means 30 minutes."
)
eq("Max charge power (kW) = Nominal_kWh × C_rate_charge")
eq("Max discharge power (kW) = Nominal_kWh × C_rate_discharge")
body("Example: 10 kWh LFP battery (C_discharge = 1.0) → max discharge = 10 kW (can fully discharge in 1 hour).")
body("Example: 10 kWh Lead-Acid Flooded (C_discharge = 0.2) → max discharge = 2 kW (needs 5 hours).")

h2("9.4 One-Way Efficiency (charging and discharging)")
body(
    "Round-trip efficiency η_RT = η_charge × η_discharge. The system uses the geometric mean "
    "as the per-direction efficiency:"
)
eq("η_one_way = sqrt(η_RT)")
body("For example, LFP with RTE = 0.95:")
eq("η_one_way = sqrt(0.95) = 0.975   (97.5% efficient in each direction)")
body("When charging, stored energy increases by:")
eq("ΔE_stored = Power_in × η_one_way   [kWh per hour at that power level]")
body("When discharging, load served by battery:")
eq("Power_out (to load) ≤ Power_drawn × η_one_way")
body("This means for every 100 Wh discharged from the battery, only ~97.5 Wh reaches the load (rest is heat).")

h2("9.5 SOC Update Each Hour")
body("At the start of each simulation hour:")
eq("SOC_current = current_energy_kWh / usable_capacity_kWh  [0.0 to 1.0]")
body("After charging δ kWh from solar:")
eq("current_energy_kWh += δ / 1000 × η_one_way   (kW × 1hr × efficiency)")
body("After discharging δ kWh to load:")
eq("current_energy_kWh -= δ / 1000 / η_one_way   (discharging incurs efficiency loss)")
body("Guard: SOC never goes below 0 or above 1.")

h2("9.6 Battery Pairing with Solar Systems")
body(
    "A battery can be 'paired' to a specific named solar system. Paired batteries have exclusive "
    "first claim on that solar system's surplus energy (Step 1 of dispatch). "
    "Unpaired batteries share the remaining solar surplus pooled from all systems (Step 3). "
    "Inverter capacity caps discharge rate: a battery sharing an inverter with a solar system "
    "cannot discharge faster than the inverter's rated output power (kW)."
)

h2("9.7 Backup Runtime Calculation")
body("The system calculates two backup runtimes:")
eq("Backup at critical load = Usable_kWh / Critical_Load_kW   [hours]")
eq("Backup at optimized load = Available_kWh_now / Optimized_Load_kW   [hours]")
body("'Critical load' uses the sum of all components marked 'critical' priority. "
     "'Optimized load' uses the full diversity-adjusted demand and the battery's current charge, "
     "not full capacity — so it reflects the actual state right now.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 10. GENERATOR FUEL COST MODELS
# ══════════════════════════════════════════════════════════════════════════════
h1("10. Generator Fuel Cost — Flat Rate vs Affine ISO 8528 Model")

h2("10.1 The Naive Flat-Rate Model (Why It Is Wrong)")
body(
    "A simple approach: if the generator burns 20 liters per hour at full rated load, "
    "and fuel costs $0.50/liter, then cost = $10/hr. Divide by rated kW (say 50 kW) "
    "to get $0.20/kWh. Apply this rate to every kWh the generator produces."
)
eq("cost_per_kwh_flat = (fuel_cost_per_liter × fuel_consumption_lph) / rated_kW")
body(
    "The problem: this model assumes fuel consumption scales linearly with output power. "
    "In reality, a diesel generator has no-load losses — it burns fuel just to keep the engine "
    "running, even at zero electrical output. At 50% load it does NOT burn 50% of rated-load fuel. "
    "It burns more like 65–70% because the fixed overhead is the same."
)

h2("10.2 The Affine Fuel Model (ISO 8528 / CIBSE)")
body(
    "The correct model (from ISO 8528, the international standard for generating sets, "
    "also used in CIBSE guides) is affine (linear with a non-zero intercept):"
)
eq("F(P) = F₀ + (F_rated − F₀) × P / P_rated   [liters/hour]")
body("Where:")
bullet("F(P) = fuel consumption at actual load P (liters/hour)")
bullet("F₀ = no-load fuel consumption (liters/hour) — the fuel just to keep the engine running")
bullet("F_rated = fuel consumption at 100% rated load (liters/hour)")
bullet("P = actual electrical output (kW)")
bullet("P_rated = rated electrical capacity (kW)")
eq("Default F₀ = 0.30 × F_rated   (30% of rated fuel at no-load — typical for diesel gensets)")
body("If the user specifies no_load_fuel_lph, that value is used directly instead.")

h2("10.3 Cost per kWh at Actual Load")
eq("Cost_per_kWh(P) = fuel_price × F(P) / P")
body("Example: At P = P_rated (100% load), this equals the flat-rate model. "
     "At P = 0.5 × P_rated (50% load), cost is higher per kWh because the fixed F₀ overhead is divided by fewer kWh.")

h2("10.4 Marginal Cost — Used in the Cost Signal")
body(
    "The cost signal asks: 'What is the extra cost of consuming 1 more kW this hour?' "
    "This is the mathematical derivative dC/dP (marginal cost), not the average cost:"
)
eq("Marginal cost = d(fuel_price × F(P)) / dP")
eq("             = fuel_price × (F_rated − F₀) / P_rated   [$/kWh]")
body(
    "This is constant (independent of P) because F(P) is linear in P. "
    "The marginal cost is LOWER than the average cost because the no-load overhead F₀ "
    "is already sunk — the next kW only adds the variable slope, not the fixed intercept."
)

example_header("Generator Fuel Cost Comparison")
body("Generator: 100 kW rated, fuel_consumption_lph = 25 L/hr at 100% load, fuel_cost = $1.00/L, F₀ = 30% × 25 = 7.5 L/hr.")
eq("At 100% load (100 kW):")
eq("  F(100) = 7.5 + (25 - 7.5) × 100/100 = 7.5 + 17.5 = 25.0 L/hr")
eq("  Cost/hr = 25.0 × $1.00 = $25.00/hr")
eq("  Cost/kWh = $25.00 / 100 = $0.250/kWh")
eq("")
eq("At 50% load (50 kW):")
eq("  F(50) = 7.5 + (25 - 7.5) × 50/100 = 7.5 + 8.75 = 16.25 L/hr")
eq("  Cost/hr = 16.25 × $1.00 = $16.25/hr")
eq("  Cost/kWh = $16.25 / 50 = $0.325/kWh  (30% more expensive per kWh!)")
eq("")
eq("Marginal cost = 1.00 × (25 - 7.5) / 100 = $0.175/kWh")
body("The marginal cost ($0.175) is lower than even the 100%-load average cost ($0.250) because "
     "it does not include any fixed overhead allocation.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 11. SOURCE DISPATCH ALGORITHM
# ══════════════════════════════════════════════════════════════════════════════
h1("11. Source Dispatch Algorithm — Priority-Based Hourly Dispatch")

h2("11.1 Purpose")
body(
    "Every hour of the day, the system decides which power sources serve the load. "
    "This is done in strict priority order: cheapest and cleanest sources first. "
    "The algorithm runs for all 24 hours before any financial calculation."
)

h2("11.2 Seven-Step Priority Order (per hour)")
body("For each hour h from 0 to 23, apply these steps in sequence:")
bullet("Step 1 — Paired solar charges paired batteries: each named solar system's proportional output goes to its dedicated battery bank first (up to charge rate limit).")
bullet("Step 2 — Remaining solar covers load directly: solar is the cheapest energy (free, no fuel).")
bullet("Step 3 — Surplus solar charges unpaired batteries: leftover solar after serving load.).")
bullet("Step 4 — Battery banks discharge to cover remaining load (up to discharge rate and inverter limits).")
bullet("Step 5 — Utility grid covers whatever is still unmet (up to utility capacity limit).")
bullet("Step 6 — Generator covers any remaining unmet load (last resort — most expensive).")
bullet("Step 7 — Opportunistic generator charging: IF the generator is already running (Step 6 > 0) AND has spare capacity below 85% loading, charge batteries from the spare capacity.")

h2("11.3 Why Utility Does Not Charge Batteries (Deliberate Design)")
body(
    "The system intentionally does NOT charge batteries from the grid. "
    "Without Time-of-Use (ToU) tariff data, charging from grid costs the full tariff rate, "
    "and then the energy is discharged later to offset... the same grid energy. "
    "Round-trip efficiency (~95% for LFP) means you lose ~5% of the energy. Net result: "
    "charging from grid costs MORE than just using the grid directly. "
    "This will change when ToU tariff features are added."
)

h2("11.4 Opportunistic Generator Charging — The 85% Cap")
body(
    "Diesel generators are most efficient (best fuel consumption per kWh) at 70–85% of rated load. "
    "Below 50% load, specific fuel consumption rises sharply. Above 85%, wear rate increases. "
    "So when the generator is already running (it was started to cover load), we have an opportunity: "
    "if the load is below 85% of rated capacity, we can safely charge batteries with the spare headroom "
    "WITHOUT starting the generator just for charging."
)
eq("Spare_for_charging = genCap × 0.85 − genLoad_this_hour   [W]")
body("The AC watts drawn from the generator for charging go through an inverter/rectifier (AC→DC), "
     "so an additional efficiency loss applies:")
eq("DC power stored in battery = AC watts drawn × INV_EFF × battery_one_way_eff")
eq("INV_EFF = 0.95  (AC→DC rectifier efficiency)")

h2("11.5 Solar System Capacity Ratios")
body(
    "When multiple named solar systems exist, the total solar profile is proportionally split "
    "between systems based on their inverter capacity:"
)
eq("Ratio_sys_i = capacity_kW_i / total_solar_capacity_kW")
eq("Solar output for system i (hour h) = solar_total(h) × Ratio_sys_i")

h2("11.6 Battery State Update")
body("After all 7 steps, each battery's state is updated:")
eq("New SOC = old_energy_kWh ± (charge/discharge kWh) × efficiency_factor")
body("The system tracks: solar_used, battery_charged_solar, battery_charged_gen, battery_discharged, utility_used, generator_used, unmet — all as 24-element arrays.")

h2("11.7 Solar Self-Consumption Metric")
eq("Solar_self_consumption% = (solar_used_kWh + solar_charged_to_battery_kWh) / solar_generated_kWh × 100")
body("This tells you what fraction of your solar generation was actually used on-site vs exported/wasted. "
     "Higher is better. If you have a battery, self-consumption is typically 80–95%.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 12. COST SIGNAL ENGINE
# ══════════════════════════════════════════════════════════════════════════════
h1("12. Cost Signal Engine — Pricing Every Hour of the Day")

h2("12.1 What the Cost Signal Is")
body(
    "The cost signal is a 24-element array (one value per hour) that answers the question: "
    "'If I ran 1 extra kW of load during hour h, what would it cost?' "
    "This is a marginal cost concept — the cost of one additional unit at the margin. "
    "The optimizer uses it to decide when to schedule shiftable loads."
)

h2("12.2 The Cost Ladder (cheapest to most expensive)")
eq("0.000  $/kWh — Solar surplus  (free energy — solar > load, next kW costs nothing)")
eq("0.010  $/kWh — Battery stored (near-zero cost — stored solar, only cell wear)")
eq("tariff $/kWh — Grid base rate  (utility tariff from project settings)")
eq("peak_tariff  — Grid peak rate  (during peak hours if configured)")
eq("marginal_gen — Generator marginal fuel cost (diesel cost × slope / rated_kW)")
eq("999.0  $/kWh — No source / load shedding sentinel")

h2("12.3 Solar Layer — Discount During Sunshine")
body(
    "For the load-scheduling optimizer (ProjectController), a combined cost signal is used "
    "that blends the monetary cost with a solar irradiance layer. The idea: even if there is "
    "no solar surplus at the moment, running loads during daylight hours is still preferable "
    "because solar is partially offsetting the load."
)
eq("solarFrac = solar_W(h) / max(solar_W)   [0.0 to 1.0]")
eq("If monetary cost exists:")
eq("  signal(h) = base_cost × (1.0 − 0.9 × solarFrac)")
eq("If no monetary cost:")
eq("  signal(h) = 1.0 − solarFrac   (pure inverse irradiance)")
body("At peak solar (solarFrac = 1.0): signal = base_cost × 0.1 (10% of base — extremely cheap hour).")
body("At night (solarFrac = 0.0): signal = base_cost × 1.0 (full cost).")
note("The 90% solar discount means loads will strongly prefer solar hours. "
     "This correctly models the benefit of running flexible loads (dishwasher, EV, water heater) midday.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 13. LOAD SCHEDULING OPTIMIZATION
# ══════════════════════════════════════════════════════════════════════════════
h1("13. Load Scheduling Optimization — Finding Cheapest Operating Windows")

h2("13.1 What Makes a Load 'Shiftable'?")
body(
    "Not all loads must run at fixed times. A water heater can heat water between 6 AM and 10 AM "
    "or between 1 PM and 5 PM — the occupant does not care when it heats, only that hot water "
    "is available when needed. These 'shiftable' or 'flexible' loads are candidates for scheduling. "
    "Each shiftable component has:"
)
bullet("required_run_hours — how many hours it must run per day (e.g. 3 hours for a water heater)")
bullet("earliest_start_hour — earliest it can start (e.g. 7 AM)")
bullet("latest_end_hour — latest it must finish by (e.g. 22:00)")
bullet("max_interruptions — whether it can run in multiple separated windows (0 = must run continuously)")

h2("13.2 Constraint Validation")
body("Before scheduling, constraints are checked:")
eq("Window width = latest_end_hour − earliest_start_hour")
eq("Must satisfy: required_run_hours > 0  AND  required_run_hours ≤ window_width")
body("If required_run_hours exceeds the window, the optimizer rejects it with an error message.")

h2("13.3 Continuous (No Split) Scheduling")
body("When max_interruptions = 0, the load must run in one continuous block. "
     "The optimizer exhaustively tests every possible start position within the window:")
eq("For s = earliest_start to (latest_end − required_run_hours):")
eq("    cost = Σ signal(h) for h = s to s + required_run_hours")
eq("    if cost < best_cost: best_cost = cost; best_start = s")
eq("Output: one interval [best_start : best_start + required_run_hours]")
body("Time complexity: O((window_width − run_hours) × run_hours). For a 24-hour window with 3 run hours, "
     "that is 21 × 3 = 63 operations — nearly instant.")

h2("13.4 Split Scheduling (Interruptions Allowed)")
body("When max_interruptions > 0, the load can run in multiple separate windows. "
     "The optimizer uses a greedy cheapest-hours approach:")
eq("1. Collect cost_signal values for hours within the window")
eq("2. Sort hours by cost (ascending — cheapest first)")
eq("3. Pick the cheapest required_run_hours hours")
eq("4. Group consecutive chosen hours into intervals")
body("Example: run_hours = 3, cheapest hours are [2, 3, 14]. Hours 2 and 3 are consecutive → one interval. "
     "Hour 14 is separate → second interval. Output: [02:00–04:00] and [14:00–15:00].")

h2("13.5 The Improvement Guard — Only Save if Actually Better")
body(
    "This was a critical bug that was fixed. The original code always saved the new schedule. "
    "The fixed version compares the new schedule's cost against the BEST window the old schedule "
    "already contained. Only if the new schedule is genuinely cheaper (by at least 0.0001 cost units) "
    "is the database record updated."
)
eq("current_best_cost = min over all run_hours-windows inside current intervals of Σ signal(h)")
eq("new_cost = Σ signal(h) for h in new intervals")
eq("Save only if: new_cost < current_best_cost − 0.0001")
body("Without this guard, the optimizer would overwrite a good manual schedule with a slightly different "
     "but equally-priced automatic one, causing confusing behavior where pressing 'Optimize' "
     "changes the schedule without improving costs.")

h2("13.6 Savings Report")
eq("Savings = current_best_cost − new_cost")
eq("Savings% = Savings / current_best_cost × 100")

example_header("Full Scheduling Example — Washing Machine")
body("Setup: washing machine, run_hours = 2, window = 8:00–20:00 (12 hours), no splits.")
body("Cost signal for hours 8–20 (from tariff + solar):")
eq("h=8:  0.12  h=9:  0.10  h=10: 0.05  h=11: 0.03  h=12: 0.02  h=13: 0.02")
eq("h=14: 0.03  h=15: 0.05  h=16: 0.08  h=17: 0.10  h=18: 0.12  h=19: 0.12")
body("Testing all 2-hour windows:")
eq("h=8–10:  0.12+0.10 = 0.22")
eq("h=10–12: 0.05+0.03 = 0.08")
eq("h=11–13: 0.03+0.02 = 0.05  ← cheapest")
eq("h=12–14: 0.02+0.03 = 0.05  ← tied")
body("Best start = 11:00. Output interval: [11:00–13:00]. The machine runs during peak solar hours.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 14. HOURLY LOAD PROFILE GENERATION
# ══════════════════════════════════════════════════════════════════════════════
h1("14. Hourly Load Profile Generation — Turning Components into a 24-Hour Demand Curve")

h2("14.1 Purpose")
body(
    "The dispatch engine and financial engine both need a 24-hour array of load in Watts. "
    "This is built by projecting each component's time intervals onto the 24-hour axis, "
    "applying diversity factors, and summing everything up."
)

h2("14.2 Seasonal and Day-Type Filtering")
body("Before a component contributes to any hour, it is checked against three filters:")
bullet("Season filter: if usage_season = 'summer', the component only runs in June/July/August. Components set to 'all' are always included.")
bullet("Day-type filter: if usage_day_type = 'weekend', the component only runs on weekend days. Components on 'all' run every day.")
bullet("Working season intervals: custom date ranges (e.g. 'January 15 to March 30') configured at the project/building/room level. Components inherit their parent entity's season if they do not override it.")

h2("14.3 Hour Membership Test")
body("For each hourly slot, the system checks if the component's usage interval covers that hour:")
eq("midpoint = h + 0.5  (evaluate at center of hour, e.g. hour 9 → 9.5)")
eq("Is active if:  start ≤ midpoint < end")
body("For overnight intervals that cross midnight (end < start, e.g. 22:00–06:00):")
eq("Is active if: midpoint ≥ start  OR  midpoint < end  (wraps around)")

h2("14.4 Critical Load Always On")
body("Critical-priority components are always on, 24 hours, regardless of interval settings:")
eq("for h in 0..23: profile[h] += critical_load_W × DF (DF = 1.0 for critical)")

h2("14.5 Diversity Factor Applied to Watts (Not VA)")
body("The diversity factor is applied to the ACTIVE power (W) contribution, not VA:")
eq("contribution_W(h) = VA × PF × DF_effective")
body("This correctly scales the power demand at each level of the hierarchy.")

h2("14.6 Group-Max in Profile Context")
body("The same group-max rule from total power applies: within each entity (project/building/floor/room), "
     "only the component with the highest VA in each group_name contributes to the profile. "
     "Standby units do not add to the profile.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 15. FINANCIAL ANALYSIS
# ══════════════════════════════════════════════════════════════════════════════
h1("15. Financial Analysis — Costs, Savings, Payback, LCOE, 25-Year Projection")

h2("15.1 Annual Energy Calculation")
body("The financial engine runs the dispatch simulation for a representative day (a Monday in the specified month) and scales to annual:")
eq("Annual_kWh = daily_kWh × 365")
body("This is an approximation. A more precise model would simulate all 12 months; the current approach "
     "is industry-standard for quick feasibility studies and is accurate to within ~10% for typical locations.")

h2("15.2 Annual Operating Costs — With Solar System")
body("Three cost components:")
eq("Grid cost     = grid_kWh_annual × weighted_tariff")
eq("Generator cost = Σ_hours(F(P_h) × fuel_price) × 365   (affine model, not flat rate)")
eq("Maintenance    = Σ solar_system.annual_maintenance_cost")
eq("Total WITH solar = grid_cost + generator_cost + maintenance")

h2("15.3 Weighted Average Tariff")
body("If the utility has both peak and off-peak rates:")
eq("peak_hours = peak_end − peak_start")
eq("off_peak_hours = 24 − peak_hours")
eq("weighted_tariff = (off_peak_hours × base_rate + peak_hours × peak_rate) / 24")

h2("15.4 Baseline (Without Solar)")
body("A second dispatch simulation is run with solar = 0 and batteries = 0 to establish what the project would cost without renewable investment:")
eq("Total WITHOUT solar = baseline_grid_cost + baseline_generator_cost")
body("This is the counterfactual: what are you paying now?")

h2("15.5 Annual Savings")
eq("Annual savings = Total_WITHOUT − (Grid_WITH + Generator_WITH + Maintenance)")
eq("Savings% = Annual_savings / Total_WITHOUT × 100")
body("Maintenance cost is included in 'with solar' because you would not pay it without the solar installation.")

h2("15.6 Total Investment")
eq("Total investment = Σ solar_system.installation_cost + Σ battery.purchase_cost")

h2("15.7 Simple Payback Period")
eq("Simple_payback_years = Total_investment / Annual_savings")
body("This is the number of years until savings fully recover the investment, ignoring time value of money. "
     "A payback of 5–8 years is considered good for solar in the Middle East/North Africa region.")

h2("15.8 LCOE — Levelized Cost of Energy")
body(
    "LCOE (Levelized Cost of Energy) is the average cost per kWh delivered by the solar system "
    "over its full 25-year lifetime, including installation and maintenance costs. "
    "It answers: 'Is solar cheaper than buying from the grid over the long run?'"
)
eq("LCOE = (Installation_cost + Maintenance × 25) / (Solar_kWh_year_1 × 25)")
body("If LCOE < grid tariff, solar is cost-effective over 25 years.")
body("Note: this formula ignores panel degradation and discount rate. A more rigorous NPV-based LCOE "
     "would use discounted cash flows, but this simplified form is standard for engineering-level analysis.")

h2("15.9 Panel Degradation Over 25 Years")
body("Crystalline silicon panels degrade at approximately 0.5% per year:")
eq("Degradation factor at year y = (1 − 0.005)^y")
eq("Solar_kWh at year y = Solar_kWh_year_1 × (0.995)^y")

h2("15.10 Battery Replacement Schedule")
body("Each battery has a rated_cycle_life (number of full cycles). Assuming 1 cycle per day:")
eq("Years_to_end_of_life = max(0, rated_cycle_life / 365 − age_years_already)")
eq("Replacement year = ceil(years_to_end_of_life)")
body("Battery replacement cost is added as a negative cash flow in that year of the projection.")

h2("15.11 25-Year Cumulative Cash Flow")
body("Starting from −Total_investment (upfront cost) in year 0:")
eq("Net at year y = Annual_savings × degradation_factor_y − battery_replacement_cost_y")
eq("Cumulative at year y = Cumulative(y-1) + Net(y)")
body("The year when Cumulative first crosses zero is the payback year. "
     "The final value at year 25 is the total 25-year net benefit of the investment.")

example_header("25-Year Projection Snapshot")
body("Investment: $50,000 (solar) + $15,000 (battery) = $65,000 total. Annual savings = $12,000/year.")
eq("Year 0:  Cumulative = −$65,000")
eq("Year 1:  Net = 12,000 × 0.995¹ − 0 = $11,940.   Cumulative = −$53,060")
eq("Year 5:  Net = 12,000 × 0.995⁵ ≈ $11,706.       Cumulative ≈ −$6,500")
eq("Year 6:  Net ≈ $11,648.                           Cumulative ≈ +$5,148  ← payback year = 6!")
eq("Year 10: LFP battery replaced. Cost = $15,000.")
eq("Year 10: Net = $11,416 − $15,000 = −$3,584.     Cumulative drops sharply that year.")
eq("Year 25: Total 25-yr benefit ≈ $185,000 − $80,000 costs ≈ +$105,000 net")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 16. COMPLETE WORKED EXAMPLE — FULL PROJECT CALCULATION
# ══════════════════════════════════════════════════════════════════════════════
h1("16. End-to-End Worked Example — Small Office Building")

h2("Setup")
body("A single-story office building with one floor, two rooms:")
bullet("Room 1 (Open-plan office): 10 computers (500 VA each, PF=0.90), 5 LED fixtures (200 VA each, PF=1.0), 1 AC unit (3,000 VA, PF=0.85, group='AC1', is_motor=true)")
bullet("Room 2 (Server room): 2 servers (2,000 VA each, PF=0.90)")
bullet("Floor level: 1 elevator (5,000 VA, PF=0.80, 3-phase, is_motor=true)")
bullet("Building type: office")
bullet("20 socket outlets on the floor")

h2("Step 1 — Raw Component Sum (no diversity)")
eq("Room 1: 10×500 + 5×200 + 1×3000 = 5000+1000+3000 = 9,000 VA")
eq("        W = 5000×0.90 + 1000×1.0 + 3000×0.85 = 4500+1000+2550 = 8,050 W")
eq("Room 2: 2×2000 = 4,000 VA,   W = 4000×0.90 = 3,600 W")
eq("Floor:  elevator = 5,000 VA (3-phase, skip from 1-phase sum)")
eq("Total raw = 9000+4000+5000 = 18,000 VA")

h2("Step 2 — Diversity Factors (office building)")
eq("DF: room_to_floor = 0.85,  floor_to_building = 0.80,  project = 0.70")
eq("Open-plan office room DF = 0.80 (room coincidence)")
eq("Server room DF = 1.00 (always 100%)")
eq("")
eq("Room 1 effective DF = 0.80 × 0.85 × 0.80 × 0.70 = 0.3808")
eq("Room 2 effective DF = 1.00 × 0.85 × 0.80 × 0.70 = 0.4760")
eq("Floor elevator DF = floor_to_building × project = 0.80 × 0.70 = 0.56")
eq("")
eq("Room 1 diversified W = 8,050 × 0.3808 = 3,065 W")
eq("Room 2 diversified W = 3,600 × 0.4760 = 1,714 W")
eq("Floor elevator diversified W = 4,000 × 0.56 = 2,240 W  (5000×0.80 PF = 4000 W)")
eq("Total diversified W = 3,065 + 1,714 + 2,240 = 7,019 W")

h2("Step 3 — Socket Demand")
eq("20 outlets: demand = 10×200×1.00 + 10×200×0.75 = 2,000+1,500 = 3,500 VA")
eq("Assumed PF = 0.95 → W contribution = 3,500 × 0.95 = 3,325 W")
eq("Q contribution = 3,500 × sin(arccos(0.95)) = 3,500 × 0.3122 = 1,093 VAR")

h2("Step 4 — Reactive Power and Total VA")
eq("Total W = 7,019 + 3,325 = 10,344 W")
eq("Total Q from components: (8,050×0.38 - 7,019 → compute Q properly...)")
body("(In actual code, Q is tracked alongside W through sumPowerWithGroups; simplified here for illustration)")
eq("Assume total Q ≈ 5,200 VAR")
eq("Total VA = sqrt(10,344² + 5,200²) = sqrt(106,998,336 + 27,040,000) = sqrt(134,038,336) = 11,578 VA ≈ 11.6 kVA")
eq("System PF = 10,344 / 11,578 = 0.893")

h2("Step 5 — Motor Inrush (125% Rule)")
eq("Largest motor: elevator 5,000 VA (PF=0.80).  Single unit → base_VA = 5,000")
eq("Delta = 5,000 × 0.25 = 1,250 VA")
eq("Delta_W = 1,250 × 0.80 = 1,000 W")
eq("Delta_Q = 1,250 × sqrt(1−0.64) = 1,250 × 0.60 = 750 VAR")
eq("max_W (unoptimized) = 18,000×PF_avg + 1,000 inrush ≈ higher than optimized")
body("The max_VA (worst case) is used for cable sizing; the diversified VA is used for transformer sizing.")

h2("Step 6 — PF Correction")
eq("System PF = 0.893 — above 0.85 threshold, so PF correction is NOT recommended.")
body("If PF were below 0.85, capacitor bank would be calculated as shown in Section 5.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 17. SINGLE-LINE DIAGRAM LOGIC
# ══════════════════════════════════════════════════════════════════════════════
h1("17. Single-Line Diagram — Auto-Generated SVG")

h2("17.1 Purpose")
body(
    "A single-line diagram (SLD) is the standard engineering drawing that shows how all power "
    "sources connect to loads through breakers and busbars. It uses simplified symbols instead "
    "of drawing every wire. Every electrical engineer uses SLDs for system documentation, "
    "fault analysis, and inspection by authorities."
)

h2("17.2 What Gets Auto-Generated")
body("The system generates an SVG (Scalable Vector Graphics) diagram that shows:")
bullet("Source nodes at the top: Solar PV panels, BESS (battery), Utility Grid meter, Generator")
bullet("Each source connects through a circuit breaker symbol to a main busbar")
bullet("The busbar distributes to each building through another breaker")
bullet("Building panels are shown at the bottom with their calculated load (kVA and kW)")

h2("17.3 Breaker Symbols")
body(
    "Circuit breakers are drawn as a small rectangle with diagonal lines (IEC symbol standard). "
    "They are placed between every source and the main bus, and between the main bus and each load. "
    "This is the correct topology for an LV (low voltage, <1 kV) distribution system."
)

h2("17.4 Source Rating Display")
body("Each source node displays:")
bullet("Solar: 'X.X kWp' — kilowatt-peak capacity (nameplate rating)")
bullet("Battery: 'X.X kWh' — total usable storage capacity")
bullet("Grid: 'X.X kVA' — utility connection capacity")
bullet("Generator: 'X.X kW' — rated electrical output")

h2("17.5 Disclaimer")
body(
    "The auto-generated SLD is a schematic guide only. It is not a certified engineering drawing. "
    "For actual installation, a licensed electrical engineer must produce stamped, site-specific drawings "
    "per local wiring regulations (IEC 60364, NEC, or local authority equivalent)."
)

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 18. STANDARDS REFERENCE SUMMARY
# ══════════════════════════════════════════════════════════════════════════════
h1("18. Electrical Standards Reference")

h2("IEC 60364-8-1")
body("IEC 60364 is the international standard for low-voltage electrical installations. "
     "Part 8-1 specifically addresses energy efficiency. It provides the diversity factor "
     "tables (demand factors) used for sizing conductors and protective devices in buildings.")

h2("PENRA (Palestine Energy and Natural Resources Authority)")
body("The regulatory body that governs electrical installations in the Palestinian territories. "
     "PENRA aligns with IEC standards and sets the minimum power factor requirement at 0.85 "
     "(below which financial penalties apply). The target PF in Power Profile is 0.95.")

h2("NEC Article 430 — Motors, Motor Circuits, and Controllers")
body("The National Electrical Code (US), Article 430, specifies that the single largest "
     "motor in a feeder must be sized at 125% of its full-load current rating to account "
     "for starting (locked-rotor) inrush. Power Profile implements this as the 125% inrush "
     "rule on VA/W vectors.")

h2("IEC 60831 — Capacitors for Power Factor Correction")
body("Defines testing and performance requirements for shunt capacitor banks used in power "
     "factor correction. Power Profile follows IEC 60831 recommendations for delta (Δ) "
     "connection configuration and step sizes.")

h2("ISO 8528 — Reciprocating Internal Combustion Engine Driven Alternating Current Generating Sets")
body("ISO 8528 standardizes the performance, testing, and rating of diesel generators. "
     "The affine fuel consumption model F(P) = F₀ + (F_rated − F₀) × P/P_rated "
     "is derived from ISO 8528 Part 1 test data for rated continuous power operation.")

h2("CIBSE Guide C — Reference Data (Chartered Institution of Building Services Engineers)")
body("CIBSE Guide C provides UK/international building services reference data, including "
     "the per-room and per-building-type coincidence factors (demand factors) used in "
     "DiversityFactorService. The values are consistent with IEC 60364-8-1 but provide "
     "more granular room-type breakdown.")

h2("NASA POWER — Prediction of Worldwide Energy Resources")
body("A NASA satellite program providing historical solar irradiance data globally. "
     "The ALLSKY_SFC_SW_DWN parameter (All-Sky Surface Solar Radiation) gives hourly "
     "Global Horizontal Irradiance (W/m²) with ±3% accuracy. The dataset covers 1981–present "
     "and is freely accessible without API keys.")

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 19. QUICK-REFERENCE FORMULA TABLE
# ══════════════════════════════════════════════════════════════════════════════
h1("19. Quick-Reference Formula Table")

formulas = [
    ("Apparent power",      "S = sqrt(P² + Q²)",                      "VA"),
    ("Active power",        "P = S × PF",                              "W"),
    ("Reactive power",      "Q = P × tan(arccos(PF))",                 "VAR"),
    ("Power factor",        "PF = P / S",                              "unitless"),
    ("Effective DF",        "DF = DF_room × DF_r2f × DF_f2b × 0.70",  "—"),
    ("Socket demand",       "D(n)=min(n,10)×200 + min(max(n-10,0),10)×150 + max(n-20,0)×80", "VA"),
    ("PF correction Q",     "Q_cap = Q_existing − P×tan(arccos(0.95))","VAR"),
    ("Capacitor value",     "C = (Q_cap/3) / (2π×50×400²)  ×10⁶",    "μF per phase (delta)"),
    ("Line current 3ph",    "I = S / (√3 × 400)",                     "A"),
    ("Motor inrush",        "ΔVA = VA_largest_motor × 0.25",           "VA extra"),
    ("Imbalance %",         "Imb = (Imax−Imin)/Iavg × 100",           "%"),
    ("Neutral current",     "|I_N| = |I_A + I_B + I_C| (phasors)",    "A"),
    ("Solar output",        "P(h) = GHI(h)/1000 × cap_kW × 0.80",    "W"),
    ("Solar output (static)","P(h) = Peak_W × sin(π × t)",             "W"),
    ("PSH-based peak",      "Peak_W = cap × PSH × PR × π / (2×D)",    "W"),
    ("Declination",         "δ = 23.45° × sin(360/365 × (DOY−81))",   "degrees"),
    ("Sunrise",             "Sunrise = 12 − arccos(-tan(lat)×tan(δ))/15", "decimal hour"),
    ("Capacity estimate",   "cap_W = area × 0.17 × 1000 × 0.75",       "W"),
    ("Generator F(P)",      "F(P) = F₀ + (F_rated−F₀) × P/P_rated",  "L/hr"),
    ("Gen cost at P",       "Cost(P) = fuel_price × F(P) / P",         "$/kWh"),
    ("Gen marginal cost",   "MC = fuel_price × (F_rated−F₀) / P_rated","$/kWh"),
    ("Annual savings",      "S_yr = (Cost_no_solar − Cost_with_solar) × 365", "$"),
    ("Simple payback",      "PBY = Investment / Annual_savings",        "years"),
    ("LCOE",                "LCOE = (Install + Maint×25) / (Solar_yr×25)", "$/kWh"),
    ("Degradation",         "factor_y = (1 − 0.005)^y",               "unitless"),
    ("Battery usable",      "kWh_usable = nominal_kWh × DoD",          "kWh"),
    ("Battery 1-way eff",   "η = sqrt(RTE)",                           "unitless"),
    ("SOC update charge",   "E += P_charge × η",                       "kWh"),
    ("SOC update discharge","E −= P_discharge / η",                    "kWh"),
    ("Solar self-consump",  "SSC = (solar_used + solar_charged) / solar_generated × 100", "%"),
]

table = doc.add_table(rows=1, cols=3)
table.style = 'Table Grid'
hdr = table.rows[0].cells
hdr[0].text = "Quantity"
hdr[1].text = "Formula"
hdr[2].text = "Unit"
for cell in hdr:
    for run in cell.paragraphs[0].runs:
        run.bold = True

for name, formula, unit in formulas:
    row = table.add_row().cells
    row[0].text = name
    row[1].text = formula
    row[2].text = unit

page_break()

# ══════════════════════════════════════════════════════════════════════════════
# 20. GLOSSARY
# ══════════════════════════════════════════════════════════════════════════════
h1("20. Glossary of Terms and Symbols")

glossary = [
    ("S", "Apparent Power (VA or kVA) — total power the supply must provide"),
    ("P", "Active / Real Power (W or kW) — power doing actual work"),
    ("Q", "Reactive Power (VAR or kVAR) — power oscillating in magnetic/electric fields"),
    ("PF", "Power Factor — ratio of active to apparent power (0.0–1.0)"),
    ("φ", "Phase angle between current and voltage (degrees or radians)"),
    ("DF", "Diversity Factor — fraction of installed capacity expected to run simultaneously"),
    ("PSH", "Peak Sun Hours — equivalent full-sun hours per day (kWh/m²/day)"),
    ("PR", "Performance Ratio — actual solar output / theoretical maximum"),
    ("GHI", "Global Horizontal Irradiance — solar power per unit area (W/m²)"),
    ("STC", "Standard Test Conditions — 1,000 W/m², 25°C, AM 1.5 spectrum"),
    ("SLD", "Single-Line Diagram — engineering schematic of power distribution"),
    ("BESS", "Battery Energy Storage System"),
    ("SOC", "State of Charge — current energy as fraction of usable capacity (0–1)"),
    ("DoD", "Depth of Discharge — maximum fraction of nominal capacity that can be discharged"),
    ("RTE", "Round-Trip Efficiency — energy out / energy in for one charge-discharge cycle"),
    ("C-rate", "Charge/Discharge rate as multiple of capacity (1C = full in/out in 1 hour)"),
    ("LCOE", "Levelized Cost of Energy ($/kWh) — lifetime cost of a generation source per kWh"),
    ("NPV", "Net Present Value — future cash flows discounted to today's value"),
    ("LFP", "Lithium Iron Phosphate — safest, longest-life lithium battery chemistry"),
    ("NMC", "Nickel Manganese Cobalt — high energy density lithium chemistry"),
    ("kVA", "kilovolt-ampere (1,000 VA) — apparent power unit"),
    ("kW", "kilowatt (1,000 W) — active power unit"),
    ("kVAR", "kilovolt-ampere reactive (1,000 VAR) — reactive power unit"),
    ("kWh", "kilowatt-hour — energy unit (1 kW running for 1 hour)"),
    ("V_LL", "Line-to-Line Voltage (400 V in IEC 3-phase systems)"),
    ("V_LN", "Line-to-Neutral Voltage (230 V in IEC systems)"),
    ("IEC", "International Electrotechnical Commission — standards body"),
    ("NEC", "National Electrical Code — US electrical installation standard"),
    ("CIBSE", "Chartered Institution of Building Services Engineers — UK standards body"),
]

for term, definition in glossary:
    p = doc.add_paragraph()
    run_term = p.add_run(f"{term}: ")
    run_term.bold = True
    run_term.font.color.rgb = RGBColor(0x1a,0x56,0xdb)
    p.add_run(definition)

doc.add_paragraph()
divider()
close_p = doc.add_paragraph()
close_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = close_p.add_run("Power Profile — Equations & Logic Guide  |  Ahmed Zoher  |  June 2026")
r.font.size = Pt(10); r.italic = True; r.font.color.rgb = RGBColor(0x88,0x88,0x88)

# ══════════════════════════════════════════════════════════════════════════════
# SAVE
# ══════════════════════════════════════════════════════════════════════════════
output_path = r"e:\graduation project\power-profile\Power_Profile_Equations_Logic_Guide.docx"
doc.save(output_path)
print(f"Saved: {output_path}")
