<?php
/**
 * Plugin Name: RCM - Area soci
 * Description: Area riservata ai tesserati: accesso via email con link e codice, tessera digitale, dati personali. I soci non sono utenti WordPress. Interruttore spenta/prova/attiva in Soci > Area soci. Lo stile sta in rcm-area-soci/area-soci.css.
 * Version: 1.0.1
 * Author: Roma Club Matera
 *
 * COME FUNZIONA, E PERCHE' COSI'
 *
 * - I soci NON sono utenti WordPress. Stanno nella tabella dei soci
 *   (mu-plugin rcm-compleanni) e li inserisce il Club: nessuna registrazione.
 *   Sul sito restano solo gli amministratori, quindi vale ancora la
 *   valutazione per cui le vulnerabilita' "Contributor+" dei plugin del tema
 *   non sono sfruttabili.
 * - Entra chi ha una tessera annuale della stagione in corso
 *   (rcm_soci_tipologie, rcm_soci_stagione_corrente). La casella "attivo"
 *   della tabella vuol dire "riceve gli auguri" e con l'accesso non c'entra.
 * - Niente password. Il socio scrive la sua email e riceve un link e un codice
 *   di 6 cifre, validi 15 minuti e una volta sola. Il codice serve quando
 *   l'email si legge su un altro dispositivo, e su iPhone, dove il sito
 *   installato ha cookie separati da Safari.
 * - La tessera digitale e' il solo retro, quello con i dati da mostrare
 *   all'ingresso della sede (decisione di Michele, 18/09/2026).
 * - Il link NON fa entrare da solo: apre una pagina con il pulsante "Entra".
 *   Molti programmi di posta aprono i link per controllarli, e un link che
 *   facesse entrare subito verrebbe "consumato" da quel controllo.
 * - Il modulo risponde sempre allo stesso modo, che l'email sia di un socio o
 *   no: altrimenti si potrebbe scoprire chi e' socio provando degli indirizzi.
 * - Le sessioni stanno sul server: il cookie contiene solo un valore casuale,
 *   e "esci" chiude davvero la sessione.
 * - Il cookie di sessione si chiama rcm_socio: e' il nome escluso dalla cache
 *   in nginx (roles/webserver/templates/wordpress.conf.j2). Se cambia qui, va
 *   cambiato anche li', o le pagine personali tornano in cache.
 *
 * L'INTERRUTTORE (Soci > Area soci)
 *
 * - spenta: la pagina risponde 404, nessuna email parte;
 * - prova: la pagina la vedono gli amministratori e chi apre il link di prova
 *   segreto; le email partono solo verso gli indirizzi di prova;
 * - attiva: aperta ai soci. La accende il Club.
 */

defined( 'ABSPATH' ) || exit;

const RCM_AS_VERSIONE       = '1.0.1';
const RCM_AS_OPZIONE        = 'rcm_area_soci';
const RCM_AS_DB             = 'rcm_as_db_version';
const RCM_AS_DB_VER         = '1.0';
const RCM_AS_PAGINA         = 'area-soci';
const RCM_AS_COOKIE         = 'rcm_socio';     // lo stesso nome escluso dalla cache in nginx
const RCM_AS_COOKIE_PROVA   = 'rcm_as_prova';
const RCM_AS_COOKIE_EMAIL   = 'rcm_as_email';
const RCM_AS_DURATA_LINK    = 900;             // 15 minuti
const RCM_AS_DURATA_SESSIONE = 5184000;        // 60 giorni
const RCM_AS_TENTATIVI      = 5;

/* -------------------------------------------------------------------------
 * Tabelle: accessi (link e codici) e sessioni
 * ---------------------------------------------------------------------- */

function rcm_as_tab( $nome ) {
	global $wpdb;
	return $wpdb->prefix . 'rcm_soci_' . $nome;
}

// Su init e non su admin_init: la pagina puo' essere aperta da un socio prima
// che un amministratore passi dalla bacheca, e senza tabelle darebbe errore.
add_action( 'init', 'rcm_as_installa', 1 );
function rcm_as_installa() {
	if ( get_option( RCM_AS_DB ) === RCM_AS_DB_VER ) {
		return;
	}
	global $wpdb;
	$c = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		'CREATE TABLE ' . rcm_as_tab( 'accessi' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		socio_id bigint(20) unsigned NOT NULL,
		token_hash char(64) NOT NULL,
		codice_hash char(64) NOT NULL,
		tentativi tinyint(3) unsigned NOT NULL DEFAULT 0,
		scade_il datetime NOT NULL,
		usato_il datetime DEFAULT NULL,
		creato_il datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY token_hash (token_hash),
		KEY socio_id (socio_id)
	) $c;"
	);
	dbDelta(
		'CREATE TABLE ' . rcm_as_tab( 'sessioni' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		socio_id bigint(20) unsigned NOT NULL,
		token_hash char(64) NOT NULL,
		agente varchar(190) NOT NULL DEFAULT '',
		creato_il datetime NOT NULL,
		scade_il datetime NOT NULL,
		ultimo_uso datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		KEY socio_id (socio_id)
	) $c;"
	);
	update_option( RCM_AS_DB, RCM_AS_DB_VER, false );
}

/* -------------------------------------------------------------------------
 * Interruttore e regole di accesso
 * ---------------------------------------------------------------------- */

function rcm_as_opzioni() {
	$o = wp_parse_args(
		get_option( RCM_AS_OPZIONE, array() ),
		array(
			'stato'  => 'spenta',
			'tester' => array(),
			'chiave' => '',
		)
	);
	if ( ! in_array( $o['stato'], array( 'spenta', 'prova', 'attiva' ), true ) ) {
		$o['stato'] = 'spenta';
	}
	$o['tester'] = array_values( array_filter( array_map( 'strtolower', (array) $o['tester'] ) ) );
	return $o;
}

function rcm_as_socio( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . rcm_compleanni_tabella() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

function rcm_as_socio_per_email( $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . rcm_compleanni_tabella() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Tessera annuale della stagione in corso: e' questo, e solo questo, che fa entrare. */
function rcm_as_socio_valido( $socio ) {
	if ( ! $socio || ! function_exists( 'rcm_soci_tipologie' ) ) {
		return false;
	}
	$tipologie = rcm_soci_tipologie();
	return isset( $tipologie[ $socio->tipologia ] ) && $socio->stagione === rcm_soci_stagione_corrente();
}

/** L'interruttore: in prova passano solo gli indirizzi di prova. */
function rcm_as_email_ammessa( $email ) {
	$o = rcm_as_opzioni();
	if ( 'attiva' === $o['stato'] ) {
		return true;
	}
	if ( 'prova' === $o['stato'] ) {
		return in_array( strtolower( (string) $email ), $o['tester'], true );
	}
	return false;
}

function rcm_as_puo_entrare( $socio ) {
	return $socio && rcm_as_socio_valido( $socio ) && rcm_as_email_ammessa( $socio->email );
}

/* -------------------------------------------------------------------------
 * Attrezzi
 * ---------------------------------------------------------------------- */

/** Nel database vanno solo impronte: chi legge le tabelle non trova niente da usare. */
function rcm_as_hash( $valore ) {
	return hash_hmac( 'sha256', (string) $valore, wp_salt( 'auth' ) . '|rcm_area_soci' );
}

function rcm_as_token() {
	return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
}

/** Le scadenze si confrontano in UTC: niente sorprese col cambio dell'ora. */
function rcm_as_ora_utc( $piu = 0 ) {
	return gmdate( 'Y-m-d H:i:s', time() + $piu );
}

function rcm_as_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

function rcm_as_cookie( $nome, $valore, $scadenza ) {
	setcookie(
		$nome,
		$valore,
		array(
			'expires'  => $scadenza,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			// Lax e non Strict: il cookie deve viaggiare anche quando si arriva
			// dall'email, che e' una navigazione da un altro sito.
			'samesite' => 'Lax',
		)
	);
	if ( $scadenza < time() ) {
		unset( $_COOKIE[ $nome ] );
	} else {
		$_COOKIE[ $nome ] = $valore;
	}
}

/** Al massimo $max volte in $finestra secondi. */
function rcm_as_limite( $chiave, $max, $finestra ) {
	$k = 'rcm_as_l_' . md5( $chiave );
	$n = (int) get_transient( $k );
	if ( $n >= $max ) {
		return false;
	}
	set_transient( $k, $n + 1, $finestra );
	return true;
}

function rcm_as_url_pagina( $args = array() ) {
	$pagina = get_page_by_path( RCM_AS_PAGINA );
	$url    = $pagina ? get_permalink( $pagina ) : home_url( '/' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

function rcm_as_torna( $args = array() ) {
	wp_safe_redirect( rcm_as_url_pagina( $args ) );
	exit;
}

function rcm_as_turnstile_ok() {
	// la verifica del plugin conosce anche whitelist e failsafe: se c'e', vince lei
	if ( function_exists( 'cfturnstile_check' ) ) {
		$esito = cfturnstile_check();
		return is_array( $esito ) && ! empty( $esito['success'] );
	}
	return true; // chiavi non configurate: restano i limiti sui tentativi
}

/* -------------------------------------------------------------------------
 * Link e codici di accesso
 * ---------------------------------------------------------------------- */

/** Crea un accesso nuovo e chiude quelli vecchi non usati: ne vale uno solo alla volta. */
function rcm_as_crea_accesso( $socio_id ) {
	global $wpdb;
	$t = rcm_as_tab( 'accessi' );
	$wpdb->query( $wpdb->prepare( "UPDATE $t SET usato_il = %s WHERE socio_id = %d AND usato_il IS NULL", rcm_as_ora_utc(), $socio_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

	$token  = rcm_as_token();
	$codice = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	$wpdb->insert(
		$t,
		array(
			'socio_id'    => $socio_id,
			'token_hash'  => rcm_as_hash( 't|' . $token ),
			'codice_hash' => rcm_as_hash( 'c|' . $socio_id . '|' . $codice ),
			'tentativi'   => 0,
			'scade_il'    => rcm_as_ora_utc( RCM_AS_DURATA_LINK ),
			'creato_il'   => rcm_as_ora_utc(),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array(
		'token'  => $token,
		'codice' => $codice,
	);
}

/** L'accesso a cui punta un link, se e' ancora buono. Non lo consuma. */
function rcm_as_accesso_da_token( $token ) {
	if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
		return null;
	}
	global $wpdb;
	$t = rcm_as_tab( 'accessi' );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token_hash = %s AND usato_il IS NULL AND scade_il > %s", rcm_as_hash( 't|' . $token ), rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Consuma un accesso. Vero solo per chi arriva primo: due richieste insieme non entrano entrambe. */
function rcm_as_consuma( $accesso_id ) {
	global $wpdb;
	$t = rcm_as_tab( 'accessi' );
	return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET usato_il = %s WHERE id = %d AND usato_il IS NULL", rcm_as_ora_utc(), $accesso_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Il codice di 6 cifre: al quinto tentativo sbagliato l'accesso non vale piu'. */
function rcm_as_verifica_codice( $socio, $codice ) {
	global $wpdb;
	$t = rcm_as_tab( 'accessi' );
	$a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE socio_id = %d AND usato_il IS NULL AND scade_il > %s ORDER BY id DESC LIMIT 1", $socio->id, rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! $a || (int) $a->tentativi >= RCM_AS_TENTATIVI ) {
		return false;
	}
	$wpdb->query( $wpdb->prepare( "UPDATE $t SET tentativi = tentativi + 1 WHERE id = %d", $a->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$codice = preg_replace( '/\D/', '', (string) $codice );
	if ( ! hash_equals( $a->codice_hash, rcm_as_hash( 'c|' . $socio->id . '|' . $codice ) ) ) {
		// all'ultimo tentativo si chiude anche il link: ne serve uno nuovo
		if ( (int) $a->tentativi + 1 >= RCM_AS_TENTATIVI ) {
			rcm_as_consuma( (int) $a->id );
		}
		return false;
	}
	return rcm_as_consuma( (int) $a->id );
}

/* -------------------------------------------------------------------------
 * Sessioni
 * ---------------------------------------------------------------------- */

function rcm_as_crea_sessione( $socio_id ) {
	global $wpdb;
	$t = rcm_as_tab( 'sessioni' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE socio_id = %d AND scade_il < %s", $socio_id, rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$token = rcm_as_token();
	$wpdb->insert(
		$t,
		array(
			'socio_id'   => $socio_id,
			'token_hash' => rcm_as_hash( 's|' . $token ),
			'agente'     => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 190 ),
			'creato_il'  => rcm_as_ora_utc(),
			'scade_il'   => rcm_as_ora_utc( RCM_AS_DURATA_SESSIONE ),
			'ultimo_uso' => rcm_as_ora_utc(),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	rcm_as_cookie( RCM_AS_COOKIE, $token, time() + RCM_AS_DURATA_SESSIONE );
}

/**
 * Il socio della sessione corrente, o null. Ricontrolla ogni volta che possa
 * ancora entrare: una tessera scaduta o un indirizzo tolto dalle prove chiudono
 * fuori subito, anche con una sessione aperta.
 */
function rcm_as_socio_corrente() {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$cache = null;
	$token = isset( $_COOKIE[ RCM_AS_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ RCM_AS_COOKIE ] ) ) : '';
	if ( '' === $token ) {
		return null;
	}
	global $wpdb;
	$t = rcm_as_tab( 'sessioni' );
	$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token_hash = %s AND scade_il > %s", rcm_as_hash( 's|' . $token ), rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! $s ) {
		return null;
	}
	$socio = rcm_as_socio( $s->socio_id );
	if ( ! rcm_as_puo_entrare( $socio ) ) {
		return null;
	}
	if ( strtotime( $s->ultimo_uso . ' UTC' ) < time() - 600 ) {
		$wpdb->update( $t, array( 'ultimo_uso' => rcm_as_ora_utc() ), array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$socio->sessione_id = (int) $s->id;
	$cache              = $socio;
	return $cache;
}

/* -------------------------------------------------------------------------
 * La pagina: nascosta finche' l'interruttore non dice altrimenti
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'rcm_as_cancello', 1 );
function rcm_as_cancello() {
	if ( ! is_page( RCM_AS_PAGINA ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );

	$o = rcm_as_opzioni();
	if ( current_user_can( 'manage_options' ) || 'attiva' === $o['stato'] ) {
		return;
	}
	if ( 'prova' === $o['stato'] && '' !== $o['chiave'] ) {
		$impronta = rcm_as_hash( 'prova|' . $o['chiave'] );
		$data     = isset( $_GET['chiave'] ) ? sanitize_text_field( wp_unslash( $_GET['chiave'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $data && hash_equals( $o['chiave'], $data ) ) {
			rcm_as_cookie( RCM_AS_COOKIE_PROVA, $impronta, time() + 30 * DAY_IN_SECONDS );
			// via la chiave dall'indirizzo: non resta nella cronologia
			wp_safe_redirect( remove_query_arg( 'chiave' ) );
			exit;
		}
		$cookie = isset( $_COOKIE[ RCM_AS_COOKIE_PROVA ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ RCM_AS_COOKIE_PROVA ] ) ) : '';
		if ( '' !== $cookie && hash_equals( $impronta, $cookie ) ) {
			return;
		}
	}
	rcm_as_404();
}

function rcm_as_404() {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	$modello = get_query_template( '404' );
	if ( $modello ) {
		include $modello;
	}
	exit;
}

// Mai nella sitemap, mai sui motori, mai nella ricerca interna.
add_filter(
	'wpseo_exclude_from_sitemap_by_post_ids',
	function ( $ids ) {
		$p = get_page_by_path( RCM_AS_PAGINA );
		if ( $p ) {
			$ids[] = $p->ID;
		}
		return $ids;
	}
);
add_filter(
	'wpseo_robots',
	function ( $robots ) {
		return is_page( RCM_AS_PAGINA ) ? 'noindex, nofollow' : $robots;
	}
);
add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( ! is_admin() && $q->is_main_query() && $q->is_search() ) {
			$p = get_page_by_path( RCM_AS_PAGINA );
			if ( $p ) {
				$q->set( 'post__not_in', array_merge( (array) $q->get( 'post__not_in' ), array( $p->ID ) ) );
			}
		}
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_page( RCM_AS_PAGINA ) ) {
			$base = WPMU_PLUGIN_URL . '/rcm-area-soci/';
			// Oswald: il carattere stretto piu' vicino alle scritte stampate sulla tessera
			wp_enqueue_style( 'rcm-area-soci-font', 'https://fonts.googleapis.com/css2?family=Oswald:wght@600&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_enqueue_style( 'rcm-area-soci', $base . 'area-soci.css', array(), RCM_AS_VERSIONE );
			wp_enqueue_script( 'rcm-area-soci', $base . 'area-soci.js', array(), RCM_AS_VERSIONE, true );
		}
	}
);

/* -------------------------------------------------------------------------
 * Le azioni: richiesta, ingresso, uscita, dati
 * ---------------------------------------------------------------------- */

foreach ( array( 'richiedi', 'entra', 'esci', 'salva' ) as $rcm_as_azione ) {
	add_action( 'admin_post_nopriv_rcm_as_' . $rcm_as_azione, 'rcm_as_' . $rcm_as_azione );
	add_action( 'admin_post_rcm_as_' . $rcm_as_azione, 'rcm_as_' . $rcm_as_azione );
}

function rcm_as_nonce_ok( $azione ) {
	return (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_rcm_as'] ?? '' ) ), $azione ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

function rcm_as_richiedi() {
	if ( ! rcm_as_nonce_ok( 'rcm_as_richiedi' ) ) {
		rcm_as_torna( array( 'avviso' => 'scaduto' ) );
	}
	if ( ! rcm_as_turnstile_ok() ) {
		rcm_as_torna( array( 'avviso' => 'verifica' ) );
	}
	$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
	if ( ! is_email( $email ) ) {
		rcm_as_torna( array( 'avviso' => 'email' ) );
	}
	// Da qui in poi la risposta e' la stessa in ogni caso.
	if ( rcm_as_limite( 'e|' . $email, 3, 15 * MINUTE_IN_SECONDS ) && rcm_as_limite( 'ip|' . rcm_as_ip(), 10, HOUR_IN_SECONDS ) ) {
		$socio = rcm_as_socio_per_email( $email );
		if ( rcm_as_puo_entrare( $socio ) ) {
			rcm_as_manda_email( $socio, rcm_as_crea_accesso( (int) $socio->id ), false );
		}
	}
	rcm_as_cookie( RCM_AS_COOKIE_EMAIL, $email, time() + 15 * MINUTE_IN_SECONDS );
	rcm_as_torna( array( 'inviato' => 1 ) );
}

function rcm_as_entra() {
	if ( ! rcm_as_nonce_ok( 'rcm_as_entra' ) ) {
		rcm_as_torna( array( 'avviso' => 'scaduto' ) );
	}
	if ( ! rcm_as_limite( 'entra|' . rcm_as_ip(), 20, HOUR_IN_SECONDS ) ) {
		rcm_as_torna( array( 'avviso' => 'troppi' ) );
	}

	$modo = sanitize_key( wp_unslash( $_POST['modo'] ?? '' ) );
	if ( 'link' === $modo ) {
		$a     = rcm_as_accesso_da_token( sanitize_text_field( wp_unslash( $_POST['accesso'] ?? '' ) ) );
		$socio = $a ? rcm_as_socio( $a->socio_id ) : null;
		if ( $a && rcm_as_puo_entrare( $socio ) && rcm_as_consuma( (int) $a->id ) ) {
			rcm_as_crea_sessione( (int) $socio->id );
			rcm_as_torna();
		}
		rcm_as_torna( array( 'avviso' => 'link' ) );
	}

	$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
	$socio = rcm_as_socio_per_email( $email );
	if ( rcm_as_puo_entrare( $socio ) && rcm_as_verifica_codice( $socio, sanitize_text_field( wp_unslash( $_POST['codice'] ?? '' ) ) ) ) {
		rcm_as_crea_sessione( (int) $socio->id );
		rcm_as_torna();
	}
	rcm_as_torna(
		array(
			'inviato' => 1,
			'avviso'  => 'codice',
		)
	);
}

function rcm_as_esci() {
	if ( rcm_as_nonce_ok( 'rcm_as_esci' ) ) {
		$socio = rcm_as_socio_corrente();
		if ( $socio ) {
			global $wpdb;
			$wpdb->delete( rcm_as_tab( 'sessioni' ), array( 'id' => $socio->sessione_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		rcm_as_cookie( RCM_AS_COOKIE, '', time() - HOUR_IN_SECONDS );
	}
	rcm_as_torna( array( 'uscito' => 1 ) );
}

function rcm_as_salva() {
	$socio = rcm_as_socio_corrente();
	if ( ! $socio || ! rcm_as_nonce_ok( 'rcm_as_salva' ) ) {
		rcm_as_torna( array( 'avviso' => 'scaduto' ) );
	}
	$grezzo   = sanitize_text_field( wp_unslash( $_POST['telefono'] ?? '' ) );
	$telefono = '' === $grezzo ? '' : rcm_compleanni_telefono( $grezzo );
	if ( '' !== $grezzo && '' === $telefono ) {
		rcm_as_torna( array( 'avviso' => 'telefono' ) );
	}
	global $wpdb;
	$wpdb->update( rcm_compleanni_tabella(), array( 'telefono' => $telefono ), array( 'id' => $socio->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	rcm_as_torna( array( 'salvato' => 1 ) );
}

/* -------------------------------------------------------------------------
 * L'email: accesso e benvenuto
 * ---------------------------------------------------------------------- */

function rcm_as_manda_email( $socio, $accesso, $benvenuto ) {
	// Seconda difesa: anche se qualcosa sopra sbagliasse, in prova parte solo
	// verso gli indirizzi di prova, e da spenta non parte niente.
	if ( ! rcm_as_email_ammessa( $socio->email ) ) {
		return false;
	}
	$o    = rcm_as_opzioni();
	$args = array( 'accesso' => $accesso['token'] );
	if ( 'prova' === $o['stato'] ) {
		$args['chiave'] = $o['chiave'];
	}
	$url    = rcm_as_url_pagina( $args );
	$codice = substr( $accesso['codice'], 0, 3 ) . ' ' . substr( $accesso['codice'], 3 );
	$nome   = esc_html( $socio->nome );

	$oggetto = $benvenuto
		? 'Benvenuto nell\'area soci del Roma Club Matera'
		: 'Il tuo accesso all\'area soci del Roma Club Matera';
	$apertura = $benvenuto
		? "<p>Ciao $nome,</p><p>da oggi hai la tua <strong>area riservata</strong> sul sito del Roma Club Matera: dentro trovi la <strong>tessera digitale</strong>, da mostrare all'ingresso della sede, e i tuoi dati. Non serve nessuna password.</p>"
		: "<p>Ciao $nome,</p><p>ecco il tuo accesso all'area soci.</p>";

	$html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:520px">'
		. $apertura
		. '<p style="margin:26px 0"><a href="' . esc_url( $url ) . '" style="background:#8e1f2f;color:#fff;padding:13px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Entra nell\'area soci</a></p>'
		. '<p>Oppure, se stai usando un altro dispositivo, scrivi questo codice nella pagina di accesso:</p>'
		. '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;margin:8px 0 22px">' . esc_html( $codice ) . '</p>'
		. '<p style="color:#666;font-size:13px">Il link e il codice valgono 15 minuti e si usano una volta sola. Se non hai chiesto tu di entrare, ignora questa email: senza il link o il codice nessuno può accedere.</p>'
		. '<p style="color:#666;font-size:13px">Roma Club Matera &ldquo;Francesco Totti&rdquo;</p></div>';

	return wp_mail( $socio->email, $oggetto, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/* -------------------------------------------------------------------------
 * Il benvenuto, dal modulo del socio in bacheca
 * ---------------------------------------------------------------------- */

add_action( 'rcm_soci_modulo_campi', 'rcm_as_campo_benvenuto' );
function rcm_as_campo_benvenuto( $socio ) {
	$o     = rcm_as_opzioni();
	$spenta = 'spenta' === $o['stato'];
	?>
	<tr><th>Area soci</th><td>
		<label><input type="checkbox" name="rcm_as_benvenuto" value="1" <?php disabled( $spenta ); ?>> manda l'email di benvenuto</label>
		<p class="description">
			<?php if ( $spenta ) : ?>
				L'area soci è <strong>spenta</strong>: il benvenuto si potrà mandare quando la accendete, da <em>Soci &rsaquo; Area soci</em>.
			<?php elseif ( 'prova' === $o['stato'] ) : ?>
				L'area soci è <strong>in prova</strong>: l'email parte solo se l'indirizzo è fra quelli di prova.
			<?php else : ?>
				Con il primo link di accesso e due righe di spiegazione. Serve una tessera della stagione in corso.
			<?php endif; ?>
			<?php if ( $socio && ! empty( $socio->benvenuto_il ) ) : ?>
				<br>Già mandata il <?php echo esc_html( mysql2date( 'd/m/Y \a\l\l\e H:i', $socio->benvenuto_il ) ); ?>.
			<?php endif; ?>
		</p>
	</td></tr>
	<?php
}

add_action( 'rcm_socio_salvato', 'rcm_as_dopo_salvataggio', 10, 2 );
function rcm_as_dopo_salvataggio( $id, $nuovo ) {
	if ( empty( $_POST['rcm_as_benvenuto'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato dalla pagina Soci
		return;
	}
	$socio = rcm_as_socio( $id );
	if ( ! rcm_as_socio_valido( $socio ) ) {
		rcm_compleanni_avviso( 'Benvenuto non mandato: serve una tessera annuale della stagione in corso.', 'warning' );
		return;
	}
	if ( ! rcm_as_email_ammessa( $socio->email ) ) {
		rcm_compleanni_avviso( 'Benvenuto non mandato: l\'area soci è spenta, o in prova e questo indirizzo non è fra quelli di prova.', 'warning' );
		return;
	}
	if ( rcm_as_manda_email( $socio, rcm_as_crea_accesso( (int) $id ), true ) ) {
		global $wpdb;
		$wpdb->update( rcm_compleanni_tabella(), array( 'benvenuto_il' => current_time( 'mysql' ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		rcm_compleanni_avviso( 'Email di benvenuto mandata a ' . esc_html( $socio->email ) . '.' );
	} else {
		rcm_compleanni_avviso( 'L\'email di benvenuto non è partita: controlla la posta del sito.', 'error' );
	}
}

/* -------------------------------------------------------------------------
 * Impostazioni: Soci > Area soci
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rcm-soci', 'Area soci', 'Area soci', 'manage_options', 'rcm-area-soci', 'rcm_as_pagina_impostazioni' );
	},
	20
);

function rcm_as_pagina_impostazioni() {
	$o = rcm_as_opzioni();
	if ( isset( $_POST['rcm_as_salva_impostazioni'] ) && check_admin_referer( 'rcm_as_impostazioni' ) ) {
		$stato  = sanitize_key( wp_unslash( $_POST['stato'] ?? 'spenta' ) );
		$tester = array();
		foreach ( preg_split( '/[\s,;]+/', (string) wp_unslash( $_POST['tester'] ?? '' ) ) as $riga ) {
			$e = strtolower( sanitize_email( $riga ) );
			if ( is_email( $e ) ) {
				$tester[] = $e;
			}
		}
		$chiave = $o['chiave'];
		if ( '' === $chiave || ! empty( $_POST['rigenera'] ) ) {
			$chiave = wp_generate_password( 24, false );
		}
		$o = array(
			'stato'  => in_array( $stato, array( 'spenta', 'prova', 'attiva' ), true ) ? $stato : 'spenta',
			'tester' => array_values( array_unique( $tester ) ),
			'chiave' => $chiave,
		);
		update_option( RCM_AS_OPZIONE, $o, false );
		rcm_compleanni_avviso( 'Impostazioni salvate.' );
		$o = rcm_as_opzioni();
	}

	global $wpdb;
	$tip    = function_exists( 'rcm_soci_tipologie' ) ? array_keys( rcm_soci_tipologie() ) : array();
	$validi = $tip ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . rcm_compleanni_tabella() . ' WHERE stagione = %s AND tipologia IN (' . implode( ',', array_fill( 0, count( $tip ), '%s' ) ) . ')', array_merge( array( rcm_soci_stagione_corrente() ), $tip ) ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
	$link   = '' !== $o['chiave'] ? rcm_as_url_pagina( array( 'chiave' => $o['chiave'] ) ) : '';
	?>
	<div class="wrap">
		<h1>Area soci</h1>
		<p><strong><?php echo esc_html( $validi ); ?></strong> soci hanno una tessera della stagione <?php echo esc_html( function_exists( 'rcm_soci_stagione_corrente' ) ? rcm_soci_stagione_corrente() : '' ); ?> e, ad area attiva, potrebbero entrare.</p>
		<form method="post">
			<?php wp_nonce_field( 'rcm_as_impostazioni' ); ?>
			<table class="form-table">
				<tr><th>Stato</th><td>
					<?php
					$voci = array(
						'spenta' => 'Spenta — la pagina non esiste per nessuno, nessuna email parte',
						'prova'  => 'Prova — la vedono gli amministratori e chi apre il link di prova; le email partono solo verso gli indirizzi di prova',
						'attiva' => 'Attiva — aperta ai soci',
					);
					foreach ( $voci as $chiave => $etichetta ) :
						?>
						<label style="display:block;margin-bottom:6px"><input type="radio" name="stato" value="<?php echo esc_attr( $chiave ); ?>" <?php checked( $o['stato'], $chiave ); ?>> <?php echo esc_html( $etichetta ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th><label for="rcm-as-tester">Indirizzi di prova</label></th><td>
					<textarea id="rcm-as-tester" name="tester" rows="4" class="large-text" placeholder="uno per riga"><?php echo esc_textarea( implode( "\n", $o['tester'] ) ); ?></textarea>
					<p class="description">Contano solo in prova. Devono essere soci in archivio con una tessera della stagione in corso.</p>
				</td></tr>
				<?php if ( $link ) : ?>
					<tr><th>Link di prova</th><td>
						<input type="text" readonly class="large-text code" value="<?php echo esc_attr( $link ); ?>" onclick="this.select()">
						<p class="description">Da aprire sul telefono per vedere la pagina in prova. Non va condiviso fuori da chi prova.
							<label><input type="checkbox" name="rigenera" value="1"> cambia il link (quello vecchio smette di funzionare)</label></p>
					</td></tr>
				<?php endif; ?>
			</table>
			<?php submit_button( 'Salva', 'primary', 'rcm_as_salva_impostazioni' ); ?>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Pulizia notturna di link e sessioni scaduti
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'rcm_as_pulizia' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rcm_as_pulizia' );
		}
	}
);
add_action(
	'rcm_as_pulizia',
	function () {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rcm_as_tab( 'accessi' ) . ' WHERE scade_il < %s', rcm_as_ora_utc( -DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rcm_as_tab( 'sessioni' ) . ' WHERE scade_il < %s', rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
);

/* -------------------------------------------------------------------------
 * Il contenuto della pagina: [rcm_area_soci]
 * ---------------------------------------------------------------------- */

add_shortcode( 'rcm_area_soci', 'rcm_as_shortcode' );
function rcm_as_shortcode() {
	$o = rcm_as_opzioni();
	ob_start();
	echo '<div class="rcm-as">';

	if ( current_user_can( 'manage_options' ) && 'attiva' !== $o['stato'] ) {
		printf(
			'<p class="rcm-as-banner">Area soci <strong>%s</strong>: la vedi perché sei amministratore.</p>',
			'prova' === $o['stato'] ? 'in prova' : 'spenta'
		);
	}
	rcm_as_messaggi();

	$socio = rcm_as_socio_corrente();
	if ( $socio ) {
		rcm_as_mostra_tessera( $socio );
		rcm_as_mostra_dati( $socio );
	} elseif ( ! empty( $_GET['accesso'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		rcm_as_mostra_conferma( sanitize_text_field( wp_unslash( $_GET['accesso'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} elseif ( ! empty( $_GET['inviato'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		rcm_as_mostra_codice();
	} else {
		rcm_as_mostra_richiesta();
	}

	echo '</div>';
	return ob_get_clean();
}

function rcm_as_messaggi() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$avvisi = array(
		'scaduto'  => 'La pagina è rimasta aperta troppo a lungo: riprova.',
		'verifica' => 'Non siamo riusciti a verificare che tu non sia un robot: riprova.',
		'email'    => 'Scrivi un indirizzo email valido.',
		'link'     => 'Questo link non vale più: è già stato usato, oppure sono passati più di 15 minuti. Chiedine uno nuovo qui sotto.',
		'codice'   => 'Codice non valido o scaduto. Dopo cinque tentativi sbagliati ne serve uno nuovo.',
		'troppi'   => 'Troppi tentativi: riprova fra un\'ora.',
		'telefono' => 'Numero di cellulare non riconosciuto: non l\'abbiamo salvato.',
	);
	$chiave = isset( $_GET['avviso'] ) ? sanitize_key( wp_unslash( $_GET['avviso'] ) ) : '';
	if ( isset( $avvisi[ $chiave ] ) ) {
		echo '<p class="rcm-as-avviso rcm-as-avviso--errore">' . esc_html( $avvisi[ $chiave ] ) . '</p>';
	}
	if ( ! empty( $_GET['salvato'] ) ) {
		echo '<p class="rcm-as-avviso">Dati salvati.</p>';
	}
	if ( ! empty( $_GET['uscito'] ) ) {
		echo '<p class="rcm-as-avviso">Sei uscito dall\'area soci.</p>';
	}
	// phpcs:enable
}

function rcm_as_azione_url() {
	return admin_url( 'admin-post.php' );
}

function rcm_as_mostra_richiesta() {
	?>
	<section class="rcm-as-scheda">
		<h2 class="rcm-as-titolo">Entra nell'area soci</h2>
		<p>Scrivi l'email con cui sei tesserato: ti mandiamo un link per entrare e un codice di 6 cifre. Nessuna password da ricordare.</p>
		<form method="post" action="<?php echo esc_url( rcm_as_azione_url() ); ?>" class="rcm-as-modulo">
			<input type="hidden" name="action" value="rcm_as_richiedi">
			<?php wp_nonce_field( 'rcm_as_richiedi', '_rcm_as' ); ?>
			<label for="rcm-as-email">Email</label>
			<input id="rcm-as-email" type="email" name="email" required autocomplete="email" inputmode="email">
			<?php
			if ( shortcode_exists( 'simple-turnstile' ) ) {
				echo do_shortcode( '[simple-turnstile]' );
			}
			?>
			<button type="submit">Mandami il link</button>
		</form>
		<p class="rcm-as-nota">L'area è riservata ai tesserati della stagione in corso. Non sei tesserato? <a href="<?php echo esc_url( home_url( '/tesseramento-2026-27/' ) ); ?>">Tesserati al Roma Club Matera</a>.</p>
	</section>
	<?php
}

function rcm_as_mostra_codice() {
	$email = isset( $_COOKIE[ RCM_AS_COOKIE_EMAIL ] ) ? sanitize_email( wp_unslash( $_COOKIE[ RCM_AS_COOKIE_EMAIL ] ) ) : '';
	?>
	<section class="rcm-as-scheda">
		<h2 class="rcm-as-titolo">Controlla la posta</h2>
		<p>Se l'indirizzo è di un socio, ti abbiamo mandato un'email con <strong>un link e un codice di 6 cifre</strong>. Guarda anche nella posta indesiderata.</p>
		<p>Puoi toccare il link nell'email, oppure scrivere qui il codice:</p>
		<form method="post" action="<?php echo esc_url( rcm_as_azione_url() ); ?>" class="rcm-as-modulo">
			<input type="hidden" name="action" value="rcm_as_entra">
			<input type="hidden" name="modo" value="codice">
			<?php wp_nonce_field( 'rcm_as_entra', '_rcm_as' ); ?>
			<label for="rcm-as-email2">Email</label>
			<input id="rcm-as-email2" type="email" name="email" required autocomplete="email" value="<?php echo esc_attr( $email ); ?>">
			<label for="rcm-as-codice">Codice</label>
			<input id="rcm-as-codice" name="codice" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="rcm-as-codice">
			<button type="submit">Entra</button>
		</form>
		<p class="rcm-as-nota"><a href="<?php echo esc_url( rcm_as_url_pagina() ); ?>">Non è arrivata? Chiedine una nuova</a></p>
	</section>
	<?php
}

function rcm_as_mostra_conferma( $token ) {
	$a     = rcm_as_accesso_da_token( $token );
	$socio = $a ? rcm_as_socio( $a->socio_id ) : null;
	if ( ! $a || ! rcm_as_puo_entrare( $socio ) ) {
		echo '<p class="rcm-as-avviso rcm-as-avviso--errore">Questo link non vale più: è già stato usato, oppure sono passati più di 15 minuti.</p>';
		rcm_as_mostra_richiesta();
		return;
	}
	?>
	<section class="rcm-as-scheda">
		<h2 class="rcm-as-titolo">Ciao <?php echo esc_html( $socio->nome ); ?></h2>
		<p>Un tocco e sei dentro.</p>
		<form method="post" action="<?php echo esc_url( rcm_as_azione_url() ); ?>" class="rcm-as-modulo">
			<input type="hidden" name="action" value="rcm_as_entra">
			<input type="hidden" name="modo" value="link">
			<input type="hidden" name="accesso" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'rcm_as_entra', '_rcm_as' ); ?>
			<button type="submit">Entra come <?php echo esc_html( $socio->nome ); ?></button>
		</form>
	</section>
	<?php
}

function rcm_as_mostra_tessera( $socio ) {
	$tip   = rcm_soci_tipologie();
	$base  = WPMU_PLUGIN_URL . '/rcm-area-soci/';
	$campi = array(
		'nome'      => $socio->nome,
		'cognome'   => $socio->cognome,
		'tipologia' => $tip[ $socio->tipologia ] ?? '',
		// Il numero lo scrive il Club a mano: finche' non c'e', la tessera dice VIRTUAL.
		'numero'    => '' !== (string) $socio->numero_tessera ? $socio->numero_tessera : 'VIRTUAL',
		// Nel riquadro stretto della validita' la stagione ci sta solo corta.
		'validita'  => substr( (string) $socio->stagione, 2 ),
		'rilascio'  => mysql2date( 'd/m/Y', $socio->creato_il ),
	);
	?>
	<section class="rcm-as-tessera-box">
		<div class="rcm-as-tessera">
			<picture>
				<source srcset="<?php echo esc_url( $base . 'tessera-retro.webp' ); ?>" type="image/webp">
				<img src="<?php echo esc_url( $base . 'tessera-retro.jpg' ); ?>" alt="Tessera del socio del Roma Club Matera" width="1075" height="720">
			</picture>
			<?php foreach ( $campi as $campo => $valore ) : ?>
				<span class="rcm-as-campo rcm-as-campo--<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $valore ); ?></span>
			<?php endforeach; ?>
		</div>
		<p class="rcm-as-vivo"><span class="rcm-as-punto" aria-hidden="true"></span><span>Da mostrare all'ingresso</span><span class="rcm-as-ora" data-rcm-orologio><?php echo esc_html( wp_date( 'd/m/Y H:i:s' ) ); ?></span></p>
		<p class="rcm-as-nota">Data e ora scorrono dal vivo: uno screenshot resta fermo e si riconosce.</p>
	</section>
	<?php
}

function rcm_as_mostra_dati( $socio ) {
	$tel = $socio->telefono && function_exists( 'rcm_soci_telefono_leggibile' ) ? rcm_soci_telefono_leggibile( $socio->telefono ) : '';
	?>
	<section class="rcm-as-scheda">
		<h2 class="rcm-as-titolo">I tuoi dati</h2>
		<dl class="rcm-as-dati">
			<div><dt>Nome</dt><dd><?php echo esc_html( $socio->nome . ' ' . $socio->cognome ); ?></dd></div>
			<div><dt>Email</dt><dd><?php echo esc_html( $socio->email ); ?></dd></div>
		</dl>
		<form method="post" action="<?php echo esc_url( rcm_as_azione_url() ); ?>" class="rcm-as-modulo">
			<input type="hidden" name="action" value="rcm_as_salva">
			<?php wp_nonce_field( 'rcm_as_salva', '_rcm_as' ); ?>
			<label for="rcm-as-tel">Cellulare</label>
			<input id="rcm-as-tel" name="telefono" type="tel" autocomplete="tel" value="<?php echo esc_attr( $tel ); ?>" placeholder="377 281 4538">
			<button type="submit">Salva</button>
		</form>
		<p class="rcm-as-nota">Il cellulare serve al Club per gli auguri e per organizzare le trasferte. Per cambiare nome o email scrivi a <a href="mailto:info@romaclubmatera.it">info@romaclubmatera.it</a>.</p>
		<form method="post" action="<?php echo esc_url( rcm_as_azione_url() ); ?>" class="rcm-as-esci">
			<input type="hidden" name="action" value="rcm_as_esci">
			<?php wp_nonce_field( 'rcm_as_esci', '_rcm_as' ); ?>
			<button type="submit" class="rcm-as-secondario">Esci</button>
		</form>
	</section>
	<?php
}
