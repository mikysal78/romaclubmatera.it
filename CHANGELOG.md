# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate qui.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/)
e il versionamento [SemVer](https://semver.org/lang/it/).

## [Unreleased]

### Sicurezza
- **Aggiornati i tre temi di default inattivi**: `twentytwentytwo` 2.1 -> 2.2,
  `twentytwentythree` 1.6 -> 1.7, `twentytwentyfour` 1.5 -> 1.6. Non sono
  attivi e non toccano l'aspetto del sito, ma restano codice sul disco.
  Core (7.1) e gli 11 plugin attivi erano gia' aggiornati; `wp core
  verify-checksums` e `wp plugin verify-checksums --all` passano (esclusi
  Slider Revolution e Unlimited Elements, premium e quindi non su
  wordpress.org). Verificato con `visual-check` che nessuna pagina sia
  cambiata.

### Modificato
- **Stemma della Juventus invisibile in classifica**: quello scaricato a suo
  tempo da football-data era la variante **bianca** del marchio, pensata per i
  fondi scuri, e sulla tabella bianca spariva lasciando la cella vuota. Ora e'
  la variante nera (il file bianco resta in `/root/backup-crest_779-bianco.png`
  sul server). Le altre 19 squadre erano gia' a posto.
- **"Il prossimo match" in home page mostrava l'ultima partita giocata**. Il
  blocco era `[event_blocks number="1"]`: con `date="auto"` (il default)
  SportsPress divide il numero di eventi richiesti fra passati e futuri, e con
  `number="1"` fa `ceil(1/2)=1` partite gia' giocate piu' `floor(1/2)=0` da
  giocare - cioe' sempre e solo l'ultima. Finche' gli eventi non avevano un
  punteggio la cosa passava inosservata, perche' sotto il titolo si leggeva
  comunque una data; da quando i risultati vengono riempiti in automatico
  sotto "Il prossimo match" compariva "4 - 0". Ora e'
  `[event_blocks number="1" status="future" orderby="date" order="ASC"]`.
- **Carosello della home page**: al posto delle tre foto di eventi (*Roma*,
  *Matera 2024*, *16 Birra* - allegati 563/564/565, rimasti in libreria) ci sono
  ora **nove foto della sede** (allegati 1333-1341), ordinate come una visita:
  ingresso, sala principale, maglie storiche, muro delle figurine, sciarpe delle
  finali europee, gemellaggi, stemmi storici, prime pagine dei giornali e
  bancone. Titolo dell'allegato = didascalia in sovrimpressione (il widget ha
  `caption_type: title`), testo alternativo descrittivo su ognuna.
  Le originali sono PNG 1448x1086 da ~2 MB: ricodificate a 1100px di larghezza,
  JPEG progressivo q80, ~140 KB l'una (1,3 MB per tutte e nove) perche' il
  widget usa `thumbnail_size: full` e le carica tutte nel DOM. Il ritratto del
  bancone (1086x1448) e' stato ritagliato al centro in 4:3 come le altre: le
  slide devono avere tutte lo stesso formato o il carosello balla.
  Le vecchie slide erano verticali 2:3, queste sono orizzontali 4:3: la fascia
  e' passata da ~720px a ~360px di altezza.
  Aggiunta la **spaziatura di 14px tra le slide** (`image_spacing_custom`):
  la sezione ha lo sfondo nero, quindi il vuoto si legge come un bordo nero e
  le foto non sembrano piu' attaccate.

### Aggiunto
- **Pagina Classifica** (`/classifica/`, pagina 1345, nel menu accanto a
  Calendario) con la classifica di Serie A aggiornata ogni mattina dallo stesso
  timer del calendario (`roles/sportspress_fixtures/files/update-standings.php`,
  con le funzioni in comune in `sp-lib.php`).
  **La classifica non la calcola SportsPress**: il plugin la ricava dagli eventi
  in archivio, e sul sito ci sono solo le partite della Roma - verrebbe fuori
  una riga coi dati veri e diciannove a zero. Si scrivono invece i valori
  "manuali" nel meta `sp_teams`, che SportsPress usa al posto di quelli
  calcolati quando ci sono: la tabella e' quella vera senza dover importare
  tutte le 380 partite del campionato. Le otto colonne che il sito aveva gia'
  (`P W D L F A GD Pts`) corrispondono una a una ai campi della API.
  **L'ordinamento e' il punto delicato**: SportsPress riordina sempre da se',
  per punti, differenza reti e gol fatti. In Serie A pero' il primo criterio a
  parita' di punti e' lo scontro diretto, che senza le partite delle altre
  squadre non e' calcolabile, e una tabella ordinata "quasi" bene sarebbe
  peggio di una dichiaratamente sbagliata. Si scrive quindi anche la posizione
  ufficiale in una colonna nascosta (slug `posizione`, priorita' 1, ASC; le
  altre spostate di uno), che non viene mostrata e serve solo a ordinare: cosi'
  l'ordine e' esattamente quello della Lega. Lo slug e' `posizione` e non `pos`
  perche' `pos` e' gia' la chiave che SportsPress usa per la posizione
  calcolata, e le due si sovrascriverebbero.
  La tabella da riempire si trova da sola - e' la `sp_table` con la stessa lega
  e stagione della competizione - quindi nella config non c'e' nessun ID.
  Sul sito sono state taggate le 20 squadre con lega e stagione (SportsPress
  prende da li' le righe) e create la colonna `posizione` (1343), la tabella
  `Classifica Serie A 2026/27` (1344) e la pagina (1345). Lo shortcode ha
  `rows="20"`, se no la tabella si ferma a dieci righe con la paginazione.
- **Il calendario automatico ora regge piu' competizioni**, in vista della
  Champions: `sportspress_fixtures_competizioni` e' un elenco, una voce per
  coppa o campionato, e la config diventa un ini a sezioni (una vecchia config
  senza sezioni continua a funzionare, cosi' un aggiornamento dello script
  senza rilanciare Ansible non rompe il timer notturno).
  La parte delicata e' **come si abbina un match della API al suo evento**:
  per giornata (`sp_day`) va bene nei gironi, ma nelle fasi a eliminazione la
  giornata **non identifica il match** - negli ottavi di Champions il
  `matchday` vale 1 o 2, cioe' andata e ritorno, e collide con le giornate 1 e
  2 della fase campionato. Di default (`abbinamento: auto`) si usa la giornata
  nelle fasi a girone (`REGULAR_SEASON`, `LEAGUE_STAGE`, `GROUP_STAGE`) e la
  **data** altrove: fra gli eventi a +/-7 giorni si prende quello con le stesse
  due squadre nello stesso verso casa/trasferta, e a pari punteggio si rinuncia
  con un avviso invece di indovinare. Un evento gia' abbinato non viene riusato
  nella stessa passata, cosi' andata e ritorno non finiscono sullo stesso.
  Provato forzando `abbinamento: data` sulle 39 giornate di Serie A gia' in
  archivio: ritrova gli stessi eventi, zero avvisi, zero date cambiate.
  Aggiunta anche la gestione dei **supplementari**: `fullTime` della API li
  comprende, quindi il secondo tempo per differenza non tornerebbe - quando la
  `duration` non e' `REGULAR` si scrive solo il totale e lo si annota nel log.
  Ai rigori il punteggio resta pari e l'esito e' un pareggio: chi passa il
  turno non e' un dato che SportsPress registri.
  La Champions e' gia' predisposta ma commentata: il tier gratuito la copre
  (l'Europa League no, risponde "restricted") e la Roma e' qualificata di
  diritto avendo chiuso 3a in Serie A 2025/26, ma football-data non ha ancora
  pubblicato la stagione 2026/27 della coppa.
- **Risultati delle partite in automatico** (`roles/sportspress_fixtures`): lo
  script che ogni mattina allinea data e ora del calendario ora scrive anche il
  punteggio delle partite concluse. Legge da football-data.org `fullTime` e
  `halfTime` e riempie il meta `sp_results` con le tre variabili configurate su
  SportsPress - `goals`, `firsthalf`, `secondhalf` (il secondo tempo per
  differenza) - piu' l'esito, ricavato dalle `sp_outcome` in base alla loro
  condizione (`>` vittoria, `=` pareggio, `<` sconfitta) invece che da uno slug
  scritto a mano.
  Scrive **solo a partita finita** (`FINISHED` o `AWARDED`): su un calendario un
  parziale di una partita in corso si legge come definitivo. Se l'evento fosse
  ancora in stato `future` lo pubblica, altrimenti il risultato resterebbe
  invisibile in pagina.
  Per capire **quale delle due squadre e' in casa** prova entrambi gli
  accoppiamenti fra le squadre dell'evento e quelle della API, li punteggia e
  tiene il migliore; a pari punteggio non indovina, salta e lascia un avviso.
  L'uguaglianza esatta del nome vale piu' della sottostringa, e non e' un
  dettaglio: "Milan" e' contenuto in "FC Internazionale Milano", quindi con la
  sola sottostringa si assegnerebbe all'una il punteggio dell'altra.
  Si spegne con `sportspress_fixtures_results: false` per tornare ai punteggi a
  mano. Rieseguirlo non riscrive nulla se il risultato e' gia' quello giusto.
  In pagina **non serve una colonna nuova**: l'opzione
  `sportspress_event_list_time_format` e' su `combined`, quindi la colonna
  "Orario/Risultati" mostra l'ora finche' la partita non e' giocata e il
  punteggio da li' in poi.
- **Controllo visivo degli aggiornamenti** (`scripts/visual-check.py`,
  `visual/config.json`, `visual/baseline.json`, target `make visual-baseline` e
  `make visual-check`): fotografa le 15 pagine pubbliche con Chrome headless a
  1440px e 390px, pagina intera, e le confronta pixel per pixel con una
  baseline. Esce con codice 1 se qualcosa e' cambiato oltre la tolleranza e
  salva in `visual/shots/diff/` lo scatto nuovo con in rosso cio' che si e'
  mosso.
  Quattro accorgimenti, ognuno trovato perche' senza di esso il confronto
  dava falsi allarmi:
  la pagina va **scorsa a scatti** prima dello scatto, altrimenti le animazioni
  di entrata di Elementor non partono e i blocchi restano a `opacity: 0` (una
  pagina che risulta mezza vuota);
  va **rimosso il preloader** del tema, che con la cache del browser fredda
  resta a schermo e falsa tutta la pagina;
  i **caroselli vanno riportati alla prima slide** oltre che fermati, se no
  ogni scatto ne pesca una diversa;
  e vanno spente **le transizioni CSS oltre alle animazioni**, perche' un
  titolo colto a meta' dissolvenza cambia l'antialiasing delle lettere e fa
  risultare diversa una pagina identica.
  Per i blocchi che cambiano contenuto a ogni caricamento `config.json` ha
  `nascondi` (selettori CSS resi invisibili mantenendo l'ingombro - usato per
  lo slider Revolution della home, le citazioni a rotazione di Unlimited
  Elements e la mappa di Google su Contattaci), `ignora` (intervalli di righe
  per viewport) e `tolleranza`; per tutte le altre pagine e' 0,05%.
  Il nascondere usa `opacity: 0` oltre a `visibility: hidden`: `visibility` si
  eredita e Slider Revolution rimette `visibility: visible` sulle proprie slide
  appena si inizializza, quindi lo slider della home riaffiorava o no a seconda
  di quanto tempo aveva avuto per partire (falso allarme del 3,79% su
  mobile-home). `opacity` non e' ereditata e vale per tutto il sottoalbero:
  nessun figlio puo' disfarla.
  Con questi accorgimenti due passate consecutive danno 0,000% su tutte e 30
  le combinazioni pagina/viewport.
  Nel repo va solo `baseline.json` (dimensioni + impronta di ogni fascia di 16
  righe, poche decine di KB): gli screenshot pesano decine di MB e stanno in
  `visual/shots/`, ignorato da git.
- **Sfumatura sotto le didascalie del carosello** in `rcm-custom.css`, sul
  widget `.our-img`: le foto della sede hanno pareti bianche e la didascalia in
  oro - pensata per le foto scure di prima - ci finiva sopra illeggibile.
  Gradiente nero dal basso sul 45% della slide, con `z-index` sulla didascalia
  perche' ha `margin-top` negativo (impostazione *Spazio didascalia* di
  Elementor) e senza finirebbe sotto la sfumatura.
  La sfumatura, ancorata alla `<figure>`, si fermava **44px sopra il bordo
  vero della foto** e lasciava scoperta una striscia chiara: la "linea sotto
  la foto", evidentissima sulla slide del bancone che in fondo ha il pavimento
  chiaro. La causa e' lo *Spazio didascalia* di Elementor, `-84px`: quel
  margine negativo accorcia la figure di `84 - 40` px rispetto all'immagine,
  che quindi le deborda sotto. Risolto **posizionando la didascalia in
  assoluto** (`bottom: 44px`, `52px` sotto i 768px dove la riga e' 32px) cosi'
  la figure torna alta quanto l'immagine; serve la specificita' di Elementor
  (4 classi, `.elementor .elementor-element.our-img ...`) per battere il suo
  `margin-block-start`. Aggiunti anche `display: block` sull'immagine, che da
  `inline` si portava dietro il filo di baseline, e `bottom: -1px` sulla
  sfumatura per l'arrotondamento delle altezze frazionarie.
- **Pagina "La nostra rete"** (ID 1329, slug `/la-nostra-rete/`), in costruzione,
  voce di menu figlia di *Il Club* dopo *Le nostre trasferte*. Pagina classica
  come le altre segnaposto, quindi eredita hero e colori del tema; badge
  "Lavori in corso" in oro `#e6af14`, due righe di introduzione e due sezioni
  gia' strutturate - **Affiliazioni** e **Gemellaggi e amicizie** - con caselle
  tratteggiate al posto dei loghi, cosi' si capisce cosa arrivera' senza far
  sembrare la pagina rotta. Impostato `_yoast_wpseo_meta-robots-noindex = 1`
  come per Statuto/Regolamento/Store. Riordinato il menu con `menu_order` da 1
  a 15.
  **Logo UTR** (allegato 1331) recuperato dal sito ufficiale `utronlus.com`,
  dove non e' un `<img>` ma un file raggiungibile a `/images/logo.jpg` (il
  template Joomla ha lo sfondo del logo commentato via nel CSS). Esiste solo a
  208x207px, mostrato a 170px in una card bianca come quelle degli sponsor,
  perche' il file ha fondo bianco e la pagina e' scura. **Ricodificato con
  `-strip` prima del caricamento**: il sito di origine contiene link SEO
  nascosti iniettati (`beautystic`, `replica-watches`, ecc.) nel `<body>`,
  segno che l'installazione Joomla e' compromessa - dal file caricato sono stati
  buttati via tutti i metadati e sono rimasti solo i pixel. Il logo e' servito
  dal nostro server, non agganciato al loro.
  Per i **gemellaggi** nessun logo caricato: vale la cautela di *Il Romanista*,
  il logo e' dell'altro club e va chiesto a loro.
- **Avviso privacy sopra il modulo di tesseramento** (pagina 902, Elementor).
  Il modulo JotForm raccoglie nome, data e luogo di nascita, indirizzo, telefono,
  e-mail, **tipo e numero di documento** e i dati di eventuali familiari, ma
  nella pagina non c'era alcun rimando all'informativa: l'art. 13 GDPR chiede
  che sia resa al momento della raccolta. Aggiunto un riquadro sopra il modulo
  (widget HTML inserito come primo elemento della colonna, prima di quello con
  lo script JotForm) con finalita' in una riga, menzione di JotForm come
  fornitore, link all'informativa e indirizzo `privacy@romaclubmatera.it`.
  Testo scuro su fondo bianco dichiarato esplicitamente, come per le card degli
  sponsor: la pagina e' a fondo nero e altrimenti sarebbe stato invisibile.
  Backup del layout precedente in `/root/elementor_data_902_backup_*.json`.
  **Restano due cose da correggere dentro JotForm** (modulo `251772457622360`,
  non modificabile da qui, serve l'account JotForm):
  1. la spunta *"Autorizzo il trattamento dei dati per le finalita' di
     marketing"* e' **obbligatoria** (`validate[required]` sull'input,
     `jf-required` sul contenitore): senza spuntarla non si puo' inviare la
     richiesta di tesseramento. Un consenso marketing obbligatorio non e'
     liberamente prestato (artt. 4.11 e 7.4 GDPR) e **contraddice la nostra
     stessa informativa**, che dichiara quel consenso facoltativo e ininfluente
     sul tesseramento. Va reso non obbligatorio.
  2. nel modulo non c'e' alcun link all'informativa ne' una presa visione: da
     aggiungere come campo dedicato, obbligatorio, distinto dal consenso
     marketing.
- **Informativa privacy aggiornata per le recensioni** (pagina 905). Il modulo
  chiedeva il consenso e rimandava all'informativa, ma l'informativa non parlava
  delle recensioni: il link puntava a un testo che non copriva quel trattamento.
  Aggiunti: la voce *Dati delle recensioni* (nome, citta', e-mail, testo)
  nell'elenco dei dati trattati; la finalita' *Pubblicazione delle recensioni*
  con base giuridica il consenso (art. 6.1.a) e la revoca; i tempi di
  conservazione (online fino a revoca, non approvate eliminate); *recensioni*
  fra i moduli protetti da Turnstile; la facoltativita' dell'invio in *Natura
  del conferimento*; e una sezione dedicata **Recensioni dei soci** nello stile
  di quelle su JotForm e Turnstile, che chiarisce cosa viene pubblicato (nome,
  citta', voto, testo), cosa no (l'e-mail), che nulla va online senza
  approvazione e come chiedere la rimozione. Data dell'informativa portata ad
  agosto 2026. Backup del testo precedente in `/root/privacy_backup_*.html`.
  Corretto anche il modulo: per la rimozione rimandava a `info@`, ora a
  `privacy@` come il resto dell'informativa.
- **Rimando a "Il Romanista" nel footer.** Nuovo mu-plugin `rcm-romanista.php`:
  card in fondo alla colonna destra del footer, sotto *Contatti*, separata dal
  blocco iscrizione da una riga sottile a tutta colonna. Si aggancia a
  `dynamic_sidebar_after` sulla sidebar `footer4`, quindi senza toccare il
  footer builder del tema. Stile nella sezione "Il Romanista" di
  `rcm-custom.css`.
  Provata anche la variante con una quarta colonna dedicata (theme mod
  `footer_middle_columns` a 4 + aggancio a `thewebs_render_footer_column`):
  funziona, ma stringe tutte le colonne da 433 a 325px e manda a capo il campo
  e-mail della newsletter, quindi e' stata scartata. Se un giorno servisse:
  `thewebs()->option()` **non passa da nessun filtro**, il numero di colonne si
  cambia solo scrivendo il theme mod.
  **E' un link, non la locandina**: la prima pagina e' opera dell'editore e
  riprodurla sul sito - anche scaricandola in automatico, anche con credito -
  richiede l'autorizzazione della redazione. Per lo stesso motivo la testata e'
  scritta in testo e non col loro logo. Tecnicamente la locandina sarebbe
  banale: la pubblicano a un indirizzo fisso che sostituiscono ogni giorno.
  Il codice e' gia' predisposto: il filtro `rcm_romanista_locandina_url`, se
  restituisce un indirizzo, fa mostrare l'immagine al posto del testo tenendo il
  link sotto. Da accendere **solo** dopo l'ok scritto, e servendo una copia dal
  nostro server invece di agganciare la loro immagine.
- **Recensioni dei soci: raccolta, moderazione e striscia scorrevole.** Nuovo
  mu-plugin `rcm-recensioni.php` (+ `rcm-recensioni/recensioni.css`), versionato
  in `roles/wordpress/files/`.
  *Raccolta*: pagina **Recensioni** (ID 1323, `/recensioni/`, voce di menu fra
  *Sponsor* e *Contattaci*) con modulo - nome, citta', e-mail, voto in stelle,
  testo 30-600 caratteri, spunta di consenso obbligatoria e link alla privacy
  policy. Antispam a tre livelli: nonce, campo-trappola nascosto e Turnstile.
  *Moderazione*: le recensioni arrivano come **bozze in attesa**, niente va
  online da solo; avviso via e-mail a `info@` con il link per approvare.
  *Archivio*: tipo di contenuto `rcm_recensione` (non pubblico, nessuna pagina
  propria), con voto/citta'/e-mail nel pannello laterale e colonne in elenco.
  L'e-mail non viene mai pubblicata.
  *Vetrina*: striscia agganciata a `thewebs_before_footer`, quindi **sopra il
  footer** e su tutte le pagine tranne `/recensioni/` (dove le schede ci sono
  gia'). Scorrimento continuo in CSS puro, senza JavaScript: la lista e'
  duplicata e l'animazione trasla del 50%, cosi' il giro si richiude senza
  salti. Si ferma al passaggio del mouse e col focus da tastiera. Da telefono e
  con `prefers-reduced-motion` l'animazione sparisce e diventa una striscia da
  sfogliare col dito.
  *Soglia*: sotto le 3 recensioni pubblicate la striscia non compare - una
  vetrina mezza vuota fa peggio di nessuna vetrina. Per vedere che aspetto avra'
  prima di averle, `?rcm_anteprima=1` mostra tre schede finte tenute in memoria
  (mai salvate, mai visibili ai visitatori) a chi e' collegato e puo' scrivere.
  *Turnstile*: il widget lo disegna il plugin con lo shortcode
  `[simple-turnstile]` e la verifica passa da `cfturnstile_check()`, cosi'
  valgono le impostazioni del pannello. Attenzione: il plugin espone anche
  l'action `cfturnstile_display_widget`, ma il suo callback *restituisce* la
  stringa invece di stamparla e `do_action` scarta i valori di ritorno - da li'
  esce un div vuoto. Da non caricare una seconda copia di `api.js`: il plugin la
  serve in modalita' `explicit` e la doppia inclusione impediva il rendering.
- **CSS su misura spostato dal database al tema figlio.** Stava in
  *Aspetto > Personalizza > CSS aggiuntivo* (post 867, tipo `custom_css`): 242
  righe che un ripristino da zero avrebbe perso, perche' nel repo non c'era
  niente. Ora e' `bestfoot-child/assets/css/rcm-custom.css`, versionato come
  `roles/wordpress/files/rcm-custom.css` e installato dal ruolo `wordpress`
  insieme allo snippet di aggancio (`blockinfile` con marker `RCM CUSTOM CSS`
  in `functions.php`). Nuova variabile `wp_child_theme_dir`; i task si saltano
  se il tema figlio non c'e' ancora, perche' arriva con `import-site.yml`.
  Nel campo del Customizer resta solo un commento che dice dov'e' finito il CSS.
  **Attenzione all'ordine di caricamento**: WordPress stampa il CSS del
  Customizer su `wp_head` con priorita' 101, cioe' *dopo* i fogli per-pagina di
  Elementor. Diverse regole (tabelle SportsPress del calendario, ruolo sotto le
  foto del direttivo) hanno la stessa specificita' di quelle generate da
  Elementor e vincevano solo perche' arrivavano dopo: con un normale
  `wp_enqueue_style` uscivano prima e si rompevano in silenzio. Il foglio quindi
  viene registrato e stampato a mano su `wp_head` 101, nello stesso punto di
  prima. Verificato l'ordine dei `<link>` e il risultato a video su home,
  direttivo, calendario, tesseramento e sponsor. Backup del contenuto
  precedente in `/root/custom_css_prima_dello_spostamento_*.css` e del
  `functions.php` in `/root/functions_bestfoot-child_backup_*.php`.
- **Hero della pagina Sponsor: bandiere del club al posto del fondo oro.**
  Sfondo con la foto `Gallery-48` (allegato 671, bandiere *Eterna Fedelta'* e
  *Presente* con le sciarpe alzate) e velo scuro in sfumatura, solo su
  `.page-id-1312` - le altre pagine tengono l'oro del tema. Sotto il titolo, il
  claim in romanesco *"Nun e' pubblicita': e' famija"* con il perche'
  sponsorizzare il club. L'hero e' un template del tema, quindi il testo e'
  agganciato all'action `thewebs_entry_hero` (priorita' 20, dopo titolo e
  breadcrumb) da un mu-plugin nuovo, `rcm-sponsor-hero.php`, invece che generato
  dal CSS: cosi' resta markup vero, leggibile da Google e dagli screen reader.
  Ridotti anche i margini di `.content-area` sulla sola pagina Sponsor, che con
  i 5-8em del tema lasciava due fasce nere vuote sopra e sotto le card.
  L'altezza dell'hero e' un `min-height` (400/300/180px per breakpoint): con il
  claim il contenuto la supera e da telefono arrivava a filo del bordo, quindi
  aggiunto padding verticale a `.entry-header`. Verificato con Chrome headless a
  360, 390, 768 e 1440px.
  Backup del CSS del customizer in `/root/custom_css_backup_*.css` sul CT.
- **Pagina "Sponsor"** (ID 1312, slug `/sponsor/`). Pagina classica come le
  altre segnaposto, quindi eredita hero e colori del tema; griglia flessibile di
  card bianche su fondo scuro, ognuna con logo, nome, categoria in oro
  `#e6af14`, una riga di descrizione e bottone *Visita il sito* verso il sito
  dello sponsor (`target="_blank"` + `rel="noopener noreferrer"`). Loghi
  **scaricati e ricaricati nella media library** del sito (niente hotlink verso
  i server degli sponsor): *Coppola Rossa Matera* (ID 1311, dal loro
  `LOGO-COPPOLA-ROSSA-600.png`) e *Amarena Garden House / Ristorante Lavanda*
  (ID 1315, dal loro `logo-black.png`). I due loghi hanno proporzioni molto
  diverse (600x371 contro 450x102): normalizzati con un box fisso alto 140px e
  `max-width/max-height`, cosi' le card restano allineate. Testi delle card
  scritti esplicitamente in scuro (`#1c1c1c` / `#3a3a3a`) perche' il tema e' a
  fondo nero e altrimenti sarebbero bianchi su card bianca. Pagina indicizzabile
  (nessun `noindex`). Nel menu **Primary** come voce di primo livello tra
  *Store* e *Contattaci*, con riordino di `menu_order` da 1 a 13.
- **Pagine segnaposto "in costruzione": Statuto, Regolamento e Store**
  (ID 1304, 1305, 1306). Pagine classiche (non Elementor), quindi ereditano
  l'hero col titolo e i colori del tema; contenuto centrato con badge
  "Lavori in corso" in oro `#e6af14`, due righe di spiegazione e rimando a
  `info@romaclubmatera.it` / `/contatti/`. Impostato
  `_yoast_wpseo_meta-robots-noindex = 1`, cosi' Yoast le tiene fuori dalla
  sitemap finche' non avranno contenuto vero.
  Nel menu **Primary**: *Statuto* e *Regolamento* come voci figlie di
  *Chi siamo* (dopo *Direttivo*), *Store* al primo livello subito prima di
  *Contattaci*. Riordinato tutto il menu con `menu_order` da 1 a 12 per evitare
  posizioni duplicate.
- **Blocco "Ultime news" in home** (pagina 12, Elementor): nuova sezione subito
  sotto "Maciniamo chilometri ... superiamo gli ostacoli..." e prima de
  "Il Direttivo". Mostra i 3 articoli piu' recenti della categoria *News* con
  widget **Post Magazine Grid** (Unlimited Elements), lo stesso che il tema usava
  nella sezione blog: impostazioni riprese dalla revisione 594 della home, cosi'
  tipografia e bottoni restano quelli del tema. Data in italiano sopra il titolo,
  velo scuro al 35% sulla foto per la leggibilita', bottone "Tutte le news"
  verso `/news/`. Backup del layout precedente in
  `/root/elementor_data_12_backup_20260818-2136.json` sul CT.

### Modificato
- **Pagina "Direttivo" (ID 236): nome e ruolo sotto la foto.** Il tema mostrava
  il nome *sopra* la foto e solo al passaggio del mouse (classe `.our-text`,
  `opacity: 0`, tirata su con `margin-top: -22em` e `offset_y` per breakpoint),
  quindi da fermo le 12 caselle erano solo foto senza didascalia. Ora per ogni
  membro: foto, **nome** in bianco 24px sotto la foto e, sotto, una riga di
  **ruolo/descrizione** (widget testo, classe `our-role`, oro `#e6af14`,
  maiuscoletto 13px) pronta per essere riscritta dalla redazione. Compilati i
  ruoli noti dalla pagina *Chi siamo* - Vito Plasmati *Presidente*, Rino Di
  Gennaro *Vice presidente* - gli altri 10 restano "Membro del direttivo".
  Rimossi i 12 widget **social-icons**: puntavano tutti a `#` ed erano visibili
  solo in hover sopra la foto, posizione che il nuovo layout non lascia libera.
  In *Aspetto > Personalizza > CSS aggiuntivo* il velo oro pieno sull'hover
  (serviva a far leggere quelle icone) diventa una velatura al 18%, cosi' la
  foto resta visibile. Portata a `fast` anche qui la dissolvenza d'ingresso,
  come gia' fatto in home. Backup del layout in
  `/root/elementor_data_236_backup_20260819-2348.json` sul CT.
- **Articolo "Iscriversi al Roma Club Matera"** (ID 210): il titolo iniziava in
  minuscolo e lo slug era ancora quello demo del tema
  (`/football-is-the-ballet-of-the-masses/`). Ora e'
  `/iscriversi-al-roma-club-matera/`; il vecchio indirizzo risponde 301 sul nuovo
  grazie al redirect nativo di WordPress sugli slug storici.
- **Animazioni d'ingresso della home da "slow" a "fast"** (pagina 12): ogni
  sezione nasce con `elementor-invisible` (`visibility: hidden`) e compare in
  dissolvenza entrando nel viewport. Con la durata "slow" (2 s) scorrendo si
  vedevano bande nere al posto delle sezioni non ancora comparse - "Il Direttivo",
  che ha una foto di sfondo, sembrava avere lo sfondo nero. Ora `animation_duration`
  e' `fast` (0,8 s) su tutte e 8 le sezioni animate; lo slider resta senza
  animazione, com'era in origine.
- **Immagini del tema portate in locale** (nessun contenuto dipende piu' dal sito
  demo `themes.webswaala.com`): 40 URL distinte lo referenziavano - 37 avevano
  gia' il file in `uploads/`, mancavano solo `blog-img-01.jpg` e `blog-img-02.jpg`,
  scaricati sul CT. Riscritte **227 occorrenze** su tutto il database
  (`wp search-replace`, 109 in forma normale + 118 con le slash escapate dentro
  `_elementor_data`, che la prima passata non intercetta). Toccava anche link a
  pagine del demo (`?p=194`, `?page_id=239`) e i `guid` di 54 allegati. L'unico
  contenuto vivo coinvolto oltre alla home era la pagina *Chi siamo* (ID 16).
  Verificate 84 immagini fra home e Chi siamo, incluse quelle nel CSS generato da
  Elementor: nessuna rotta. Backup del database prima della sostituzione in
  `/root/db-pre-webswaala-20260818-2251.sql` sul CT.
- **Widget "Articoli recenti"** (barra laterale e footer, blocchi
  `core/latest-posts`): limitati alla categoria *News*, cosi' l'elenco resta
  quello delle news correnti.
- **Articoli 206 e 208** ("Grazie di tutto: la serata sociale" e "Dal 2012 a oggi:
  la storia del Roma Club Matera") messi in bozza su richiesta: `/news/` mostra
  ora solo Match Day Roma-Fiorentina, Roma-Inter, Iscriviti al Roma Club e
  La nostra sede.
- **"Le trasferte dei tifosi" rinominata "Le nostre trasferte"** nella home
  (Elementor, pagina 12), nella voce di menu 250 e nel titolo della pagina 239.
  Lo slug `/trasferte/` non cambia.

- **Widget Contatti nel footer**: il link dell'email puntava al segnaposto
  `mailto:football@gmail.com` pur mostrando `info@romaclubmatera.it` — chi ci
  cliccava scriveva a un indirizzo inesistente. Corretto il `mailto:`; il numero
  di telefono, che era un link morto `href="#"`, e' ora un link `tel:`.

### Rimosso
- **Sezione Eventi**: i 24 articoli storici (ID 1238-1261) sono nel cestino, la
  voce di menu 249 e la categoria *Eventi* (term 32) sono eliminate,
  `/category/eventi/` risponde 404. Le 219 foto restano nella libreria media su
  richiesta. Export WXR di sicurezza in `/root/backup-eventi/` sul CT.
  Gli slug degli articoli erano occupati dagli allegati omonimi (i vecchi URL
  finivano in 301 su un file `.jpg`): rinominati con prefisso `foto-`, cosi' i
  vecchi indirizzi rispondono 404 e gli slug restano liberi in caso di ripristino.
- **mu-plugin rcm-news-query**: serviva a tenere la categoria *Eventi* fuori da
  `/news/`, che senza quella categoria non ha piu' motivo di esistere.

## [1.1.0] - 2026-08-10

Personalizzazioni del sito del Roma Club Matera: calendario partite, SEO,
posta in uscita, anti-spam e auguri di compleanno ai soci.

### Aggiunto
- **mu-plugin rcm-compleanni** (nel ruolo wordpress): anagrafica soci in tabella
  dedicata `<prefisso>_rcm_soci` con import CSV idempotente sull'email, e invio
  automatico degli auguri di compleanno via il relay SMTP del sito. Menu *Soci* in
  bacheca (elenco, import, impostazioni auguri con segnaposto, prova di invio,
  prossimi compleanni a 30 giorni). Anti-doppione con `ultimo_invio_anno`; il 29
  febbraio slitta al 28 negli anni non bisestili. L'invio nasce spento.
  I soci non sono utenti WordPress: la lista la importa il cliente.
- **cron di sistema per WP-Cron** (ruolo wordpress): `wp cron event run --due-now`
  ogni 15 minuti come `www-data`. WP-Cron da solo parte con le visite e su un sito
  a basso traffico gli invii pianificati uscirebbero in ritardo.
- **roles/sportspress_fixtures**: aggiornamento giornaliero degli orari delle
  partite SportsPress dalla API football-data.org (systemd timer 07:15,
  script via `wp eval-file`, token in vault `vault_football_data_token`).
  Aggiorna solo i match con orario ufficializzato (status `TIMED`+), per non
  sovrascrivere il calendario ufficiale della Lega con date provvisorie.
  Nel play principale con tag `sportspress` / `fixtures`.
- **mu-plugin rcm-next-match** (nel ruolo sportspress_fixtures): evidenzia la
  riga della prossima partita nelle tabelle event-list, mostra "da definire"
  al posto di 0:00 per gli orari non ufficializzati (calendario e banner) e
  svuota le cache quando un evento programmato viene pubblicato.
- **Email in uscita via WP Mail SMTP** (ruolo wordpress): plugin installato e
  configurato con costanti `WPMS_*` in `wp-config.php`, relay autenticato su
  porta 587 con TLS e mittente forzato. Password dal vault
  (`vault_smtp_password`), si attiva con `enable_smtp`. Sostituisce Site
  Mailer, rimosso dal live perche' non tracciava alcun invio.
- **Anti-spam Cloudflare Turnstile** (ruolo wordpress): plugin
  `simple-cloudflare-turnstile` su Contact Form 7, commenti, login e
  registrazione, in modalita' invisibile (interaction-only). Chiavi dal vault;
  la protezione si attiva solo quando le chiavi sono valorizzate, cosi' una
  configurazione incompleta non chiude fuori nessuno dalla bacheca.
- **Yoast SEO e permalink parlanti** (ruolo wordpress): plugin installato e
  struttura permalink `/%postname%/`.
- **mu-plugin rcm-image-sizes** (ruolo wordpress): registra la size
  `rcm_gallery_16_9` (800x450, crop). Elementor non genera i crop `custom` dal
  frontend, quindi il widget image-gallery usa questa size registrata.
- Logo del club in SVG nella radice del repo.

### Modificato
- **Commenti e ping chiusi di default** (ruolo wordpress):
  `default_comment_status` e `default_ping_status` a `closed` sui nuovi
  contenuti.

### Corretto
- **robots.txt**: il template nginx serviva un 404 al posto del robots virtuale
  di WordPress/Yoast (che contiene il riferimento alla sitemap). Aggiunto
  `try_files` cosi' la richiesta arriva a WordPress quando il file non esiste.

## [1.0.3] - 2026-06-30

Migrazione di un sito esistente da backup "Backup Migration" (BMI).

### Aggiunto
- **scripts/import-site.sh** + **import-site.yml** + `make import ZIP=...`: importano
  un backup BMI nel sito nuovo. Fanno un backup di sicurezza, reimportano il DB
  (rinominando il prefisso temporaneo del dump), sincronizzano `wp-content`,
  eseguono il search-replace del dominio, impostano l'admin con la nuova password,
  riattivano Redis e sistemano permessi e cache. Le credenziali DB restano le nuove.

### Corretto
- import: `home`/`siteurl` aggiornati solo se diversi (no errore "unchanged"
  dopo il search-replace); upgrade `http://` -> `https://` per evitare mixed-content.
- import: `rsync` tollerante ai codici 23/24 (delete parziali non fatali).
- import: i comandi post-import girano con `--skip-plugins --skip-themes`, così un
  plugin con file incompleti (es. Elementor a metà aggiornamento) non blocca la migrazione.
- import: rigenerazione automatica del CSS di Elementor dopo il cambio dominio.

## [1.0.2] - 2026-06-30

Correzioni emerse dal deploy reale in produzione e miglioramenti di idempotenza.

### Corretto
- **common**: rimossa la ricorsione infinita su `users` (era un self-reference
  `users: "{{ users | default([]) }}"` nell'include del ruolo). Il default `users: []`
  vive ora in `group_vars` (vars.yml.example). Verificato a runtime.
- **ansible.cfg**: il callback `yaml` (rimosso da `community.general` 12+) è sostituito
  da `ansible.builtin.default` con `callback_result_format = yaml`. Output invariato.
- **phpmyadmin**: la `location` è ora servita dal vhost (ruolo `webserver`), non più
  iniettata con `blockinfile` — niente "togli e rimetti" a ogni run.
- **phpmyadmin**: `blowfish_secret` persistente (`/var/lib/phpmyadmin/blowfish.secret`),
  non più rigenerato a ogni deploy (niente logout delle sessioni).

### Aggiunto
- **teardown.yml** + `make teardown CONFIRM=PULISCI`: ripulisce il CT per ripartire da
  zero (servizi, pacchetti, dati, cron, backup, UFW, certificati locali) senza distruggere
  il container e senza revocare i certificati su Let's Encrypt.
- **inventory/hosts.yml.example**: l'inventario reale (`hosts.yml`) è ora escluso dal repo,
  come `vars.yml` e `vault.yml`. CI e `make init` lo materializzano dall'esempio.

## [1.0.0] - 2026-06-30

Prima release stabile. Deploy testato in produzione su un CT Debian 13 (Trixie)
in Proxmox, con HTTPS valido.

### Aggiunto
- Playbook `site.yml`: stack completo Nginx + PHP-FPM 8.4 + MariaDB + Redis + WP-CLI.
- Bootstrap automatico di `python3` per immagini Debian minimal.
- Ruolo `common` con integrazione del ruolo base `mikysal78.ninux_common`.
- Ruolo `hardening`: UFW, fail2ban, SSH drop-in, sysctl (`/etc/sysctl.d/`),
  unattended-upgrades, utente sudo non-root.
- Ruolo `database`: MariaDB con tuning InnoDB in base alla RAM del CT.
- Ruolo `php`: PHP-FPM 8.4, estensioni, OPcache e pool auto-dimensionati.
- Ruolo `redis`: object cache con limiti di memoria.
- Ruolo `webserver`: Nginx, vhost, micro-cache FastCGI, security headers.
- Ruolo `wordpress`: install via WP-CLI, salts, plugin redis-cache, permessi sicuri.
- Ruolo `phpmyadmin`: download, path non standard, Basic Auth.
- Ruolo `backup`: directory, script con retention, cron giornaliero.
- Playbook standalone `letsencrypt.yml` con staging, force e hook di reload al rinnovo.
- Riepilogo accessi con credenziali + file `credentials-<dominio>.txt` (0600).
- `Makefile` con scorciatoie (deploy, https, backup, lint, vault).
- CI GitHub Actions: `yamllint` + `ansible-lint` (profilo `production`).
- README dettagliato e file di esempio `vars.yml.example` / `vault.yml.example`.

### Note
- `vars.yml` e `vault.yml` sono esclusi dal repo: si creano dai rispettivi `.example`.
- Dipendenze Galaxy installate in `galaxy_roles/` e `collections/` (non versionate).

[1.1.0]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.1.0
[1.0.3]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.0.3
[1.0.2]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.0.2
[1.0.0]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.0.0
