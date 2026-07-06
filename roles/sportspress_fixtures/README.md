# Ruolo `sportspress_fixtures`

Aggiorna ogni giorno data/ora degli eventi SportsPress (sp_event) del
sito interrogando la API di football-data.org (gratuita, tier ONE,
Serie A inclusa).

## Come funziona

- Script PHP eseguito via `wp eval-file` come `www-data` da un systemd
  timer (`sp-fixtures-update.timer`, default 07:15 + ritardo casuale ≤15').
- Abbina i match della API agli eventi WP per **giornata** (meta `sp_day`)
  dentro league `serie-a` + season `2026-27`, verificando che i nomi
  squadra corrispondano al titolo dell'evento.
- Aggiorna solo i match con orario ufficializzato (status API `TIMED`,
  `IN_PLAY`, `PAUSED`, `FINISHED`); i match `SCHEDULED` (data/ora ancora
  provvisorie su football-data) non vengono toccati, così il calendario
  ufficiale della Lega caricato a mano non viene sovrascritto da
  placeholder. Fuso orario: quello di WordPress (Europe/Rome).

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
