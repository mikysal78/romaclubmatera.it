# Template email Mailchimp

Due template, caricati sull'account `us18` in *Template di email →
Salvati*:

| File | Nome su Mailchimp | A cosa serve |
| --- | --- | --- |
| `mailchimp-template.html` | **Newsletter Roma Club Matera** | la newsletter ricorrente: apertura, una o più notizie, riquadro del prossimo match |
| `mailchimp-tesseramento.html` | **Tesseramento Roma Club Matera** | la campagna soci: foto di apertura, fascia col titolo, vantaggi, quota, bottone al modulo |

I file qui nel repo sono la copia buona: se un template va rifatto o
qualcuno lo rovina, si ricrea da questo.

Mailchimp **riformatta l'HTML quando salva**, quindi la copia sul suo
server non resta identica byte per byte a questo file. È normale: le
differenze sono di sola spaziatura, il contenuto è lo stesso.

## Come si ricarica su Mailchimp

*Template di email* → **Codice personalizzato** → Continua → incollare il
contenuto del file nell'editor → **Rename** per dargli il nome →
**Salva** → *Save and Exit*.

Mailchimp avverte, ed è vero, che **un template a codice non si può più
aprire nel builder drag-and-drop**. Il contenuto resta comunque
modificabile: le zone marcate `mc:edit` diventano blocchi cliccabili
nell'editor della campagna.

## Cosa si può modificare senza toccare il codice

### Newsletter

| Zona | Cosa contiene |
| --- | --- |
| `anteprima` | il testo che si legge nella lista dei messaggi accanto all'oggetto |
| `apertura` | titolo e paragrafo di introduzione |
| `notizia_foto` / `notizia_testo` | la foto e il testo di una notizia |
| `notizia_bottone` | il bottone della notizia, **compreso il link** |
| `riquadro` | il riquadro giallo (es. "Il prossimo match") |
| `bottone` | il bottone grande in fondo |
| `piede_link` | i link del piè di pagina |

Il blocco notizia è `mc:repeatable`: nell'editor si duplica per averne
quante se ne vogliono, senza rimettere mano all'HTML.

Per cambiare la pagina di destinazione del bottone: cliccare il blocco,
selezionare le parole *Leggi tutto* e usare lo strumento collegamento della
barra, oppure aprire il codice della zona con il pulsante `<>` e cambiare
l'`href`.

### Tesseramento

| Zona | Cosa contiene |
| --- | --- |
| `anteprima` | il testo che si legge nella lista dei messaggi accanto all'oggetto |
| `foto` | la foto di apertura, larga quanto l'email |
| `titolo` | occhiello, titolo grande e frase sotto, nella fascia rossa |
| `apertura` | i due paragrafi di introduzione |
| `vantaggi` | l'elenco col segno di spunta |
| `quota` | il riquadro giallo con l'importo |
| `bottone` | il bottone *Tesserati ora*, **compreso il link** |
| `passi` | i tre passi numerati |
| `chiusura` | la riga di saluto |
| `piede_link` | i link del piè di pagina |

**L'importo va sostituito prima di inviare.** Nel template c'è
`€ 00,00` con sotto la riga *Importo da inserire prima dell'invio*:
sono lì apposta perché una campagna non parta con la cifra sbagliata.
Il bottone punta a `/tesseramento-2026-27/`, che è la pagina col modulo
JotForm: se cambia l'anno sociale cambia anche lo slug, e il link va
aggiornato.

## Campi che Mailchimp riempie da solo

`*|MC:SUBJECT|*` `*|FNAME|*` `*|LIST:ADDRESSLINE|*` `*|UNSUB|*`
`*|UPDATE_PROFILE|*` `*|ARCHIVE|*` `*|CURRENT_YEAR|*`

Indirizzo e link di cancellazione non sono decorazioni: senza, Mailchimp
si rifiuta di inviare (e la legge pure).

## Perché l'HTML è scritto così

Le email non sono pagine web: si disegnano con tabelle e stili inline,
perché Gmail cancella quasi tutto quello che sta in un foglio di stile e
Outlook disegna con il motore di Word.

Tre punti che sembrano ridondanti e non lo sono:

- i colori di sfondo sono scritti **sia** in `bgcolor` **sia** in
  `style`: i client vecchi leggono solo il primo, quelli nuovi solo il
  secondo;
- il blocco `<!--[if mso]>` rimette Arial dappertutto, se no da metà
  email in poi Outlook passa a Times New Roman;
- le due colonne del blocco notizia si impilano sotto i 620px con
  `display:block`, perché su un telefono una foto affiancata al testo
  lascia al testo una colonna di sei caratteri.

Le immagini sono richiamate dal sito (`romaclubmatera.it/wp-content/...`),
non allegate: se una foto viene cancellata dal sito, sparisce anche dalle
email già inviate.
