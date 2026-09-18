<?php
/**
 * Plugin Name: RCM - Verifica (API dell'app per i gestori)
 * Description: Il lato sito dell'app Android "Verifica RCM": chi gestisce i soci inquadra il QR della tessera e l'app mostra un pallino verde con la spunta o una grande X rossa, con nome e cognome. Tre controlli: ingresso in sede, pullman, biglietto. Qui ci sono le regole, l'API, il registro delle letture e la pagina Soci > App Verifica, da cui si scarica l'app e si revocano i telefoni.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * COME FUNZIONA, E PERCHE' COSI'
 *
 * - L'app e' nativa (Kotlin, repository privato romaclubmatera-app, cartella
 *   verifica/): la lettura del QR (ML Kit) e lo sblocco con impronta, volto o
 *   PIN (BiometricPrompt e Keystore di Android) avvengono sul telefono. Al
 *   sito arrivano solo il codice letto e un token.
 * - Il primo accesso e' con utente e password di WordPress, e solo per chi ha
 *   il permesso rcm_gestisci_soci (amministratori, "Gestore soci"). Il sito
 *   risponde con un token casuale, di cui conserva solo l'impronta. Il token
 *   vale per questa API e basta: non apre la bacheca. Cosi' un PIN di poche
 *   cifre sul telefono non diventa mai la chiave di un account amministratore.
 * - Ogni telefono ha il suo token, e si revoca da Soci > App Verifica. A ogni
 *   chiamata si ricontrolla che l'utente esista e abbia ancora il permesso.
 * - Le regole:
 *     ingresso  - tessera valida;
 *     pullman   - tessera valida e prenotazione Confermata con il pullman per
 *                 la partita scelta;
 *     biglietto - lo stesso, con il biglietto.
 *   "Prenotato" non basta: il pagamento non e' arrivato.
 * - Ogni lettura va in wp_rcm_soci_verifiche: serve all'appello sul pullman
 *   ("saliti 12 su 30") e a riconoscere un QR che passa due volte, che resta
 *   verde ma con l'avviso "gia' passato alle...": e' il segno di un QR
 *   fotografato e girato a qualcun altro.
 * - Notifiche delle nuove prenotazioni senza Firebase: l'app chiede
 *   /app/prenotazioni?dopo=<id> ogni 15 minuti (WorkManager, il minimo di
 *   Android per i lavori periodici) e mostra una notifica per ogni riga nuova.
 *   Niente account Google, niente chiavi da custodire; se un giorno servisse
 *   l'avviso istantaneo si aggiunge FCM.
 * - L'APK non sta nel docroot: si scarica da Soci > App Verifica, solo da chi
 *   gestisce i soci. Il percorso e' RCM_VER_APK.
 */

defined( 'ABSPATH' ) || exit;

const RCM_VER_DB     = 'rcm_ver_db_version';
const RCM_VER_DB_VER = '1.0';
const RCM_VER_APK    = '/var/www/rcm-privato/verifica-rcm.apk';
const RCM_VER_HEADER = 'X-RCM-App-Token';

function rcm_ver_tabella() {
	global $wpdb;
	return $wpdb->prefix . 'rcm_soci_verifiche';
}

function rcm_ver_tabella_dispositivi() {
	global $wpdb;
	return $wpdb->prefix . 'rcm_soci_app_dispositivi';
}

add_action( 'init', 'rcm_ver_installa', 1 );
function rcm_ver_installa() {
	if ( get_option( RCM_VER_DB ) === RCM_VER_DB_VER ) {
		return;
	}
	global $wpdb;
	$c = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		'CREATE TABLE ' . rcm_ver_tabella() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		socio_id bigint(20) unsigned NOT NULL DEFAULT 0,
		contesto varchar(10) NOT NULL,
		evento_id bigint(20) unsigned NOT NULL DEFAULT 0,
		esito tinyint(1) NOT NULL,
		motivo varchar(40) NOT NULL DEFAULT '',
		utente_id bigint(20) unsigned NOT NULL,
		dispositivo_id bigint(20) unsigned NOT NULL DEFAULT 0,
		creato_il datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY socio_contesto (socio_id,contesto,evento_id),
		KEY evento_id (evento_id)
	) $c;"
	);
	dbDelta(
		'CREATE TABLE ' . rcm_ver_tabella_dispositivi() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		utente_id bigint(20) unsigned NOT NULL,
		token_hash char(64) NOT NULL,
		nome varchar(190) NOT NULL DEFAULT '',
		versione varchar(20) NOT NULL DEFAULT '',
		creato_il datetime NOT NULL,
		ultimo_uso datetime NOT NULL,
		revocato_il datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		KEY utente_id (utente_id)
	) $c;"
	);
	update_option( RCM_VER_DB, RCM_VER_DB_VER, false );
}

function rcm_ver_contesti() {
	return array(
		'ingresso'  => 'Ingresso sede',
		'pullman'   => 'Pullman',
		'biglietto' => 'Biglietto',
	);
}

/* -------------------------------------------------------------------------
 * Le regole
 * ---------------------------------------------------------------------- */

/**
 * L'esito di una lettura: ok, titolo, nome (nome e cognome), righe, e per il
 * registro socio_id e motivo.
 */
function rcm_ver_esito( $token, $contesto, $evento_id ) {
	$ko = function ( $titolo, $motivo, $nome = '', $righe = array() ) {
		return compact( 'titolo', 'motivo', 'nome', 'righe' ) + array( 'ok' => false );
	};

	$socio = function_exists( 'rcm_qr_socio_da_token' ) ? rcm_qr_socio_da_token( $token ) : null;
	if ( ! $socio ) {
		return $ko( 'QR non riconosciuto', 'qr', '', array( 'Non è il QR di un socio, oppure è stato sostituito.' ) ) + array( 'socio_id' => 0 );
	}
	$nome = trim( $socio->nome . ' ' . $socio->cognome );
	$tip  = rcm_soci_tipologie();
	$base = array( 'socio_id' => (int) $socio->id );

	if ( ! rcm_soci_tessera_valida( $socio ) ) {
		$senza = empty( $socio->tipologia ) || ! isset( $tip[ $socio->tipologia ] );
		return $ko(
			$senza ? 'Nessuna tessera' : 'Tessera scaduta',
			$senza ? 'senza_tessera' : 'scaduta',
			$nome,
			$senza ? array( 'Non ha una tessera annuale.' ) : array( 'Tessera ' . $tip[ $socio->tipologia ] . ' ' . $socio->stagione . ': non rinnovata.' )
		) + $base;
	}

	$tessera = $tip[ $socio->tipologia ] . ' · ' . ( '' !== (string) $socio->numero_tessera ? 'n. ' . $socio->numero_tessera : 'VIRTUAL' ) . ' · ' . $socio->stagione;

	if ( 'ingresso' === $contesto ) {
		return array(
			'ok'     => true,
			'titolo' => 'Tessera valida',
			'motivo' => '',
			'nome'   => $nome,
			'righe'  => array( $tessera ),
		) + $base;
	}

	$partita = function_exists( 'rcm_pr_partita' ) ? rcm_pr_partita( $evento_id ) : null;
	if ( ! $partita ) {
		return $ko( 'Partita non scelta', 'partita', $nome, array( 'Scegli la partita in alto.' ) ) + $base;
	}
	$p     = rcm_pr_prenotazione( $socio->id, $partita->id );
	$serve = 'pullman' === $contesto ? 'pullman' : 'biglietto';
	$cosa  = 'pullman' === $contesto ? 'posto in pullman' : 'biglietto';
	if ( ! $p || 'annullato' === $p->stato || empty( $p->$serve ) ) {
		return $ko( 'pullman' === $contesto ? 'Nessun posto in pullman' : 'Nessun biglietto', 'non_prenotato', $nome, array( 'Nessun ' . $cosa . ' prenotato per ' . $partita->titolo . '.', $tessera ) ) + $base;
	}
	if ( 'confermato' !== $p->stato ) {
		return $ko( 'Non pagato', 'non_pagato', $nome, array( 'Prenotazione per ' . $partita->titolo . ' non ancora confermata: il pagamento non risulta.', $tessera ) ) + $base;
	}

	$righe = array( $partita->titolo );
	if ( 'biglietto' === $contesto ) {
		$righe[] = 'Settore: ' . $p->settore;
	}
	$persone = rcm_pr_persone( $p );
	if ( $persone ) {
		$righe[] = 'Con ' . count( $persone ) . ( 1 === count( $persone ) ? ' persona:' : ' persone:' );
		foreach ( $persone as $x ) {
			$righe[] = '· ' . $x['nome'] . ( $x['tesserato'] ? ' (tesserato)' : ' (non tesserato, con sovrapprezzo)' );
		}
	}
	$righe[] = $tessera;
	return array(
		'ok'     => true,
		'titolo' => 'pullman' === $contesto ? 'Può salire' : 'Biglietto confermato',
		'motivo' => '',
		'nome'   => $nome,
		'righe'  => $righe,
	) + $base;
}

/** L'ultima lettura buona dello stesso socio, nello stesso controllo. */
function rcm_ver_gia_passato( $socio_id, $contesto, $evento_id ) {
	global $wpdb;
	$t = rcm_ver_tabella();
	if ( 'ingresso' === $contesto ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT creato_il FROM $t WHERE socio_id = %d AND contesto = 'ingresso' AND esito = 1 AND creato_il >= %s ORDER BY id DESC LIMIT 1", $socio_id, wp_date( 'Y-m-d 00:00:00' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
	return $wpdb->get_var( $wpdb->prepare( "SELECT creato_il FROM $t WHERE socio_id = %d AND contesto = %s AND evento_id = %d AND esito = 1 ORDER BY id DESC LIMIT 1", $socio_id, $contesto, $evento_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Per l'appello: soci passati e soci attesi, in quel controllo e partita. */
function rcm_ver_conteggio( $contesto, $evento_id ) {
	global $wpdb;
	if ( 'ingresso' === $contesto ) {
		$passati = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT socio_id) FROM ' . rcm_ver_tabella() . " WHERE contesto = 'ingresso' AND esito = 1 AND creato_il >= %s", wp_date( 'Y-m-d 00:00:00' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array( 'passati' => $passati, 'attesi' => null );
	}
	$col     = 'pullman' === $contesto ? 'pullman' : 'biglietto';
	$passati = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT socio_id) FROM ' . rcm_ver_tabella() . ' WHERE contesto = %s AND evento_id = %d AND esito = 1', $contesto, $evento_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$attesi  = function_exists( 'rcm_pr_tabella' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . rcm_pr_tabella() . " WHERE evento_id = %d AND stato = 'confermato' AND $col = 1", $evento_id ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return array( 'passati' => $passati, 'attesi' => $attesi );
}

/* -------------------------------------------------------------------------
 * I telefoni e i loro token
 * ---------------------------------------------------------------------- */

function rcm_ver_hash( $token ) {
	$chiave = function_exists( 'rcm_as_segreto' ) ? rcm_as_segreto() : wp_salt( 'auth' );
	return hash_hmac( 'sha256', 'app|' . $token, $chiave );
}

/**
 * Il telefono della chiamata, se il token e' buono e l'utente puo' ancora
 * gestire i soci. Imposta anche l'utente corrente, per il registro.
 */
function rcm_ver_dispositivo_corrente( WP_REST_Request $req ) {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$cache = null;
	$token = (string) $req->get_header( str_replace( '-', '_', strtolower( RCM_VER_HEADER ) ) );
	if ( strlen( $token ) < 40 ) {
		return null;
	}
	global $wpdb;
	$t = rcm_ver_tabella_dispositivi();
	$d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token_hash = %s AND revocato_il IS NULL", rcm_ver_hash( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! $d ) {
		return null;
	}
	$utente = get_user_by( 'id', $d->utente_id );
	if ( ! $utente || ! user_can( $utente, RCM_SOCI_CAP_VEDI ) ) {
		return null;
	}
	// La versione dell'app arriva a ogni chiamata (User-Agent "VerificaRCM/1.0.2"):
	// dopo un aggiornamento la riga del telefono in bacheca la segue.
	$aggiorna = array();
	if ( preg_match( '#VerificaRCM/([0-9][0-9A-Za-z.\-+]{0,19})#', (string) $req->get_header( 'user_agent' ), $m ) && $m[1] !== $d->versione ) {
		$aggiorna['versione'] = $m[1];
		$d->versione          = $m[1];
	}
	if ( strtotime( $d->ultimo_uso ) < time() - 300 ) {
		$aggiorna['ultimo_uso'] = current_time( 'mysql' );
	}
	if ( $aggiorna ) {
		$wpdb->update( $t, $aggiorna, array( 'id' => $d->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	wp_set_current_user( $utente->ID );
	$d->utente = $utente;
	$cache     = $d;
	return $cache;
}

function rcm_ver_permesso( WP_REST_Request $req ) {
	return rcm_ver_dispositivo_corrente( $req ) ? true : new WP_Error( 'rcm_ver_token', 'Accesso scaduto o revocato: entra di nuovo con utente e password.', array( 'status' => 401 ) );
}

/** Al massimo $max volte in $finestra secondi. */
function rcm_ver_limite( $chiave, $max, $finestra ) {
	$k = 'rcm_ver_l_' . md5( $chiave );
	$n = (int) get_transient( $k );
	if ( $n >= $max ) {
		return false;
	}
	set_transient( $k, $n + 1, $finestra );
	return true;
}

/* -------------------------------------------------------------------------
 * L'API: /wp-json/rcm/v1/app/...
 * ---------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rcm/v1',
			'/app/accesso',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'utente'      => array( 'type' => 'string', 'required' => true ),
					'password'    => array( 'type' => 'string', 'required' => true ),
					'dispositivo' => array( 'type' => 'string', 'default' => '' ),
					'versione'    => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => 'rcm_ver_api_accesso',
			)
		);
		register_rest_route(
			'rcm/v1',
			'/app/partite',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'rcm_ver_permesso',
				'callback'            => 'rcm_ver_api_partite',
			)
		);
		register_rest_route(
			'rcm/v1',
			'/app/verifica',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'rcm_ver_permesso',
				'args'                => array(
					'qr'       => array( 'type' => 'string', 'required' => true ),
					'contesto' => array( 'type' => 'string', 'required' => true, 'enum' => array_keys( rcm_ver_contesti() ) ),
					'evento'   => array( 'type' => 'integer', 'default' => 0 ),
				),
				'callback'            => 'rcm_ver_api_verifica',
			)
		);
		register_rest_route(
			'rcm/v1',
			'/app/prenotazioni',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'rcm_ver_permesso',
				'args'                => array(
					'dopo' => array( 'type' => 'integer', 'default' => -1 ),
				),
				'callback'            => 'rcm_ver_api_prenotazioni',
			)
		);
		register_rest_route(
			'rcm/v1',
			'/app/esci',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'rcm_ver_permesso',
				'callback'            => 'rcm_ver_api_esci',
			)
		);
	}
);

/**
 * Utente e password di WordPress in cambio di un token per questo telefono.
 * Senza Turnstile (e' un'app, non una pagina): al suo posto limiti stretti
 * per indirizzo IP e per nome utente, e la stessa risposta per utente
 * inesistente e password sbagliata.
 */
function rcm_ver_api_accesso( WP_REST_Request $req ) {
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$utente = sanitize_user( (string) $req['utente'] );
	if ( ! rcm_ver_limite( 'ip|' . $ip, 10, HOUR_IN_SECONDS ) || ! rcm_ver_limite( 'u|' . strtolower( $utente ), 5, 15 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'rcm_ver_troppi', 'Troppi tentativi: riprova fra un quarto d\'ora.', array( 'status' => 429 ) );
	}
	$u = wp_authenticate( $utente, (string) $req['password'] );
	if ( is_wp_error( $u ) ) {
		return new WP_Error( 'rcm_ver_credenziali', 'Utente o password non corretti.', array( 'status' => 401 ) );
	}
	if ( ! user_can( $u, RCM_SOCI_CAP_VEDI ) ) {
		return new WP_Error( 'rcm_ver_permesso', 'Questo utente non fa parte del direttivo nel sito: l\'app Verifica non è per lui.', array( 'status' => 403 ) );
	}
	$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	global $wpdb;
	$wpdb->insert(
		rcm_ver_tabella_dispositivi(),
		array(
			'utente_id'  => $u->ID,
			'token_hash' => rcm_ver_hash( $token ),
			'nome'       => mb_substr( sanitize_text_field( (string) $req['dispositivo'] ), 0, 190 ),
			'versione'   => mb_substr( sanitize_text_field( (string) $req['versione'] ), 0, 20 ),
			'creato_il'  => current_time( 'mysql' ),
			'ultimo_uso' => current_time( 'mysql' ),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response(
		array(
			'token' => $token,
			'nome'  => $u->display_name,
		)
	);
}

/** Le partite da scegliere per pullman e biglietto: quella di oggi e le prossime. */
function rcm_ver_api_partite() {
	$out = array();
	foreach ( function_exists( 'rcm_pr_prossime' ) ? rcm_pr_prossime( 6 ) : array() as $x ) {
		$out[] = array(
			'id'     => $x->id,
			'titolo' => $x->titolo,
			'data'   => wp_date( 'D j/n', $x->quando->getTimestamp() ) . ( $x->ora ? ' · ' . $x->ora : '' ),
			'oggi'   => wp_date( 'Y-m-d' ) === $x->quando->format( 'Y-m-d' ),
		);
	}
	return rest_ensure_response( array( 'partite' => $out ) );
}

function rcm_ver_api_verifica( WP_REST_Request $req ) {
	$d        = rcm_ver_dispositivo_corrente( $req );
	$contesto = $req['contesto'];
	$evento   = 'ingresso' === $contesto ? 0 : absint( $req['evento'] );

	// Il QR contiene l'indirizzo della verifica: se ne tiene solo il codice
	$letto = trim( (string) $req['qr'] );
	$token = '';
	$parti = wp_parse_url( $letto );
	if ( $parti && ! empty( $parti['host'] ) && wp_parse_url( home_url(), PHP_URL_HOST ) === $parti['host'] && ! empty( $parti['query'] ) ) {
		parse_str( $parti['query'], $q );
		$token = isset( $q[ RCM_QR_PARAM ] ) ? (string) $q[ RCM_QR_PARAM ] : '';
	}

	$esito = rcm_ver_esito( $token, $contesto, $evento );
	if ( $esito['ok'] ) {
		$prima = rcm_ver_gia_passato( $esito['socio_id'], $contesto, $evento );
		if ( $prima ) {
			$esito['avviso'] = 'Già passato alle ' . mysql2date( 'H:i', $prima ) . ( 'ingresso' === $contesto ? '' : ' del ' . mysql2date( 'j/n', $prima ) ) . ': controlla che sia lui.';
		}
	}

	global $wpdb;
	$wpdb->insert(
		rcm_ver_tabella(),
		array(
			'socio_id'       => $esito['socio_id'],
			'contesto'       => $contesto,
			'evento_id'      => $evento,
			'esito'          => $esito['ok'] ? 1 : 0,
			'motivo'         => $esito['motivo'],
			'utente_id'      => $d->utente_id,
			'dispositivo_id' => $d->id,
			'creato_il'      => current_time( 'mysql' ),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	unset( $esito['socio_id'], $esito['motivo'] );
	$esito['avviso']    = $esito['avviso'] ?? '';
	$esito['conteggio'] = rcm_ver_conteggio( $contesto, $evento );
	return rest_ensure_response( $esito );
}

/**
 * Le prenotazioni piu' recenti, per le notifiche e per l'elenco nell'app.
 * Con dopo=<id> (anche 0) solo quelle con id piu' alto; senza, le ultime 30.
 * "ultimo" e' l'id piu' alto: l'app lo conserva e lo rimanda la volta dopo.
 * Alla prima chiamata l'app non notifica niente, prende solo il segno. dopo=0
 * e' un segno valido ("non c'era ancora nessuna prenotazione"), per questo il
 * valore di "nessun parametro" e' -1.
 */
function rcm_ver_api_prenotazioni( WP_REST_Request $req ) {
	if ( ! function_exists( 'rcm_pr_tabella' ) ) {
		return rest_ensure_response( array( 'prenotazioni' => array(), 'ultimo' => 0 ) );
	}
	global $wpdb;
	$t    = rcm_pr_tabella();
	$s    = rcm_compleanni_tabella();
	$dopo = (int) $req['dopo'];
	$sql  = "SELECT p.*, s.nome, s.cognome FROM $t p LEFT JOIN $s s ON s.id = p.socio_id";
	$rows = $dopo >= 0
		? $wpdb->get_results( $wpdb->prepare( "$sql WHERE p.id > %d ORDER BY p.id ASC LIMIT 50", $dopo ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		: $wpdb->get_results( "$sql ORDER BY p.id DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$stati = rcm_pr_stati();
	$out   = array();
	foreach ( $rows as $r ) {
		$partita = rcm_pr_partita( $r->evento_id );
		$cosa    = array();
		if ( $r->biglietto ) {
			$cosa[] = 'biglietto (' . $r->settore . ')';
		}
		if ( $r->pullman ) {
			$cosa[] = 'pullman';
		}
		$persone = rcm_pr_persone( $r );
		$out[]   = array(
			'id'       => (int) $r->id,
			'socio'    => trim( $r->nome . ' ' . $r->cognome ),
			'partita'  => $partita ? $partita->titolo : '',
			'data'     => $partita ? wp_date( 'D j/n', $partita->quando->getTimestamp() ) : '',
			'cosa'     => implode( ' + ', $cosa ),
			'persone'  => count( $persone ),
			'non_tesserati' => rcm_pr_non_tesserati( $r ),
			'stato'    => $stati[ $r->stato ] ?? $r->stato,
			'quando'   => mysql2date( 'j/n H:i', $r->creato_il ),
		);
	}
	$ultimo = (int) $wpdb->get_var( "SELECT MAX(id) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'prenotazioni' => $out, 'ultimo' => $ultimo ) );
}

function rcm_ver_api_esci( WP_REST_Request $req ) {
	$d = rcm_ver_dispositivo_corrente( $req );
	global $wpdb;
	$wpdb->update( rcm_ver_tabella_dispositivi(), array( 'revocato_il' => current_time( 'mysql' ) ), array( 'id' => $d->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'ok' => true ) );
}

/* -------------------------------------------------------------------------
 * In bacheca: Soci > App Verifica
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rcm-soci', 'App Verifica', 'App Verifica', RCM_SOCI_CAP_VEDI, 'rcm-app-verifica', 'rcm_ver_pagina_admin' );
	},
	16
);

function rcm_ver_apk_info() {
	if ( ! is_readable( RCM_VER_APK ) ) {
		return null;
	}
	$meta = array();
	$json = RCM_VER_APK . '.json'; // versione scritta accanto all'APK al momento del caricamento
	if ( is_readable( $json ) ) {
		$meta = (array) json_decode( (string) file_get_contents( $json ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
	return array(
		'dimensione' => filesize( RCM_VER_APK ),
		'data'       => filemtime( RCM_VER_APK ),
		'versione'   => $meta['versione'] ?? '',
		'sha256'     => $meta['sha256'] ?? '',
	);
}

add_action( 'admin_post_rcm_ver_scarica', 'rcm_ver_scarica' );
function rcm_ver_scarica() {
	if ( ! current_user_can( RCM_SOCI_CAP_VEDI ) ) {
		wp_die( 'Non hai i permessi per scaricare l\'app.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rcm_ver_scarica' );
	if ( ! is_readable( RCM_VER_APK ) ) {
		wp_die( 'L\'app non è ancora stata caricata sul server.', '', array( 'response' => 404 ) );
	}
	nocache_headers();
	header( 'Content-Type: application/vnd.android.package-archive' );
	header( 'Content-Disposition: attachment; filename="Verifica-RCM.apk"' );
	header( 'Content-Length: ' . filesize( RCM_VER_APK ) );
	readfile( RCM_VER_APK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

add_action( 'admin_post_rcm_ver_revoca', 'rcm_ver_revoca' );
function rcm_ver_revoca() {
	if ( ! current_user_can( RCM_SOCI_CAP_VEDI ) ) {
		wp_die( 'Non hai i permessi.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rcm_ver_revoca' );
	global $wpdb;
	$t = rcm_ver_tabella_dispositivi();
	$d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", absint( $_GET['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	// un gestore revoca i suoi telefoni; un amministratore anche quelli degli altri
	if ( $d && ( (int) $d->utente_id === get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
		$wpdb->update( $t, array( 'revocato_il' => current_time( 'mysql' ) ), array( 'id' => $d->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	wp_safe_redirect( admin_url( 'admin.php?page=rcm-app-verifica&revocato=1' ) );
	exit;
}

function rcm_ver_pagina_admin() {
	global $wpdb;
	$apk   = rcm_ver_apk_info();
	$qui   = admin_url( 'admin.php?page=rcm-app-verifica' );
	$admin = current_user_can( 'manage_options' );
	$t     = rcm_ver_tabella_dispositivi();
	$righe = $admin
		? $wpdb->get_results( "SELECT * FROM $t WHERE revocato_il IS NULL ORDER BY ultimo_uso DESC" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE revocato_il IS NULL AND utente_id = %d ORDER BY ultimo_uso DESC", get_current_user_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! empty( $_GET['revocato'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		rcm_compleanni_avviso( 'Telefono revocato: alla prossima lettura l\'app chiederà di nuovo utente e password.' );
	}
	?>
	<div class="wrap">
		<h1>App Verifica</h1>
		<p style="max-width:48em">L'app Android per controllare le tessere all'<strong>ingresso della sede</strong>, alla <strong>salita sul pullman</strong> e per i <strong>biglietti</strong>: si inquadra il QR code del socio e compare un pallino verde con la spunta, oppure una X rossa con il motivo. È per chi gestisce i soci: si entra con lo stesso utente e la stessa password di questa bacheca, poi con impronta, volto o un PIN.</p>

		<div style="display:flex;flex-wrap:wrap;gap:28px;align-items:flex-start;margin-top:18px">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;max-width:30em">
				<h2 style="margin-top:0">Scarica l'app</h2>
				<?php if ( $apk ) : ?>
					<p>
						<a class="button button-primary button-hero" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rcm_ver_scarica' ), 'rcm_ver_scarica' ) ); ?>">Scarica Verifica RCM<?php echo $apk['versione'] ? ' ' . esc_html( $apk['versione'] ) : ''; ?></a>
					</p>
					<p class="description">
						Android 8 o successivo, <?php echo esc_html( size_format( $apk['dimensione'], 1 ) ); ?>, del <?php echo esc_html( wp_date( 'j F Y', $apk['data'] ) ); ?>.
						<?php if ( $apk['sha256'] ) : ?>
							<br>SHA-256: <code style="font-size:11px;word-break:break-all"><?php echo esc_html( $apk['sha256'] ); ?></code>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<p><strong>L'app non è ancora stata caricata sul server.</strong></p>
				<?php endif; ?>
				<p class="description">L'app è solo per il Club: non è sul Play Store né sull'App Store. Per questo il telefono la tratta come un'app "non riconosciuta", e la prima installazione chiede qualche conferma in più.</p>
			</div>
			<?php if ( function_exists( 'rcm_qr_svg_da_url' ) ) : ?>
				<div style="text-align:center">
					<div style="width:200px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px"><?php echo rcm_qr_svg_da_url( $qui, 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG generato dalla libreria ?></div>
					<p class="description" style="max-width:200px">Inquadralo con il telefono per aprire questa pagina lì.</p>
				</div>
			<?php endif; ?>
		</div>

		<div style="display:flex;flex-wrap:wrap;gap:28px;margin-top:22px">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;max-width:34em">
				<h2 style="margin-top:0">Installare su Android</h2>
				<ol style="line-height:1.6">
					<li>Dal telefono apri questa pagina (inquadra il QR ed entra nella bacheca) e tocca <strong>Scarica</strong>. Chrome può avvisare che il file <em>potrebbe essere dannoso</em>: tocca <strong>Scarica comunque</strong>.</li>
					<li>Apri il file <strong>Verifica-RCM.apk</strong> dalla notifica del download o da <em>File › Download</em>.</li>
					<li>La prima volta Android dice che <em>per motivi di sicurezza il telefono non può installare app sconosciute da questa fonte</em>: tocca <strong>Impostazioni</strong>, attiva <strong>Consenti da questa fonte</strong> e torna indietro. La voce si trova anche in <em>Impostazioni › App › Accesso speciale › Installa app sconosciute</em> (il percorso cambia un po' da marca a marca).</li>
					<li>Tocca <strong>Installa</strong>. Se compare <em>Google Play Protect</em> con <em>App non sicura</em> o <em>sviluppatore non riconosciuto</em>, tocca <strong>Altri dettagli › Installa comunque</strong>. Non inviare l'app a Google per l'analisi: è un'app interna del Club.</li>
					<li>Finita l'installazione, puoi <strong>disattivare di nuovo</strong> <em>Consenti da questa fonte</em>: l'app resta e si aggiorna scaricando la versione nuova da qui.</li>
					<li>Apri <strong>Verifica RCM</strong>, entra con utente e password, scegli impronta, volto o PIN e consenti fotocamera e notifiche.</li>
				</ol>
				<p class="description">Il controllo SHA-256 qui sopra serve a chi vuole essere sicuro che il file sia quello pubblicato dal Club.</p>
			</div>

			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;max-width:34em">
				<h2 style="margin-top:0">Installare su iPhone</h2>
				<p>Apple non permette di installare app scaricate da un sito: un'app che non è sull'App Store arriva sugli iPhone tramite <strong>TestFlight</strong>, l'app ufficiale di Apple per le versioni riservate.</p>
				<ol style="line-height:1.6">
					<li>Installa <strong>TestFlight</strong> dall'App Store (è gratuita, di Apple).</li>
					<li>Apri il <strong>link di invito</strong> che il Club ti manda (o l'email di invito) e tocca <strong>Accetta</strong>, poi <strong>Installa</strong>.</li>
					<li>Apri <strong>Verifica RCM</strong>, entra con utente e password, scegli Face ID, Touch ID o PIN e consenti fotocamera e notifiche.</li>
					<li>Se l'iPhone dice <em>Sviluppatore non attendibile</em>: <em>Impostazioni › Generali › VPN e gestione dispositivi</em>, tocca il profilo del Club e <strong>Autorizza</strong>.</li>
				</ol>
				<p class="description">Le versioni TestFlight scadono dopo 90 giorni: quando esce quella nuova, TestFlight la propone da solo. Su iPhone le notifiche delle prenotazioni possono arrivare in ritardo, perché iOS decide lui quando far lavorare le app in background.</p>
			</div>
		</div>

		<h2 style="margin-top:2em"><?php echo $admin ? 'Telefoni collegati' : 'I tuoi telefoni collegati'; ?></h2>
		<?php if ( ! $righe ) : ?>
			<p>Nessun telefono collegato.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr><?php echo $admin ? '<th>Utente</th>' : ''; ?><th>Telefono</th><th>Versione</th><th>Collegato il</th><th>Ultimo uso</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $righe as $r ) : ?>
					<tr>
						<?php if ( $admin ) : ?>
							<td><?php echo esc_html( ( $u = get_user_by( 'id', $r->utente_id ) ) ? $u->display_name : '#' . $r->utente_id ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( $r->nome ? $r->nome : '—' ); ?></td>
						<td><?php echo esc_html( $r->versione ); ?></td>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r->creato_il ) ); ?></td>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r->ultimo_uso ) ); ?></td>
						<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rcm_ver_revoca&id=' . $r->id ), 'rcm_ver_revoca' ) ); ?>" onclick="return confirm('Revocare questo telefono? L\'app chiederà di nuovo utente e password.')">Revoca</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Un telefono perso o passato a un altro va revocato qui: l'app smette subito di funzionare. Si revocano da soli gli accessi degli utenti a cui si toglie il ruolo.</p>
		<?php endif; ?>
	</div>
	<?php
}
