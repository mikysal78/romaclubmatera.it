# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate qui.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/)
e il versionamento [SemVer](https://semver.org/lang/it/).

## [Unreleased]

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
