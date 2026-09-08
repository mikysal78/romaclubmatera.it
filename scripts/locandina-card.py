#!/usr/bin/env python3
"""Ricava dalla locandina di una partita l'immagine in evidenza per la news.

Il problema che risolve: il riquadro delle news in home e' quasi quadrato
(1,08:1 su desktop, 1,16:1 da telefono) e ritaglia con object-fit: cover. Una
locandina e' verticale 2:3, quindi verrebbe tagliata del 38% in altezza -
proprio dove stanno la testata in alto e "Forza Roma" in fondo.

La carta e' quadrata 1200x1200: a quel rapporto il ritaglio del riquadro e'
del 7% su desktop e del 14% da telefono, e cade sul fondo, non sul contenuto.
Dentro ci va la locandina INTERA, ridimensionata per starci, e tenuta nella
parte alta perche' il widget scrive titolo ed estratto sul quarto inferiore.
Il fondo e' la locandina stessa sfocata e scurita, cosi' i colori sono quelli
della serata e non una banda nera.

    python3 scripts/locandina-card.py locandina.jpg card.jpg
"""

import sys
from PIL import Image, ImageEnhance, ImageFilter

LATO = 1200

# Il widget delle news scrive titolo ed estratto sopra l'immagine, a partire
# dal 77,6% dell'altezza (misurato in pagina). La locandina va tenuta sopra
# quella fascia: se la si centra nel quadrato, il testo del widget finisce
# sull'orario e sull'apertura della sede, che sono la ragione per cui uno
# guarda la locandina.
ZONA_LIBERA = 0.776
MARGINE = 0.98    # quanto della zona libera occupa la locandina
SFOCATURA = 26
LUMINOSITA = 0.42


def carta(sorgente: str, destinazione: str) -> None:
    loc = Image.open(sorgente).convert("RGB")

    # Fondo: la locandina che riempie il quadrato, sfocata e scurita. Serve a
    # non lasciare bande vuote ai lati mantenendo i colori giusti.
    scala = max(LATO / loc.width, LATO / loc.height)
    fondo = loc.resize((round(loc.width * scala), round(loc.height * scala)), Image.LANCZOS)
    fondo = fondo.crop((
        (fondo.width - LATO) // 2,
        (fondo.height - LATO) // 2,
        (fondo.width - LATO) // 2 + LATO,
        (fondo.height - LATO) // 2 + LATO,
    ))
    fondo = fondo.filter(ImageFilter.GaussianBlur(SFOCATURA))
    fondo = ImageEnhance.Brightness(fondo).enhance(LUMINOSITA)

    # Davanti: la locandina intera, nessun ritaglio, nella parte alta.
    avanti = loc.copy()
    alta = round(LATO * ZONA_LIBERA * MARGINE)
    avanti.thumbnail((round(LATO * MARGINE), alta), Image.LANCZOS)
    fondo.paste(
        avanti,
        ( (LATO - avanti.width) // 2, round((LATO * ZONA_LIBERA - avanti.height) / 2) )
    )

    fondo.save(destinazione, "JPEG", quality=86, optimize=True, progressive=True)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    carta(sys.argv[1], sys.argv[2])
    import os
    im = Image.open(sys.argv[2])
    print(f"{sys.argv[2]}: {im.size[0]}x{im.size[1]}, {round(os.path.getsize(sys.argv[2])/1024)} KB")
