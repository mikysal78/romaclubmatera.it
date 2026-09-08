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
				<?php echo do_shortcode( '[contact-form-7 id="' . (int) $atts['form'] . '"]' ); ?>

				<p class="rcm-big-nota">
					La richiesta non e' un acquisto: serve al Club per capire quanti siamo e in quale settore.
					Ti ricontattiamo noi con disponibilita' e prezzi.
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
					Il modulo si apre gia' compilato.
				</p>
			<?php endif; ?>
		</div>

		<div class="rcm-big-settori">
			<?php echo rcm_big_schema(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG scritto qui dentro. ?>
			<p class="rcm-big-didascalia">
				I quattro settori dello Stadio Olimpico. Dentro ognuno ci sono altre suddivisioni
				(centrale, laterale, parterre): se hai una preferenza precisa, scrivila nelle note.
			</p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Lo schema dei settori.
 *
 * E' un disegno originale, non la piantina della societa' o della biglietteria:
 * quelle sono opere protette e non si possono copiare. Qui bastano i quattro
 * settori principali, che sono un fatto, non un disegno di qualcun altro.
 */
function rcm_big_schema() {
	return <<<'SVG'
<svg class="rcm-big-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 420 340" role="img" aria-labelledby="rcm-olimpico-t rcm-olimpico-d">
  <title id="rcm-olimpico-t">Stadio Olimpico: i settori</title>
  <desc id="rcm-olimpico-d">Schema dei quattro settori principali dello Stadio Olimpico di Roma: Curva Sud e Curva Nord dietro le porte, Tribuna Monte Mario e Tribuna Tevere sui lati lunghi.</desc>
  <ellipse cx="210" cy="170" rx="198" ry="158" fill="#141414" stroke="#2c2c2c" stroke-width="1"/>
  <!-- L'anello si divide sulle diagonali, non sugli assi: cosi' ogni settore
       sta tutto in un colore e la sua etichetta non finisce a cavallo di due
       fondi diversi. Curve in rosso, tribune in giallo. -->
  <g stroke="#141414" stroke-width="2">
    <path d="M210 170 L70 58.3 A198 158 0 0 1 350 58.3 Z" fill="#8e1f2f"/>
    <path d="M210 170 L350 281.7 A198 158 0 0 1 70 281.7 Z" fill="#8e1f2f"/>
    <path d="M210 170 L350 58.3 A198 158 0 0 1 350 281.7 Z" fill="#e6af14"/>
    <path d="M210 170 L70 281.7 A198 158 0 0 1 70 58.3 Z" fill="#e6af14"/>
  </g>
  <ellipse cx="210" cy="170" rx="126" ry="96" fill="#1b1b1b" stroke="#2c2c2c" stroke-width="1"/>
  <rect x="118" y="103" width="184" height="134" rx="3" fill="#1f5c34" stroke="#2a7a45" stroke-width="1.5"/>
  <line x1="210" y1="103" x2="210" y2="237" stroke="#2a7a45" stroke-width="1.5"/>
  <circle cx="210" cy="170" r="20" fill="none" stroke="#2a7a45" stroke-width="1.5"/>
  <rect x="118" y="140" width="16" height="60" fill="none" stroke="#2a7a45" stroke-width="1.5"/>
  <rect x="286" y="140" width="16" height="60" fill="none" stroke="#2a7a45" stroke-width="1.5"/>
  <g font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">
    <text x="210" y="48" font-size="15" fill="#f7ecd5">CURVA NORD</text>
    <text x="210" y="304" font-size="15" fill="#f7ecd5">CURVA SUD</text>
    <!-- Ai lati l'anello e' largo 72px e il testo orizzontale ne chiederebbe
         di piu': ruotato lungo la tribuna ci sta comodo, ed e' anche come
         si leggono le piantine vere. -->
    <text x="48" y="170" font-size="13" fill="#3a2c05" dominant-baseline="middle"
          transform="rotate(-90 48 170)">TRIBUNA MONTE MARIO</text>
    <text x="372" y="170" font-size="13" fill="#3a2c05" dominant-baseline="middle"
          transform="rotate(90 372 170)">TRIBUNA TEVERE</text>
  </g>
</svg>
SVG;
}
