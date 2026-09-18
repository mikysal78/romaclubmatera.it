<?php
/**
 * Plugin Name: RCM - Area soci: prenotazioni
 * Description: Nell'area soci, prenotazione del biglietto e del posto in pullman per le partite del calendario, finche' il Club non le chiude. Il socio vede "Prenotato" finche' il Club non segna il pagamento, poi "Confermato". Il Club le gestisce da Soci > Prenotazioni.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * COME FUNZIONA
 *
 * - Le partite sono gli eventi di SportsPress (sp_event) del calendario: niente
 *   da inserire due volte.
 * - Le prenotazioni di una partita le chiude il Club, a mano, da
 *   Soci > Prenotazioni (decisione di Michele, 18/09/2026). Chiuse, non si
 *   prenota, non si modifica e non si annulla piu': il Club sta comprando i
 *   biglietti e fissando il pullman. I 10 giorni restano come indicazione per
 *   il socio, nel conto alla rovescia, e non chiudono niente da soli.
 *   Una partita il cui giorno e' passato non si prenota comunque.
 * - Tre stati, e solo tre, come li vede il socio:
 *     prenotato  - richiesta fatta, pagamento non ancora arrivato;
 *     confermato - il Club ha segnato il pagamento: il biglietto c'e', il
 *                  posto in pullman e' riservato;
 *     annullato  - dal socio (prima della chiusura) o dal Club.
 *   Il pagamento lo segna il Club a mano: nessun pagamento passa dal sito.
 * - Una prenotazione per socio e partita; dentro, anche le altre persone per
 *   cui prenota, per nome: biglietti e posti sono nominativi. Ogni persona in
 *   piu' dev'essere tesserata, oppure paga un sovrapprezzo: il socio lo
 *   dichiara persona per persona, il Club lo vede in bacheca insieme al
 *   controllo sull'archivio soci. L'importo non compare mai (Michele,
 *   18/09/2026): lo comunica il Club.
 * - Le email seguono l'interruttore dell'area soci: da spenta non parte niente,
 *   in prova solo verso gli indirizzi di prova.
 *
 * Carica prima di rcm-area-soci.php (ordine alfabetico dei mu-plugin): per
 * questo qui nessuna funzione di quel file viene chiamata fuori dagli hook.
 */

defined( 'ABSPATH' ) || exit;

const RCM_PR_DB          = 'rcm_pr_db_version';
const RCM_PR_DB_VER      = '1.0';
const RCM_PR_GIORNI      = 10;  // entro quando si chiede di prenotare: solo indicazione
const RCM_PR_META_CHIUSE = '_rcm_pr_chiuse'; // sulla partita: prenotazioni chiuse dal Club
const RCM_PR_MAX_PERSONE = 3;   // altre persone oltre al socio (Michele, 18/09/2026)

function rcm_pr_tabella() {
	global $wpdb;
	return $wpdb->prefix . 'rcm_soci_prenotazioni';
}

add_action( 'init', 'rcm_pr_installa', 1 );
function rcm_pr_installa() {
	if ( get_option( RCM_PR_DB ) === RCM_PR_DB_VER ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		'CREATE TABLE ' . rcm_pr_tabella() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		socio_id bigint(20) unsigned NOT NULL,
		evento_id bigint(20) unsigned NOT NULL,
		biglietto tinyint(1) NOT NULL DEFAULT 0,
		settore varchar(60) NOT NULL DEFAULT '',
		pullman tinyint(1) NOT NULL DEFAULT 0,
		persone text NOT NULL,
		note text NOT NULL,
		stato varchar(12) NOT NULL DEFAULT 'prenotato',
		nota_club text NOT NULL,
		creato_il datetime NOT NULL,
		aggiornato_il datetime NOT NULL,
		confermato_il datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY socio_evento (socio_id,evento_id),
		KEY evento_id (evento_id)
	) " . $wpdb->get_charset_collate() . ';'
	);
	update_option( RCM_PR_DB, RCM_PR_DB_VER, false );
}

/* -------------------------------------------------------------------------
 * Le partite
 * ---------------------------------------------------------------------- */

function rcm_pr_stati() {
	return array(
		'prenotato'  => 'Prenotato',
		'confermato' => 'Confermato',
		'annullato'  => 'Annullato',
	);
}

/**
 * I settori dell'Olimpico fra cui il socio sceglie per le partite in casa.
 * Non sono tutti quelli dello stadio: sono quelli di cui il Club dispone, e
 * cambiano nel tempo. Per questo si scrivono in bacheca (Soci > Prenotazioni),
 * uno per riga, e non qui. Chi li assegna al Club non va nominato nelle pagine
 * pubbliche: al socio si dice solo "i settori disponibili".
 */
function rcm_pr_settori() {
	$salvati = get_option( 'rcm_pr_settori' );
	if ( is_array( $salvati ) && $salvati ) {
		return $salvati;
	}
	// quelli assegnati al Club a settembre 2026
	return array( 'Distinti Nord Est', 'Tribuna Tevere Top Nord', 'Tribuna Monte Mario Laterale Nord' );
}

/**
 * Una partita del calendario, con quello che serve per prenotarla. Null se
 * l'id non e' una partita di SportsPress.
 */
function rcm_pr_partita( $id ) {
	$p = get_post( $id );
	if ( ! $p || 'sp_event' !== $p->post_type || ! in_array( $p->post_status, array( 'publish', 'future' ), true ) ) {
		return null;
	}
	$tz       = wp_timezone();
	$quando   = new DateTimeImmutable( $p->post_date, $tz );
	$entro    = $quando->setTime( 23, 59, 59 )->modify( '-' . RCM_PR_GIORNI . ' days' );
	$oggi     = new DateTimeImmutable( 'today', $tz );
	$luogo    = wp_get_post_terms( $p->ID, 'sp_venue', array( 'fields' => 'names' ) );
	$chiusa   = (bool) get_post_meta( $p->ID, RCM_PR_META_CHIUSE, true );
	$futura   = $quando->setTime( 0, 0 )->getTimestamp() >= $oggi->getTimestamp();

	return (object) array(
		'id'       => $p->ID,
		'titolo'   => get_the_title( $p ),
		'quando'   => $quando,
		// in SportsPress l'ora 00:00 vuol dire "orario non ancora deciso"
		'ora'      => '00:00' === $quando->format( 'H:i' ) ? '' : $quando->format( 'H:i' ),
		'luogo'    => is_array( $luogo ) && $luogo ? $luogo[0] : '',
		// in casa se la Roma e' scritta per prima: "Roma vs Como"
		'casa'     => (bool) preg_match( '/^\s*(as\s+)?roma\b/i', $p->post_title ),
		'entro'    => $entro,
		'chiusa'   => $chiusa,
		'aperta'   => ! $chiusa && $futura,
		// giorni che restano per prenotare entro l'indicazione; -1 se e' passata
		'giorni'   => time() <= $entro->getTimestamp() ? (int) $oggi->diff( $entro->setTime( 0, 0 ) )->days : -1,
		'futura'   => $futura,
	);
}

/** Le prossime partite del calendario, dalla piu' vicina. */
function rcm_pr_prossime( $quante = 12 ) {
	$ids = get_posts(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => $quante,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'date_query'     => array( array( 'after' => wp_date( 'Y-m-d 00:00:00' ), 'inclusive' => true ) ),
			'no_found_rows'  => true,
		)
	);
	return array_values( array_filter( array_map( 'rcm_pr_partita', $ids ) ) );
}

function rcm_pr_data( $partita ) {
	$testo = wp_date( 'l j F Y', $partita->quando->getTimestamp() );
	return $partita->ora ? $testo . ', ore ' . $partita->ora : $testo . ' (orario da definire)';
}

/* -------------------------------------------------------------------------
 * Le prenotazioni
 * ---------------------------------------------------------------------- */

function rcm_pr_prenotazione( $socio_id, $evento_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . rcm_pr_tabella() . ' WHERE socio_id = %d AND evento_id = %d', $socio_id, $evento_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

function rcm_pr_prenotazioni_socio( $socio_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . rcm_pr_tabella() . ' WHERE socio_id = %d ORDER BY evento_id', $socio_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/**
 * Le altre persone della prenotazione, salvate in JSON:
 * array( array( 'nome' => 'Mario Rossi', 'tesserato' => true ), ... ).
 */
function rcm_pr_persone( $prenotazione ) {
	$v   = json_decode( (string) $prenotazione->persone, true );
	$out = array();
	foreach ( is_array( $v ) ? $v : array() as $x ) {
		if ( is_array( $x ) && ! empty( $x['nome'] ) ) {
			$out[] = array(
				'nome'      => (string) $x['nome'],
				'tesserato' => ! empty( $x['tesserato'] ),
			);
		}
	}
	return $out;
}

function rcm_pr_etichetta_persona( $x ) {
	return $x['nome'] . ( $x['tesserato'] ? ' (tesserato)' : ' (non tesserato, con sovrapprezzo)' );
}

function rcm_pr_elenco_persone( $prenotazione ) {
	return implode( ', ', array_map( 'rcm_pr_etichetta_persona', rcm_pr_persone( $prenotazione ) ) );
}

/** Quante persone in piu' non sono tesserate, cioe' pagano il sovrapprezzo. */
function rcm_pr_non_tesserati( $prenotazione ) {
	$n = 0;
	foreach ( rcm_pr_persone( $prenotazione ) as $x ) {
		$n += $x['tesserato'] ? 0 : 1;
	}
	return $n;
}

/**
 * Le persone dal modulo: una riga per persona, nome e "tesserato si/no".
 * Le righe senza nome si saltano; un nome senza la scelta restituisce null,
 * perche' se c'e' il sovrapprezzo non si indovina.
 */
function rcm_pr_leggi_persone_post() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificato dal chiamante
	$nomi = (array) wp_unslash( $_POST['persona_nome'] ?? array() );
	$tess = (array) wp_unslash( $_POST['persona_tessera'] ?? array() );
	// phpcs:enable
	$out = array();
	foreach ( $nomi as $i => $nome ) {
		$nome = mb_substr( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $nome ) ) ), 0, 60 );
		if ( '' === $nome ) {
			continue;
		}
		$scelta = sanitize_key( (string) ( $tess[ $i ] ?? '' ) );
		if ( ! in_array( $scelta, array( 'si', 'no' ), true ) ) {
			return null;
		}
		$out[] = array(
			'nome'      => $nome,
			'tesserato' => 'si' === $scelta,
		);
	}
	return $out;
}

/**
 * Per il Club: chi e' dichiarato tesserato risulta in archivio con la tessera
 * valida? Confronta "nome cognome" e "cognome nome", senza maiuscole.
 */
function rcm_pr_in_archivio( $nome ) {
	global $wpdb;
	$t = rcm_compleanni_tabella();
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE ( LOWER( CONCAT( nome, ' ', cognome ) ) = LOWER( %s ) OR LOWER( CONCAT( cognome, ' ', nome ) ) = LOWER( %s ) ) AND " . rcm_soci_sql_tessera_valida() . ' LIMIT 1', $nome, $nome ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/* -------------------------------------------------------------------------
 * Le azioni del socio
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_nopriv_rcm_pr_prenota', 'rcm_pr_prenota' );
add_action( 'admin_post_rcm_pr_prenota', 'rcm_pr_prenota' );
add_action( 'admin_post_nopriv_rcm_pr_annulla', 'rcm_pr_annulla' );
add_action( 'admin_post_rcm_pr_annulla', 'rcm_pr_annulla' );

add_filter(
	'rcm_as_avvisi',
	function ( $avvisi ) {
		return $avvisi + array(
			'pr_chiusa'     => 'Le prenotazioni per questa partita sono chiuse.',
			'pr_niente'     => 'Scegli almeno il biglietto o il posto in pullman.',
			'pr_settore'    => 'Scegli il settore dello stadio.',
			'pr_persone'    => 'Puoi prenotare al massimo per ' . RCM_PR_MAX_PERSONE . ' persone oltre a te.',
			'pr_tesserato'  => 'Per ogni persona in più indica se è tesserata o no: per chi non lo è c\'è un sovrapprezzo.',
			'pr_confermata' => 'Questa prenotazione è già confermata: per cambiarla scrivi al Club.',
		);
	}
);
add_filter(
	'rcm_as_conferme',
	function ( $conferme ) {
		return $conferme + array(
			'prenotato' => 'Prenotazione registrata. Resta "Prenotato" finché il Club non riceve il pagamento.',
			'modificato' => 'Prenotazione aggiornata.',
			'annullato' => 'Prenotazione annullata.',
		);
	}
);

function rcm_pr_prenota() {
	$socio = rcm_as_socio_corrente();
	if ( ! $socio || ! rcm_as_nonce_ok( 'rcm_pr_prenota' ) ) {
		rcm_as_torna( array( 'avviso' => 'scaduto' ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificato sopra
	$partita = rcm_pr_partita( absint( $_POST['evento'] ?? 0 ) );
	if ( ! $partita || ! $partita->aperta ) {
		rcm_as_torna( array( 'avviso' => 'pr_chiusa' ) );
	}
	$biglietto = ! empty( $_POST['biglietto'] ) ? 1 : 0;
	$pullman   = ! empty( $_POST['pullman'] ) ? 1 : 0;
	if ( ! $biglietto && ! $pullman ) {
		rcm_as_torna( array( 'avviso' => 'pr_niente' ) );
	}
	$settore = '';
	if ( $biglietto ) {
		if ( $partita->casa ) {
			$settore = sanitize_text_field( wp_unslash( $_POST['settore'] ?? '' ) );
			if ( ! in_array( $settore, rcm_pr_settori(), true ) ) {
				rcm_as_torna( array( 'avviso' => 'pr_settore' ) );
			}
		} else {
			$settore = 'Settore ospiti';
		}
	}
	$persone = rcm_pr_leggi_persone_post();
	if ( null === $persone ) {
		rcm_as_torna( array( 'avviso' => 'pr_tesserato' ) );
	}
	if ( count( $persone ) > RCM_PR_MAX_PERSONE ) {
		rcm_as_torna( array( 'avviso' => 'pr_persone' ) );
	}
	$note = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 500 );
	// phpcs:enable

	global $wpdb;
	$prima = rcm_pr_prenotazione( $socio->id, $partita->id );
	if ( $prima && 'confermato' === $prima->stato ) {
		rcm_as_torna( array( 'avviso' => 'pr_confermata' ) );
	}
	$dati = array(
		'biglietto'     => $biglietto,
		'settore'       => $settore,
		'pullman'       => $pullman,
		'persone'       => $persone ? wp_json_encode( $persone, JSON_UNESCAPED_UNICODE ) : '',
		'note'          => $note,
		'stato'         => 'prenotato',
		'aggiornato_il' => current_time( 'mysql' ),
	);
	if ( $prima ) {
		$wpdb->update( rcm_pr_tabella(), $dati, array( 'id' => $prima->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$wpdb->insert( rcm_pr_tabella(), $dati + array( 'socio_id' => $socio->id, 'evento_id' => $partita->id, 'nota_club' => '', 'creato_il' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$nuova = rcm_pr_prenotazione( $socio->id, $partita->id );
	$rifatta = $prima && 'annullato' === $prima->stato;
	rcm_pr_avvisa_club( $socio, $partita, $nuova, $prima && ! $rifatta ? 'modificata' : 'nuova' );
	if ( ! $prima || $rifatta ) {
		rcm_pr_email_socio( $socio, $partita, $nuova );
	}
	rcm_as_torna( array( 'fatto' => $prima && ! $rifatta ? 'modificato' : 'prenotato' ) );
}

function rcm_pr_annulla() {
	$socio = rcm_as_socio_corrente();
	if ( ! $socio || ! rcm_as_nonce_ok( 'rcm_pr_annulla' ) ) {
		rcm_as_torna( array( 'avviso' => 'scaduto' ) );
	}
	$partita = rcm_pr_partita( absint( $_POST['evento'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$p       = $partita ? rcm_pr_prenotazione( $socio->id, $partita->id ) : null;
	if ( ! $p || ! $partita->aperta ) {
		rcm_as_torna( array( 'avviso' => 'pr_chiusa' ) );
	}
	if ( 'confermato' === $p->stato ) {
		rcm_as_torna( array( 'avviso' => 'pr_confermata' ) );
	}
	global $wpdb;
	$wpdb->update( rcm_pr_tabella(), array( 'stato' => 'annullato', 'aggiornato_il' => current_time( 'mysql' ) ), array( 'id' => $p->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	rcm_pr_avvisa_club( $socio, $partita, rcm_pr_prenotazione( $socio->id, $partita->id ), 'annullata dal socio' );
	rcm_as_torna( array( 'fatto' => 'annullato' ) );
}

/* -------------------------------------------------------------------------
 * Email
 * ---------------------------------------------------------------------- */

function rcm_pr_riassunto_html( $partita, $p ) {
	$righe = array( '<strong>' . esc_html( $partita->titolo ) . '</strong> &middot; ' . esc_html( rcm_pr_data( $partita ) ) );
	if ( $p->biglietto ) {
		$righe[] = 'Biglietto: ' . esc_html( $p->settore );
	}
	if ( $p->pullman ) {
		$righe[] = 'Posto in pullman';
	}
	if ( rcm_pr_persone( $p ) ) {
		$righe[] = 'Anche per: ' . esc_html( rcm_pr_elenco_persone( $p ) );
	}
	if ( rcm_pr_non_tesserati( $p ) ) {
		$righe[] = 'Con sovrapprezzo per ' . ( 1 === rcm_pr_non_tesserati( $p ) ? '1 persona non tesserata' : rcm_pr_non_tesserati( $p ) . ' persone non tesserate' );
	}
	if ( '' !== $p->note ) {
		$righe[] = 'Note: ' . esc_html( $p->note );
	}
	return implode( '<br>', $righe );
}

function rcm_pr_manda( $a, $oggetto, $corpo ) {
	$html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:520px">' . $corpo
		. '<p style="color:#666;font-size:13px">Roma Club Matera &ldquo;Francesco Totti&rdquo;</p></div>';
	return wp_mail( $a, $oggetto, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

function rcm_pr_pagamenti_html() {
	if ( ! function_exists( 'rcm_tess_pagamenti' ) ) {
		return '';
	}
	$voci = array();
	foreach ( rcm_tess_pagamenti() as $m ) {
		$voci[] = esc_html( $m['nome'] );
	}
	return implode( ', ', $voci );
}

/** Al socio, sui passaggi che lo riguardano. */
function rcm_pr_email_socio( $socio, $partita, $p ) {
	if ( ! rcm_as_email_ammessa( $socio->email ) ) {
		return false;
	}
	$link = '<p><a href="' . esc_url( rcm_as_url_pagina() ) . '">Vedi le tue prenotazioni nell\'area soci</a></p>';
	$ciao = '<p>Ciao ' . esc_html( $socio->nome ) . ',</p>';
	$riep = '<p style="background:#fbf3e1;padding:12px 14px;border-radius:6px">' . rcm_pr_riassunto_html( $partita, $p ) . '</p>';

	if ( 'confermato' === $p->stato ) {
		$cosa = array();
		if ( $p->biglietto ) {
			$cosa[] = 'il biglietto c\'è';
		}
		if ( $p->pullman ) {
			$cosa[] = 'il posto in pullman è riservato';
		}
		$nota = '' !== $p->nota_club ? '<p>' . nl2br( esc_html( $p->nota_club ) ) . '</p>' : '';
		return rcm_pr_manda( $socio->email, 'Confermato: ' . $partita->titolo, $ciao . '<p>abbiamo ricevuto il pagamento: <strong>' . esc_html( implode( ' e ', $cosa ) ) . '</strong>.</p>' . $riep . $nota . $link );
	}
	if ( 'annullato' === $p->stato ) {
		$nota = '' !== $p->nota_club ? '<p>' . nl2br( esc_html( $p->nota_club ) ) . '</p>' : '';
		return rcm_pr_manda( $socio->email, 'Prenotazione annullata: ' . $partita->titolo, $ciao . '<p>la tua prenotazione è stata annullata.</p>' . $riep . $nota . $link );
	}
	// l'importo varia da partita a partita: lo comunica il Club, il sito no
	$pagare = '<p>Per quanto e come pagare ti scrive il Club. Si può pagare con: ' . rcm_pr_pagamenti_html() . '.</p>';
	return rcm_pr_manda(
		$socio->email,
		'Prenotato: ' . $partita->titolo,
		$ciao . '<p>abbiamo ricevuto la tua prenotazione. Resta <strong>Prenotato</strong> finché non arriva il pagamento: allora diventa <strong>Confermato</strong> e te lo scriviamo.</p>' . $riep . $pagare . $link
	);
}

/** Al Club, a ogni richiesta, modifica o annullamento del socio. */
function rcm_pr_avvisa_club( $socio, $partita, $p, $cosa ) {
	if ( ! rcm_as_email_ammessa( $socio->email ) ) {
		return false;
	}
	$prova = 'prova' === rcm_as_opzioni()['stato'] ? '[PROVA] ' : '';
	$url   = admin_url( 'admin.php?page=rcm-soci-prenotazioni&evento=' . $partita->id );
	return rcm_pr_manda(
		apply_filters( 'rcm_pr_email_club', 'info@romaclubmatera.it' ),
		$prova . 'Prenotazione ' . $cosa . ': ' . $socio->nome . ' ' . $socio->cognome . ' - ' . $partita->titolo,
		'<p><strong>' . esc_html( $socio->nome . ' ' . $socio->cognome ) . '</strong> (' . esc_html( $socio->email ) . ') &mdash; prenotazione ' . esc_html( $cosa ) . '.</p>'
		. '<p>' . rcm_pr_riassunto_html( $partita, $p ) . '</p>'
		. '<p><a href="' . esc_url( $url ) . '">Apri le prenotazioni della partita</a></p>'
	);
}

/* -------------------------------------------------------------------------
 * Nell'area soci
 * ---------------------------------------------------------------------- */

add_action( 'rcm_as_sezioni', 'rcm_pr_sezione', 10, 1 );
function rcm_pr_sezione( $socio ) {
	$prossime = rcm_pr_prossime();
	$mie      = array();
	foreach ( rcm_pr_prenotazioni_socio( $socio->id ) as $p ) {
		$mie[ (int) $p->evento_id ] = $p;
	}

	echo '<section class="rcm-as-scheda rcm-pr" id="prenotazioni"><h2 class="rcm-as-titolo">Biglietti e pullman</h2>';
	if ( ! $prossime ) {
		echo '<p>Nel calendario non ci sono partite in programma.</p></section>';
		return;
	}

	$mostrate  = array();
	$prossima  = $prossime[0];
	$mostrate[] = $prossima->id;
	rcm_pr_scheda_partita( $prossima, $mie[ $prossima->id ] ?? null, 'Prossima partita' );

	// Se la prossima e' gia' chiusa (si gioca ogni settimana, e il Club chiude
	// una decina di giorni prima) si mostra anche la prima ancora aperta.
	if ( ! $prossima->aperta ) {
		foreach ( $prossime as $partita ) {
			if ( $partita->aperta ) {
				$mostrate[] = $partita->id;
				rcm_pr_scheda_partita( $partita, $mie[ $partita->id ] ?? null, isset( $mie[ $partita->id ] ) && 'annullato' !== $mie[ $partita->id ]->stato ? 'La tua prossima prenotazione' : 'Prima partita prenotabile' );
				break;
			}
		}
	}

	// Le altre prenotazioni del socio per partite future, attive
	$altre = array();
	foreach ( $mie as $evento_id => $p ) {
		if ( in_array( $evento_id, $mostrate, true ) || 'annullato' === $p->stato ) {
			continue;
		}
		$partita = rcm_pr_partita( $evento_id );
		if ( $partita && $partita->futura ) {
			$altre[] = array( $partita, $p );
		}
	}
	if ( $altre ) {
		echo '<h3 class="rcm-pr-sottotitolo">Le tue altre prenotazioni</h3>';
		foreach ( $altre as $coppia ) {
			rcm_pr_scheda_partita( $coppia[0], $coppia[1], '' );
		}
	}
	printf( '<p class="rcm-as-nota">Le prenotazioni restano aperte finché il Club non le chiude, di solito una decina di giorni prima della partita: meglio non aspettare l\'ultimo momento. Finché non paghi la prenotazione resta <strong>Prenotato</strong>; appena il pagamento arriva al Club diventa <strong>Confermato</strong>: il biglietto c\'è e il posto in pullman è tuo.</p>' );
	echo '</section>';
}

function rcm_pr_scheda_partita( $partita, $p, $etichetta ) {
	$stati = rcm_pr_stati();
	?>
	<article class="rcm-pr-partita<?php echo $p ? ' rcm-pr-partita--' . esc_attr( $p->stato ) : ''; ?>">
		<?php if ( $etichetta ) : ?>
			<p class="rcm-pr-etichetta"><?php echo esc_html( $etichetta ); ?></p>
		<?php endif; ?>
		<h3 class="rcm-pr-titolo"><?php echo esc_html( $partita->titolo ); ?></h3>
		<p class="rcm-pr-quando"><?php echo esc_html( rcm_pr_data( $partita ) ); ?><?php echo $partita->luogo ? ' &middot; ' . esc_html( $partita->luogo ) : ''; ?></p>

		<?php if ( $p && 'annullato' !== $p->stato ) : ?>
			<p class="rcm-pr-stato rcm-pr-stato--<?php echo esc_attr( $p->stato ); ?>"><?php echo esc_html( $stati[ $p->stato ] ); ?></p>
			<ul class="rcm-pr-voci">
				<?php if ( $p->biglietto ) : ?>
					<li><?php echo 'confermato' === $p->stato ? '&#10003; Il biglietto c&rsquo;&egrave;' : 'Biglietto'; ?> &middot; <?php echo esc_html( $p->settore ); ?></li>
				<?php endif; ?>
				<?php if ( $p->pullman ) : ?>
					<li><?php echo 'confermato' === $p->stato ? '&#10003; Posto in pullman riservato' : 'Posto in pullman'; ?></li>
				<?php endif; ?>
				<?php foreach ( rcm_pr_persone( $p ) as $x ) : ?>
					<li><?php echo esc_html( $x['nome'] ); ?> &middot; <?php echo $x['tesserato'] ? 'tesserato' : 'non tesserato, con sovrapprezzo'; ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( 'prenotato' === $p->stato ) : ?>
				<p class="rcm-pr-paga">
					In attesa del pagamento: per quanto e come pagare ti scrive il Club. Si può pagare con: <?php echo wp_kses_post( rcm_pr_pagamenti_html() ); ?>.
				</p>
			<?php endif; ?>
			<?php if ( '' !== $p->nota_club ) : ?>
				<p class="rcm-pr-nota-club"><?php echo nl2br( esc_html( $p->nota_club ) ); ?></p>
			<?php endif; ?>
			<?php if ( 'prenotato' === $p->stato && $partita->aperta ) : ?>
				<?php rcm_pr_conto( $partita ); ?>
				<details class="rcm-pr-modifica">
					<summary>Modifica o annulla</summary>
					<?php rcm_pr_modulo( $partita, $p ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rcm_pr_annulla">
						<input type="hidden" name="evento" value="<?php echo esc_attr( $partita->id ); ?>">
						<?php wp_nonce_field( 'rcm_pr_annulla', '_rcm_as' ); ?>
						<button type="submit" class="rcm-as-secondario">Annulla la prenotazione</button>
					</form>
				</details>
			<?php endif; ?>
		<?php elseif ( $partita->aperta ) : ?>
			<?php if ( $p ) : ?>
				<p class="rcm-pr-stato rcm-pr-stato--annullato"><?php echo esc_html( $stati['annullato'] ); ?></p>
			<?php endif; ?>
			<?php rcm_pr_conto( $partita ); ?>
			<?php rcm_pr_modulo( $partita, $p ); ?>
		<?php else : ?>
			<?php if ( $p ) : ?>
				<p class="rcm-pr-stato rcm-pr-stato--annullato"><?php echo esc_html( $stati['annullato'] ); ?></p>
			<?php endif; ?>
			<p class="rcm-pr-chiusa">Prenotazioni chiuse.</p>
		<?php endif; ?>
	</article>
	<?php
}

/** Il conto alla rovescia dei giorni per prenotare. */
function rcm_pr_conto( $partita ) {
	$fino = wp_date( 'l j F', $partita->entro->getTimestamp() );
	if ( $partita->giorni < 0 ) {
		$testo = 'Prenotazioni ancora aperte, ma per poco: il Club le chiude a breve.';
	} elseif ( 0 === $partita->giorni ) {
		$testo = '<strong>Oggi è l\'ultimo giorno</strong> per prenotare.';
	} else {
		$testo = sprintf(
			'<span class="rcm-pr-giorni">%d</span> %s per prenotare, entro %s',
			$partita->giorni,
			1 === $partita->giorni ? 'giorno' : 'giorni',
			esc_html( $fino )
		);
	}
	echo '<p class="rcm-pr-conto">' . $testo . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- composto qui sopra
}

function rcm_pr_modulo( $partita, $p ) {
	$attiva  = $p && 'prenotato' === $p->stato;
	$big     = $attiva ? (int) $p->biglietto : 1;
	$pul     = $attiva ? (int) $p->pullman : 1;
	$settore = $attiva ? $p->settore : '';
	$id      = 'rcm-pr-' . $partita->id;
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcm-as-modulo rcm-pr-modulo">
		<input type="hidden" name="action" value="rcm_pr_prenota">
		<input type="hidden" name="evento" value="<?php echo esc_attr( $partita->id ); ?>">
		<?php wp_nonce_field( 'rcm_pr_prenota', '_rcm_as' ); ?>
		<fieldset class="rcm-pr-scelte">
			<legend>Cosa prenoti</legend>
			<label><input type="checkbox" name="biglietto" value="1" <?php checked( $big ); ?>> Biglietto<?php echo $partita->casa ? '' : ' (settore ospiti)'; ?></label>
			<label><input type="checkbox" name="pullman" value="1" <?php checked( $pul ); ?>> Posto in pullman</label>
		</fieldset>
		<?php if ( $partita->casa ) : ?>
			<label for="<?php echo esc_attr( $id ); ?>-settore">Settore <span class="rcm-pr-facoltativo">fra quelli disponibili</span></label>
			<select id="<?php echo esc_attr( $id ); ?>-settore" name="settore">
				<option value="">— scegli —</option>
				<?php foreach ( rcm_pr_settori() as $s ) : ?>
					<option <?php selected( $settore, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<?php $persone = $attiva ? rcm_pr_persone( $p ) : array(); ?>
		<fieldset class="rcm-pr-persone">
			<legend>Anche per altre persone? <span class="rcm-pr-facoltativo">facoltativo</span></legend>
			<p class="rcm-pr-regola">Oltre a te, che sei tesserato, puoi indicare altre <?php echo (int) RCM_PR_MAX_PERSONE; ?> persone al massimo: devono essere tesserate, oppure pagano un <strong>sovrapprezzo</strong>.</p>
			<?php for ( $i = 0; $i < RCM_PR_MAX_PERSONE; $i++ ) : ?>
				<?php $x = $persone[ $i ] ?? null; ?>
				<div class="rcm-pr-persona"<?php echo $x ? '' : ' data-vuota="1"'; ?>>
					<input name="persona_nome[]" value="<?php echo esc_attr( $x ? $x['nome'] : '' ); ?>" placeholder="Nome e cognome" autocomplete="off" aria-label="Nome e cognome della persona <?php echo (int) ( $i + 1 ); ?>">
					<select name="persona_tessera[]" aria-label="È tesserata la persona <?php echo (int) ( $i + 1 ); ?>?">
						<option value="">È tesserata?</option>
						<option value="si" <?php selected( $x && $x['tesserato'] ); ?>>Tesserata</option>
						<option value="no" <?php selected( $x && ! $x['tesserato'] ); ?>>Non tesserata (con sovrapprezzo)</option>
					</select>
				</div>
			<?php endfor; ?>
			<button type="button" class="rcm-as-secondario rcm-pr-aggiungi" hidden>+ Aggiungi un'altra persona</button>
		</fieldset>
		<label for="<?php echo esc_attr( $id ); ?>-note">Note <span class="rcm-pr-facoltativo">facoltative</span></label>
		<textarea id="<?php echo esc_attr( $id ); ?>-note" name="note" rows="2" maxlength="500"><?php echo esc_textarea( $attiva ? $p->note : '' ); ?></textarea>
		<button type="submit"><?php echo $attiva ? 'Salva le modifiche' : 'Prenota'; ?></button>
	</form>
	<?php
}

/* -------------------------------------------------------------------------
 * In bacheca: Soci > Prenotazioni
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rcm-soci', 'Prenotazioni', 'Prenotazioni', RCM_COMPLEANNI_CAP, 'rcm-soci-prenotazioni', 'rcm_pr_pagina_admin' );
	},
	15
);

function rcm_pr_pagina_admin() {
	global $wpdb;
	$t     = rcm_pr_tabella();
	$stati = rcm_pr_stati();

	if ( isset( $_POST['rcm_pr_salva_settori'] ) && check_admin_referer( 'rcm_pr_settori' ) ) {
		$settori = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['settori'] ?? '' ) ) as $riga ) {
			$riga = mb_substr( trim( sanitize_text_field( $riga ) ), 0, 60 );
			if ( '' !== $riga && ! in_array( $riga, $settori, true ) ) {
				$settori[] = $riga;
			}
		}
		if ( $settori ) {
			update_option( 'rcm_pr_settori', $settori, false );
			rcm_compleanni_avviso( 'Settori salvati: ' . count( $settori ) . '.' );
		} else {
			rcm_compleanni_avviso( 'Serve almeno un settore: elenco non cambiato.', 'error' );
		}
	}

	if ( isset( $_POST['rcm_pr_chiusura'] ) && check_admin_referer( 'rcm_pr_chiusura' ) ) {
		$x = rcm_pr_partita( absint( $_POST['evento'] ?? 0 ) );
		if ( $x ) {
			if ( ! empty( $_POST['chiudi'] ) ) {
				update_post_meta( $x->id, RCM_PR_META_CHIUSE, 1 );
				rcm_compleanni_avviso( 'Prenotazioni chiuse per ' . esc_html( $x->titolo ) . '.' );
			} else {
				delete_post_meta( $x->id, RCM_PR_META_CHIUSE );
				rcm_compleanni_avviso( 'Prenotazioni riaperte per ' . esc_html( $x->titolo ) . '.' );
			}
			$_GET['evento'] = $x->id;
		}
	}

	if ( isset( $_POST['rcm_pr_salva'] ) && check_admin_referer( 'rcm_pr_admin' ) ) {
		$id    = absint( $_POST['id'] ?? 0 );
		$prima = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$stato = sanitize_key( wp_unslash( $_POST['stato'] ?? '' ) );
		if ( $prima && isset( $stati[ $stato ] ) ) {
			$dati = array(
				'stato'         => $stato,
				'nota_club'     => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['nota_club'] ?? '' ) ), 0, 500 ),
				'aggiornato_il' => current_time( 'mysql' ),
			);
			if ( 'confermato' === $stato && 'confermato' !== $prima->stato ) {
				$dati['confermato_il'] = current_time( 'mysql' );
			}
			$wpdb->update( $t, $dati, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$dopo    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$socio   = rcm_as_socio( $dopo->socio_id );
			$partita = rcm_pr_partita( $dopo->evento_id );
			$cambiato = $prima->stato !== $dopo->stato;
			$msg      = 'Prenotazione salvata.';
			if ( $cambiato && ! empty( $_POST['avvisa'] ) && $socio && $partita ) {
				$msg .= rcm_pr_email_socio( $socio, $partita, $dopo )
					? ' Email mandata a ' . esc_html( $socio->email ) . '.'
					: ' <strong>Email non mandata</strong> (area soci spenta, o in prova e indirizzo non di prova).';
			}
			rcm_compleanni_avviso( $msg );
		}
	}

	// Le partite: quelle in arrivo, piu' quelle passate che hanno prenotazioni
	$partite = array();
	foreach ( rcm_pr_prossime( 20 ) as $x ) {
		$partite[ $x->id ] = $x;
	}
	foreach ( $wpdb->get_col( "SELECT DISTINCT evento_id FROM $t" ) as $eid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! isset( $partite[ (int) $eid ] ) ) {
			$x = rcm_pr_partita( (int) $eid );
			if ( $x ) {
				$partite[ $x->id ] = $x;
			}
		}
	}
	uasort(
		$partite,
		function ( $a, $b ) {
			return $a->quando <=> $b->quando;
		}
	);
	$conteggi = array();
	foreach ( $wpdb->get_results( "SELECT evento_id, COUNT(*) n FROM $t WHERE stato <> 'annullato' GROUP BY evento_id" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$conteggi[ (int) $r->evento_id ] = (int) $r->n;
	}
	$scelta = absint( $_GET['evento'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $scelta ) {
		foreach ( $partite as $x ) {
			if ( $x->futura ) {
				$scelta = $x->id;
				break;
			}
		}
	}
	$partita = $scelta ? rcm_pr_partita( $scelta ) : null;
	$righe   = $scelta ? $wpdb->get_results( $wpdb->prepare( "SELECT p.*, s.nome, s.cognome, s.email, s.telefono FROM $t p LEFT JOIN " . rcm_compleanni_tabella() . " s ON s.id = p.socio_id WHERE p.evento_id = %d ORDER BY FIELD(p.stato,'prenotato','confermato','annullato'), p.creato_il", $scelta ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

	$tot = array( 'biglietti' => array( 0, 0 ), 'pullman' => array( 0, 0 ) ); // [prenotati, confermati], in persone
	$non_tesserati = 0;
	foreach ( $righe as $r ) {
		if ( 'annullato' === $r->stato ) {
			continue;
		}
		$n              = 1 + count( rcm_pr_persone( $r ) );
		$non_tesserati += rcm_pr_non_tesserati( $r );
		$c = 'confermato' === $r->stato ? 1 : 0;
		if ( $r->biglietto ) {
			$tot['biglietti'][ $c ] += $n;
		}
		if ( $r->pullman ) {
			$tot['pullman'][ $c ] += $n;
		}
	}
	?>
	<div class="wrap">
		<h1>Prenotazioni</h1>
		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="rcm-soci-prenotazioni">
			<select name="evento" onchange="this.form.submit()">
				<?php foreach ( $partite as $x ) : ?>
					<option value="<?php echo esc_attr( $x->id ); ?>" <?php selected( $scelta, $x->id ); ?>>
						<?php echo esc_html( wp_date( 'd/m/Y', $x->quando->getTimestamp() ) . ' — ' . $x->titolo . ( isset( $conteggi[ $x->id ] ) ? ' (' . $conteggi[ $x->id ] . ')' : '' ) . ( $x->chiusa ? ' · chiusa' : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<noscript><button class="button">Mostra</button></noscript>
		</form>
		<?php if ( $partita ) : ?>
			<p>
				<strong><?php echo esc_html( $partita->titolo ); ?></strong> &middot; <?php echo esc_html( rcm_pr_data( $partita ) ); ?> &middot;
				<?php if ( $partita->chiusa ) : ?>
					<strong style="color:#b32d2e">prenotazioni chiuse</strong>
				<?php elseif ( $partita->futura ) : ?>
					<strong style="color:#1a7a2e">prenotazioni aperte</strong> (ai soci si chiede di prenotare entro <?php echo esc_html( wp_date( 'j F', $partita->entro->getTimestamp() ) ); ?>)
				<?php else : ?>
					partita giocata
				<?php endif; ?>
			</p>
			<?php if ( $partita->futura ) : ?>
				<form method="post" style="margin:-4px 0 14px">
					<?php wp_nonce_field( 'rcm_pr_chiusura' ); ?>
					<input type="hidden" name="evento" value="<?php echo esc_attr( $partita->id ); ?>">
					<input type="hidden" name="chiudi" value="<?php echo $partita->chiusa ? '0' : '1'; ?>">
					<?php submit_button( $partita->chiusa ? 'Riapri le prenotazioni' : 'Chiudi le prenotazioni', $partita->chiusa ? 'secondary' : 'primary', 'rcm_pr_chiusura', false ); ?>
					<span class="description">Chiuse, i soci non possono più prenotare, modificare o annullare per questa partita.</span>
				</form>
			<?php endif; ?>
			<p>
				Biglietti: <strong><?php echo (int) ( $tot['biglietti'][0] + $tot['biglietti'][1] ); ?></strong> persone (<?php echo (int) $tot['biglietti'][1]; ?> confermate) &middot;
				Pullman: <strong><?php echo (int) ( $tot['pullman'][0] + $tot['pullman'][1] ); ?></strong> posti (<?php echo (int) $tot['pullman'][1]; ?> confermati)
			<?php if ( $non_tesserati ) : ?> &middot; Non tesserati con sovrapprezzo: <strong><?php echo (int) $non_tesserati; ?></strong><?php endif; ?>
			</p>
		<?php endif; ?>
		<?php if ( ! $righe ) : ?>
			<p>Nessuna prenotazione per questa partita.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Socio</th><th>Cosa</th><th>Persone</th><th>Note del socio</th><th style="width:34%">Stato e nota per il socio</th></tr></thead>
				<tbody>
				<?php foreach ( $righe as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->nome . ' ' . $r->cognome ); ?></strong><br><?php echo esc_html( $r->email ); ?><?php echo $r->telefono && function_exists( 'rcm_soci_telefono_leggibile' ) ? '<br>' . esc_html( rcm_soci_telefono_leggibile( $r->telefono ) ) : ''; ?></td>
						<td><?php echo $r->biglietto ? 'Biglietto · ' . esc_html( $r->settore ) . '<br>' : ''; ?><?php echo $r->pullman ? 'Pullman' : ''; ?></td>
						<td>
							<?php echo esc_html( $r->nome . ' ' . $r->cognome ); ?> <span class="description">(socio)</span>
							<?php foreach ( rcm_pr_persone( $r ) as $x ) : ?>
								<br><?php echo esc_html( $x['nome'] ); ?>
								<?php if ( ! $x['tesserato'] ) : ?>
									<span style="color:#996800">non tesserato, sovrapprezzo</span>
								<?php elseif ( rcm_pr_in_archivio( $x['nome'] ) ) : ?>
									<span style="color:#1a7a2e">&#10003; tesserato, in archivio</span>
								<?php else : ?>
									<span style="color:#b32d2e" title="Dichiarato tesserato, ma nell'archivio soci non c'è nessuno con questo nome e la tessera valida">&#9888; tesserato? non trovato in archivio</span>
								<?php endif; ?>
							<?php endforeach; ?>
						</td>
						<td><?php echo nl2br( esc_html( $r->note ) ); ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'rcm_pr_admin' ); ?>
								<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
								<select name="stato">
									<?php foreach ( $stati as $k => $v ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r->stato, $k ); ?>><?php echo esc_html( 'confermato' === $k ? 'Confermato (pagato)' : $v ); ?></option>
									<?php endforeach; ?>
								</select>
								<textarea name="nota_club" rows="2" style="width:100%;margin-top:4px" placeholder="nota per il socio, es. ritrovo alle 6:00 in piazza"><?php echo esc_textarea( $r->nota_club ); ?></textarea>
								<label><input type="checkbox" name="avvisa" value="1" checked> avvisa il socio per email se cambia lo stato</label><br>
								<?php submit_button( 'Salva', 'small', 'rcm_pr_salva', false ); ?>
								<?php echo 'confermato' === $r->stato && $r->confermato_il ? '<span class="description"> confermato il ' . esc_html( mysql2date( 'd/m/Y', $r->confermato_il ) ) . '</span>' : ''; ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:2em">Settori per le partite in casa</h2>
		<form method="post">
			<?php wp_nonce_field( 'rcm_pr_settori' ); ?>
			<p>Quelli fra cui il socio sceglie quando prenota il biglietto per una partita all'Olimpico: solo i settori di cui il Club dispone. Uno per riga, nell'ordine in cui devono comparire. Per le partite fuori casa il settore è sempre quello ospiti.</p>
			<textarea name="settori" rows="8" class="large-text" style="max-width:32em"><?php echo esc_textarea( implode( "\n", rcm_pr_settori() ) ); ?></textarea>
			<p class="description">Chi ha già prenotato tiene il settore che ha scelto, anche se lo togliete dall'elenco.</p>
			<?php submit_button( 'Salva i settori', 'secondary', 'rcm_pr_salva_settori', false ); ?>
		</form>
	</div>
	<?php
}
