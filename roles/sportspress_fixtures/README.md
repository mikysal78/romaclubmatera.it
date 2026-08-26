# Ruolo `sportspress_fixtures`

Aggiorna ogni giorno data/ora **e risultati** degli eventi SportsPress
(sp_event) del sito interrogando la API di football-data.org (gratuita,
tier ONE, Serie A inclusa).

## Come funziona

- Script PHP eseguito via `wp eval-file` come `www-data` da un systemd
  timer (`sp-fixtures-update.timer`, default 07:15 + ritardo casuale ≤15').
- Abbina i match della API agli eventi WP per **giornata** (meta `sp_day`)
  dentro league `serie-a` + season `2026-27`, verificando che i nomi
  squadra corrispondano al titolo dell'evento.
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

### Come si vede in pagina

La pagina Calendario contiene `[event_list id="873"]`. Con
`sportspress_event_list_time_format` su `combined` (l'impostazione attuale)
la colonna è **"Orario/Risultati"**: mostra l'orario finché la partita non è
giocata e il punteggio appena l'evento ha un risultato, senza bisogno di
aggiungere una colonna. Per averle separate si mette quell'opzione su
`separate` e si aggiunge `results` alle colonne del calendario 873 — ma è
un'impostazione **globale**, vale per ogni elenco eventi del sito.

## Configurazione

Token nel vault (obbligatorio, il ruolo si ferma se manca):

```sh
ansible-vault edit group_vars/all/vault.yml
# vault_football_data_token: "<token football-data.org>"
```

Tutte le altre variabili (team, stagione, slug SportsPress, percorsi,
orario del timer) sono in `defaults/main.yml` e si possono
sovrascrivere per host/gruppo. A inizio stagione nuova aggiornare
`sportspress_fixtures_fd_season` (anno di inizio, es. "2027") e
`sportspress_fixtures_sp_season` (es. "2027-28").

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
