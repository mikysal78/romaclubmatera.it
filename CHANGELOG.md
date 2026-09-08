# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate qui.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/)
e il versionamento [SemVer](https://semver.org/lang/it/).

## [Unreleased]

### Aggiunto

- **Biglietteria: una richiesta per partita**
  (`roles/wordpress/files/rcm-biglietti.php`). Nelle tabelle del calendario
  c'e' una colonna in piu' con il link *Biglietteria* accanto a ogni gara
  **ancora da giocare** - su una partita finita sarebbe un invito a chiedere
  biglietti per qualcosa che e' successo. Il link porta a `/biglietti/` con
  la partita attaccata all'indirizzo, e il modulo si apre gia' compilato.
  La colonna si aggancia agli hook `sportspress_event_list_head_row` e
  `sportspress_event_list_row`: SportsPress non viene toccato, quindi un suo
  aggiornamento non porta via niente.
  Il modulo e' un Contact Form 7 (numero di biglietti, nome, cognome,
  telefono, email, settore, note) e arriva a `info@`. Il campo della partita
  e' di sola lettura e obbligatorio: **senza partita il modulo non si mostra
  affatto**, perche' una richiesta che non dice per quale gara e'
  inservibile per chi la riceve e una delusione per chi l'ha scritta.
  Accanto c'e' lo schema dei quattro settori dell'Olimpico, **disegnato qui**
  in SVG (2 KB): le piantine della societa' e delle biglietterie sono opere
  protette e non si copiano, mentre quali siano i settori e' un fatto.
  L'anello e' diviso sulle diagonali e non sugli assi, cosi' ogni settore sta
  tutto in un colore e la sua etichetta non finisce a cavallo di due fondi.
  Il disegno e' ovale e verticale, col campo girato e le porte in alto e in
  basso: **le curve stanno dietro le porte**, e nella prima versione erano
  finite sui lati lunghi. Girato il campo torna anche la geografia vera -
  Nord a nord, Sud a sud, Monte Mario a ovest e il Tevere a est.
  Aggiornata anche la privacy policy: dati raccolti, finalita' (misure
  precontrattuali richieste dall'interessato, art. 6.1.b) e conservazione.
  Il modulo non raccoglie pagamenti, e l'informativa lo dice.
  I settori sono quelli veri, non i quattro macro-settori della prima
  versione: sul lato Tevere ci sono anche i **Distinti Nord** e **Sud**, che
  sono poi quelli in cui il Club prende posto di solito. La **Curva Sud e'
  fuori dalla tendina** ed e' disegnata in grigio con la dicitura "solo
  abbonati": lasciarla selezionabile avrebbe fatto chiedere l'unica cosa che
  non si puo' avere.
  In pagina si dice che **gli accrediti online sono solo informativi** - non
  una vendita - e che chi li chiede viene ricontattato via email, telefono o
  WhatsApp; e che il servizio e' **riservato ai tesserati**, con il link al
  tesseramento per chi non lo e' ancora e una casella di conferma nel
  modulo. Le richieste dei Roma Club infatti passano dall'Unione Tifosi
  Romanisti, che tiene la biglietteria per i club affiliati
  (`biglietti.utr@gmail.com` per le casalinghe,
  `trasferteeuropa.utr@gmail.com` per le gare in Europa).

- **La locandina de Il Romanista e' accesa.** L'autorizzazione della
  redazione e' arrivata, e la card in fondo alla colonna destra del footer
  mostra la prima pagina del giorno al posto del testo. Il filtro
  `rcm_romanista_locandina_url` era gia' pronto da mesi proprio per questo:
  non c'e' stato niente da riscrivere nel markup.
  Due scelte che restano quelle di prima: l'immagine si **copia** sul nostro
  server due volte al giorno, non si aggancia alla loro (un hotlink consuma
  banda altrui e si rompe appena cambiano un percorso); e se la copia di
  oggi non c'e', la card torna al testo - meglio nessuna locandina che la
  prima pagina di ieri spacciata per quella di oggi.
  L'originale pesa 2,1 MB a 1831x2599: nel footer di ogni pagina sarebbe
  stato assurdo per una figura alta poco piu' di trecento pixel. Viene
  ridotta a 480 px di larghezza, **76 KB**. Sotto l'immagine c'e' il credito
  alla testata.
  Aggiunta anche a `nascondi_sempre` del controllo visivo: cambia ogni
  giorno, che e' il suo mestiere, e senza quello avrebbe segnalato una
  differenza su ogni pagina ogni mattina.

- **Le pagine delle partite si presentano nei motori**
  (`roles/wordpress/files/rcm-eventi-seo.php`). Titolo con la data dentro
  (`AS Roma-Real Madrid, 14 ottobre 2026`), descrizione generata dai dati
  che ci sono gia' - squadre, giorno, ora, stadio, giornata, e il
  risultato per le partite giocate - e `SportsEvent` nei dati strutturati.
  I dati strutturati escono **solo per le 38 partite che hanno lo stadio**:
  Google, per un evento dal vivo, pretende nome, data e luogo *con
  indirizzo*, e senza quello il markup non e' incompleto, e' sbagliato, e
  finisce in Search Console come errore. Le diciotto sedi di Serie A hanno
  indirizzo e coordinate in archivio; le otto di Champions no, perche'
  football-data non manda il campo, e per quelle restano titolo e
  descrizione.
  Nota di lingua: la localizzazione di WordPress scrive "31 Agosto 2026",
  ma in italiano il mese vuole la minuscola e in un titolo di ricerca la
  maiuscola di troppo si nota.

- **Redirect permanenti per i vecchi indirizzi**
  (`webserver_redirect_permanenti` nel ruolo `webserver`). `/one-team-one-goal/`
  e' un contenuto demo del tema, cancellato da tempo: Google lo tiene in
  posizione 4,5 e in due mesi ha portato **nove clic atterrati su "Pagina
  non trovata"**, cioe' il 13% dei clic del sito. Ora e' un 301 verso la
  home. Consolidati sulla pagina `/calendario/` anche i tre indirizzi
  `/calendar/...` dei calendari SportsPress, che da soli valevano altri sei
  clic e 772 impressioni.

- **Indirizzo, telefono, email e anno di fondazione nei dati strutturati**
  (`roles/wordpress/files/rcm-schema-club.php`). Il nodo Organization che
  Yoast stampa in ogni pagina diceva solo nome, sito e logo: ai motori di
  ricerca il Club era un nome senza un posto nel mondo.
  Il tipo resta `Organization` e basta. La tentazione era `SportsClub`, ma
  in schema.org quello e' un *luogo dove si pratica sport*, e un club di
  tifosi non lo e': sarebbe stata una parola in piu' e un'informazione
  sbagliata.

- **Privacy policy: sezione sugli auguri di compleanno.** L'informativa non
  diceva che il Club tiene un'anagrafica dei soci sul sito ne' che la data
  di nascita serve a mandare gli auguri, e da oggi c'e' di mezzo anche il
  numero di cellulare per WhatsApp. Aggiunta la sezione, la voce
  nell'elenco dei dati trattati, la finalita' con la sua base giuridica
  (legittimo interesse, art. 6.1.f, con diritto di opposizione) e la riga
  sulla conservazione. La parte newsletter era gia' completa - dati,
  consenso, Mailchimp col trasferimento negli Stati Uniti - e il report
  settimanale non aggiunge destinatari: e' il Club che scrive a se' stesso.

- **Report settimanale della newsletter**
  (`roles/wordpress/files/rcm-newsletter-report.php`). Ogni lunedi' alle
  9:00 arriva a `info@romaclubmatera.it` il punto sugli iscritti: quanti
  sono, chi e' entrato e chi e' uscito negli ultimi sette giorni, e il
  confronto con la settimana prima - un numero da solo non dice se si sta
  salendo o scendendo. Si regola da Impostazioni > Report newsletter, con
  un pulsante che manda subito il report vero, per vederlo senza aspettare
  lunedi'.
  A differenza del promemoria compleanni parte anche a settimana vuota: e'
  un controllo periodico, e il silenzio sarebbe ambiguo - non si saprebbe
  se non e' successo niente o se si e' rotto qualcosa.
  La chiave API di Mailchimp non si configura qui: si legge da MC4WP, che
  ce l'ha gia'. Due copie della stessa chiave sono due cose da cambiare il
  giorno che si rigenera, e la seconda ci si scorda.

- **Compleanni: CSV di esempio da scaricare, tasto di modifica e promemoria
  alla vigilia** (`roles/wordpress/files/rcm-compleanni.php`).
  - Nella pagina di import un pulsante scarica un `soci-esempio.csv` gia'
    impostato, col BOM perche' Excel su Windows senza quello apre il file in
    ANSI e le accentate diventano scarabocchi (all'import il BOM viene tolto
    da se'). Il riquadro mostrato nella pagina e il file scaricato escono
    dalla stessa funzione: scritti due volte, prima o poi avrebbero detto
    cose diverse.
  - *Modifica* accanto a ogni socio riapre in fondo alla pagina lo stesso
    modulo dell'inserimento, gia' compilato. L'email e' la chiave unica
    della tabella: cambiarla in una gia' presente viene fermato con un
    messaggio, invece che con un errore di database. Aggiunta anche la
    casella *riceve gli auguri*, per chi non e' piu' socio e non si vuole
    cancellare dall'archivio.
  - Il giro giornaliero manda a `info@romaclubmatera.it` una sola email coi
    compleanni del giorno dopo e il link WhatsApp di ciascuno. Se domani non
    compie gli anni nessuno non parte niente: un promemoria vuoto tutte le
    mattine si impara a ignorare, e il giorno che serve non lo si legge. Il
    suo interruttore e' separato da quello degli invii, perche' si puo'
    volere il promemoria mandando gli auguri solo su WhatsApp.

- **Gli auguri di compleanno anche su WhatsApp**, a mano ma col testo gia'
  scritto (`roles/wordpress/files/rcm-compleanni.php`). Nella tabella dei
  prossimi compleanni ogni socio che ha lasciato il cellulare ha accanto il
  pulsante *Auguri su WhatsApp*: apre `wa.me` col messaggio pronto e i
  segnaposto sostituiti, e a premere invio e' una persona. Chi compie gli
  anni oggi ha il pulsante pieno.
  Automatizzarlo davvero avrebbe voluto dire la Cloud API di Meta: numero
  dedicato che non puo' stare anche sull'app normale (quello del club
  invece ci sta), verifica dell'azienda, template approvato in categoria
  marketing quindi a pagamento a messaggio, consenso da raccogliere al
  tesseramento. Le librerie non ufficiali si portano dietro il rischio che
  il numero pubblico del club venga bannato. Il pulsante costa zero e si
  puo' usare da subito; se un giorno la lista cresce, il lavoro fatto
  (colonna telefono, import, consenso) serve identico anche all'API.
  Schema dei soci alla versione 1.1 con la colonna `telefono`, import CSV
  che riconosce `cellulare`/`telefono`/`whatsapp` e normalizza i numeri in
  cifre pure con prefisso internazionale, campo nel modulo di inserimento
  manuale e testo del messaggio WhatsApp configurabile a parte (vuoto =
  quello dell'email).

- **I pulsanti social si aprono in una scheda nuova**
  (`roles/wordpress/files/rcm-link-social.php`). Chi cliccava Facebook o
  Instagram dal footer usciva dal sito e perdeva la pagina su cui stava.
  Il tema non li apre mai in una scheda nuova e non ha un'opzione per
  farlo: l'unico filtro che sembra servire, `thewebs_social_link_target`,
  in realta' decide soltanto se stampare il `rel`. Il mu-plugin apre un
  buffer attorno ai tre blocchi social del tema (intestazione, menu
  mobile, footer) e aggiunge `target="_blank"` ai soli link che portano
  fuori: telefono e email restano come sono, perche' `tel:` e `mailto:`
  non aprono una pagina e una scheda vuota che si chiude da sola e' solo
  fastidio. Aggiunge anche `rel="noopener noreferrer"` se manca, e
  annota nell'`aria-label` che la scheda e' nuova - chi naviga con un
  lettore di schermo altrimenti non se ne accorge.

- **Il calendario della Champions e' in pagina.** Il sorteggio della fase
  campionato e' uscito e football-data ha aperto la stagione 2026/27: il
  timer del mattino ha creato da solo gli otto eventi della Roma (post
  1431-1452) con squadre e stemmi, senza che nessuno toccasse niente.
  La pagina `/calendario/` ora mostra due tabelle, Serie A e Champions
  League, e i due calendari SportsPress si chiamano come le competizioni
  (`Serie A 2026/27`, `Champions League 2026/27`): i loro titoli finiscono
  in pagina come intestazioni di sezione, e sotto un H1 che dice gia'
  "Calendario AS Roma" ripetere "Calendario" due volte non aggiungeva
  niente.
  Nel calendario della coppa la colonna Stadio e' spenta: football-data
  non manda il campo `venue` per la Champions e sarebbero state otto righe
  di "N/D".

- **Gli eventi delle coppe se li crea lo script**
  (`roles/sportspress_fixtures/files/create-fixtures.php`). Fino a ieri il
  ruolo sapeva solo *aggiornare* eventi che dovevano gia' esserci: per la
  Serie A va bene, il calendario esce tutto a luglio e si carica una volta
  sola, ma una coppa no. Il sorteggio della fase campionato arriva a fine
  agosto e i turni a eliminazione si conoscono uno alla volta, a mesi di
  distanza: senza qualcosa che li crei, ogni turno andava inserito a mano
  indovinando gli stessi nomi che poi update-fixtures cerca.
  Lo script crea anche le squadre avversarie mancanti, con lo stemma preso
  dalla API, e se una squadra c'e' gia' in un'altra competizione le
  aggiunge lega e stagione invece di duplicarla.
  Spento di default, si accende per competizione con `crea: true`: sulla
  Serie A resta spento apposta, perche' un abbinamento mancato non
  sovrascriverebbe l'evento esistente ma ne creerebbe un secondo.
  `SP_CREA_DRY=1` fa vedere cosa farebbe senza scrivere niente.

- **Champions League configurata** (`crea: true`, `classifica: false`) e
  termine `sp_league` creato sul sito insieme al calendario dedicato
  (post 1353). La Roma e' arrivata terza in Serie A 2025/26 e va ai gironi.
  Quando questa voce e' stata scritta football-data non aveva ancora
  aperto la stagione 2026/27 della coppa e lo script lo annotava nel log
  passando oltre: e' stato il comportamento giusto, il sorteggio e'
  arrivato dopo e non e' servito rimettere le mani su niente. La classifica
  resta spenta perche' la fase campionato e' una tabella da 36 squadre e
  sul sito ci sono solo la Roma e le sue otto avversarie: verrebbe una
  classifica di nove righe, cioe' una cosa sbagliata detta con sicurezza.

- **Template Mailchimp per il tesseramento**
  (`newsletter/mailchimp-tesseramento.html`), caricato sull'account come
  *Tesseramento Roma Club Matera*. Struttura diversa dalla newsletter,
  perche' diverso e' il compito: una sola cosa da chiedere, e la si chiede
  subito. Foto della sede a tutta larghezza, fascia rossa col titolo, i
  quattro vantaggi presi dall'articolo "Iscriversi al Roma Club Matera",
  il riquadro della quota, il bottone al modulo JotForm e i tre passi
  dell'iscrizione. Dieci zone `mc:edit`, nessun blocco ripetibile.
  L'importo e' lasciato a `€ 00,00` con sotto la riga "Importo da inserire
  prima dell'invio": un segnaposto che si nota, invece di una cifra
  inventata che potrebbe partire cosi' com'e'.

### Corretto

- **Il certificato lo deposita ansible-dns, non questo progetto.** Il vhost
  ora porta il blocco 443 dentro il proprio template, puntato a
  `/etc/ssl/acme/<dominio>.fullchain.pem`, e certbot esce dal giro
  (`webserver_certbot: false`). Prima l'HTTPS lo iniettava certbot nello
  stesso file che il ruolo riscrive, quindi due strumenti si contendevano
  il vhost e ogni passaggio del ruolo lasciava il sito sulla sola porta 80
  - che rimanda alla 443, cioe' irraggiungibile.
  Il blocco 443 si scrive solo se il certificato c'e' davvero: un
  `ssl_certificate` che punta al vuoto non degrada il servizio, impedisce a
  nginx di partire. Se manca, il ruolo lo dice e serve il solo HTTP finche'
  ansible-dns non l'ha depositato.
  Tolti anche gli `include` da `/etc/letsencrypt/`: su un host dove certbot
  non gira sono una dipendenza nascosta da una directory di un altro
  strumento. I parametri TLS (gli stessi, da ssl-config.mozilla.org) sono
  ora nel template. Approfittandone, **HTTP/2 acceso**: non c'era.
  La correzione di poche ore fa - far reinstallare certbot quando il vhost
  perde l'HTTPS - era il rimedio giusto per la diagnosi sbagliata: curava
  il sintomo tenendo in piedi la causa, cioe' certbot dentro un file che
  non gli appartiene.

- **Un passaggio del ruolo `webserver` lasciava il sito senza HTTPS.**
  Il vhost lo scrive il template del ruolo, che contiene solo il blocco
  sulla porta 80: l'HTTPS lo aggiunge certbot dentro lo stesso file. Il
  ruolo `letsencrypt` pero' si fermava a "il certificato esiste, non faccio
  niente", quindi dopo ogni riscrittura del vhost nessuno rimetteva il
  blocco 443, e al reload il sito restava raggiungibile solo in HTTP -
  cioe' irraggiungibile, perche' la porta 80 rimanda alla 443 che non
  ascolta piu'. E' successo davvero: tre minuti di sito giu'.
  Ora si controlla il vhost, non solo l'esistenza del certificato, e se
  l'HTTPS non c'e' certbot lo reinstalla (senza riemettere niente).
  Verificato rilanciando il ruolo: certbot rimette il blocco 443 subito
  dopo il template, e il reload arriva a fine play su una configurazione
  gia' completa. Il sito non e' andato giu' un solo istante.

- **Il dry-run di certbot partiva senza che nessuno lo chiedesse**, e da
  solo faceva durare minuti ogni passaggio sul webserver. Aveva il tag
  `never`, che di norma basta, ma questo ruolo arriva da un `include_role`
  dentro `webserver` e l'inclusione propaga ai task inclusi i tag del
  chiamante: con `--tags nginx` il task si ritrovava addosso anche `nginx`,
  che batte `never`. Ora la condizione e' esplicita
  (`letsencrypt_verify`), e i tag non la aggirano. Il ruolo passa da oltre
  400 secondi a 12.

- **SEO: il logo nel footer pesava 1 MB, scaricato in ogni pagina.**
  `footer-logo-2.svg` non era un disegno vettoriale: era un PNG da
  4000x5200 pixel impacchettato dentro un SVG, mostrato a 90x117. Ora
  dentro c'e' un PNG da 270x352: il file passa da 1077 KB a 141 KB e la
  home da 7,3 a 6,4 MB. Geometria dell'SVG e nome del file invariati,
  quindi nessun riferimento da aggiornare; l'originale e' in
  `/root/seo-backup/` sul server.
  Un primo tentativo riduceva i colori a 128 e scendeva a 72 KB, ma
  ingrandendo il confronto si vedeva la palette sui bordi e sui gradienti:
  su un logo, che e' l'identita' del Club, non vale i 70 KB risparmiati.
  Resta una differenza di circa il 3% dei pixel del logo, che non dipende
  dalla compressione ma dal ridimensionamento: il browser rasterizza a
  90 px partendo da 270 invece che da 4000, e l'antialiasing sui bordi
  cade un filo diverso. Alla dimensione a cui si vede, le due versioni
  sono indistinguibili.

- **SEO: pagine sottili e doppioni fuori dall'indice.** Le pagine evento di
  SportsPress (un tabellone e poco altro), gli archivi dei tag e l'archivio
  di categoria erano indicizzabili. Quest'ultimo era il caso peggiore:
  `/category/news/` e `/news/` mostravano gli stessi articoli con due
  indirizzi diversi, cioe' due pagine che si facevano concorrenza da sole.
  Ora sono tutte `noindex` e Yoast le ha tolte da se' dalla mappa del sito,
  che passa da 24 a 18 indirizzi: meno pagine, ma tutte con qualcosa da
  dire.
  Attenzione alla scala, perche' la mappa la racconta piccola: Search
  Console ne teneva indicizzate **84**, trovate dai link interni e non
  dalla mappa. Le pagine evento sono 46 e vengono tolte tutte, quindi nelle
  prossime settimane quel numero scendera' verso la ventina. E' l'effetto
  voluto, non un guasto.
  La scelta e' stata confermata sapendo che quelle pagine qualche
  impression la ricevevano: contengono pero' solo una data, due stemmi e un
  punteggio, senza descrizione e senza dati strutturati. I dati
  `SportsEvent` che possono valere i risultati arricchiti stanno su
  `/calendario/`, che resta indicizzata: l'asset non si perde.

- **SEO: il profilo Facebook nei dati strutturati era quello sbagliato.**
  In `sameAs` finiva un indirizzo `profile.php?id=...`, cioe' un profilo
  personale, mentre nel footer il Club pubblica
  `facebook.com/romaclubmatera`. Instagram non c'era proprio. Ora
  coincidono con quelli veri.

- **SEO: cinque titoli oltre i 60 caratteri** venivano tagliati nei
  risultati di ricerca. Riscritti come titolo SEO su misura, senza toccare
  il titolo degli articoli. Tre articoli avevano la description mancante o
  oltre i 160 caratteri: riscritte. Il controllo su tutte le pagine ora da'
  18 su 18 con titolo nella misura, description, og:image e un solo H1.

- **Dalle 22 in poi i compleanni scivolavano al giorno dopo.**
  `current_time( 'timestamp' )` restituisce un timestamp col fuso gia'
  sommato dentro, e `wp_date()` glielo somma una seconda volta: d'estate,
  passate le 22, "oggi" diventava domani e "domani" dopodomani. Il difetto
  e' durato il tempo di una rifattorizzazione - il codice di prima usava
  `current_time( 'm-d' )`, che e' corretto - ed e' saltato fuori perche' il
  promemoria in prova diceva 9 settembre invece dell'8. Ora le date si
  prendono da `current_datetime()`, e "domani" e' `modify( '+1 day' )` e non
  piu' 86400 secondi: nella notte del cambio d'ora un giorno non dura
  ventiquattro ore.

- **Chi e' nato in un anno bisestile aveva il compleanno sfasato di un
  giorno** nella tabella dei prossimi compleanni. Il conto sottraeva i
  `DAYOFYEAR` della data di nascita e di oggi, ma dopo il 29 febbraio un
  anno bisestile ha un giorno dell'anno in piu': chi compiva gli anni oggi
  leggeva "domani". Ora la ricorrenza si costruisce davvero, portando
  giorno e mese sull'anno corrente (col 28 febbraio come ripiego per i nati
  il 29, la stessa data su cui gia' cade l'invio). Veniva fuori provando il
  pulsante WhatsApp, che sul giorno sbagliato si accendeva alla riga
  sbagliata.

- **Il titolo della striscia diceva "Cosa dicono i soci"**, ma le
  recensioni non le lasciano solo i tesserati: ora dice "Cosa dicono di
  noi". Cambiati insieme l'`h2` e l'`aria-label` della sezione, che devono
  restare uguali.

- **La striscia delle recensioni allargava la pagina sul telefono.**
  Pubblicata la terza recensione la striscia ha superato la soglia ed e'
  comparsa sopra il footer di *tutte* le pagine: su 390 px di schermo lo
  scorrimento orizzontale arrivava a 748. Colpa delle didascalie per i
  lettori di schermo dentro le schede, che stanno in `position:absolute`:
  da telefono la pista non e' piu' animata, quindi sparisce la `transform`
  che faceva da blocco contenitore e quelle didascalie sfuggivano al
  taglio della striscia. Aggiunto `position: relative` a
  `.rcm-recensioni-vista`.

- **SEO: quattro problemi trovati con un controllo su tutte e 21 le pagine
  pubblicate.**
  - *La home non aveva un H1 e si intitolava "Home".* Il titolo ora e'
    "Roma Club Matera Francesco Totti - Romanisti in Basilicata" (58
    caratteri) e il titolo grande in cima alla pagina e' passato da `h2` a
    `h1`. La misura del carattere su desktop la dava il tema in base al
    tag, percio' e' stata fissata a 50px/600 dentro il widget: verificato
    con `visual-check` che l'aspetto non cambi.
  - *Otto pagine erano senza meta description* (classifica, recensioni,
    la nostra rete, sponsor, store, regolamento, statuto e la news del
    Lecce): senza, Google si inventa il riassunto pescando frasi a caso.
    Scritte tutte, fra 135 e 148 caratteri.
  - *I titoli erano troppo lunghi* perche' Yoast appendeva il nome del
    sito per intero, 35 caratteri su un budget di 60. Il suffisso nei
    modelli e' ora "Roma Club Matera": 16 caratteri in meno su ogni
    pagina. Al post piu' lungo (194, 121 caratteri) e' stato dato un
    titolo SEO su misura, senza toccare il titolo dell'articolo: 121 ->
    69.
  - *Non c'era un'immagine social predefinita*, quindi nove pagine
    condivise su WhatsApp o Facebook uscivano senza figura. Impostata la
    foto dei soci sugli spalti con lo striscione del Club (allegato 653,
    2048x1152).

  Restano fuori misura quattro titoli di articoli (61-77 caratteri): sono
  lunghi di loro, e la parte che conta sta all'inizio, dove non viene
  tagliata. Le copie di sicurezza delle opzioni Yoast e dei dati Elementor
  della home sono in `/root/seo-backup/` sul server.

  Nota su una cosa che il primo controllo aveva segnalato a torto: le 21
  immagini "senza alt" della classifica hanno `alt=""` **esplicito**, che
  per uno stemma accanto al nome della squadra e' la scrittura giusta - un
  lettore di schermo direbbe altrimenti il nome due volte. Stesso discorso
  per il `noindex` su store, regolamento, statuto e la nostra rete: sono
  pagine che dicono "sta arrivando", quindi tenerle fuori dall'indice e'
  corretto.

- **L'estratto della news "Roma-Inter" parlava di un'altra partita**
  (post 202). Titolo, testo, slug, locandina e meta description dicevano
  tutti la stessa cosa - Roma-Inter all'Olimpico, sabato 19 settembre 2026
  alle 18:00, con bus A/R e biglietteria - ma il `post_excerpt` era rimasto
  quello del Lecce: "Il 31 agosto la Roma gioca al Via del Mare". L'estratto
  e' proprio cio' che si legge nell'elenco di `/news/`, quindi in vetrina
  c'era il titolo di una partita e il sommario di un'altra. Data verificata
  su football-data (giornata 5) e sull'evento SportsPress 813 prima di
  riscriverlo. Tolto anche **"prima"** dal titolo: l'articolo e' del 14
  agosto, ma nel frattempo e' uscita la news del Lecce in cui una parte del
  Club va in trasferta il 31, quindi quella di Roma-Inter non e' piu' la
  prima. Slug lasciato invariato
  (`trasferta-roma-inter-19-settembre-2026`), cosi' il link non si rompe.
  Sistemata anche la riga di chiusura, che ripeteva la stessa cosa: da
  "la prima grande trasferta della stagione" a "la prima trasferta
  **organizzata** della stagione". La distinzione e' quella vera e non
  invecchia: a Lecce "una parte dei membri sara' presente" per conto
  proprio, per Fiorentina e Atalanta si apre la sede (con alcuni tesserati
  all'Olimpico da se'), mentre Roma-Inter e' la prima con bus A/R e
  biglietteria messi dal Club.

- **Andata e ritorno non si scambiano piu'.** I due turni di una
  eliminatoria hanno le stesse due squadre a sei giorni di distanza: la
  ricerca per data li vedeva identici e si arrendeva, e la guardia
  anti-doppioni di create-fixtures scambiava il ritorno per l'andata,
  cosi' il ritorno non veniva mai creato. Ora gli eventi creati portano
  l'identificativo del match della API (`_rcm_fd_match_id`) e si abbinano
  per quello; la guardia anti-doppioni controlla anche **chi gioca in
  casa**, leggendolo dall'ordine dei meta `sp_team`.
- **La "giornata 1" non pesca piu' un'andata di ottavi.** Negli
  eliminatori `sp_day` vale 1 o 2 (andata e ritorno) e collideva con le
  prime due giornate del girone; il controllo sul titolo non bastava a
  fermarlo, perche' basta che una delle due squadre compaia. Gli eventi
  che hanno l'identificativo sono ora esclusi dalla ricerca per giornata e
  da quella per data: se non sono stati trovati per identificativo, quel
  match non e' il loro.

## [1.2.0] - 2026-08-27

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

- **Il bottone della notizia nella newsletter ora si puo' modificare**
  (`newsletter/mailchimp-template.html`, zona `mc:edit="notizia_bottone"`).
  Prima il bottone stava **fuori** dalla zona modificabile: si potevano
  cambiare titolo e testo della notizia ma non il suo link, che puntava
  sempre a `/news/` - in un blocco pensato per rimandare a una pagina
  diversa ogni volta, mezzo inutile.
  Sistemato anche il segnaposto del riquadro, che diceva "Domenica 31
  agosto": il 31 agosto 2026 e' un lunedi'. Ora e' un segnaposto neutro
  ("Giorno e ora della partita"), che in un modello e' comunque meglio di
  una data finta destinata a essere ricopiata.
  Le campagne gia' create da questo template non cambiano: si portano dietro
  la copia fatta al momento della creazione.
- **WP-Cron spento sulle visite e affidato al cron di sistema**
  (`DISABLE_WP_CRON`, ruolo `wordpress`). WP-Cron non e' un cron: e' codice che
  gira quando qualcuno carica una pagina, e il lavoro lo paga il visitatore che
  capita al momento sbagliato. Un cron di sistema per `www-data` c'era gia'
  (serviva agli auguri di compleanno), ma girava **in aggiunta** a quello delle
  visite, non al suo posto. Ora il meccanismo delle visite e' spento e resta
  solo il cron, ogni 15 minuti: e' il ritardo massimo con cui esce un post
  programmato, e si stringe abbassando `wordpress_cron_minute`.
  I due task hanno il tag `wp_cron` perche' siano applicabili da soli: il ruolo
  `wordpress` intero rimescola i salts, e lanciarlo per due righe butterebbe
  fuori tutti gli utenti loggati.
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

### Aggiunto

- **News "Roma-Atalanta: una notte giallorossa da vivere insieme"** (post 1352,
  slug `/roma-atalanta-5-settembre-2026/`, categoria News): **programmata** per
  lunedi' 31 agosto 2026 alle 21:00, cioe' subito dopo Lecce-Roma. Locandina
  Match Day (allegato 1351) con didascalia e testo alternativo, e in evidenza
  la foto della sala principale della sede, visto che l'articolo parla di
  ritrovarsi li'.
  Locandina e testo concordano - sabato 5 settembre 2026 e' davvero un sabato,
  ore 20:45, sede aperta dalle 20:15 - e concordano anche col calendario
  SportsPress (giornata 3, Stadio Olimpico).
  **Da sapere sui post programmati**: `DISABLE_WP_CRON` non e' impostato e
  nessun cron di sistema chiama `wp-cron.php`, quindi WordPress usa il cron
  interno, che scatta solo quando qualcuno visita il sito. Un post programmato
  esce alla **prima visita dopo** l'orario, non all'orario esatto.
- **News "Lecce-Roma: ovunque saremo, sara' solo Roma"** (post 1349, slug
  `/lecce-roma-31-agosto-2026/`, categoria News): locandina Match Day
  (allegato 1348) con didascalia e testo alternativo, foto di trasferta in
  evidenza e blocco contatti col telefono e la mail cliccabili - stessa
  impaginazione dei due match day gia' pubblicati.
  La prima versione della locandina dava la partita di **domenica** 31 agosto:
  il 31 agosto 2026 e' un **lunedi'**, come dicono il calendario della Lega e
  il testo dell'articolo. Il post e' rimasto in bozza finche' non e' arrivata
  la locandina corretta, che nel frattempo ha aggiunto anche la riga
  dell'apertura sede (18:00) accanto al fischio d'inizio (18:30).
- **Template newsletter per Mailchimp** (`newsletter/mailchimp-template.html`,
  caricato sull'account `us18` come *Newsletter Roma Club Matera*):
  intestazione con lo stemma su fondo scuro, filo giallorosso, apertura,
  blocco notizia **ripetibile** con foto e bottone, riquadro giallo per il
  prossimo match, bottone principale e pie' di pagina con i link del sito.
  Sette zone `mc:edit` e un `mc:repeatable`: il contenuto si cambia
  dall'editor della campagna senza toccare l'HTML. Mailchimp avverte che un
  template a codice **non torna piu' nel builder drag-and-drop**, ma le zone
  marcate restano modificabili.
  L'HTML e' fatto come vogliono le email e non come una pagina web: tabelle,
  stili inline, colori ripetuti in `bgcolor` **e** in `style` (i client vecchi
  leggono solo il primo, i nuovi solo il secondo), un blocco `<!--[if mso]>`
  che rimette Arial - se no da meta' email in poi Outlook passa a Times New
  Roman - e le due colonne del blocco notizia che si impilano sotto i 620px,
  perche' su un telefono una foto affiancata al testo gli lascia una colonna
  di sei caratteri.
  Il file nel repo e' la copia buona: prima del salvataggio il contenuto
  dell'editor di Mailchimp e' stato confrontato con quello su disco e i due
  SHA-256 coincidono.
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

[1.2.0]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.2.0
[1.1.0]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.1.0
[1.0.3]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.0.3
[1.0.2]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.0.2
[1.0.0]: https://github.com/mikysal78/romaclubmatera.it/releases/tag/v1.0.0
