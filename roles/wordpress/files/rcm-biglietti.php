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
			<?php echo rcm_big_schema(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG scritto qui dentro. ?>
			<p class="rcm-big-didascalia">
				I settori dello Stadio Olimpico. La <strong>Curva Sud</strong> &egrave; riservata agli abbonati
				e non si pu&ograve; richiedere. Dentro ogni settore ci sono altre suddivisioni (centrale,
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
 * Lo schema dei settori.
 *
 * E' un disegno originale, non la piantina della societa' o della biglietteria:
 * quelle sono opere protette e non si possono copiare. Qui bastano i quattro
 * settori principali, che sono un fatto, non un disegno di qualcun altro.
 */
function rcm_big_schema() {
	return <<<'SVG'
<svg class="rcm-big-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 340 460" role="img" aria-labelledby="rcm-olimpico-t rcm-olimpico-d">
  <title id="rcm-olimpico-t">Stadio Olimpico: i settori</title>
  <desc id="rcm-olimpico-d">Schema dei settori dello Stadio Olimpico di Roma: Curva Nord e Curva Sud dietro le porte, Tribuna Monte Mario a ovest, e sul lato Tevere i Distinti Nord, la Tribuna Tevere e i Distinti Sud. La Curva Sud &#232; riservata agli abbonati.</desc>

  <ellipse cx="170" cy="230" rx="155" ry="220" fill="#141414" stroke="#2c2c2c" stroke-width="1"/>

  <!-- Sul lato Tevere l'anello si divide in tre: Distinti Nord, Tribuna
       Tevere e Distinti Sud. Sono quelli in cui il Club prende posto di
       solito. La Curva Sud e' grigia perche' e' riservata agli abbonati:
       disegnarla come le altre avrebbe fatto chiedere l'unica cosa che non
       si puo' avere. -->
  <g stroke="#141414" stroke-width="2">
    <path d="M170 230 L92.5 39.5 A155 220 0 0 1 247.5 39.5 Z" fill="#8e1f2f"/>
    <path d="M170 230 L247.5 39.5 A155 220 0 0 1 315.6 154.8 Z" fill="#c9a227"/>
    <path d="M170 230 L315.6 154.8 A155 220 0 0 1 315.6 305.2 Z" fill="#e6af14"/>
    <path d="M170 230 L315.6 305.2 A155 220 0 0 1 247.5 420.5 Z" fill="#c9a227"/>
    <path d="M170 230 L247.5 420.5 A155 220 0 0 1 92.5 420.5 Z" fill="#3a3a3a"/>
    <path d="M170 230 L92.5 420.5 A155 220 0 0 1 92.5 39.5 Z" fill="#e6af14"/>
  </g>

  <ellipse cx="170" cy="230" rx="100" ry="155" fill="#1b1b1b" stroke="#2c2c2c" stroke-width="1"/>

  <!-- Il campo, con le porte in alto e in basso: e' li' che stanno le curve -->
  <rect x="110" y="120" width="120" height="220" rx="3" fill="#1f5c34" stroke="#2a7a45" stroke-width="1.5"/>
  <line x1="110" y1="230" x2="230" y2="230" stroke="#2a7a45" stroke-width="1.5"/>
  <circle cx="170" cy="230" r="22" fill="none" stroke="#2a7a45" stroke-width="1.5"/>
  <rect x="145" y="120" width="50" height="18" fill="none" stroke="#2a7a45" stroke-width="1.5"/>
  <rect x="145" y="322" width="50" height="18" fill="none" stroke="#2a7a45" stroke-width="1.5"/>

  <g font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">
    <text x="170" y="47" font-size="14" fill="#f7ecd5">CURVA NORD</text>
    <text x="170" y="418" font-size="14" fill="#c4c4c4">CURVA SUD</text>
    <text x="170" y="434" font-size="10" font-weight="400" fill="#9a9a9a">solo abbonati</text>
    <text x="42" y="230" font-size="12" fill="#3a2c05" dominant-baseline="middle"
          transform="rotate(-90 42 230)">TRIBUNA MONTE MARIO</text>
    <text x="298" y="230" font-size="12" fill="#3a2c05" dominant-baseline="middle"
          transform="rotate(90 298 230)">TRIBUNA TEVERE</text>
    <text x="266" y="112" font-size="11" fill="#3a2c05" dominant-baseline="middle"
          transform="rotate(52 266 112)">DISTINTI NORD</text>
    <text x="266" y="348" font-size="11" fill="#3a2c05" dominant-baseline="middle"
          transform="rotate(-52 266 348)">DISTINTI SUD</text>
  </g>
</svg>
SVG;
}
