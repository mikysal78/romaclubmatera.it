<?php
/**
 * Plugin Name: RCM - Rimando alla prima pagina de Il Romanista
 * Description: Card nella colonna destra del footer che rimanda alla prima pagina del giorno de Il Romanista. Lo stile sta in bestfoot-child/assets/css/rcm-custom.css, sezione "Il Romanista".
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * ATTENZIONE - perche' qui c'e' un link e non la locandina.
 * La prima pagina de Il Romanista e' opera dell'editore. Riprodurla sul nostro
 * sito, anche scaricandola in automatico e anche con credito e link, e' una
 * riproduzione di materiale protetto: serve l'autorizzazione della redazione.
 * Finche' non arriva, qui ci va un rimando - linkare e' sempre lecito.
 * Per lo stesso motivo non usiamo il loro logo: la testata e' scritta in testo.
 *
 * Quando l'autorizzazione arriva, non serve riscrivere niente: basta far
 * tornare al filtro rcm_romanista_locandina_url l'indirizzo dell'immagine
 * (copiata sul nostro server da un lavoro pianificato, non agganciata al loro)
 * e la card mostra la locandina al posto del testo, tenendo il link sotto.
 */

defined( 'ABSPATH' ) || exit;

const RCM_ROMANISTA_URL = 'https://www.ilromanista.eu/prima-pagina';

// dynamic_sidebar_after scatta subito dopo i widget della colonna: la card
// finisce sotto "Contatti", in fondo a destra, senza toccare il footer builder
add_action( 'dynamic_sidebar_after', 'rcm_romanista_card', 10, 2 );

/**
 * @param string $index      Id della sidebar stampata.
 * @param bool   $ha_widget  Se la sidebar aveva widget.
 */
function rcm_romanista_card( $index, $ha_widget = true ) {
	if ( 'footer4' !== $index ) {
		return;
	}

	/**
	 * Indirizzo della locandina da mostrare al posto del testo.
	 * Vuoto finche' non abbiamo l'ok della redazione: vedi il commento in testa.
	 */
	$locandina = apply_filters( 'rcm_romanista_locandina_url', '' );
	?>
	<div class="rcm-romanista">
		<a class="rcm-romanista-card" href="<?php echo esc_url( RCM_ROMANISTA_URL ); ?>" target="_blank" rel="noopener noreferrer">
			<?php if ( $locandina ) : ?>
				<img class="rcm-romanista-locandina" src="<?php echo esc_url( $locandina ); ?>"
					alt="Prima pagina de Il Romanista" loading="lazy" decoding="async">
			<?php else : ?>
				<span class="rcm-romanista-occhiello">In edicola oggi</span>
				<span class="rcm-romanista-testata">Il Romanista</span>
			<?php endif; ?>
			<span class="rcm-romanista-invito">Leggi la prima pagina</span>
		</a>
	</div>
	<?php
}
