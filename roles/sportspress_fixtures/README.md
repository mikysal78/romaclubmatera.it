# Ruolo `sportspress_fixtures`

Aggiorna ogni giorno data/ora **e risultati** degli eventi SportsPress
(sp_event) e le **classifiche** (sp_table) del sito, interrogando la API di
football-data.org (gratuita, tier ONE), per **una o più competizioni**.

Due script sullo stesso timer, con le funzioni in comune (config, chiamate
API, abbinamento dei nomi delle squadre) in `sp-lib.php`: se calendario e
classifica abbinassero i nomi in modo diverso potrebbero attribuire lo
stesso punteggio a squadre diverse.

## Come funziona

- Script PHP eseguito via `wp eval-file` come `www-data` da un systemd
  timer (`sp-fixtures-update.timer`, default 07:15 + ritardo casuale ≤15').
- Una chiamata alla API per competizione, poi ogni match viene abbinato a
  un evento WordPress dentro la league/season configurate.
- **Data e ora**: solo per i match con orario ufficializzato (status API
  `TIMED`, `IN_PLAY`, `PAUSED`, `FINISHED`); i match `SCHEDULED` (data/ora
  ancora provvisorie su football-data) non vengono toccati, così il
  calendario ufficiale della Lega caricato a mano non viene sovrascritto da
  placeholder. Fuso orario: quello di WordPress (Europe/Rome).
- **Risultati**: solo a partita conclusa (`FINISHED` o `AWARDED`), mai a
  partita in corso — sul calendario un parziale sembrerebbe definitivo.
  Scrive nel meta `sp_results` le tre variabili configurate su SportsPress
  (`goals`, `firsthalf`, `secondhalf`; il secondo tempo per differenza) più
  l'esito, che ricava dalle `sp_outcome` in base alla loro condizione
  (`>` vittoria, `=` pareggio, `<` sconfitta). Se un evento finito fosse
  ancora in stato `future` lo pubblica, altrimenti il risultato resterebbe
  invisibile.

### Come si abbina un match al suo evento

Due modi, perché non ne basta uno:

| `abbinamento` | Come trova l'evento |
| --- | --- |
| `giornata` | per il meta `sp_day` uguale al `matchday` della API |
| `data` | fra gli eventi a ±`giorni` dalla data della API, quello con le stesse due squadre nello stesso verso casa/trasferta |
| `auto` (default) | `giornata` nelle fasi elencate in `fasi_giornata`, `data` in tutte le altre |

La distinzione non è teorica: **nelle fasi a eliminazione la giornata non
identifica il match**. Negli ottavi di Champions il `matchday` vale 1 o 2
(andata e ritorno) e collide con le giornate 1 e 2 della fase campionato.
Le fasi che la API usa per i gironi sono `REGULAR_SEASON` (campionati),
`LEAGUE_STAGE` (la fase campionato della nuova Champions) e `GROUP_STAGE`.

Un evento già abbinato non viene riusato per un secondo match della stessa
passata, così andata e ritorno non finiscono sullo stesso evento.

### Come si capisce chi è in casa

L'API dà tre forme del nome (`AS Roma` / `Roma` / `ROM`) e i titoli delle
squadre su WordPress non ne seguono nessuna in modo costante (`AS Roma`,
`Fiorentina`, `Como`). Lo script prova **entrambi gli accoppiamenti**
possibili fra le due squadre dell'evento e le due dell'API, dà un punteggio
di somiglianza a ciascuno e tiene il migliore; se i due pareggiano non
indovina, salta e lascia un avviso.

L'uguaglianza esatta vale più della sottostringa, e non è un dettaglio:
"Milan" è contenuto in "FC Internazionale Milano", quindi prendendo la
sottostringa come prova certa si assegnerebbe all'una il punteggio
dell'altra.

Lo stesso punteggio serve all'abbinamento per data: fra gli eventi nella
finestra si tiene quello che riconosce **entrambe** le squadre, e a pari
punteggio si rinuncia.

### Supplementari e rigori

`fullTime` della API comprende i supplementari, quindi il secondo tempo per
differenza non tornerebbe. Quando la `duration` non è `REGULAR` si scrive
solo il totale e lo si annota nel log: su questo SportsPress non ha una
variabile dove mettere i supplementari.

Ai rigori il punteggio resta pari e l'esito è un pareggio: chi passa il
turno non è un dato che SportsPress registri.

### Come si vede in pagina

La pagina Calendario contiene `[event_list id="873"]`. Con
`sportspress_event_list_time_format` su `combined` (l'impostazione attuale)
la colonna è **"Orario/Risultati"**: mostra l'orario finché la partita non è
giocata e il punteggio appena l'evento ha un risultato, senza bisogno di
aggiungere una colonna. Per averle separate si mette quell'opzione su
`separate` e si aggiunge `results` alle colonne del calendario 873 — ma è
un'impostazione **globale**, vale per ogni elenco eventi del sito.

## Classifiche

`update-standings.php` riempie la tabella con la classifica ufficiale presa
dalla API.

**Perché non la calcola SportsPress.** Il plugin ricava la classifica dagli
eventi che ha in archivio, e sul sito ci sono solo le partite della Roma:
una classifica calcolata mostrerebbe una riga sola coi dati veri e
diciannove a zero. Si scrivono invece i valori "manuali" nel meta
`sp_teams`, che SportsPress usa **al posto** di quelli calcolati quando ci
sono. Così la tabella è quella vera senza importare tutte le 380 partite del
campionato.

Le otto colonne standard del plugin corrispondono una a una ai campi della
API: `p` `w` `d` `l` `f` `a` `gd` `pts` ← `playedGames` `won` `draw` `lost`
`goalsFor` `goalsAgainst` `goalDifference` `points`.

**L'ordinamento è il punto delicato.** SportsPress riordina sempre da sé,
per priorità di colonna: punti, differenza reti, gol fatti. In Serie A però
il primo criterio a parità di punti è lo **scontro diretto**, che senza le
partite delle altre squadre non è calcolabile. Si scrive quindi anche la
posizione ufficiale in una colonna **nascosta** con priorità 1, così
l'ordine finale è esattamente quello della Lega. La colonna non compare fra
quelle mostrate: serve solo a ordinare.

La classifica da riempire si trova da sola: è la `sp_table` con la stessa
lega e la stessa stagione della competizione, quindi nella config non
compare nessun ID.

### Cosa serve sul sito

Non è roba che faccia Ansible (i contenuti del sito non stanno nel repo),
si prepara una volta per stagione:

1. le squadre (`sp_team`) con i termini `sp_league` e `sp_season` giusti —
   è da lì che SportsPress prende le righe della tabella;
2. una colonna `sp_column` con slug **`posizione`**, senza equazione,
   `sp_priority` = 1 e `sp_order` = `ASC`; e le priorità delle altre
   spostate di uno (`pts` 2, `gd` 3, `f` 4). Senza equazione, se la
   posizione non venisse scritta la colonna resta vuota per tutte e
   l'ordinamento ricade sui punti;
3. una `sp_table` con quella lega e quella stagione, `sp_select` = `auto` e
   `sp_columns` = `["p","w","d","l","f","a","gd","pts"]` (senza
   `posizione`);
4. una pagina con `[league_table id="<ID della tabella>"]`.

Lo slug `posizione` e non `pos`: `pos` è la chiave che SportsPress usa già
internamente per il numero di posizione calcolato, e si sovrascriverebbero
a vicenda.

Per non riempire la classifica di una competizione:

```yaml
    classifica: false
```

## Configurazione

Token nel vault (obbligatorio, il ruolo si ferma se manca):

```sh
ansible-vault edit group_vars/all/vault.yml
# vault_football_data_token: "<token football-data.org>"
```

Le competizioni stanno in `sportspress_fixtures_competizioni`:

```yaml
sportspress_fixtures_competizioni:
  - nome: "Serie A"
    fd_competition: "SA"
    sp_league: "serie-a"

  - nome: "Champions League"
    fd_competition: "CL"
    sp_league: "champions-league"
```

`fd_season` e `sp_season` si possono mettere per competizione, ma di norma
si lasciano ai valori generali: a inizio stagione nuova si aggiornano solo
`sportspress_fixtures_fd_season` (anno di inizio, es. "2027") e
`sportspress_fixtures_sp_season` (es. "2027-28").

Il tier gratuito copre Serie A e **Champions League**; l'Europa League no
(la API risponde "restricted"). Finché il sorteggio non è pubblicato la API
non ha match e lo script si limita a scriverlo nel log, senza errori: si può
quindi configurare la coppa in anticipo.

Per aggiornare solo gli orari e continuare a inserire i risultati a mano:

```yaml
sportspress_fixtures_results: false
```

## Esecuzione

```sh
ansible-playbook site.yml --tags sportspress --ask-vault-pass
```

## Verifica sul server

```sh
systemctl list-timers sp-fixtures-update.timer
systemctl start sp-fixtures-update.service   # esecuzione manuale
journalctl -u sp-fixtures-update.service -n 50
```

Il servizio lancia i due script in fila: prima il calendario, poi le
classifiche.
