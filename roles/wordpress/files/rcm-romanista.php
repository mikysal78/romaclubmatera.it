<?php
/**
 * Plugin Name: RCM - Prima pagina de Il Romanista
 * Description: Card nella colonna destra del footer con la locandina del giorno de Il Romanista, scaricata sul nostro server da un lavoro pianificato. Lo stile sta in bestfoot-child/assets/css/rcm-custom.css, sezione "Il Romanista".
 * Version: 2.0.0
 * Author: Roma Club Matera
 *
 * La prima pagina e' opera dell'editore (Il Romanista Edizioni srl, che sul
 * proprio sito dichiara tutti i diritti riservati): riprodurla qui e' una
 * riproduzione di materiale protetto, e per mesi al suo posto c'e' stato un
 * semplice rimando, perche' linkare e' sempre lecito. Ora l'autorizzazione
 * della redazione c'e', e la locandina si vede.
 *
 * Due scelte che restano valide comunque:
 * - l'immagine si COPIA sul nostro server una volta al giorno, non si aggancia
 *   alla loro: un hotlink consuma banda altrui e si rompe appena cambiano un
 *   percorso;
 * - se la copia di oggi non c'e', la card torna al testo. Meglio nessuna
 *   locandina che la prima pagina di ieri spacciata per quella di oggi.
 */

defined( 'ABSPATH' ) || exit;

const RCM_ROMANISTA_URL     = 'https://www.ilromanista.eu/prima-pagina';
const RCM_ROMANISTA_SORGENTE = 'https://www.ilromanista.eu/writable/uploads/ed.ilromanista.jpg';
const RCM_ROMANISTA_HOOK    = 'rcm_romanista_scarica';
const RCM_ROMANISTA_OPZIONE = 'rcm_romanista_ultima';

/** La card e' larga 260px con 20 di padding: 480 copre gli schermi a doppia densita'. */
const RCM_ROMANISTA_LARGHEZZA = 480;

/* -------------------------------------------------------------------------
 * Dove finisce la copia
 * ---------------------------------------------------------------------- */

function rcm_romanista_percorso() {
	$su = wp_upload_dir();
	return array(
		'dir'  => trailingslashit( $su['basedir'] ) . 'romanista',
		'file' => trailingslashit( $su['basedir'] ) . 'romanista/locandina.jpg',
		'url'  => trailingslashit( $su['baseurl'] ) . 'romanista/locandina.jpg',
	);
}

/* -------------------------------------------------------------------------
 * Il lavoro pianificato
 * ---------------------------------------------------------------------- */

add_action( 'init', 'rcm_romanista_pianifica' );
function rcm_romanista_pianifica() {
	if ( wp_next_scheduled( RCM_ROMANISTA_HOOK ) ) {
		return;
	}
	// Due volte al giorno: il giornale esce la mattina presto, e un secondo
	// tentativo copre la volta che il primo trova il sito giu'.
	$prima = current_datetime()->modify( 'tomorrow 06:30' );
	wp_schedule_event( $prima->getTimestamp(), 'twicedaily', RCM_ROMANISTA_HOOK );
}

add_action( RCM_ROMANISTA_HOOK, 'rcm_romanista_scarica' );

/**
 * Scarica la locandina, la rimpicciolisce e la mette accanto alle altre.
 *
 * @return true|WP_Error
 */
function rcm_romanista_scarica() {
	$dove = rcm_romanista_percorso();

	if ( ! wp_mkdir_p( $dove['dir'] ) ) {
		return new WP_Error( 'rcm_rom_dir', 'Non riesco a creare ' . $dove['dir'] );
	}

	// Il parametro serve solo a saltare le cache intermedie: l'indirizzo del
	// file e' fisso, e' il contenuto che cambia ogni giorno.
	$risposta = wp_remote_get(
		add_query_arg( 't', time(), RCM_ROMANISTA_SORGENTE ),
		array( 'timeout' => 30 )
	);

	if ( is_wp_error( $risposta ) ) {
		return $risposta;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $risposta ) ) {
		return new WP_Error( 'rcm_rom_http', 'Il Romanista ha risposto ' . wp_remote_retrieve_response_code( $risposta ) );
	}

	$dati = wp_remote_retrieve_body( $risposta );
	if ( strlen( $dati ) < 10000 ) {
		return new WP_Error( 'rcm_rom_vuoto', 'Il file scaricato e\' troppo piccolo per essere una prima pagina.' );
	}

	$tmp = wp_tempnam( 'romanista' );
	file_put_contents( $tmp, $dati ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	// Che sia davvero un'immagine lo dice il file, non il nome ne' il server.
	$tipo = wp_getimagesize( $tmp );
	if ( ! $tipo || ! in_array( $tipo['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		return new WP_Error( 'rcm_rom_tipo', 'Il file scaricato non e\' un\'immagine.' );
	}

	// 2 MB nel footer di ogni pagina sarebbero un peso assurdo per una figura
	// alta poco piu' di trecento pixel.
	$editor = wp_get_image_editor( $tmp );
	if ( is_wp_error( $editor ) ) {
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		return $editor;
	}
	$editor->resize( RCM_ROMANISTA_LARGHEZZA, null, false );
	$editor->set_quality( 82 );
	$salvato = $editor->save( $dove['file'], 'image/jpeg' );

	unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink

	if ( is_wp_error( $salvato ) ) {
		return $salvato;
	}

	update_option( RCM_ROMANISTA_OPZIONE, current_time( 'Y-m-d' ), false );

	return true;
}

/**
 * L'indirizzo della locandina, ma solo se e' quella di oggi.
 */
add_filter( 'rcm_romanista_locandina_url', 'rcm_romanista_locandina', 10, 1 );
function rcm_romanista_locandina( $url ) {
	if ( get_option( RCM_ROMANISTA_OPZIONE ) !== current_time( 'Y-m-d' ) ) {
		return $url;
	}
	$dove = rcm_romanista_percorso();
	if ( ! file_exists( $dove['file'] ) ) {
		return $url;
	}
	// La marca temporale del file smarca la cache del browser quando la
	// locandina cambia, visto che il nome resta sempre lo stesso.
	return add_query_arg( 'v', filemtime( $dove['file'] ), $dove['url'] );
}

/* -------------------------------------------------------------------------
 * La card nel footer
 * ---------------------------------------------------------------------- */

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

	$locandina = apply_filters( 'rcm_romanista_locandina_url', '' );
	?>
	<div class="rcm-romanista">
		<a class="rcm-romanista-card" href="<?php echo esc_url( RCM_ROMANISTA_URL ); ?>" target="_blank" rel="noopener noreferrer">
			<?php if ( $locandina ) : ?>
				<img class="rcm-romanista-locandina" src="<?php echo esc_url( $locandina ); ?>"
					alt="La prima pagina de Il Romanista di oggi" loading="lazy" decoding="async">
				<span class="rcm-romanista-credito">&copy; Il Romanista</span>
			<?php else : ?>
				<?php
				/*
				 * Il ripiego sta dentro un riquadro delle stesse proporzioni della
				 * locandina, cosi' la card e' alta uguale in tutti e due i casi.
				 * Senza, fra mezzanotte e le 06:30 - la finestra in cui quella di
				 * ieri e' scaduta e quella di oggi non e' ancora arrivata - il
				 * footer si accorciava di 277px su OGNI pagina del sito, e
				 * visual-check.py segnalava diciassette pagine cambiate a ogni
				 * esecuzione notturna.
				 */
				?>
				<span class="rcm-romanista-ripiego">
					<span class="rcm-romanista-occhiello">In edicola oggi</span>
					<span class="rcm-romanista-testata">Il Romanista</span>
				</span>
			<?php endif; ?>
			<span class="rcm-romanista-invito">Leggi la prima pagina</span>
		</a>
	</div>
	<?php
}
