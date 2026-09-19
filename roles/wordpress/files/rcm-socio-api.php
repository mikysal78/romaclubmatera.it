<?php
/**
 * Plugin Name: RCM - API dell'app dei soci
 * Description: Il lato sito dell'app "RCM Soci": accesso con email e codice, tessera e QR, dati, partite prenotabili, prenotare e annullare biglietto e pullman, partenza del pullman per il promemoria. Le stesse regole e gli stessi dati dell'area soci del sito.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * COME FUNZIONA
 *
 * - Nessuna regola nuova: l'accesso usa i codici dell'area soci
 *   (rcm_as_crea_accesso, rcm_as_verifica_codice), le prenotazioni
 *   rcm_pr_salva e rcm_pr_annulla_per, l'interruttore spenta/prova/attiva
 *   rcm_as_puo_entrare. Quello che vale sul sito vale nell'app.
 * - L'app chiede il codice con /socio/richiedi: arriva la stessa email
 *   dell'area soci (link e codice); nell'app si scrive il codice. Risposta
 *   sempre uguale, che l'email sia di un socio o no.
 * - Con email e codice giusti /socio/entra restituisce un token, che l'app
 *   tiene nel portachiavi del telefono e manda in X-RCM-Socio-Token. E' una
 *   sessione dell'area soci (tabella wp_rcm_soci_sessioni) che dura 180
 *   giorni; "Esci" la chiude. A ogni chiamata si ricontrolla che il socio
 *   possa ancora entrare: tessera scaduta o area spenta chiudono fuori.
 * - Il promemoria della partenza lo programma l'app sul telefono, con i dati
 *   di /socio/partite (partenza del pullman scritta dal Club per partita).
 */

defined( 'ABSPATH' ) || exit;

const RCM_SA_HEADER = 'X-RCM-Socio-Token';
const RCM_SA_DURATA = 15552000; // 180 giorni

/* -------------------------------------------------------------------------
 * Chi sta chiamando
 * ---------------------------------------------------------------------- */

function rcm_sa_socio( WP_REST_Request $req ) {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$cache = null;
	$token = (string) $req->get_header( str_replace( '-', '_', strtolower( RCM_SA_HEADER ) ) );
	if ( strlen( $token ) < 40 || ! function_exists( 'rcm_as_hash' ) ) {
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

function rcm_sa_permesso( WP_REST_Request $req ) {
	return rcm_sa_socio( $req ) ? true : new WP_Error( 'rcm_sa_token', 'Accesso scaduto: entra di nuovo con la tua email.', array( 'status' => 401 ) );
}

function rcm_sa_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/* -------------------------------------------------------------------------
 * Le rotte: /wp-json/rcm/v1/socio/...
 * ---------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		$pubblico = '__return_true';
		$socio    = 'rcm_sa_permesso';
		$rotte    = array(
			array( 'richiedi', 'POST', $pubblico, 'rcm_sa_richiedi' ),
			array( 'entra', 'POST', $pubblico, 'rcm_sa_entra' ),
			array( 'io', 'GET', $socio, 'rcm_sa_io' ),
			array( 'dati', 'POST', $socio, 'rcm_sa_dati' ),
			array( 'partite', 'GET', $socio, 'rcm_sa_partite' ),
			array( 'prenota', 'POST', $socio, 'rcm_sa_prenota' ),
			array( 'annulla', 'POST', $socio, 'rcm_sa_annulla' ),
			array( 'esci', 'POST', $socio, 'rcm_sa_esci' ),
		);
		foreach ( $rotte as $r ) {
			register_rest_route(
				'rcm/v1',
				'/socio/' . $r[0],
				array(
					'methods'             => $r[1],
					'permission_callback' => $r[2],
					'callback'            => $r[3],
				)
			);
		}
	}
);

/** L'email con il codice. Sempre la stessa risposta: non si scopre chi e' socio. */
function rcm_sa_richiedi( WP_REST_Request $req ) {
	$email = strtolower( sanitize_email( (string) $req['email'] ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'rcm_sa_email', 'Scrivi un indirizzo email valido.', array( 'status' => 400 ) );
	}
	// senza Turnstile (e' un'app): limiti stretti per email e per indirizzo IP
	if ( rcm_as_limite( 'e|' . $email, 3, 15 * MINUTE_IN_SECONDS ) && rcm_as_limite( 'ip|' . rcm_sa_ip(), 10, HOUR_IN_SECONDS ) ) {
		$socio = rcm_as_socio_per_email( $email );
		if ( rcm_as_puo_entrare( $socio ) ) {
			rcm_as_manda_email( $socio, rcm_as_crea_accesso( (int) $socio->id ), false );
		}
	}
	return rest_ensure_response( array( 'ok' => true, 'messaggio' => 'Se l\'indirizzo è di un socio, ti abbiamo mandato un codice di 6 cifre.' ) );
}

function rcm_sa_entra( WP_REST_Request $req ) {
	if ( ! rcm_as_limite( 'entra|' . rcm_sa_ip(), 20, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rcm_sa_troppi', 'Troppi tentativi: riprova fra un\'ora.', array( 'status' => 429 ) );
	}
	$email = strtolower( sanitize_email( (string) $req['email'] ) );
	$socio = rcm_as_socio_per_email( $email );
	if ( ! rcm_as_puo_entrare( $socio ) || ! rcm_as_verifica_codice( $socio, (string) $req['codice'] ) ) {
		return new WP_Error( 'rcm_sa_codice', 'Codice non valido o scaduto. Dopo cinque tentativi sbagliati ne serve uno nuovo.', array( 'status' => 401 ) );
	}
	global $wpdb;
	$token = rcm_as_token();
	$wpdb->insert(
		rcm_as_tab( 'sessioni' ),
		array(
			'socio_id'   => $socio->id,
			'token_hash' => rcm_as_hash( 's|' . $token ),
			'agente'     => mb_substr( 'app: ' . sanitize_text_field( (string) $req['dispositivo'] ), 0, 190 ),
			'creato_il'  => rcm_as_ora_utc(),
			'scade_il'   => rcm_as_ora_utc( RCM_SA_DURATA ),
			'ultimo_uso' => rcm_as_ora_utc(),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'token' => $token, 'nome' => $socio->nome ) );
}

/** Il socio, la tessera (gli stessi campi della tessera del sito) e il QR. */
function rcm_sa_io( WP_REST_Request $req ) {
	$s   = rcm_sa_socio( $req );
	$tip = rcm_soci_tipologie();
	return rest_ensure_response(
		array(
			'nome'     => $s->nome,
			'cognome'  => $s->cognome,
			'email'    => $s->email,
			'telefono' => $s->telefono && function_exists( 'rcm_soci_telefono_leggibile' ) ? rcm_soci_telefono_leggibile( $s->telefono ) : '',
			'tessera'  => array(
				'tipologia' => $tip[ $s->tipologia ] ?? '',
				'numero'    => '' !== (string) $s->numero_tessera ? $s->numero_tessera : 'VIRTUAL',
				'validita'  => substr( (string) $s->stagione, 2 ),
				'stagione'  => $s->stagione,
				'rilascio'  => mysql2date( 'd/m/Y', $s->creato_il ),
			),
			'qr'       => function_exists( 'rcm_qr_url' ) ? rcm_qr_url( $s ) : '',
		)
	);
}

function rcm_sa_dati( WP_REST_Request $req ) {
	$s        = rcm_sa_socio( $req );
	$grezzo   = sanitize_text_field( (string) $req['telefono'] );
	$telefono = '' === $grezzo ? '' : rcm_compleanni_telefono( $grezzo );
	if ( '' !== $grezzo && '' === $telefono ) {
		return new WP_Error( 'rcm_sa_telefono', 'Numero di cellulare non riconosciuto.', array( 'status' => 400 ) );
	}
	global $wpdb;
	$wpdb->update( rcm_compleanni_tabella(), array( 'telefono' => $telefono ), array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'ok' => true ) );
}

/** Una partita, con la prenotazione del socio se c'e'. */
function rcm_sa_partita_json( $partita, $p, $prenotabile ) {
	$stati    = rcm_pr_stati();
	$partenza = rcm_pr_partenza( $partita->id );
	$out      = array(
		'id'          => $partita->id,
		'titolo'      => $partita->titolo,
		'data'        => rcm_pr_data( $partita ),
		'quando'      => $partita->quando->format( 'c' ),
		'luogo'       => $partita->luogo,
		'casa'        => $partita->casa,
		'aperta'      => $partita->aperta,
		'prenotabile' => $prenotabile,
		'giorni'      => $partita->giorni,
		'entro'       => wp_date( 'l j F', $partita->entro->getTimestamp() ),
		'settori'     => $partita->casa ? rcm_pr_settori() : array( 'Settore ospiti' ),
		'prenotazione' => null,
	);
	if ( $p && 'annullato' !== $p->stato ) {
		$out['prenotazione'] = array(
			'stato'     => $p->stato,
			'stato_testo' => $stati[ $p->stato ] ?? $p->stato,
			'biglietto' => (bool) $p->biglietto,
			'settore'   => $p->settore,
			'pullman'   => (bool) $p->pullman,
			'persone'   => rcm_pr_persone( $p ),
			'note'      => $p->note,
			'nota_club' => $p->nota_club,
			// la partenza solo a chi ha il posto in pullman confermato
			'partenza'  => ( 'confermato' === $p->stato && $p->pullman && $partenza ) ? array(
				'data'  => $partenza['data'],
				'ora'   => $partenza['ora'],
				'luogo' => $partenza['luogo'],
				'testo' => rcm_pr_partenza_testo( $partenza ),
			) : null,
		);
	}
	return $out;
}

/** La prossima partita, le prenotabili (le prime 3 aperte) e le altre prenotazioni del socio. */
function rcm_sa_partite( WP_REST_Request $req ) {
	$s   = rcm_sa_socio( $req );
	$mie = array();
	foreach ( rcm_pr_prenotazioni_socio( $s->id ) as $p ) {
		$mie[ (int) $p->evento_id ] = $p;
	}
	$prenotabili = rcm_pr_prenotabili();
	$prossime    = rcm_pr_prossime();
	$mostrate    = array();
	$out         = array( 'prossima' => null, 'prenotabili' => array(), 'altre' => array() );
	if ( $prossime ) {
		$x               = $prossime[0];
		$out['prossima'] = rcm_sa_partita_json( $x, $mie[ $x->id ] ?? null, isset( $prenotabili[ $x->id ] ) );
		$mostrate[]      = $x->id;
	}
	foreach ( $prenotabili as $x ) {
		if ( in_array( $x->id, $mostrate, true ) ) {
			continue;
		}
		$out['prenotabili'][] = rcm_sa_partita_json( $x, $mie[ $x->id ] ?? null, true );
		$mostrate[]           = $x->id;
	}
	foreach ( $mie as $evento_id => $p ) {
		if ( in_array( $evento_id, $mostrate, true ) || 'annullato' === $p->stato ) {
			continue;
		}
		$x = rcm_pr_partita( $evento_id );
		if ( $x && $x->futura ) {
			$out['altre'][] = rcm_sa_partita_json( $x, $p, false );
		}
	}
	$out['regole'] = array(
		'max_persone' => RCM_PR_MAX_PERSONE,
		'giorni'      => RCM_PR_GIORNI,
		'pagamenti'   => function_exists( 'rcm_tess_pagamenti' ) ? wp_list_pluck( rcm_tess_pagamenti(), 'nome' ) : array(),
	);
	return rest_ensure_response( $out );
}

function rcm_sa_esito( $codice ) {
	if ( in_array( $codice, array( 'prenotato', 'modificato', 'annullato' ), true ) ) {
		$testi = array(
			'prenotato'  => 'Prenotazione registrata. Resta "Prenotato" finché il Club non riceve il pagamento.',
			'modificato' => 'Prenotazione aggiornata.',
			'annullato'  => 'Prenotazione annullata.',
		);
		return rest_ensure_response( array( 'ok' => true, 'esito' => $codice, 'messaggio' => $testi[ $codice ] ) );
	}
	return new WP_Error( 'rcm_sa_' . $codice, rcm_pr_messaggio( $codice ), array( 'status' => 400 ) );
}

function rcm_sa_prenota( WP_REST_Request $req ) {
	// le persone: [{nome, tesserato}], con la scelta obbligatoria per ognuna
	$persone = array();
	foreach ( (array) $req['persone'] as $x ) {
		$nome = mb_substr( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $x['nome'] ?? '' ) ) ) ), 0, 60 );
		if ( '' === $nome ) {
			continue;
		}
		if ( ! isset( $x['tesserato'] ) || ! is_bool( $x['tesserato'] ) ) {
			$persone = null;
			break;
		}
		$persone[] = array( 'nome' => $nome, 'tesserato' => $x['tesserato'] );
	}
	return rcm_sa_esito(
		rcm_pr_salva(
			rcm_sa_socio( $req ),
			absint( $req['evento'] ),
			array(
				'biglietto' => (bool) $req['biglietto'],
				'pullman'   => (bool) $req['pullman'],
				'settore'   => (string) $req['settore'],
				'persone'   => $persone,
				'note'      => (string) $req['note'],
			)
		)
	);
}

function rcm_sa_annulla( WP_REST_Request $req ) {
	return rcm_sa_esito( rcm_pr_annulla_per( rcm_sa_socio( $req ), absint( $req['evento'] ) ) );
}

function rcm_sa_esci( WP_REST_Request $req ) {
	$s = rcm_sa_socio( $req );
	global $wpdb;
	$wpdb->delete( rcm_as_tab( 'sessioni' ), array( 'id' => $s->sessione_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'ok' => true ) );
}
