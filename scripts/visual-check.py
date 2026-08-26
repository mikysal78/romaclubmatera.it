#!/usr/bin/env python3
# =====================================================================
#  visual-check.py - Sorveglia che il sito non cambi aspetto.
#
#  Fotografa le pagine di visual/config.json con Chrome headless
#  (desktop e mobile, pagina intera) e le confronta pixel per pixel
#  con la baseline. Serve a rispondere a una domanda sola:
#  "dopo questo aggiornamento il sito e' rimasto uguale?"
#
#  Uso tipico, attorno a un aggiornamento:
#     ./scripts/visual-check.py baseline     # PRIMA di toccare qualcosa
#     ... aggiorna plugin/temi, svuota le cache ...
#     ./scripts/visual-check.py check        # DOPO: dice cosa si e' mosso
#
#  Oppure con make:  make visual-baseline / make visual-check
#
#  Gli screenshot stanno in visual/shots/ e NON sono versionati (pesano).
#  Nel repo finisce visual/baseline.json: dimensioni delle pagine e
#  impronta di ogni fascia di 16 righe. Cosi' anche su un clone senza
#  screenshot si vede subito se una pagina e' cambiata e a che altezza.
#
#  Serve: google-chrome, python3-websockets, python3-pil, python3-numpy
# =====================================================================
"""Confronto visivo delle pagine del sito prima/dopo un aggiornamento."""

from __future__ import annotations

import argparse
import asyncio
import base64
import contextlib
import datetime
import hashlib
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VISUAL = os.path.join(REPO, "visual")
CONFIG = os.path.join(VISUAL, "config.json")
BASELINE = os.path.join(VISUAL, "baseline.json")
SHOTS = os.path.join(VISUAL, "shots")

# Differenza per canale sotto la quale due pixel si considerano uguali:
# assorbe l'antialiasing senza nascondere un vero cambio di colore.
SOGLIA_PIXEL = 8
# Altezza in righe delle fasce di cui si salva l'impronta in baseline.json.
FASCIA = 16

VERDE = "\033[32m"
ROSSO = "\033[31m"
GIALLO = "\033[33m"
GRIGIO = "\033[90m"
RESET = "\033[0m"


def colore(testo: str, c: str) -> str:
    return f"{c}{testo}{RESET}" if sys.stdout.isatty() else testo


def esci(messaggio: str) -> None:
    print(colore(f"Errore: {messaggio}", ROSSO), file=sys.stderr)
    sys.exit(1)


def dipendenze():
    """Importa le librerie pesanti solo quando servono, con un messaggio utile."""
    mancanti = []
    try:
        import websockets  # noqa: F401
    except ImportError:
        mancanti.append("python3-websockets")
    try:
        from PIL import Image  # noqa: F401
    except ImportError:
        mancanti.append("python3-pil")
    try:
        import numpy  # noqa: F401
    except ImportError:
        mancanti.append("python3-numpy")
    if not chrome_bin():
        mancanti.append("google-chrome (o chromium)")
    if mancanti:
        esci("mancano: " + ", ".join(mancanti) + "\n       su Debian:  sudo apt install " +
             " ".join(m for m in mancanti if m.startswith("python3-")))


def chrome_bin() -> str | None:
    for nome in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser"):
        p = shutil.which(nome)
        if p:
            return p
    return None


def porta_libera() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


# ---------------------------------------------------------------------
#  Chrome headless + CDP
# ---------------------------------------------------------------------

class Chrome:
    """Avvia un Chrome headless usa-e-getta e lo chiude all'uscita."""

    def __init__(self):
        self.porta = porta_libera()
        self.profilo = tempfile.mkdtemp(prefix="visual-check-")
        self.proc = None
        self.versione = "?"

    def __enter__(self):
        self.proc = subprocess.Popen(
            [
                chrome_bin(),
                "--headless=new",
                f"--remote-debugging-port={self.porta}",
                "--remote-allow-origins=*",
                f"--user-data-dir={self.profilo}",
                "--disable-gpu",
                "--hide-scrollbars",
                "--no-first-run",
                "--no-default-browser-check",
                "--disable-extensions",
                "--disable-background-networking",
                "about:blank",
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        scadenza = time.time() + 30
        while time.time() < scadenza:
            try:
                with urllib.request.urlopen(
                    f"http://127.0.0.1:{self.porta}/json/version", timeout=1
                ) as r:
                    self.versione = json.load(r).get("Browser", "?")
                return self
            except (urllib.error.URLError, ConnectionError, OSError):
                time.sleep(0.3)
        self.__exit__(None, None, None)
        esci("Chrome non ha risposto sulla porta di debug entro 30 secondi")

    def __exit__(self, *_):
        if self.proc and self.proc.poll() is None:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        shutil.rmtree(self.profilo, ignore_errors=True)
        return False

    def nuova_scheda(self):
        req = urllib.request.Request(
            f"http://127.0.0.1:{self.porta}/json/new?about:blank", method="PUT"
        )
        with urllib.request.urlopen(req, timeout=10) as r:
            d = json.load(r)
        return d["webSocketDebuggerUrl"], d["id"]

    def chiudi_scheda(self, tid: str):
        with contextlib.suppress(Exception):
            urllib.request.urlopen(
                f"http://127.0.0.1:{self.porta}/json/close/{tid}", timeout=5
            ).read()


class Sessione:
    """Il minimo indispensabile di protocollo CDP: manda comandi, aspetta la risposta."""

    def __init__(self, ws):
        self.ws = ws
        self.n = 0

    async def cmd(self, metodo: str, **params):
        self.n += 1
        await self.ws.send(json.dumps({"id": self.n, "method": metodo, "params": params}))
        while True:
            msg = json.loads(await self.ws.recv())
            if msg.get("id") == self.n:
                if "error" in msg:
                    raise RuntimeError(f"{metodo}: {msg['error']}")
                return msg.get("result", {})


# Scorre la pagina a scatti: le animazioni di entrata di Elementor partono
# quando l'elemento entra nello schermo, altrimenti resta a opacita' zero
# e nello screenshot si vedrebbe una pagina mezza vuota.
JS_SCORRI = """
(async () => {
  const attendi = ms => new Promise(r => setTimeout(r, ms));
  const passo = window.innerHeight * 0.8;
  for (let y = 0; y < document.body.scrollHeight; y += passo) {
    window.scrollTo(0, y);
    await attendi(250);
  }
  window.scrollTo(0, 0);

  /* Lo scorrimento fa partire il caricamento delle immagini lazy, ma non
     aspetta che finiscano: senza questa attesa una foto puo' finire nello
     scatto ancora a mezza risoluzione e la pagina risulta diversa. */
  try { await document.fonts.ready; } catch (_) {}
  const scadenza = Date.now() + 20000;
  while (Date.now() < scadenza) {
    const incomplete = Array.from(document.images).filter(i => !i.complete);
    if (!incomplete.length) break;
    await attendi(300);
  }
  await attendi(500);
})()
"""

# Congela tutto cio' che si muove da solo, altrimenti due scatti della
# stessa pagina non sono mai uguali. Va eseguito DOPO lo scorrimento:
# prima le animazioni di entrata devono aver fatto il loro corso.
JS_CONGELA = """
document.querySelectorAll('.elementor-invisible').forEach(e => {
  e.classList.remove('elementor-invisible');
  e.style.opacity = '1'; e.style.animation = 'none'; e.style.transform = 'none';
});
/* i caroselli tornano alla prima slide, se no ogni scatto ne pesca una diversa */
document.querySelectorAll('.swiper, .swiper-container').forEach(e => {
  const s = e.swiper;
  if (!s) return;
  if (s.autoplay && s.autoplay.stop) s.autoplay.stop();
  try { s.slideToLoop ? s.slideToLoop(0, 0) : s.slideTo(0, 0); } catch (_) {}
});
document.querySelectorAll('video').forEach(v => { try { v.pause(); } catch (_) {} });
/* il preloader del tema resta a schermo finche' la pagina non ha finito */
document.querySelectorAll('.pageloader, #pageloader').forEach(e => e.remove());
/* Spegne animazioni E transizioni. Senza le transizioni un titolo colto a
   meta' dissolvenza cambia l'antialiasing delle lettere e due scatti della
   stessa pagina risultano diversi per una manciata di pixel sui bordi. */
const stop = document.createElement('style');
stop.textContent = `*, *::before, *::after {
  animation: none !important; animation-delay: 0s !important;
  transition: none !important; transition-delay: 0s !important;
  caret-color: transparent !important;
}`;
document.head.appendChild(stop);
/* Nasconde i blocchi che cambiano contenuto a ogni caricamento (vedi
   "nascondi" in config.json). Non display:none: l'ingombro deve restare,
   se no il resto della pagina si sposta e non e' piu' confrontabile riga
   per riga.
   Regola in foglio di stile con !important, non stile inline: Slider
   Revolution riscrive di continuo l'attributo style dei suoi contenitori
   e cancellerebbe un inline, mentre !important lo batte.
   E soprattutto opacity, non solo visibility: visibility si eredita, e
   Slider Revolution rimette "visibility: visible" sulle sue slide appena
   si inizializza, quindi il contenuto riaffiorava a seconda di quanto
   tempo aveva avuto per partire. opacity non e' ereditata e vale per tutto
   il sottoalbero: nessun figlio puo' disfarla. */
const nascondi = (SELETTORI);
if (nascondi.length) {
  const via = document.createElement('style');
  via.textContent = nascondi.join(',') +
    '{visibility:hidden !important;opacity:0 !important;}';
  document.head.appendChild(via);
}
"""


async def scatta(cfg: dict, dest: str, attesa: float) -> tuple[list[str], str]:
    """Fotografa ogni pagina x ogni viewport dentro dest/. Torna i file e la versione di Chrome."""
    import websockets

    os.makedirs(dest, exist_ok=True)
    fatti = []
    base = cfg["base_url"].rstrip("/")

    with Chrome() as chrome:
        print(colore(f"  {chrome.versione}", GRIGIO))
        url_ws, tid = chrome.nuova_scheda()
        async with websockets.connect(url_ws, max_size=256 * 1024 * 1024) as ws:
            s = Sessione(ws)
            await s.cmd("Page.enable")
            await s.cmd("Runtime.enable")
            for nome_vp, larghezza, altezza in cfg["viewports"]:
                for pagina in cfg["pagine"]:
                    slug = nome(pagina["path"])
                    await s.cmd(
                        "Emulation.setDeviceMetricsOverride",
                        width=larghezza, height=altezza,
                        deviceScaleFactor=1, mobile=(nome_vp == "mobile"),
                    )
                    await s.cmd("Page.navigate", url=base + pagina["path"])
                    # gli iframe di terze parti (JotForm) disegnano il contenuto
                    # molto dopo il load: per quelle pagine config.json alza l'attesa
                    await asyncio.sleep(pagina.get("attesa", attesa))
                    with contextlib.suppress(Exception):
                        await s.cmd("Runtime.evaluate", expression=JS_SCORRI, awaitPromise=True)
                    await asyncio.sleep(2)
                    congela = JS_CONGELA.replace(
                        "(SELETTORI)", json.dumps(pagina.get("nascondi", []))
                    )
                    await s.cmd("Runtime.evaluate", expression=congela)
                    png = await s.cmd(
                        "Page.captureScreenshot", format="png", captureBeyondViewport=True
                    )
                    f = f"{nome_vp}-{slug}.png"
                    with open(os.path.join(dest, f), "wb") as fh:
                        fh.write(base64.b64decode(png["data"]))
                    fatti.append(f)
                    print(f"  {f}")
        chrome.chiudi_scheda(tid)
        return fatti, chrome.versione


def nome(path: str) -> str:
    return path.strip("/").split("/")[-1] or "home"


# ---------------------------------------------------------------------
#  Confronto
# ---------------------------------------------------------------------

def impronte(percorso: str) -> dict:
    """Dimensioni della pagina e hash di ogni fascia di 16 righe."""
    from PIL import Image
    import numpy as np

    arr = np.asarray(Image.open(percorso).convert("RGB"))
    h, w = arr.shape[:2]
    fasce = [
        hashlib.sha1(arr[y:y + FASCIA].tobytes()).hexdigest()[:12]
        for y in range(0, h, FASCIA)
    ]
    return {"w": int(w), "h": int(h), "fasce": fasce}


def confronta(a: str, b: str, ignora: list = ()) -> tuple[float, list[tuple[int, int]]]:
    """Percentuale di pixel diversi e intervalli di righe in cui cadono.

    `ignora` e' una lista di intervalli [y_da, y_a] esclusi dal conteggio:
    servono per il contenuto che cambia da solo e occupa un'area nota
    (mappa, slider), cosi' il resto della pagina resta sorvegliato stretto.
    """
    from PIL import Image
    import numpy as np

    ia = np.asarray(Image.open(a).convert("RGB")).astype(np.int16)
    ib = np.asarray(Image.open(b).convert("RGB")).astype(np.int16)
    diversi = np.abs(ia - ib).max(axis=2) > SOGLIA_PIXEL
    for y_da, y_a in ignora:
        diversi[max(0, y_da):y_a + 1] = False
    pct = 100.0 * diversi.sum() / diversi.size
    righe = np.where(diversi.any(axis=1))[0]
    intervalli = []
    for y in righe:
        if intervalli and y - intervalli[-1][1] <= FASCIA:
            intervalli[-1][1] = int(y)
        else:
            intervalli.append([int(y), int(y)])
    return pct, [tuple(i) for i in intervalli[:4]]


def salva_diff(a: str, b: str, dest: str, ignora: list = ()) -> None:
    """Immagine di controllo: lo scatto nuovo con in rosso cio' che e' cambiato."""
    from PIL import Image
    import numpy as np

    ia = np.asarray(Image.open(a).convert("RGB")).astype(np.int16)
    ib = np.asarray(Image.open(b).convert("RGB"))
    diversi = np.abs(ia - ib.astype(np.int16)).max(axis=2) > SOGLIA_PIXEL
    for y_da, y_a in ignora:
        diversi[max(0, y_da):y_a + 1] = False
    out = ib.copy()
    out[diversi] = (out[diversi] * 0.35 + np.array([255, 0, 0]) * 0.65).astype(np.uint8)
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    Image.fromarray(out).save(dest)


def carica_config() -> dict:
    if not os.path.exists(CONFIG):
        esci(f"manca {CONFIG}")
    with open(CONFIG, encoding="utf-8") as f:
        cfg = json.load(f)
    for p in cfg["pagine"]:
        p.setdefault("tolleranza", cfg.get("tolleranza_default", 0.05))
    return cfg


# ---------------------------------------------------------------------
#  Comandi
# ---------------------------------------------------------------------

def cmd_baseline(args) -> int:
    cfg = carica_config()
    dest = os.path.join(SHOTS, "baseline")
    print(f"Baseline: {len(cfg['pagine'])} pagine x {len(cfg['viewports'])} viewport")
    shutil.rmtree(dest, ignore_errors=True)
    files, versione = asyncio.run(scatta(cfg, dest, args.attesa))

    dati = {
        "creata": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
        "chrome": versione,
        "base_url": cfg["base_url"],
        "soglia_pixel": SOGLIA_PIXEL,
        "fascia": FASCIA,
        "pagine": {f: impronte(os.path.join(dest, f)) for f in sorted(files)},
    }
    with open(BASELINE, "w", encoding="utf-8") as f:
        json.dump(dati, f, indent=2, ensure_ascii=False)
        f.write("\n")
    print(f"\n{colore('Baseline salvata', VERDE)}: {os.path.relpath(BASELINE, REPO)}"
          f" ({len(files)} scatti in {os.path.relpath(dest, REPO)}/)")
    return 0


def cmd_check(args) -> int:
    cfg = carica_config()
    if not os.path.exists(BASELINE):
        esci("nessuna baseline. Lancia prima:  ./scripts/visual-check.py baseline")
    with open(BASELINE, encoding="utf-8") as f:
        base = json.load(f)

    dir_base = os.path.join(SHOTS, "baseline")
    dir_ora = os.path.join(SHOTS, "current")
    dir_diff = os.path.join(SHOTS, "diff")
    shutil.rmtree(dir_ora, ignore_errors=True)
    shutil.rmtree(dir_diff, ignore_errors=True)

    print(f"Confronto con la baseline del {base['creata'][:16].replace('T', ' ')}")
    files, versione = asyncio.run(scatta(cfg, dir_ora, args.attesa))

    if versione != base.get("chrome"):
        print(colore(
            f"\n  Attenzione: baseline creata con {base.get('chrome')}, ora {versione}."
            "\n  Un cambio di browser sposta l'antialiasing su tutte le pagine:"
            "\n  differenze diffuse e piccolissime possono venire da li'.", GIALLO))

    per_slug = {nome(p["path"]): p for p in cfg["pagine"]}

    righe, problemi = [], 0
    for f in sorted(files):
        vp, slug = f.split("-", 1)[0], f.split("-", 1)[1][:-4]
        pagina = per_slug.get(slug, {})
        tol = pagina.get("tolleranza", cfg.get("tolleranza_default", 0.05))
        ignora = pagina.get("ignora", {}).get(vp, [])
        rif = base["pagine"].get(f)
        png_base = os.path.join(dir_base, f)

        if rif is None:
            righe.append((f, "-", colore("NUOVA (non in baseline)", GIALLO)))
            continue

        ora = impronte(os.path.join(dir_ora, f))
        if (ora["w"], ora["h"]) != (rif["w"], rif["h"]):
            righe.append((f, "-", colore(
                f"ALTEZZA CAMBIATA {rif['w']}x{rif['h']} -> {ora['w']}x{ora['h']}", ROSSO)))
            problemi += 1
            continue

        if os.path.exists(png_base):
            pct, intervalli = confronta(png_base, os.path.join(dir_ora, f), ignora)
            dove = " ".join(f"y={a}-{b}" for a, b in intervalli)
            if pct > tol:
                salva_diff(png_base, os.path.join(dir_ora, f), os.path.join(dir_diff, f), ignora)
                righe.append((f, f"{pct:.3f}%", colore(f"DIVERSA  {dove}", ROSSO)))
                problemi += 1
            else:
                extra = f"{GRIGIO}entro tolleranza {tol}%{RESET}" if pct else ""
                righe.append((f, f"{pct:.3f}%", colore("uguale", VERDE) +
                              (f"  {extra}" if pct and sys.stdout.isatty() else "")))
        else:
            # senza lo screenshot di riferimento restano le impronte delle fasce
            mosse = [i for i, (x, y) in enumerate(zip(rif["fasce"], ora["fasce"])) if x != y]
            if mosse:
                dove = f"y={mosse[0] * FASCIA}-{(mosse[-1] + 1) * FASCIA}"
                righe.append((f, f"{len(mosse)} fasce", colore(f"DIVERSA  {dove}", ROSSO)))
                problemi += 1
            else:
                righe.append((f, "impronte", colore("uguale", VERDE)))

    larg = max(len(r[0]) for r in righe)
    print()
    for f, misura, esito in righe:
        n = per_slug.get(f.split("-", 1)[1][:-4], {}).get("nota", "")
        print(f"  {f:<{larg}}  {misura:>10}  {esito}" + (f"  {GRIGIO}({n}){RESET}"
              if n and "DIVERSA" in esito and sys.stdout.isatty() else ""))

    print()
    if problemi:
        print(colore(f"{problemi} pagine cambiate.", ROSSO) +
              f" Le immagini di controllo (in rosso cio' che si e' mosso) sono in "
              f"{os.path.relpath(dir_diff, REPO)}/")
        print("Se il cambiamento e' voluto, rifai la baseline:  "
              "./scripts/visual-check.py baseline")
        return 1
    print(colore("Nessuna pagina e' cambiata.", VERDE))
    return 0


def main() -> int:
    p = argparse.ArgumentParser(
        description="Confronto visivo delle pagine del sito prima/dopo un aggiornamento.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="Le pagine sorvegliate e le tolleranze stanno in visual/config.json",
    )
    p.add_argument("comando", choices=("baseline", "check"),
                   help="baseline = fotografa lo stato attuale come riferimento; "
                        "check = confronta com'e' adesso con il riferimento")
    p.add_argument("--attesa", type=float, default=5.0, metavar="SEC",
                   help="secondi di attesa dopo il caricamento di ogni pagina (default: 5)")
    args = p.parse_args()

    dipendenze()
    os.makedirs(SHOTS, exist_ok=True)
    return cmd_baseline(args) if args.comando == "baseline" else cmd_check(args)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print("\nInterrotto.", file=sys.stderr)
        sys.exit(130)
