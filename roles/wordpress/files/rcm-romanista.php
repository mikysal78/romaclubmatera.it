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

/**
 * La card sta in una colonna sua, la quarta, a destra della newsletter.
 *
 * La riga centrale del footer nasce a 3 colonne; il numero arriva da
 * footer_middle_columns, che il tema legge dai theme mods e che portiamo a 4
 * (lo fa anche il ruolo Ansible, cosi' resta dopo un ripristino). Il tema poi
 * cicla sulle colonne e per ognuna lancia thewebs_render_footer_column: la
 * quarta non ha widget assegnati, quindi resterebbe vuota ed e' li' che
 * entriamo noi. Nessun file del tema toccato.
 */
const RCM_ROMANISTA_COLONNA = 4;

add_action( 'thewebs_render_footer_column', 'rcm_romanista_card', 10, 2 );

/**
 * @param string $riga    Riga del footer ("top", "middle", "bottom").
 * @param int    $colonna Numero della colonna in corso.
 */
function rcm_romanista_card( $riga, $colonna ) {
	if ( 'middle' !== $riga || RCM_ROMANISTA_COLONNA !== (int) $colonna ) {
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
