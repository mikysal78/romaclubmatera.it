<?php
/**
 * Plugin Name: RCM - I link social si aprono in una scheda nuova
 * Description: Aggiunge target="_blank" ai pulsanti social di intestazione, menu mobile e footer. Il tema (thewebs) non li apre mai in una scheda nuova e non offre un'opzione per farlo: l'unico filtro che sembra servire, thewebs_social_link_target, in realta' decide soltanto se stampare il rel. Chi clicca Facebook dal footer perde la pagina del sito su cui stava.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

/**
 * Il tema stampa i tre blocchi social agganciandosi a queste action a
 * priorita' 10. Con 9 e 11 si apre e si chiude il buffer attorno al solo
 * blocco social: il ritocco al markup non tocca il resto della pagina.
 */
const RCM_SOCIAL_ACTION = array(
	'thewebs_header_social',
	'thewebs_mobile_social',
	'thewebs_footer_social',
);

foreach ( RCM_SOCIAL_ACTION as $azione ) {
	add_action( $azione, 'rcm_social_apri_buffer', 9 );
	add_action( $azione, 'rcm_social_chiudi_buffer', 11 );
}

function rcm_social_apri_buffer() {
	ob_start();
}

function rcm_social_chiudi_buffer() {
	echo rcm_social_scheda_nuova( ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- e' markup del tema, gia' passato per esc_attr, a cui si aggiungono solo attributi fissi.
}

/**
 * Aggiunge target e rel ai soli link che portano fuori dal sito.
 *
 * Telefono e email restano come sono: "tel:" e "mailto:" non aprono una
 * pagina, e una scheda vuota che si chiude da sola e' solo fastidio.
 *
 * @param string $html Il blocco social stampato dal tema.
 * @return string
 */
function rcm_social_scheda_nuova( $html ) {
	return preg_replace_callback(
		'#<a\s[^>]*href="https?://[^"]*"[^>]*>#i',
		'rcm_social_ritocca_anchor',
		$html
	);
}

/**
 * @param array $trovato Il tag <a ...> intero in posizione 0.
 * @return string
 */
function rcm_social_ritocca_anchor( $trovato ) {
	$tag = $trovato[0];

	if ( preg_match( '#\starget=#i', $tag ) ) {
		return $tag;
	}

	$aggiunte = ' target="_blank"';

	// Con target="_blank" il rel non e' un vezzo: senza noopener la pagina
	// che si apre puo' riscrivere window.opener e portare altrove quella da
	// cui si e' partiti. Il tema lo mette gia', ma non e' detto che lo faccia
	// sempre (un filtro suo puo' toglierlo), quindi si controlla.
	if ( ! preg_match( '#\srel=#i', $tag ) ) {
		$aggiunte .= ' rel="noopener noreferrer"';
	}

	// Chi naviga con un lettore di schermo non si accorge che si e' aperta
	// una scheda: la si annuncia nell'etichetta che il tema ha gia' messo.
	$tag = preg_replace(
		'#\saria-label="([^"]*)"#i',
		' aria-label="$1 (si apre in una scheda nuova)"',
		$tag,
		1
	);

	return preg_replace( '#^<a#i', '<a' . $aggiunte, $tag, 1 );
}
