#!/usr/bin/env python3
"""Genera lo schema prospettico dello Stadio Olimpico per la pagina Biglietteria.

Il risultato va incollato dentro rcm_big_schema() in
roles/wordpress/files/rcm-biglietti.php, al posto dell'SVG che c'e'.

Perche' uno script e non l'SVG scritto a mano: i settori sono spicchi di un
anello ellittico, e ogni confine e' un punto su due ellissi diverse. Calcolati
a occhio non combaciano, e le etichette non seguono la curva.

Settembre 2026: i settori di cui il Club dispone sono tre, e sono parti di
settore - Distinti Nord Est, l'anello alto della Tevere lato nord ("Top
Nord"), la Monte Mario laterale lato nord. Lo schema li disegna pieni nel
colore del Club e spegne tutto il resto; Tevere e Monte Mario sono divise
nelle loro parti, altrimenti "Top Nord" e "Laterale Nord" non si vedrebbero.
Se i settori del Club cambiano, si cambia CLUB qui sotto e si rigenera.

    python3 scripts/olimpico-svg.py    # scrive olimpico.svg nella cartella corrente
"""
import math

CX, CY = 250.0, 200.0
A_OUT, B_OUT = 226.0, 128.0     # bordo esterno dell'anello
A_IN,  B_IN  = 134.0, 74.0      # bordo interno (affaccio sul campo)
MURO = 30.0                      # altezza della facciata, da' la profondita'
TOP = 0.52                       # dove comincia l'anello alto, fra interno (0) ed esterno (1)

# Geografia vera: Nord a sinistra, Sud a destra, Tevere in alto, Monte Mario in basso.
# Angoli in gradi, antiorari, 0 = destra (Curva Sud).

ROSSO, TESTO_ROSSO = '#8e1f2f', '#f7ecd5'
SPENTO, SPENTO_2, TESTO_SPENTO = '#4b4b4b', '#565656', '#c4c4c4'
ABBONATI = '#333333'

# (da, a, r0, r1, id) - r0/r1: frazione dell'anello, 0 = interno, 1 = esterno
PEZZI = [
    (-30,  30,  0,   1,   'curva-sud'),
    ( 30,  64,  0,   1,   'distinti-sud'),
    ( 64, 116,  0,   TOP, 'tevere-basso'),
    ( 64,  90,  TOP, 1,   'tevere-top-sud'),
    ( 90, 116,  TOP, 1,   'tevere-top-nord'),
    (116, 150,  0,   1,   'distinti-nord-est'),
    (150, 210,  0,   1,   'curva-nord'),
    (210, 244,  0,   1,   'distinti-nord-ovest'),
    (244, 268,  0,   1,   'monte-mario-laterale-nord'),
    (268, 306,  0,   1,   'monte-mario-centrale'),
    (306, 330,  0,   1,   'monte-mario-laterale-sud'),
]

# I settori del Club
CLUB = {'distinti-nord-est', 'tevere-top-nord', 'monte-mario-laterale-nord'}

# Etichette dentro l'anello: (testo, angolo, frazione del raggio, dimensione, id
# del pezzo che la ospita). "|" manda a capo: negli spicchi stretti una riga
# sola sborda nel vicino.
ETICHETTE = [
    ('CURVA SUD',           0,    0.5,  12, 'curva-sud'),
    ('DISTINTI|SUD',        47,   0.5,  9,  'distinti-sud'),
    ('ANELLO BASSO',        90,   0.26, 9,  'tevere-basso'),
    ('TOP SUD',             77,   0.76, 9,  'tevere-top-sud'),
    ('TOP NORD',            103,  0.76, 9,  'tevere-top-nord'),
    ('DISTINTI|NORD EST',   133,  0.5,  9,  'distinti-nord-est'),
    ('CURVA NORD',          180,  0.5,  12, 'curva-nord'),
    ('DISTINTI|NORD OVEST', 227,  0.5,  9,  'distinti-nord-ovest'),
    ('LATERALE|NORD',       256,  0.5,  9,  'monte-mario-laterale-nord'),
    ('CENTRALE',            287,  0.5,  9,  'monte-mario-centrale'),
    ('LATERALE|SUD',        318,  0.5,  9,  'monte-mario-laterale-sud'),
]

# I nomi delle due tribune, fuori dall'anello: dentro non c'e' posto per il
# nome e per le sue parti insieme.
TRIBUNE = [
    ('TRIBUNA TEVERE',      90,  -14),   # sopra il bordo esterno
    ('TRIBUNA MONTE MARIO', 287,  MURO + 16),  # sotto la facciata
]


def p(a, b, t, dy=0.0):
    r = math.radians(t)
    return (CX + a * math.cos(r), CY - b * math.sin(r) + dy)


def f(pt):
    return f"{pt[0]:.1f} {pt[1]:.1f}"


def raggi(fr):
    return A_IN + (A_OUT - A_IN) * fr, B_IN + (B_OUT - B_IN) * fr


def spicchio(t1, t2, r0, r1, colore):
    """Parte dell'anello fra due angoli e due raggi, in senso antiorario."""
    grande = 1 if (t2 - t1) % 360 > 180 else 0
    ao, bo = raggi(r1)
    ai, bi = raggi(r0)
    o1, o2 = p(ao, bo, t1), p(ao, bo, t2)
    i1, i2 = p(ai, bi, t1), p(ai, bi, t2)
    return (f'<path d="M{f(o1)} A{ao:.1f} {bo:.1f} 0 {grande} 0 {f(o2)} '
            f'L{f(i2)} A{ai:.1f} {bi:.1f} 0 {grande} 1 {f(i1)} Z" fill="{colore}"/>')


def colore(pid, n):
    if pid in CLUB:
        return ROSSO
    if pid == 'curva-sud':
        return ABBONATI
    return SPENTO if n % 2 == 0 else SPENTO_2


out = []
out.append('<svg class="rcm-big-svg" xmlns="http://www.w3.org/2000/svg" viewBox="14 44 472 386" role="img" aria-labelledby="rcm-olimpico-t rcm-olimpico-d">')
out.append('  <title id="rcm-olimpico-t">Stadio Olimpico: i settori del Club</title>')
out.append('  <desc id="rcm-olimpico-d">Lo Stadio Olimpico di Roma visto dall&#8217;alto in prospettiva. '
           'In evidenza i settori di cui il Club dispone per le partite in casa: Distinti Nord Est, '
           'l&#8217;anello alto della Tribuna Tevere lato nord (Top Nord) e la Tribuna Monte Mario laterale nord. '
           'Gli altri settori sono in grigio; la Curva Sud &#232; riservata agli abbonati.</desc>')

o_dx = p(A_OUT, B_OUT, 0)
o_sx = p(A_OUT, B_OUT, 180)
out.append('  <!-- La facciata esterna: e\' quella che fa sembrare lo stadio visto -->')
out.append('  <!-- da un angolo invece che schiacciato sulla carta. -->')
out.append(f'  <path d="M{f(o_sx)} A{A_OUT} {B_OUT} 0 0 0 {f(o_dx)} '
           f'L{f((o_dx[0], o_dx[1] + MURO))} A{A_OUT} {B_OUT} 0 0 1 {f((o_sx[0], o_sx[1] + MURO))} Z" fill="#2a2a2a"/>')
out.append('  <ellipse cx="%.0f" cy="%.0f" rx="%.0f" ry="%.0f" fill="#1c1c1c"/>' % (CX, CY, A_OUT, B_OUT))

out.append('  <!-- I settori dell\'anello: quelli del Club pieni, gli altri spenti -->')
for n, (t1, t2, r0, r1, pid) in enumerate(PEZZI):
    out.append('  ' + spicchio(t1, t2, r0, r1, colore(pid, n)).replace('<path ', f'<path data-settore="{pid}" '))

# I confini: radiali fra i settori, e l'arco fra anello basso e alto della Tevere
out.append('  <g fill="none" stroke="#141414" stroke-width="1.5">')
for t1, t2, r0, r1, pid in PEZZI:
    ai, bi = raggi(r0)
    ao, bo = raggi(r1)
    a1, a2 = p(ao, bo, t1), p(ai, bi, t1)
    out.append(f'    <line x1="{a1[0]:.1f}" y1="{a1[1]:.1f}" x2="{a2[0]:.1f}" y2="{a2[1]:.1f}"/>')
at, bt = raggi(TOP)
s1, s2 = p(at, bt, 64), p(at, bt, 116)
out.append(f'    <path d="M{f(s1)} A{at:.1f} {bt:.1f} 0 0 0 {f(s2)}"/>')
out.append('  </g>')

# I settori del Club con un filo chiaro intorno: si staccano anche su schermi piccoli
out.append('  <g fill="none" stroke="#f0bc42" stroke-width="1.6" stroke-linejoin="round">')
for t1, t2, r0, r1, pid in PEZZI:
    if pid in CLUB:
        out.append('    ' + spicchio(t1, t2, r0, r1, 'none').replace(' fill="none"', ''))
out.append('  </g>')

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

# Etichette dentro l'anello, inclinate come la curva su cui poggiano
out.append('  <g font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">')
for testo, ang, fr, dim, pid in ETICHETTE:
    a, b = raggi(fr)
    px, py = p(a, b, ang)
    r = math.radians(ang)
    dx, dy = -a * math.sin(r), -b * math.cos(r)
    rot = math.degrees(math.atan2(dy, dx))
    if rot > 90:
        rot -= 180
    elif rot < -90:
        rot += 180
    fill = TESTO_ROSSO if pid in CLUB else TESTO_SPENTO
    peso = '' if pid in CLUB else ' font-weight="400"'
    righe = testo.split('|')
    passo = dim * 1.15
    tsp = ''.join(
        f'<tspan x="{px:.1f}" y="{py - passo * (len(righe) - 1) / 2 + passo * i:.1f}">{r}</tspan>'
        for i, r in enumerate(righe))
    out.append(f'    <text font-size="{dim}" fill="{fill}"{peso} '
               f'dominant-baseline="middle" transform="rotate({rot:.1f} {px:.1f} {py:.1f})">{tsp}</text>')
nota = p(*raggi(0.2), 0)
out.append(f'    <text x="{nota[0]:.1f}" y="{nota[1]:.1f}" font-size="8" font-weight="400" fill="#9a9a9a" '
           f'dominant-baseline="middle" transform="rotate(90 {nota[0]:.1f} {nota[1]:.1f})">solo abbonati</text>')
for testo, ang, dy in TRIBUNE:
    px, py = p(A_OUT, B_OUT, ang, dy)
    out.append(f'    <text x="{px:.1f}" y="{py:.1f}" font-size="12" letter-spacing=".5" fill="currentColor" '
               f'dominant-baseline="middle">{testo}</text>')
out.append('  </g>')

# Legenda, sotto lo stadio
out.append('  <g font-family="Arial, Helvetica, sans-serif" font-size="11" dominant-baseline="middle">')
out.append(f'    <rect x="150" y="408" width="14" height="14" rx="2" fill="{ROSSO}" stroke="#f0bc42" stroke-width="1.4"/>')
out.append('    <text x="170" y="415.5" fill="currentColor">Settori del Club</text>')
out.append(f'    <rect x="276" y="408" width="14" height="14" rx="2" fill="{SPENTO}"/>')
out.append('    <text x="296" y="415.5" fill="currentColor" opacity=".75">Non disponibili</text>')
out.append('  </g>')
out.append('</svg>')

svg = '\n'.join(out)
open('olimpico.svg', 'w').write(svg)
print('generato:', len(svg), 'byte')
