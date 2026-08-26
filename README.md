# Sito Roma Club Matera "Francesco Totti"

Infrastruttura e personalizzazioni di **romaclubmatera.it**: playbook Ansible per il deploy di un sito **WordPress mono-sito** su un container **LXC Debian 13 (Trixie)** *minimal* su Proxmox, con stack performante e hardening del sistema.

Stack: **Nginx + PHP-FPM 8.4 + MariaDB + Redis (object cache) + WP-CLI**, micro-cache FastCGI, HTTPS Let's Encrypt opzionale, **phpMyAdmin** protetto, **hardening** completo e **backup** automatici con retention.

> Pensato per **clean install**: il playbook assume un CT appena creato, non un sistema con WordPress già presente.

> [!IMPORTANT]
> **Tutti i diritti riservati.** Questo repository è consultabile ma non
> riusabile: copia, riuso delle personalizzazioni e opere derivate
> richiedono l'autorizzazione scritta dei titolari — Roma Club Matera
> "Francesco Totti" e mikysal78 — info@romaclubmatera.it.
> Vedi [LICENSE](LICENSE).

---

## Indice

1. [Requisiti minimi per un sito performante](#1-requisiti-minimi-per-un-sito-performante)
2. [Architettura](#2-architettura)
3. [Preparazione del CT su Proxmox](#3-preparazione-del-ct-su-proxmox)
4. [Installazione del progetto](#4-installazione-del-progetto)
5. [Configurazione (variabili e segreti)](#5-configurazione-variabili-e-segreti)
6. [Esecuzione](#6-esecuzione)
7. [Cosa fa l'hardening](#7-cosa-fa-lhardening)
8. [Backup e ripristino](#8-backup-e-ripristino)
9. [Personalizzazioni del sito (mu-plugin)](#9-personalizzazioni-del-sito-mu-plugin)
10. [Manutenzione e tag utili](#10-manutenzione-e-tag-utili)
11. [Troubleshooting](#11-troubleshooting)
12. [Pubblicazione su GitHub](#12-pubblicazione-su-github)

---

## 1. Requisiti minimi per un sito performante

Dimensionamento del **container LXC** in base al traffico. I valori di tuning (PHP-FPM, OPcache, InnoDB buffer pool, Redis) si **autoregolano** in base a `ct_memory_mb`.

| Scenario | vCPU | RAM | Disco | Note |
|---|---|---|---|---|
| **Minimo** (blog/vetrina, basso traffico) | 1 | **1 GB** | 10 GB | Funziona, ma con poco margine |
| **Consigliato** (sito professionale) | 2 | **2 GB** | 20 GB | Buon compromesso prezzo/prestazioni |
| **Performante** (traffico medio, WooCommerce leggero) | 2–4 | **4 GB** | 40 GB | Cache piena efficacia |
| **Enterprise** (traffico alto) | 4+ | **8 GB+** | 80 GB+ SSD/NVMe | Redis + FastCGI cache spingono molto |

**Requisiti software** (gestiti dal playbook):

- Debian **13 "Trixie"** (kernel 6.12 LTS) — PHP **8.4** di default
- Ansible **≥ 2.15** sul controller (la tua macchina), Python 3 sul CT
- Container con accesso a Internet (per APT, WP-CLI, phpMyAdmin, Let's Encrypt)

**Perché è performante:**

- **OPcache** PHP attivo e tarato (bytecode in RAM, niente ricompilazione).
- **Redis object cache**: query e oggetti WordPress in memoria invece che a DB.
- **Micro-cache FastCGI Nginx**: le pagine anonime vengono servite dalla cache (bypass automatico per utenti loggati, POST, area admin, carrello).
- **InnoDB buffer pool** dimensionato al ~25% della RAM.
- **Static assets** con `expires 30d` e header di cache.

> **HTTPS:** per un sito *davvero* professionale attiva `enable_https: true` (Let's Encrypt). Richiede che il dominio punti già all'IP pubblico del CT e la porta 80 sia raggiungibile.

---

## 2. Architettura

```
Internet ──▶ Nginx (80/443, FastCGI cache, security headers)
                │
                ├─▶ PHP-FPM 8.4 (OPcache)  ──▶  WordPress (/var/www/<dominio>)
                │                                   │
                │                                   ├─▶ MariaDB (127.0.0.1, InnoDB)
                │                                   └─▶ Redis (object cache)
                │
                └─▶ /<alias-segreto>  ──▶  phpMyAdmin (Basic Auth + cookie auth)

Hardening:  UFW (deny-in) · fail2ban · SSH drop-in · sysctl · unattended-upgrades
Backup:     /var/backups/wordpress  (cron giornaliero, retention configurabile)
```

Struttura del repository:

```
wordpress-trixie-ansible/
├── ansible.cfg
├── CHANGELOG.md              # storico delle versioni
├── Makefile                  # scorciatoie: make deploy, make https, ...
├── requirements.yml          # ruoli/collections (include mikysal78.ninux_common)
├── site.yml                  # playbook principale
├── letsencrypt.yml           # playbook standalone per HTTPS
├── teardown.yml              # pulizia del CT per ripartire da zero (distruttivo)
├── import-site.yml           # importa un backup del vecchio sito (migrazione)
├── scripts/import-site.sh    # script di import eseguito sul CT
├── inventory/hosts.yml.example  # IP del CT: copia in hosts.yml (ignorato da git)
├── group_vars/all/
│   ├── vars.yml.example      # configurazione: copia in vars.yml (ignorato da git)
│   └── vault.yml.example     # password: copia in vault.yml e cifra (ignorato da git)
└── roles/
    ├── common/               # mikysal78.ninux_common + pacchetti base
    ├── hardening/            # firewall, ssh, fail2ban, sysctl, updates
    ├── database/             # MariaDB + DB/utente WordPress
    ├── php/                  # PHP-FPM 8.4 + estensioni + OPcache
    ├── redis/                # Redis object cache
    ├── webserver/            # Nginx + vhost + FastCGI cache
    ├── letsencrypt/          # certbot: emissione + rinnovo automatico HTTPS
    ├── wordpress/            # WP-CLI: download, config, install, redis plugin, mu-plugin
    ├── phpmyadmin/           # phpMyAdmin protetto
    ├── sportspress_fixtures/ # calendario partite da football-data.org
    └── backup/               # directory + script + cron
```

---

## 3. Preparazione del CT su Proxmox

1. **Crea il CT** con template Debian 13. Esempio da shell Proxmox:

   ```bash
   pveam update
   pveam available | grep debian-13
   pveam download local debian-13-standard_13.*_amd64.tar.zst

   pct create 110 local:vztmpl/debian-13-standard_13.*_amd64.tar.zst \
     --hostname wp01 \
     --cores 2 --memory 2048 --swap 512 \
     --rootfs local-lvm:20 \
     --net0 name=eth0,bridge=vmbr0,ip=dhcp \
     --features nesting=1 \
     --unprivileged 1 \
     --onboot 1
   pct start 110
   ```

2. **Accesso SSH**: imposta una password di root o inietta una chiave, e assicurati che `openssh-server` sia presente:

   ```bash
   pct enter 110
   apt-get update && apt-get install -y openssh-server
   passwd root        # oppure configura una chiave SSH
   exit
   ```

> **Nota LXC unprivileged:** alcuni parametri `sysctl` del kernel sono read-only nel container; il playbook li applica in *best-effort* e prosegue senza fallire.

---

## 4. Installazione del progetto

Sul **controller** (la tua macchina, dove gira Ansible):

```bash
git clone https://github.com/mikysal78/wordpress-trixie-ansible.git
cd wordpress-trixie-ansible

# Ruoli e collections (installa anche mikysal78.ninux_common)
ansible-galaxy install -r requirements.yml
```

Grazie alla configurazione in `ansible.cfg`, le dipendenze Galaxy vengono installate **dentro il progetto** e **non vengono versionate** (sono già nel `.gitignore`):

- ruoli esterni → `galaxy_roles/` (i tuoi ruoli locali restano in `roles/`)
- collections → `collections/`

L'installazione di `mikysal78.ninux_common` è inclusa in `requirements.yml`. In alternativa, manualmente:

```bash
ansible-galaxy role install mikysal78.ninux_common
```

---

## 5. Configurazione (variabili e segreti)

### 5.1 Inventario

L'inventario è **non versionato** (l'IP reale non finisce su GitHub). Crealo dal modello e mettici l'IP del CT:

```bash
cp inventory/hosts.yml.example inventory/hosts.yml
nano inventory/hosts.yml
```

```yaml
wp01:
  ansible_host: 10.0.0.50      # <-- IP del tuo container
```

### 5.2 Variabili principali — `group_vars/all/vars.yml`

La configurazione vive in un file **non versionato** (così le tue impostazioni reali non finiscono su GitHub). Crealo dal modello:

```bash
cp group_vars/all/vars.yml.example group_vars/all/vars.yml
nano group_vars/all/vars.yml
```

I valori che userai più spesso:

| Variabile | Default | Descrizione |
|---|---|---|
| `wp_domain` | `example.com` | Dominio del sito |
| `wp_admin_user` | `admin` | Utente amministratore WordPress |
| `wp_admin_email` | … | Email admin |
| `db_name` / `db_user` | `wordpress` / `wp_user` | Database e utente del sito |
| `ct_memory_mb` | `2048` | RAM del CT (guida il tuning automatico) |
| `enable_redis` | `true` | Object cache Redis |
| `enable_phpmyadmin` | `true` | Installa phpMyAdmin |
| `enable_https` | `false` | HTTPS Let's Encrypt |
| `enable_fastcgi_cache` | `true` | Micro-cache pagine anonime |
| `phpmyadmin_alias` | `dbadmin-7h3x` | Path "segreto" di phpMyAdmin |
| `hardening_ssh_port` | `22` | Porta SSH |
| `admin_user` | `sysop` | Utente sudo non-root |
| `admin_ssh_pubkey` | `""` | Chiave pubblica admin (vedi sotto) |
| `backup_retention_days` | `7` | Giorni di retention dei backup |

### 5.3 Password (vault) — **obbligatorio**

Le password stanno in un file cifrato. Crea e cifra il vault:

```bash
cp group_vars/all/vault.yml.example group_vars/all/vault.yml

# Genera password robuste:
openssl rand -base64 24

# Modifica le password nel file:
nano group_vars/all/vault.yml

# Cifra il file:
ansible-vault encrypt group_vars/all/vault.yml
```

Variabili segrete contenute nel vault:

| Variabile vault | A cosa serve |
|---|---|
| `vault_mysql_root_password` | Password **root di MySQL/MariaDB** |
| `vault_db_password` | Password dell'**utente database** WordPress |
| `vault_wp_admin_password` | Password dell'**admin WordPress** |
| `vault_phpmyadmin_basic_password` | Password **Basic Auth** davanti a phpMyAdmin |
| `vault_admin_user_password` | (Opzionale) password dell'utente sudo di sistema |

> Tutto ciò che hai chiesto è qui: **dominio, user/pass WordPress, user/pass database, password root di MySQL**.

---

## 6. Esecuzione

```bash
# Verifica sintassi
ansible-playbook site.yml --syntax-check

# Test di connessione
ansible -m ping wordpress

# (Consigliato) prova a vuoto
ansible-playbook site.yml --ask-vault-pass --check --diff

# Deploy completo
ansible-playbook site.yml --ask-vault-pass
```

Al termine vedrai un riepilogo con URL del sito, area admin, phpMyAdmin e directory backup.

> **Primo collegamento al CT**: l'immagine minimal potrebbe non avere `python3`. Il playbook lo installa da solo in fase di *bootstrap* (task `raw`), quindi puoi lanciarlo direttamente.

### 6.1 HTTPS con Let's Encrypt

Hai due modi per ottenere il certificato:

**A) Tutto in un colpo** — imposta `enable_https: true` in `vars.yml`: `site.yml` emette il certificato alla fine (richiede DNS già puntato).

**B) Playbook dedicato** `letsencrypt.yml` *(consigliato)* — deployi prima in HTTP, punti il DNS con calma, poi emetti il certificato senza rilanciare tutto:

```bash
# 1) Prova senza rate-limit (certificato di TEST, non valido nei browser)
ansible-playbook letsencrypt.yml --ask-vault-pass -e letsencrypt_staging=true

# 2) Emissione reale
ansible-playbook letsencrypt.yml --ask-vault-pass

# 3) Passare da staging a produzione (forza la ri-emissione)
ansible-playbook letsencrypt.yml --ask-vault-pass -e letsencrypt_force=true

# 4) Includere anche il test di rinnovo (dry-run) come verifica
ansible-playbook letsencrypt.yml --ask-vault-pass --tags all,verify
```

Cosa fa il ruolo `letsencrypt`:

- installa `certbot` + `python3-certbot-nginx`;
- **verifica che Nginx sia attivo** (il vhost HTTP sulla porta 80 serve per la validazione) e si ferma con un messaggio chiaro se non lo è;
- emette il certificato per apex e `www` (se `wp_canonical != non-www`) + eventuali `letsencrypt_extra_domains`, con `--redirect` (HTTP→HTTPS automatico);
- installa un **deploy-hook** in `/etc/letsencrypt/renewal-hooks/deploy/` che **ricarica Nginx a ogni rinnovo** (solo se `nginx -t` passa);
- assicura il **timer di rinnovo automatico** `certbot.timer`.

Variabili rilevanti (in `vars.yml` o via `-e`): `letsencrypt_staging`, `letsencrypt_force`, `letsencrypt_extra_domains`, `letsencrypt_email`.

> ⚠️ **Rate limit**: Let's Encrypt limita a 5 emissioni/settimana per dominio. Usa **sempre `letsencrypt_staging=true`** per i test, poi passa a produzione con `letsencrypt_force=true`.

### 6.2 Scorciatoie con `make`

Il `Makefile` raccoglie i comandi più frequenti. `make` (o `make help`) mostra l'elenco:

| Comando | Azione |
|---|---|
| `make init` | Crea `vars.yml` e `vault.yml` dagli esempi |
| `make deps` | Installa ruoli e collections Galaxy |
| `make ping` | Verifica la connessione al CT |
| `make lint` | `yamllint` + `ansible-lint` |
| `make check` | Dry-run con diff |
| `make deploy` | Deploy completo dello stack |
| `make https-staging` / `make https` / `make https-force` | Certificato Let's Encrypt (test / reale / forzato) |
| `make backup` | Lancia subito un backup sul CT |
| `make import ZIP=...` | Importa un backup del vecchio sito |
| `make teardown CONFIRM=PULISCI` | Ripulisce il CT (distruttivo) |
| `make vault-edit` / `make vault-encrypt` / `make vault-view` | Gestione del vault |

Variabili utili: `make deploy VAULT="--vault-password-file .vault_pass"` per non digitare la password del vault, oppure `make deploy EXTRA="-e ct_memory_mb=4096"` per passare override.

---

## 7. Cosa fa l'hardening

Pensato per immagine **minimal**, con attenzione ai limiti di LXC:

- **Firewall UFW**: default *deny* in ingresso; consente solo SSH (con `limit` anti brute-force), 80 e 443.
- **fail2ban**: jail per `sshd`, `nginx-http-auth`, `nginx-botsearch`.
- **SSH** (drop-in `sshd_config.d/99-hardening.conf`, stile Trixie): niente login root con password, `MaxAuthTries 3`, no X11/agent forwarding, timeout sessione.
- **Utente sudo non-root** (`admin_user`) con eventuale chiave SSH.
- **sysctl** di sicurezza in `/etc/sysctl.d/` (su Trixie `/etc/sysctl.conf` **non è più onorato**); applicati in best-effort sui CT unprivileged.
- **unattended-upgrades**: patch di sicurezza automatiche.
- **WordPress**: `DISALLOW_FILE_EDIT`, salts randomizzati, permessi file 644 / dir 755, `wp-config.php` a 640, blocco esecuzione PHP in `uploads`, `xmlrpc.php` negato.
- **phpMyAdmin**: path non standard + **Basic Auth** + `cookie auth` + `AllowNoPassword=false`.

> ⚠️ **Per non bloccarti fuori dall'SSH**: imposta `admin_ssh_pubkey` con la tua chiave **prima** di mettere a `true` `ssh_disable_password_auth` o `ssh_disable_root_login`. Con i default (`false`) resti sempre in grado di accedere.

---

## 8. Backup e ripristino

Il ruolo `backup` crea:

- la directory **`/var/backups/wordpress`** (modo `0750`, solo root);
- lo script **`/usr/local/sbin/wp-backup.sh`**;
- un **cron giornaliero** (default 03:30) con log in `/var/log/wp-backup.log`.

Ogni backup è un archivio `dominio-AAAAMMGG-HHMMSS.tar` contenente:

- `db.sql.gz` — dump MariaDB `--single-transaction` (coerente, senza lock);
- `files.tar.gz` — tutta la doc-root (escludendo `wp-content/cache`);
- `SHA256SUMS` — checksum per verifica integrità.

La **retention** elimina gli archivi più vecchi di `backup_retention_days` giorni.

**Backup manuale:**

```bash
/usr/local/sbin/wp-backup.sh
```

**Ripristino** (esempio):

```bash
cd /var/backups/wordpress
tar -xf example.com-20260629-033000.tar
cd example.com-20260629-033000
sha256sum -c SHA256SUMS

# Database
zcat db.sql.gz | mysql --defaults-extra-file=/root/.wp-backup.cnf wordpress

# File
tar -xzf files.tar.gz -C /var/www/
chown -R www-data:www-data /var/www/example.com
```

> Per un setup *enterprise*, copia gli archivi **off-site** (rsync/restic verso un altro host o object storage). Il formato `.tar` rende l'export semplice.

---

## 8.1 Import di un sito esistente (migrazione)

`import-site.yml` + `scripts/import-site.sh` importano nel sito nuovo un backup del plugin **Backup Migration** (file `BM_*.zip`), impostando dominio e credenziali nuove. Lo script gira **sul CT** e in sequenza: fa un **backup di sicurezza** del sito attuale, svuota e reimporta il database (rinominando il prefisso temporaneo del dump), sincronizza `wp-content` (uploads/themes/plugins), esegue il **search-replace** del dominio vecchio→nuovo sui dati serializzati, imposta l'utente admin con la **nuova password**, riattiva Redis e sistema permessi e cache.

Le credenziali del database (`wp-config.php`) **non vengono toccate**: restano quelle nuove.

```bash
# via Ansible (consigliato): carica lo zip sul CT ed esegue tutto
make import ZIP=/percorso/locale/BM_xxx.zip
# equivale a:
ansible-playbook import-site.yml --ask-vault-pass -e import_backup_file=/percorso/BM_xxx.zip
```

Oppure direttamente sul CT (dopo aver copiato lì lo zip):

```bash
NEW_ADMIN_PASS='NuovaPassword!' sudo -E /usr/local/sbin/import-site.sh \
  --zip /root/BM_xxx.zip --site-root /var/www/romaclubmatera.it \
  --new-domain romaclubmatera.it --admin-user miky --yes
```

Note importanti:
- il dominio vecchio viene letto dal manifest del backup; quello nuovo da `wp_domain`;
- gli utenti del vecchio sito vengono importati: l'admin indicato riceve la **nuova** password (gli altri restano con le credenziali vecchie, cambiale o rimuovile dalla dashboard);
- viene sempre creato un **backup pre-import** in `/var/backups/` sul CT: se qualcosa non torna, puoi ripristinare;
- dopo l'import, controlla home, menu, media e i plugin pesanti (Elementor, Slider Revolution).

## 9. Personalizzazioni del sito (mu-plugin)

Le funzioni su misura non sono plugin da installare dalla bacheca: sono **mu-plugin**
(`wp-content/mu-plugins/`, sempre attivi, non disattivabili per sbaglio) versionati nel
repo e copiati dal playbook. Il codice sta in `roles/<ruolo>/files/`.

| Mu-plugin | Ruolo | Cosa fa |
|---|---|---|
| `rcm-image-sizes.php` | `wordpress` | registra il formato 16:9 (800x450) usato dalla gallery delle trasferte |
| `rcm-compleanni.php` | `wordpress` | anagrafica soci e auguri di compleanno automatici |
| `rcm-next-match.php` | `sportspress_fixtures` | evidenzia la prossima partita e mostra "da definire" sugli orari non ancora ufficiali |

### 9.1 Auguri di compleanno ai soci (`rcm-compleanni`)

Manda gli auguri via email ai soci il giorno del compleanno, usando il relay SMTP già
configurato (vedi `enable_smtp`). I soci **non sono utenti WordPress**: stanno in una
tabella dedicata `<prefisso>_rcm_soci`, popolata via import CSV.

In bacheca compare il menu **Soci**, riservato agli amministratori:

- **Elenco soci** — ricerca, paginazione, aggiunta manuale, eliminazione; in cima il
  totale e quanti soci sono senza data di nascita (quelli vengono esclusi dagli invii);
- **Importa CSV** — prima riga con i nomi delle colonne (`nome`, `cognome`, `email`,
  `data di nascita`), separatore `,` o `;`, date `gg/mm/aaaa` o `aaaa-mm-gg`. L'import è
  **idempotente sull'email**: aggiorna invece di duplicare e le celle vuote non
  sovrascrivono i dati già in archivio;
- **Auguri** — interruttore on/off, ora di invio, oggetto e testo con i segnaposto
  `{nome}` `{cognome}` `{eta}` `{anno_nascita}`, indirizzo in Ccn, prova di invio con dati
  finti e tabella dei compleanni nei 30 giorni successivi.

Note di funzionamento:

- l'invio parte **spento**: si accende dalla scheda *Auguri* dopo aver importato la lista;
- niente doppioni: ogni socio ha `ultimo_invio_anno`, quindi più esecuzioni nello stesso
  giorno non rimandano la stessa email;
- il 29 febbraio, negli anni non bisestili, gli auguri escono il 28.

> **WP-Cron e cron di sistema.** WP-Cron gira solo quando qualcuno visita il sito: su un
> sito a basso traffico gli invii pianificati uscirebbero in ritardo. Il ruolo `wordpress`
> installa quindi un cron di sistema per `www-data` che ogni 15 minuti esegue
> `wp cron event run --due-now`. Vale per tutti gli eventi pianificati, non solo i compleanni.

```bash
# Stato della pianificazione e prova manuale (sul CT)
sudo -u www-data wp --path=/var/www/example.com cron event list | grep compleanni
sudo -u www-data wp --path=/var/www/example.com cron event run rcm_compleanni_invio_giornaliero
```

---

## 10. Manutenzione e tag utili

Esegui solo una parte del playbook con i tag:

```bash
ansible-playbook site.yml --ask-vault-pass --tags hardening
ansible-playbook site.yml --ask-vault-pass --tags nginx,php
ansible-playbook site.yml --ask-vault-pass --tags backup
ansible-playbook site.yml --ask-vault-pass --tags wordpress
```

Tag disponibili: `common`, `hardening`, `database`, `php`, `redis`, `nginx`, `wordpress`, `phpmyadmin`, `backup`, `sportspress`.

**Comandi WP-CLI utili** (sul CT, come `www-data`):

```bash
sudo -u www-data wp --path=/var/www/example.com plugin list
sudo -u www-data wp --path=/var/www/example.com redis status
sudo -u www-data wp --path=/var/www/example.com core update
```

---

## 10.1 Controllo visivo dopo gli aggiornamenti

Aggiornare WordPress, un plugin o un tema può cambiare l'aspetto del sito senza
che nessuno se ne accorga: un margine diverso, un font che non carica, una
sezione che sparisce in fondo alla pagina. `scripts/visual-check.py` fotografa
le pagine con Chrome headless (desktop 1440px e mobile 390px, pagina intera) e
le confronta pixel per pixel con un riferimento salvato in precedenza.

```bash
# PRIMA di aggiornare: fotografa lo stato attuale
make visual-baseline

# ... aggiorna plugin/temi, svuota le cache ...

# DOPO: dice quali pagine si sono mosse e a che altezza
make visual-check
```

`visual-check` esce con codice 1 se qualcosa è cambiato oltre la tolleranza, ed
è quindi utilizzabile anche in uno script. Per ogni pagina diversa salva in
`visual/shots/diff/` lo scatto nuovo con evidenziato in rosso ciò che si è
mosso. Se il cambiamento era voluto, si rifà la baseline.

**Cosa sta dove**

| File | Versionato | Contenuto |
|---|---|---|
| `visual/config.json` | sì | pagine sorvegliate e tolleranza di ognuna |
| `visual/baseline.json` | sì | dimensioni e impronta di ogni fascia di 16 righe |
| `visual/shots/` | no | gli screenshot veri (decine di MB, si rigenerano) |

**Il contenuto che cambia da solo.** Alcuni blocchi sono diversi a ogni
caricamento: lo slider della home, le citazioni a rotazione, la mappa di Google,
l'ordine casuale delle recensioni. Senza accorgimenti segnalerebbero una
differenza a ogni giro e il controllo diventerebbe inutile. `config.json` offre
tre modi, in ordine di preferenza:

| Chiave | Quando usarla |
|---|---|
| `nascondi` | il blocco ha un selettore CSS suo: viene reso invisibile prima dello scatto, **mantenendo l'ingombro**, così il resto della pagina resta confrontabile riga per riga. È il modo migliore: regge anche se il layout si sposta |
| `ignora` | non c'è un selettore pulito, ma l'area è nota: si escludono dal conteggio degli intervalli di righe, per viewport (`{"desktop": [[470, 1000]]}`). Attenzione, sono posizioni assolute: se sopra si aggiunge contenuto vanno ricalcolate |
| `tolleranza` | il rumore è sparso su tutta la pagina e non si può circoscrivere |

Per tutte le altre pagine la tolleranza di default è 0,05%: in pratica devono
restare identiche. Ciò che viene nascosto o ignorato **non è più sorvegliato**:
tienilo per i blocchi che davvero non si possono stabilizzare.

**Due avvertenze.**

- Il confronto pixel per pixel vale fra scatti dello *stesso* Chrome. Se il
  browser si aggiorna, l'antialiasing cambia ovunque e le differenze diventano
  diffuse e minuscole: lo script rileva il caso e lo dice, ma conviene rifare la
  baseline dopo un aggiornamento di Chrome.
- Gli screenshot dimostrano com'è il sito, non *perché*. Se qualcosa si muove,
  la controprova più diretta è guardare cosa è cambiato sul disco del server:
  `find /var/www/<sito> -newermt "-2 hours" -type f` e
  `wp plugin verify-checksums --all`.

Le dipendenze sono su Debian: `sudo apt install python3-websockets python3-pil
python3-numpy` più `google-chrome` (o `chromium`).

---

## 10.2 Newsletter ai soci (Mailchimp)

Le email al gruppo non partono dal sito ma da **Mailchimp** (account `us18`,
mittente `info@romaclubmatera.it`). Il sito raccoglie solo gli iscritti, con
il modulo nel piè di pagina.

Il template dell'email sta nel repo in
[`newsletter/`](newsletter/README.md) ed è caricato su Mailchimp come
**Newsletter Roma Club Matera**.

### Mandare una newsletter

1. Mailchimp → **Campagne** → *Crea* → **Email**.
2. Alla voce *Contenuto* → **Modifica design** → scheda **Salvati** →
   scegliere *Newsletter Roma Club Matera*.
3. Cliccare dentro i blocchi per cambiare il testo. Il blocco della notizia
   si **duplica**: una copia per ogni cosa da raccontare. Quello che si può
   modificare, zona per zona, è elencato in
   [`newsletter/README.md`](newsletter/README.md).
4. Compilare **Oggetto** e testo di anteprima. Sono le due righe che
   decidono se l'email viene aperta: l'anteprima non ripete l'oggetto, lo
   completa.
5. **Invia email di prova** a un proprio indirizzo e guardarla **dal
   telefono**, non solo dal computer: è lì che la legge quasi tutto il
   gruppo.
6. *Invia*.

### Cose da sapere prima di premere invia

- **L'invio non si annulla.** Non c'è un "richiama": una volta partita è
  partita, e sbagliare un nome o una data si vede.
- **Gli iscritti devono confermare.** L'iscrizione dal sito è a doppia
  conferma: chi non clicca il link che riceve per email **non entra nella
  lista** e non riceverà mai niente. Se qualcuno dice di essersi iscritto e
  non riceve, la prima cosa da controllare è questa, in *Pubblico →
  Contatti*, dove lo stato risulta *In attesa*.
- **Indirizzo e link di cancellazione sono obbligatori** e stanno già nel
  piè di pagina del template: non vanno tolti, né per legge né per
  Mailchimp, che senza si rifiuta di inviare.
- Con il **piano gratuito** Mailchimp aggiunge un suo badge in fondo
  all'email. Non è un errore del template.
- La versione in **solo testo** la genera Mailchimp da sola: serve ai client
  che non mostrano l'HTML, e si può correggere prima dell'invio.
- Le foto sono richiamate dal sito. **Se una foto viene cancellata da
  WordPress sparisce anche dalle email già inviate**: le immagini vecchie
  vanno lasciate dove sono.

### Se il template va rifatto

Si ricarica dal file nel repo — istruzioni in
[`newsletter/README.md`](newsletter/README.md). Un template a codice **non
si può aprire nel builder drag-and-drop** di Mailchimp: la struttura si
cambia solo modificando l'HTML e ricaricandolo, ed è il motivo per cui la
copia buona sta nel repo.

---

## 11. Troubleshooting

| Sintomo | Causa / Soluzione |
|---|---|
| `python3 not found` al primo run | Normale su minimal: il bootstrap lo installa, rilancia il playbook |
| Task sysctl "falliscono" silenziosamente | Atteso in LXC unprivileged: i parametri read-only vengono ignorati |
| `UFW` non si abilita | In alcuni CT serve `--features nesting=1`; il logging kernel è best-effort |
| phpMyAdmin 404 | Controlla `phpmyadmin_alias` e che il blocco location sia nel vhost (`nginx -t`) |
| Redis "Connection refused" | Verifica `systemctl status redis-server` e `wp redis status` |
| Certbot fallisce | Il dominio deve risolvere all'IP del CT e la porta 80 essere pubblica |
| Errore moduli MySQL | Il ruolo installa `python3-pymysql`; assicurati che il run sia arrivato al ruolo `database` |

---

## 12. Pubblicazione su GitHub

Dalla cartella del progetto:

```bash
git init
git add .
git commit -m "WordPress su Debian Trixie (Nginx+PHP-FPM 8.4+MariaDB+Redis) con hardening e backup"
git branch -M main

# Crea prima il repo vuoto su GitHub, poi:
git remote add origin https://github.com/mikysal78/wordpress-trixie-ansible.git
git push -u origin main
```

> ✅ Il `.gitignore` **esclude già** `group_vars/all/vars.yml`, `group_vars/all/vault.yml` e `inventory/hosts.yml` (la tua configurazione e le password non finiscono nel repo: vengono versionati solo i file `*.example`), le dipendenze Galaxy installate localmente (`galaxy_roles/`, `collections/`) e i file `credentials-*.txt`. Se vuoi versionare il vault *cifrato*, rimuovi la riga relativa dal `.gitignore` — ma **mai** committare vault o vars in chiaro.

Con `gh` CLI puoi creare il repo al volo:

```bash
gh repo create wordpress-trixie-ansible --public --source=. --remote=origin --push
```

---

## Licenza

**Tutti i diritti riservati** — vedi [LICENSE](LICENSE).

Il codice e le personalizzazioni di questo repository non sono riusabili
senza autorizzazione scritta dei titolari: Roma Club Matera "Francesco
Totti" per i contenuti e l'identità, mikysal78 come autore del codice
(info@romaclubmatera.it). Il software di terze parti installato dai
playbook resta soggetto alle proprie licenze.
