#!/usr/bin/env python3
"""Genera lo schema prospettico dello Stadio Olimpico per la pagina Biglietteria.

Il risultato va incollato dentro rcm_big_schema() in
roles/wordpress/files/rcm-biglietti.php, al posto dell'SVG che c'e'.

Perche' uno script e non l'SVG scritto a mano: i settori sono spicchi di un
anello ellittico, e ogni confine e' un punto su due ellissi diverse. Calcolati
a occhio non combaciano, e le etichette non seguono la curva.

    python3 scripts/olimpico-svg.py    # scrive olimpico.svg nella cartella corrente
"""
import math

CX, CY = 250.0, 200.0
A_OUT, B_OUT = 226.0, 128.0     # bordo esterno dell'anello
A_IN,  B_IN  = 134.0, 74.0      # bordo interno (affaccio sul campo)
MURO = 30.0                      # altezza della facciata, da' la profondita'

def p(a, b, t, dy=0.0):
    r = math.radians(t)
    return (CX + a * math.cos(r), CY - b * math.sin(r) + dy)

def f(pt):
    return f"{pt[0]:.1f} {pt[1]:.1f}"

def spicchio(t1, t2, colore):
    """Settore fra due angoli, in senso antiorario."""
    grande = 1 if (t2 - t1) % 360 > 180 else 0
    o1, o2 = p(A_OUT, B_OUT, t1), p(A_OUT, B_OUT, t2)
    i1, i2 = p(A_IN, B_IN, t1), p(A_IN, B_IN, t2)
    return (f'<path d="M{f(o1)} A{A_OUT} {B_OUT} 0 {grande} 0 {f(o2)} '
            f'L{f(i2)} A{A_IN} {B_IN} 0 {grande} 1 {f(i1)} Z" fill="{colore}"/>')

# Settori: (da, a, colore, etichetta, angolo dell'etichetta, colore testo)
ROSSO, GIALLO, ORO, GRIGIO = '#8e1f2f', '#e6af14', '#c9a227', '#4a4a4a'
SETTORI = [
    (-30,  30,  GRIGIO, 'CURVA SUD',           0,   '#d0d0d0'),
    ( 30,  64,  ORO,    'DISTINTI SUD',        47,  '#3a2c05'),
    ( 64, 116,  GIALLO, 'TRIBUNA TEVERE',      90,  '#3a2c05'),
    (116, 150,  ORO,    'DISTINTI NORD EST',   133, '#3a2c05'),
    (150, 210,  ROSSO,  'CURVA NORD',          180, '#f7ecd5'),
    (210, 244,  ORO,    'DISTINTI NORD OVEST', 227, '#3a2c05'),
    (244, 330,  GIALLO, 'TRIBUNA MONTE MARIO', 287, '#3a2c05'),
]

out = []
out.append('<svg class="rcm-big-svg" xmlns="http://www.w3.org/2000/svg" viewBox="14 58 472 314" role="img" aria-labelledby="rcm-olimpico-t rcm-olimpico-d">')
out.append('  <title id="rcm-olimpico-t">Stadio Olimpico: i settori</title>')
out.append('  <desc id="rcm-olimpico-d">Lo Stadio Olimpico di Roma visto dall&#8217;alto in prospettiva, con l&#8217;anello delle tribune diviso nei suoi settori: Curva Nord e Curva Sud dietro le porte, Tribuna Monte Mario e Tribuna Tevere sui lati lunghi, e ai quattro angoli i Distinti. La Curva Sud &#232; riservata agli abbonati.</desc>')

# La facciata esterna: la meta' bassa dell'ellisse, abbassata, da' lo spessore
o_dx = p(A_OUT, B_OUT, 0)
o_sx = p(A_OUT, B_OUT, 180)
out.append('  <!-- La facciata esterna: e\' quella che fa sembrare lo stadio visto -->')
out.append('  <!-- da un angolo invece che schiacciato sulla carta. -->')
out.append(f'  <path d="M{f(o_sx)} A{A_OUT} {B_OUT} 0 0 0 {f(o_dx)} '
           f'L{f((o_dx[0], o_dx[1] + MURO))} A{A_OUT} {B_OUT} 0 0 1 {f((o_sx[0], o_sx[1] + MURO))} Z" fill="#2a2a2a"/>')

out.append('  <ellipse cx="%.0f" cy="%.0f" rx="%.0f" ry="%.0f" fill="#1c1c1c"/>' % (CX, CY, A_OUT, B_OUT))
out.append('  <!-- I settori dell\'anello -->')
for t1, t2, colore, _, _, _ in SETTORI:
    out.append('  ' + spicchio(t1, t2, colore))
out.append('  <g fill="none" stroke="#141414" stroke-width="1.5">')
for t1, _, _, _, _, _ in SETTORI:
    a1, a2 = p(A_OUT, B_OUT, t1), p(A_IN, B_IN, t1)
    out.append(f'    <line x1="{a1[0]:.1f}" y1="{a1[1]:.1f}" x2="{a2[0]:.1f}" y2="{a2[1]:.1f}"/>')
out.append('  </g>')

# L'invaso: pista e campo, schiacciati con la stessa prospettiva
out.append('  <ellipse cx="%.0f" cy="%.0f" rx="%.0f" ry="%.0f" fill="#151515"/>' % (CX, CY, A_IN, B_IN))
out.append('  <!-- La pista d\'atletica: e\' lei che tiene le tribune lontane dal campo -->')
out.append(f'  <rect x="{CX-118:.0f}" y="{CY-62:.0f}" width="236" height="124" rx="62" fill="#8a4a34"/>')
out.append(f'  <rect x="{CX-108:.0f}" y="{CY-53:.0f}" width="216" height="106" rx="53" fill="none" stroke="#a35c42" stroke-width="1"/>')
out.append(f'  <rect x="{CX-98:.0f}" y="{CY-44:.0f}" width="196" height="88" rx="44" fill="#17482a" stroke="#a35c42" stroke-width="1"/>')
out.append('  <!-- Il campo, con le porte dietro le due curve -->')
out.append(f'  <rect x="{CX-92:.0f}" y="{CY-38:.0f}" width="184" height="76" rx="1" fill="#1f5c34" stroke="#4a9c63" stroke-width="1.2"/>')
out.append(f'  <line x1="{CX:.0f}" y1="{CY-38:.0f}" x2="{CX:.0f}" y2="{CY+38:.0f}" stroke="#4a9c63" stroke-width="1.2"/>')
out.append(f'  <ellipse cx="{CX:.0f}" cy="{CY:.0f}" rx="17" ry="11" fill="none" stroke="#4a9c63" stroke-width="1.2"/>')
out.append(f'  <rect x="{CX-92:.0f}" y="{CY-22:.0f}" width="22" height="44" fill="none" stroke="#4a9c63" stroke-width="1.2"/>')
out.append(f'  <rect x="{CX+70:.0f}" y="{CY-22:.0f}" width="22" height="44" fill="none" stroke="#4a9c63" stroke-width="1.2"/>')

# Etichette fuori dall'anello, inclinate come la curva su cui poggiano
out.append('  <g font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">')
for _, _, _, testo, ang, colore_testo in SETTORI:
    px, py = p((A_OUT + A_IN) / 2, (B_OUT + B_IN) / 2, ang)
    # inclinazione: la tangente all'ellisse in quel punto
    r = math.radians(ang)
    dx, dy = -((A_OUT + A_IN) / 2) * math.sin(r), -((B_OUT + B_IN) / 2) * math.cos(r)
    rot = math.degrees(math.atan2(dy, dx))
    if rot > 90:
        rot -= 180
    elif rot < -90:
        rot += 180
    # I Distinti stanno in spicchi stretti: testo piccolo o sborda nel vicino.
    dim = 9 if testo.startswith('DISTINTI') else 12
    out.append(f'    <text x="{px:.1f}" y="{py:.1f}" font-size="{dim}" fill="{colore_testo}" '
               f'dominant-baseline="middle" transform="rotate({rot:.1f} {px:.1f} {py:.1f})">{testo}</text>')
# La nota della Curva Sud su un raggio piu' interno: parallela al nome, non sopra.
nota = p(A_IN + (A_OUT - A_IN) * 0.22, B_IN + (B_OUT - B_IN) * 0.22, 0)
out.append(f'    <text x="{nota[0]:.1f}" y="{nota[1]:.1f}" font-size="9" font-weight="400" fill="#9a9a9a" '
           f'dominant-baseline="middle" transform="rotate(90 {nota[0]:.1f} {nota[1]:.1f})">solo abbonati</text>')
out.append('  </g>')
out.append('</svg>')

svg = '\n'.join(out)
open('olimpico.svg', 'w').write(svg)
print('generato:', len(svg), 'byte')
