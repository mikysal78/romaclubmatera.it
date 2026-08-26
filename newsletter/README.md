# Template newsletter Mailchimp

`mailchimp-template.html` è il template dell'email del Club, caricato su
Mailchimp come **Newsletter Roma Club Matera** (account `us18`, sezione
*Template di email → Salvati*).

Il file qui nel repo è la copia buona: se il template va rifatto o
qualcuno lo rovina, si ricrea da questo.

## Come si ricarica su Mailchimp

*Template di email* → **Codice personalizzato** → Continua → incollare il
contenuto del file nell'editor → **Rename** per dargli il nome →
**Salva** → *Save and Exit*.

Mailchimp avverte, ed è vero, che **un template a codice non si può più
aprire nel builder drag-and-drop**. Il contenuto resta comunque
modificabile: le zone marcate `mc:edit` diventano blocchi cliccabili
nell'editor della campagna.

## Cosa si può modificare senza toccare il codice

| Zona | Cosa contiene |
| --- | --- |
| `anteprima` | il testo che si legge nella lista dei messaggi accanto all'oggetto |
| `apertura` | titolo e paragrafo di introduzione |
| `notizia_foto` / `notizia_testo` | la foto e il testo di una notizia |
| `riquadro` | il riquadro giallo (es. "Il prossimo match") |
| `bottone` | il bottone grande in fondo |
| `piede_link` | i link del piè di pagina |

Il blocco notizia è `mc:repeatable`: nell'editor si duplica per averne
quante se ne vogliono, senza rimettere mano all'HTML.

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
