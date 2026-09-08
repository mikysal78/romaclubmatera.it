<?php
/**
 * Plugin Name: RCM - Richiesta biglietti
 * Description: Colonna "Biglietteria" accanto a ogni partita da giocare nelle tabelle di SportsPress, e pagina con il modulo di richiesta accanto allo schema dei settori dell'Olimpico. Il modulo e' un Contact Form 7 e la partita gli arriva dall'indirizzo, gia' compilata.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

/** Slug della pagina che ospita il modulo. */
const RCM_BIG_PAGINA = 'biglietti';

/** Nome del parametro con cui la partita viaggia nell'indirizzo. */
const RCM_BIG_PARAM = 'partita';

/* -------------------------------------------------------------------------
 * La colonna nelle tabelle del calendario
 * ---------------------------------------------------------------------- */

add_action( 'sportspress_event_list_head_row', 'rcm_big_intestazione', 10, 1 );
function rcm_big_intestazione( $usecolumns = null ) {
	echo '<th class="data-biglietteria">Biglietteria</th>';
}

/**
 * La cella, una per partita.
 *
 * Il link compare solo sulle partite ancora da giocare: per una gia' giocata
 * sarebbe un invito a chiedere biglietti per qualcosa che e' finito.
 *
 * @param WP_Post $event      L'evento della riga.
 * @param array   $usecolumns Colonne attive, passate da SportsPress.
 */
add_action( 'sportspress_event_list_row', 'rcm_big_cella', 10, 2 );
function rcm_big_cella( $event, $usecolumns = null ) {
	$quando = get_post_time( 'U', true, $event );

	if ( ! $quando || $quando < time() ) {
		echo '<td class="data-biglietteria">&mdash;</td>';
		return;
	}

	printf(
		'<td class="data-biglietteria"><a class="rcm-big-link" href="%s">Biglietteria</a></td>',
		esc_url( rcm_big_indirizzo( $event->ID ) )
	);
}

/** L'indirizzo della pagina del modulo, con la partita attaccata. */
function rcm_big_indirizzo( $id_evento ) {
	$pagina = get_page_by_path( RCM_BIG_PAGINA );
	if ( ! $pagina ) {
		return '';
	}
	return add_query_arg( RCM_BIG_PARAM, (int) $id_evento, get_permalink( $pagina ) );
}

/* -------------------------------------------------------------------------
 * La partita richiesta
 * ---------------------------------------------------------------------- */

/**
 * Legge la partita dall'indirizzo e la traduce in qualcosa di scrivibile.
 *
 * Non si fida del parametro: prende un id, cerca l'evento, e se non e' un
 * evento futuro torna vuoto. Cosi' nel modulo non finisce mai testo arrivato
 * da fuori.
 *
 * @return array|null  Con chiavi id, titolo, quando.
 */
function rcm_big_partita() {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$cache = null;

	$id = isset( $_GET[ RCM_BIG_PARAM ] ) ? absint( $_GET[ RCM_BIG_PARAM ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $id ) {
		return $cache;
	}

	$evento = get_post( $id );
	if ( ! $evento || 'sp_event' !== $evento->post_type ) {
		return $cache;
	}

	$quando = date_create_immutable( $evento->post_date, wp_timezone() );

	$squadre = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, 'sp_team', false ) ) ) );
	$titolo  = count( $squadre ) >= 2
		? get_the_title( $squadre[0] ) . '-' . get_the_title( $squadre[1] )
		: get_the_title( $id );

	$cache = array(
		'id'     => $id,
		'titolo' => $titolo,
		'quando' => $quando,
		'testo'  => sprintf(
			'%s, %s',
			$titolo,
			mb_strtolower( wp_date( 'l j F Y \a\l\l\e H:i', $quando->getTimestamp() ), 'UTF-8' )
		),
	);

	return $cache;
}

/**
 * Riempie il campo "partita" del modulo con la partita scelta nel calendario.
 *
 * Il campo e' di sola lettura: chi arriva dal calendario non deve riscrivere
 * a mano quello che ha appena cliccato, e noi riceviamo un dato pulito invece
 * di "quella con la Juve".
 */
add_filter( 'wpcf7_form_tag', 'rcm_big_compila_partita', 10, 1 );
function rcm_big_compila_partita( $tag ) {
	if ( ! is_array( $tag ) || 'partita' !== ( $tag['name'] ?? '' ) ) {
		return $tag;
	}
	$p = rcm_big_partita();
	if ( $p ) {
		$tag['values'] = array( $p['testo'] );
	}
	return $tag;
}

/* -------------------------------------------------------------------------
 * Il numero di telefono
 * ---------------------------------------------------------------------- */

/**
 * Riduce un numero alla forma internazionale, solo cifre.
 *
 * Stesse regole di rcm-compleanni: "377 281 4538", "+39 377-281-4538" e
 * "0039 377 2814538" sono lo stesso numero. Senza prefisso si assume
 * l'Italia; chi ha un numero estero scrive il "+".
 *
 * @param string $grezzo Come l'ha scritto chi compila.
 * @return string Cifre pure, oppure '' se non somiglia a un numero.
 */
function rcm_big_telefono( $grezzo ) {
	$grezzo = trim( (string) $grezzo );
	if ( '' === $grezzo ) {
		return '';
	}

	$internazionale = ( '+' === substr( $grezzo, 0, 1 ) );
	$cifre          = preg_replace( '/\D+/', '', $grezzo );

	if ( '' === $cifre ) {
		return '';
	}
	if ( '00' === substr( $cifre, 0, 2 ) ) {
		$cifre          = substr( $cifre, 2 );
		$internazionale = true;
	}
	if ( ! $internazionale ) {
		$cifre = '39' . $cifre;
	}

	// E.164: al massimo 15 cifre. Sotto le undici, con il 39 davanti, non e'
	// un numero italiano completo.
	if ( strlen( $cifre ) < 11 || strlen( $cifre ) > 15 ) {
		return '';
	}

	return $cifre;
}

/**
 * Controlla il numero prima di accettare la richiesta.
 *
 * Quello di Contact Form 7 non basta: la sua regola accetta "0", "12" e
 * "333" - qualunque cosa fatta di cifre - e rifiuta "(377) 281 4538", che e'
 * un modo normale di scrivere un numero. Il risultato e' che passano i numeri
 * troncati, cioe' proprio quelli su cui poi il Club non riesce a richiamare.
 */
add_filter( 'wpcf7_validate_tel*', 'rcm_big_valida_telefono', 20, 2 );
function rcm_big_valida_telefono( $risultato, $tag ) {
	if ( 'telefono' !== $tag->name ) {
		return $risultato;
	}

	$valore = isset( $_POST['telefono'] ) ? sanitize_text_field( wp_unslash( $_POST['telefono'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ci pensa Contact Form 7.

	// Se e' vuoto se ne occupa l'obbligatorieta', non questo controllo.
	if ( '' !== trim( $valore ) && ! rcm_big_telefono( $valore ) ) {
		$risultato->invalidate(
			$tag,
			'Questo numero non sembra completo. Scrivilo per intero, per esempio 377 281 4538; se e\' estero mettici il prefisso con il +.'
		);
	}

	return $risultato;
}

/**
 * Nell'email il numero arriva sempre nella stessa forma.
 *
 * Chi compila lo scrive come gli viene; chi lo legge in sede deve poterlo
 * toccare e chiamare senza ricopiarlo.
 */
add_filter( 'wpcf7_posted_data', 'rcm_big_normalizza_telefono' );
function rcm_big_normalizza_telefono( $dati ) {
	if ( empty( $dati['telefono'] ) ) {
		return $dati;
	}

	$cifre = rcm_big_telefono( $dati['telefono'] );
	if ( ! $cifre ) {
		return $dati;
	}

	$nazionale = ( '39' === substr( $cifre, 0, 2 ) ) ? substr( $cifre, 2 ) : '';

	// I cellulari italiani cominciano per 3 e si leggono a gruppi di 3-3-4.
	// Un fisso no: 0835 123456 spezzato allo stesso modo diventerebbe
	// "083 512 3456", che non somiglia piu' a un numero di Matera.
	if ( $nazionale && '3' === $nazionale[0] && 10 === strlen( $nazionale ) ) {
		$dati['telefono'] = '+39 ' . substr( $nazionale, 0, 3 ) . ' ' . substr( $nazionale, 3, 3 ) . ' ' . substr( $nazionale, 6 );
	} elseif ( $nazionale ) {
		$dati['telefono'] = '+39 ' . $nazionale;
	} else {
		$dati['telefono'] = '+' . $cifre;
	}

	return $dati;
}

/* -------------------------------------------------------------------------
 * La pagina: modulo a sinistra, settori a destra
 * ---------------------------------------------------------------------- */

add_shortcode( 'rcm_biglietti', 'rcm_big_pagina' );
function rcm_big_pagina( $atts ) {
	$atts = shortcode_atts( array( 'form' => 0 ), $atts, 'rcm_biglietti' );
	$p    = rcm_big_partita();

	ob_start();
	?>
	<div class="rcm-big">
		<div class="rcm-big-modulo">
			<?php if ( $p ) : ?>
				<p class="rcm-big-partita">Richiesta biglietti per<br><strong><?php echo esc_html( $p['testo'] ); ?></strong></p>

				<?php echo rcm_big_riservato(); // phpcs:ignore WordPress.Security.EscapeOutput -- markup scritto qui dentro. ?>
				<?php echo do_shortcode( '[contact-form-7 id="' . (int) $atts['form'] . '"]' ); ?>

				<p class="rcm-big-nota">
					<strong>Gli accrediti online sono solo informativi</strong>: non &egrave; una vendita e non
					impegna a niente. Il Club raccoglie la richiesta, verifica la disponibilit&agrave; e ti ricontatta
					<strong>via email, telefono o WhatsApp</strong>.
					<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Come trattiamo i tuoi dati</a>.
				</p>
			<?php else : ?>
				<?php
				/*
				 * Senza partita il modulo non si mostra. Con un campo di sola
				 * lettura vuoto si potrebbe inviare lo stesso, e arriverebbe una
				 * richiesta che non dice per quale gara: inservibile per chi la
				 * riceve e una delusione per chi l'ha scritta.
				 */
				?>
				<p class="rcm-big-partita rcm-big-avviso">
					Per chiedere i biglietti scegli prima la partita: apri il
					<a href="<?php echo esc_url( home_url( '/calendario/' ) ); ?>">calendario</a>
					e usa il link <strong>Biglietteria</strong> sulla riga della gara che ti interessa.
					Il modulo si apre gi&agrave; compilato.
				</p>

				<?php echo rcm_big_riservato(); // phpcs:ignore WordPress.Security.EscapeOutput -- markup scritto qui dentro. ?>
			<?php endif; ?>
		</div>

		<div class="rcm-big-settori">
			<?php echo rcm_big_mappa(); // phpcs:ignore WordPress.Security.EscapeOutput -- markup montato qui dentro. ?>
			<p class="rcm-big-didascalia">
				I settori dello Stadio Olimpico. La <strong>Curva Sud</strong> &egrave; riservata agli abbonati
				e non compare fra le scelte. Dentro ogni settore ci sono altre suddivisioni (centrale,
				laterale, parterre): se hai una preferenza precisa, scrivila nelle note.
			</p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Il riquadro che dice a chi e' riservato il servizio.
 *
 * Il Club puo' procurare i biglietti solo ai propri tesserati. Chi non lo e'
 * non va lasciato davanti a una porta chiusa, ma davanti al modulo di
 * tesseramento: e' l'unica cosa che deve fare per rientrare.
 */
function rcm_big_riservato() {
	$tessera = get_page_by_path( 'tesseramento-2026-27' );
	$link    = $tessera ? get_permalink( $tessera ) : home_url( '/' );

	return sprintf(
		'<p class="rcm-big-tesserati">Il servizio &egrave; riservato ai <strong>tesserati del Roma Club '
		. 'Matera &ldquo;Francesco Totti&rdquo;</strong>.'
		. '<br>Non sei ancora tesserato? <a href="%s">Tesserati al Roma Club Matera</a>.</p>',
		esc_url( $link )
	);
}

/**
 * La mappa dei settori: la figura caricata in libreria, se c'e'.
 *
 * Si cerca per slug e non per identificativo fisso: un id scritto nel codice
 * si rompe al primo ripristino da backup su un sito diverso, uno slug no.
 * Se manca si ripiega sul disegno qui sotto, che non dipende da niente.
 */
function rcm_big_mappa() {
	$trovati = get_posts(
		array(
			'post_type'      => 'attachment',
			'name'           => 'olimpico-settori',
			'posts_per_page' => 1,
			'post_status'    => 'inherit',
			'fields'         => 'ids',
		)
	);

	if ( ! $trovati ) {
		return rcm_big_schema();
	}

	return wp_get_attachment_image(
		$trovati[0],
		'full',
		false,
		array(
			'class'    => 'rcm-big-mappa',
			'loading'  => 'lazy',
			'decoding' => 'async',
		)
	);
}

/**
 * Lo schema dei settori, di riserva.
 *
 * E' un disegno originale, non la piantina della societa' o della biglietteria:
 * quelle sono opere protette e non si possono copiare. I nomi e la posizione
 * dei settori invece sono un fatto, e quelli si possono dire.
 *
 * Il file lo genera scripts/olimpico-svg.py: gli archi dei settori vanno
 * calcolati, e a mano non combaciano mai.
 */
function rcm_big_schema() {
	return <<<'SVG'
<svg class="rcm-big-svg" xmlns="http://www.w3.org/2000/svg" viewBox="14 58 472 314" role="img" aria-labelledby="rcm-olimpico-t rcm-olimpico-d">
  <title id="rcm-olimpico-t">Stadio Olimpico: i settori</title>
  <desc id="rcm-olimpico-d">Lo Stadio Olimpico di Roma visto dall&#8217;alto in prospettiva, con l&#8217;anello delle tribune diviso nei suoi settori: Curva Nord e Curva Sud dietro le porte, Tribuna Monte Mario e Tribuna Tevere sui lati lunghi, e ai quattro angoli i Distinti. La Curva Sud &#232; riservata agli abbonati.</desc>
  <!-- La facciata esterna: e' quella che fa sembrare lo stadio visto -->
  <!-- da un angolo invece che schiacciato sulla carta. -->
  <path d="M24.0 200.0 A226.0 128.0 0 0 0 476.0 200.0 L476.0 230.0 A226.0 128.0 0 0 1 24.0 230.0 Z" fill="#2a2a2a"/>
  <ellipse cx="250" cy="200" rx="226" ry="128" fill="#1c1c1c"/>
  <!-- I settori dell'anello -->
  <path d="M445.7 264.0 A226.0 128.0 0 0 0 445.7 136.0 L366.0 163.0 A134.0 74.0 0 0 1 366.0 237.0 Z" fill="#4a4a4a"/>
  <path d="M445.7 136.0 A226.0 128.0 0 0 0 349.1 85.0 L308.7 133.5 A134.0 74.0 0 0 1 366.0 163.0 Z" fill="#c9a227"/>
  <path d="M349.1 85.0 A226.0 128.0 0 0 0 150.9 85.0 L191.3 133.5 A134.0 74.0 0 0 1 308.7 133.5 Z" fill="#e6af14"/>
  <path d="M150.9 85.0 A226.0 128.0 0 0 0 54.3 136.0 L134.0 163.0 A134.0 74.0 0 0 1 191.3 133.5 Z" fill="#c9a227"/>
  <path d="M54.3 136.0 A226.0 128.0 0 0 0 54.3 264.0 L134.0 237.0 A134.0 74.0 0 0 1 134.0 163.0 Z" fill="#8e1f2f"/>
  <path d="M54.3 264.0 A226.0 128.0 0 0 0 150.9 315.0 L191.3 266.5 A134.0 74.0 0 0 1 134.0 237.0 Z" fill="#c9a227"/>
  <path d="M150.9 315.0 A226.0 128.0 0 0 0 445.7 264.0 L366.0 237.0 A134.0 74.0 0 0 1 191.3 266.5 Z" fill="#e6af14"/>
  <g fill="none" stroke="#141414" stroke-width="1.5">
    <line x1="445.7" y1="264.0" x2="366.0" y2="237.0"/>
    <line x1="445.7" y1="136.0" x2="366.0" y2="163.0"/>
    <line x1="349.1" y1="85.0" x2="308.7" y2="133.5"/>
    <line x1="150.9" y1="85.0" x2="191.3" y2="133.5"/>
    <line x1="54.3" y1="136.0" x2="134.0" y2="163.0"/>
    <line x1="54.3" y1="264.0" x2="134.0" y2="237.0"/>
    <line x1="150.9" y1="315.0" x2="191.3" y2="266.5"/>
  </g>
  <ellipse cx="250" cy="200" rx="134" ry="74" fill="#151515"/>
  <!-- La pista d'atletica: e' lei che tiene le tribune lontane dal campo -->
  <rect x="132" y="138" width="236" height="124" rx="62" fill="#8a4a34"/>
  <rect x="142" y="147" width="216" height="106" rx="53" fill="none" stroke="#a35c42" stroke-width="1"/>
  <rect x="152" y="156" width="196" height="88" rx="44" fill="#17482a" stroke="#a35c42" stroke-width="1"/>
  <!-- Il campo, con le porte dietro le due curve -->
  <rect x="158" y="162" width="184" height="76" rx="1" fill="#1f5c34" stroke="#4a9c63" stroke-width="1.2"/>
  <line x1="250" y1="162" x2="250" y2="238" stroke="#4a9c63" stroke-width="1.2"/>
  <ellipse cx="250" cy="200" rx="17" ry="11" fill="none" stroke="#4a9c63" stroke-width="1.2"/>
  <rect x="158" y="178" width="22" height="44" fill="none" stroke="#4a9c63" stroke-width="1.2"/>
  <rect x="320" y="178" width="22" height="44" fill="none" stroke="#4a9c63" stroke-width="1.2"/>
  <g font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">
    <text x="430.0" y="200.0" font-size="12" fill="#d0d0d0" dominant-baseline="middle" transform="rotate(-90.0 430.0 200.0)">CURVA SUD</text>
    <text x="372.8" y="126.1" font-size="9" fill="#3a2c05" dominant-baseline="middle" transform="rotate(27.6 372.8 126.1)">DISTINTI SUD</text>
    <text x="250.0" y="99.0" font-size="12" fill="#3a2c05" dominant-baseline="middle" transform="rotate(0.0 250.0 99.0)">TRIBUNA TEVERE</text>
    <text x="127.2" y="126.1" font-size="9" fill="#3a2c05" dominant-baseline="middle" transform="rotate(-27.6 127.2 126.1)">DISTINTI NORD EST</text>
    <text x="70.0" y="200.0" font-size="12" fill="#f7ecd5" dominant-baseline="middle" transform="rotate(-90.0 70.0 200.0)">CURVA NORD</text>
    <text x="127.2" y="273.9" font-size="9" fill="#3a2c05" dominant-baseline="middle" transform="rotate(27.6 127.2 273.9)">DISTINTI NORD OVEST</text>
    <text x="302.6" y="296.6" font-size="12" fill="#3a2c05" dominant-baseline="middle" transform="rotate(-9.7 302.6 296.6)">TRIBUNA MONTE MARIO</text>
    <text x="404.2" y="200.0" font-size="9" font-weight="400" fill="#9a9a9a" dominant-baseline="middle" transform="rotate(90 404.2 200.0)">solo abbonati</text>
  </g>
</svg>
SVG;
}
