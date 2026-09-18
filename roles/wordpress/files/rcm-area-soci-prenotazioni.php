<?php
/**
 * Plugin Name: RCM - Area soci: prenotazioni
 * Description: Nell'area soci, prenotazione del biglietto e del posto in pullman per le partite del calendario, fino a 10 giorni prima. Il socio vede "Prenotato" finche' il Club non segna il pagamento, poi "Confermato". Il Club le gestisce da Soci > Prenotazioni.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * COME FUNZIONA
 *
 * - Le partite sono gli eventi di SportsPress (sp_event) del calendario: niente
 *   da inserire due volte.
 * - Si prenota fino a 10 giorni prima della partita: l'ultimo giorno utile e'
 *   la data della partita meno 10, fino a mezzanotte. Poi la prenotazione si
 *   chiude, anche per modificarla o annullarla (a quel punto il Club ha gia'
 *   comprato i biglietti e fissato il pullman).
 * - Tre stati, e solo tre, come li vede il socio:
 *     prenotato  - richiesta fatta, pagamento non ancora arrivato;
 *     confermato - il Club ha segnato il pagamento: il biglietto c'e', il
 *                  posto in pullman e' riservato;
 *     annullato  - dal socio (prima della chiusura) o dal Club.
 *   Il pagamento lo segna il Club a mano: nessun pagamento passa dal sito.
 * - Una prenotazione per socio e partita; dentro, anche le altre persone per
 *   cui prenota, per nome: biglietti e posti sono nominativi.
 * - Le email seguono l'interruttore dell'area soci: da spenta non parte niente,
 *   in prova solo verso gli indirizzi di prova.
 *
 * Carica prima di rcm-area-soci.php (ordine alfabetico dei mu-plugin): per
 * questo qui nessuna funzione di quel file viene chiamata fuori dagli hook.
 */

defined( 'ABSPATH' ) || exit;

const RCM_PR_DB          = 'rcm_pr_db_version';
const RCM_PR_DB_VER      = '1.0';
const RCM_PR_GIORNI      = 10;  // quanti giorni prima della partita si chiude
const RCM_PR_MAX_PERSONE = 5;   // altre persone oltre al socio

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

/** I settori dell'Olimpico, gli stessi del modulo pubblico dei biglietti. */
function rcm_pr_settori() {
	return array( 'Distinti Nord Est', 'Tribuna Tevere', 'Distinti Sud', 'Distinti Nord Ovest', 'Curva Nord', 'Tribuna Monte Mario', 'Indifferente, consigliatemi voi' );
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
	$chiusura = $quando->setTime( 23, 59, 59 )->modify( '-' . RCM_PR_GIORNI . ' days' );
	$oggi     = new DateTimeImmutable( 'today', $tz );
	$luogo    = wp_get_post_terms( $p->ID, 'sp_venue', array( 'fields' => 'names' ) );
	$aperta   = time() <= $chiusura->getTimestamp();

	return (object) array(
		'id'       => $p->ID,
		'titolo'   => get_the_title( $p ),
		'quando'   => $quando,
		// in SportsPress l'ora 00:00 vuol dire "orario non ancora deciso"
		'ora'      => '00:00' === $quando->format( 'H:i' ) ? '' : $quando->format( 'H:i' ),
		'luogo'    => is_array( $luogo ) && $luogo ? $luogo[0] : '',
		// in casa se la Roma e' scritta per prima: "Roma vs Como"
		'casa'     => (bool) preg_match( '/^\s*(as\s+)?roma\b/i', $p->post_title ),
		'chiusura' => $chiusura,
		'aperta'   => $aperta,
		'giorni'   => $aperta ? (int) $oggi->diff( $chiusura->setTime( 0, 0 ) )->days : 0,
		'futura'   => $quando->getTimestamp() >= ( new DateTimeImmutable( 'today', $tz ) )->getTimestamp(),
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

/** Le altre persone, una per riga, pulite e al massimo RCM_PR_MAX_PERSONE. */
function rcm_pr_leggi_persone( $grezzo ) {
	$righe = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $grezzo ) as $riga ) {
		$riga = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $riga ) ) );
		if ( '' !== $riga ) {
			$righe[] = mb_substr( $riga, 0, 60 );
		}
	}
	return $righe;
}

function rcm_pr_persone( $prenotazione ) {
	return rcm_pr_leggi_persone( $prenotazione->persone );
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
			'pr_chiusa'     => 'Le prenotazioni per questa partita sono chiuse: si prenota fino a ' . RCM_PR_GIORNI . ' giorni prima.',
			'pr_niente'     => 'Scegli almeno il biglietto o il posto in pullman.',
			'pr_settore'    => 'Scegli il settore dello stadio.',
			'pr_persone'    => 'Puoi prenotare al massimo per ' . RCM_PR_MAX_PERSONE . ' persone oltre a te.',
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
	$persone = rcm_pr_leggi_persone( wp_unslash( $_POST['persone'] ?? '' ) );
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
		'persone'       => implode( "\n", $persone ),
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
	$persone = rcm_pr_persone( $p );
	if ( $persone ) {
		$righe[] = 'Anche per: ' . esc_html( implode( ', ', $persone ) );
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

	// La prossima e' quasi sempre gia' chiusa (si gioca ogni settimana e si
	// chiude 10 giorni prima): allora si mostra anche la prima ancora aperta.
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
	printf( '<p class="rcm-as-nota">Si prenota fino a %d giorni prima della partita. Il pagamento si fa al Club: quando arriva, la prenotazione diventa <strong>Confermata</strong>.</p>', (int) RCM_PR_GIORNI );
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
				<?php if ( rcm_pr_persone( $p ) ) : ?>
					<li>Anche per: <?php echo esc_html( implode( ', ', rcm_pr_persone( $p ) ) ); ?></li>
				<?php endif; ?>
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
			<p class="rcm-pr-chiusa">Prenotazioni chiuse il <?php echo esc_html( wp_date( 'j F', $partita->chiusura->getTimestamp() ) ); ?>.</p>
		<?php endif; ?>
	</article>
	<?php
}

/** Il conto alla rovescia dei giorni per prenotare. */
function rcm_pr_conto( $partita ) {
	$fino = wp_date( 'l j F', $partita->chiusura->getTimestamp() );
	if ( 0 === $partita->giorni ) {
		$testo = '<strong>Ultimo giorno</strong> per prenotare: si chiude stanotte a mezzanotte.';
	} else {
		$testo = sprintf(
			'<span class="rcm-pr-giorni">%d</span> %s per prenotare, fino a %s',
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
			<label for="<?php echo esc_attr( $id ); ?>-settore">Settore</label>
			<select id="<?php echo esc_attr( $id ); ?>-settore" name="settore">
				<option value="">— scegli —</option>
				<?php foreach ( rcm_pr_settori() as $s ) : ?>
					<option <?php selected( $settore, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<label for="<?php echo esc_attr( $id ); ?>-persone">Anche per altre persone? <span class="rcm-pr-facoltativo">nome e cognome, una per riga, al massimo <?php echo (int) RCM_PR_MAX_PERSONE; ?></span></label>
		<textarea id="<?php echo esc_attr( $id ); ?>-persone" name="persone" rows="2"><?php echo esc_textarea( $attiva ? $p->persone : '' ); ?></textarea>
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
		add_submenu_page( 'rcm-soci', 'Prenotazioni', 'Prenotazioni', 'manage_options', 'rcm-soci-prenotazioni', 'rcm_pr_pagina_admin' );
	},
	15
);

function rcm_pr_pagina_admin() {
	global $wpdb;
	$t     = rcm_pr_tabella();
	$stati = rcm_pr_stati();

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
	foreach ( $righe as $r ) {
		if ( 'annullato' === $r->stato ) {
			continue;
		}
		$n = 1 + count( rcm_pr_persone( $r ) );
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
						<?php echo esc_html( wp_date( 'd/m/Y', $x->quando->getTimestamp() ) . ' — ' . $x->titolo . ( isset( $conteggi[ $x->id ] ) ? ' (' . $conteggi[ $x->id ] . ')' : '' ) . ( $x->aperta ? '' : ' · chiusa' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<noscript><button class="button">Mostra</button></noscript>
		</form>
		<?php if ( $partita ) : ?>
			<p>
				<strong><?php echo esc_html( $partita->titolo ); ?></strong> &middot; <?php echo esc_html( rcm_pr_data( $partita ) ); ?> &middot;
				<?php echo $partita->aperta ? 'prenotazioni aperte fino a ' . esc_html( wp_date( 'j F', $partita->chiusura->getTimestamp() ) ) : 'prenotazioni chiuse'; ?>
			</p>
			<p>
				Biglietti: <strong><?php echo (int) ( $tot['biglietti'][0] + $tot['biglietti'][1] ); ?></strong> persone (<?php echo (int) $tot['biglietti'][1]; ?> confermate) &middot;
				Pullman: <strong><?php echo (int) ( $tot['pullman'][0] + $tot['pullman'][1] ); ?></strong> posti (<?php echo (int) $tot['pullman'][1]; ?> confermati)
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
						<td><?php echo esc_html( $r->nome . ' ' . $r->cognome ); ?><?php foreach ( rcm_pr_persone( $r ) as $nome ) { echo '<br>' . esc_html( $nome ); } ?></td>
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
	</div>
	<?php
}
