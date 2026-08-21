<?php
/**
 * Plugin Name: RCM - Recensioni dei soci
 * Description: Raccoglie le recensioni dal sito (modulo pubblico con Turnstile e moderazione) e le mostra in una striscia scorrevole sopra il footer.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

const RCM_REC_CPT = 'rcm_recensione';

/** Sotto questa soglia la striscia non compare: due recensioni fanno peggio di zero. */
const RCM_REC_SOGLIA = 3;

/** Quante ne mostra la striscia al massimo. */
const RCM_REC_QUANTE = 12;

const RCM_REC_LEN_MIN = 30;
const RCM_REC_LEN_MAX = 600;

/** Chiave del transient che tiene il conto delle recensioni pubblicate. */
const RCM_REC_TRANSIENT = 'rcm_recensioni_conteggio';

/**
 * Dove arriva l'avviso di una nuova recensione. admin_email e' admin@, che
 * nessuno legge: la posta del club e' info@. Filtrabile se un giorno cambia.
 */
function rcm_rec_destinatario() {
	return apply_filters( 'rcm_recensioni_destinatario', 'info@romaclubmatera.it' );
}

/* -------------------------------------------------------------------------
 * Tipo di contenuto
 * ---------------------------------------------------------------------- */

add_action( 'init', 'rcm_rec_registra_cpt' );
function rcm_rec_registra_cpt() {
	register_post_type(
		RCM_REC_CPT,
		array(
			'labels'              => array(
				'name'               => 'Recensioni',
				'singular_name'      => 'Recensione',
				'menu_name'          => 'Recensioni',
				'add_new'            => 'Aggiungi',
				'add_new_item'       => 'Aggiungi una recensione',
				'edit_item'          => 'Modifica recensione',
				'new_item'           => 'Nuova recensione',
				'view_item'          => 'Vedi recensione',
				'search_items'       => 'Cerca fra le recensioni',
				'not_found'          => 'Nessuna recensione',
				'not_found_in_trash' => 'Nessuna recensione nel cestino',
				'all_items'          => 'Tutte le recensioni',
			),
			// non ha una pagina sua: esiste solo per finire nella striscia
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-star-filled',
			'menu_position'       => 25,
			'supports'            => array( 'title', 'editor' ),
			'capability_type'     => 'post',
			'has_archive'         => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
		)
	);
}

/* -------------------------------------------------------------------------
 * Campi in dashboard
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', 'rcm_rec_metabox' );
function rcm_rec_metabox() {
	add_meta_box( 'rcm-rec-dati', 'Dati della recensione', 'rcm_rec_metabox_html', RCM_REC_CPT, 'side', 'high' );
}

function rcm_rec_metabox_html( $post ) {
	$voto      = (int) get_post_meta( $post->ID, '_rcm_voto', true );
	$citta     = (string) get_post_meta( $post->ID, '_rcm_citta', true );
	$email     = (string) get_post_meta( $post->ID, '_rcm_email', true );
	$consenso  = (string) get_post_meta( $post->ID, '_rcm_consenso', true );
	wp_nonce_field( 'rcm_rec_salva', 'rcm_rec_nonce' );
	?>
	<p>
		<label for="rcm_voto"><strong>Voto</strong></label><br>
		<select name="rcm_voto" id="rcm_voto">
			<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
				<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $voto, $i ); ?>>
					<?php echo esc_html( str_repeat( '★', $i ) . str_repeat( '☆', 5 - $i ) ); ?>
				</option>
			<?php endfor; ?>
		</select>
	</p>
	<p>
		<label for="rcm_citta"><strong>Citta'</strong></label><br>
		<input type="text" class="widefat" name="rcm_citta" id="rcm_citta" value="<?php echo esc_attr( $citta ); ?>">
	</p>
	<p>
		<label for="rcm_email"><strong>E-mail</strong></label><br>
		<input type="email" class="widefat" name="rcm_email" id="rcm_email" value="<?php echo esc_attr( $email ); ?>">
		<span class="description">Non viene mai pubblicata: serve solo per ricontattare chi ha scritto.</span>
	</p>
	<?php if ( $consenso ) : ?>
		<p class="description">Consenso alla pubblicazione dato il <?php echo esc_html( mysql2date( 'j F Y, H:i', $consenso ) ); ?>.</p>
	<?php endif; ?>
	<?php
}

add_action( 'save_post_' . RCM_REC_CPT, 'rcm_rec_salva_metabox' );
function rcm_rec_salva_metabox( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['rcm_rec_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rcm_rec_nonce'] ) ), 'rcm_rec_salva' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['rcm_voto'] ) ) {
		update_post_meta( $post_id, '_rcm_voto', max( 1, min( 5, (int) $_POST['rcm_voto'] ) ) );
	}
	if ( isset( $_POST['rcm_citta'] ) ) {
		update_post_meta( $post_id, '_rcm_citta', sanitize_text_field( wp_unslash( $_POST['rcm_citta'] ) ) );
	}
	if ( isset( $_POST['rcm_email'] ) ) {
		update_post_meta( $post_id, '_rcm_email', sanitize_email( wp_unslash( $_POST['rcm_email'] ) ) );
	}
}

add_filter( 'manage_' . RCM_REC_CPT . '_posts_columns', 'rcm_rec_colonne' );
function rcm_rec_colonne( $colonne ) {
	$nuove = array();
	foreach ( $colonne as $chiave => $etichetta ) {
		$nuove[ $chiave ] = ( 'title' === $chiave ) ? 'Nome' : $etichetta;
		if ( 'title' === $chiave ) {
			$nuove['rcm_voto']  = 'Voto';
			$nuove['rcm_citta'] = "Citta'";
			$nuove['rcm_testo'] = 'Recensione';
		}
	}
	return $nuove;
}

add_action( 'manage_' . RCM_REC_CPT . '_posts_custom_column', 'rcm_rec_colonna_html', 10, 2 );
function rcm_rec_colonna_html( $colonna, $post_id ) {
	switch ( $colonna ) {
		case 'rcm_voto':
			$voto = (int) get_post_meta( $post_id, '_rcm_voto', true );
			echo esc_html( str_repeat( '★', $voto ) . str_repeat( '☆', 5 - $voto ) );
			break;
		case 'rcm_citta':
			echo esc_html( get_post_meta( $post_id, '_rcm_citta', true ) );
			break;
		case 'rcm_testo':
			echo esc_html( wp_trim_words( get_post_field( 'post_content', $post_id ), 18 ) );
			break;
	}
}

/* -------------------------------------------------------------------------
 * Conteggio (in cache: gira su ogni pagina del sito)
 * ---------------------------------------------------------------------- */

function rcm_rec_conteggio() {
	$n = get_transient( RCM_REC_TRANSIENT );
	if ( false === $n ) {
		$conteggi = wp_count_posts( RCM_REC_CPT );
		$n        = isset( $conteggi->publish ) ? (int) $conteggi->publish : 0;
		set_transient( RCM_REC_TRANSIENT, $n, DAY_IN_SECONDS );
	}
	return (int) $n;
}

add_action( 'transition_post_status', 'rcm_rec_svuota_cache', 10, 3 );
function rcm_rec_svuota_cache( $nuovo, $vecchio, $post ) {
	if ( $post instanceof WP_Post && RCM_REC_CPT === $post->post_type ) {
		delete_transient( RCM_REC_TRANSIENT );
	}
}

add_action( 'deleted_post', 'rcm_rec_svuota_cache_delete', 10, 2 );
function rcm_rec_svuota_cache_delete( $post_id, $post = null ) {
	if ( $post instanceof WP_Post && RCM_REC_CPT === $post->post_type ) {
		delete_transient( RCM_REC_TRANSIENT );
	}
}

/* -------------------------------------------------------------------------
 * Fogli di stile e script
 * ---------------------------------------------------------------------- */

function rcm_rec_pagina_ha_modulo() {
	$post = get_post();
	return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'rcm_form_recensione' );
}

add_action( 'wp_enqueue_scripts', 'rcm_rec_assets' );
function rcm_rec_assets() {
	$serve_striscia = rcm_rec_conteggio() >= RCM_REC_SOGLIA || rcm_rec_anteprima_attiva();
	$serve_modulo   = rcm_rec_pagina_ha_modulo();
	if ( ! $serve_striscia && ! $serve_modulo ) {
		return;
	}

	$rel  = 'rcm-recensioni/recensioni.css';
	$path = WPMU_PLUGIN_DIR . '/' . $rel;
	if ( file_exists( $path ) ) {
		wp_enqueue_style( 'rcm-recensioni', WPMU_PLUGIN_URL . '/' . $rel, array(), filemtime( $path ) );
	}

	// lo script di Turnstile non lo carico io: ci pensa il plugin, che lo serve
	// in modalita' "explicit". Caricandone una seconda copia il widget non veniva
	// disegnato affatto.
}

/* -------------------------------------------------------------------------
 * Modulo pubblico
 * ---------------------------------------------------------------------- */

add_shortcode( 'rcm_form_recensione', 'rcm_rec_shortcode_modulo' );
function rcm_rec_shortcode_modulo() {
	ob_start();

	$esito = isset( $_GET['recensione'] ) ? sanitize_key( wp_unslash( $_GET['recensione'] ) ) : '';
	if ( 'ok' === $esito ) {
		echo '<p class="rcm-rec-esito rcm-rec-ok">Grazie! La recensione &egrave; arrivata. La leggiamo e la pubblichiamo a breve.</p>';
		return ob_get_clean();
	}

	// dopo un errore i valori tornano nel modulo: nessuno riscrive 500 caratteri due volte
	$vecchi = array();
	$token  = isset( $_GET['rec_token'] ) ? sanitize_key( wp_unslash( $_GET['rec_token'] ) ) : '';
	if ( $token ) {
		$vecchi = get_transient( 'rcm_rec_bozza_' . $token );
		$vecchi = is_array( $vecchi ) ? $vecchi : array();
	}
	$val = function ( $campo ) use ( $vecchi ) {
		return isset( $vecchi[ $campo ] ) ? $vecchi[ $campo ] : '';
	};

	if ( $esito && 'ok' !== $esito ) {
		printf(
			'<p class="rcm-rec-esito rcm-rec-ko">%s</p>',
			esc_html( rcm_rec_messaggio_errore( $esito ) )
		);
	}
	?>
	<form class="rcm-rec-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="rcm_recensione">
		<input type="hidden" name="rcm_da" value="<?php echo esc_url( get_permalink() ); ?>">
		<?php wp_nonce_field( 'rcm_rec_invio', 'rcm_rec_invio_nonce' ); ?>

		<p class="rcm-rec-riga">
			<label for="rcm-nome">Nome e cognome <span aria-hidden="true">*</span></label>
			<input type="text" id="rcm-nome" name="rcm_nome" required maxlength="80" value="<?php echo esc_attr( $val( 'nome' ) ); ?>">
		</p>

		<p class="rcm-rec-riga">
			<label for="rcm-citta">Citt&agrave;</label>
			<input type="text" id="rcm-citta" name="rcm_citta" maxlength="60" value="<?php echo esc_attr( $val( 'citta' ) ); ?>">
		</p>

		<p class="rcm-rec-riga">
			<label for="rcm-email">E-mail <span aria-hidden="true">*</span></label>
			<input type="email" id="rcm-email" name="rcm_email" required maxlength="120" value="<?php echo esc_attr( $val( 'email' ) ); ?>">
			<span class="rcm-rec-aiuto">Non viene pubblicata. Ci serve solo per poterti ricontattare.</span>
		</p>

		<fieldset class="rcm-rec-stelle">
			<legend>Il tuo voto <span aria-hidden="true">*</span></legend>
			<div class="rcm-rec-stelle-gruppo">
			<?php
			$voto_scelto = (int) $val( 'voto' );
			// in ordine inverso: cosi' il CSS puo' accendere le stelle precedenti con ~
			for ( $i = 5; $i >= 1; $i-- ) :
				$id = 'rcm-voto-' . $i;
				?>
				<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="rcm_voto" value="<?php echo esc_attr( $i ); ?>"
					<?php checked( $voto_scelto, $i ); ?> <?php echo 5 === $i ? 'required' : ''; ?>>
				<label for="<?php echo esc_attr( $id ); ?>">
					<span aria-hidden="true">★</span>
					<span class="screen-reader-text"><?php echo esc_html( $i . ( 1 === $i ? ' stella' : ' stelle' ) ); ?></span>
				</label>
			<?php endfor; ?>
			</div>
		</fieldset>

		<p class="rcm-rec-riga">
			<label for="rcm-testo">La tua recensione <span aria-hidden="true">*</span></label>
			<textarea id="rcm-testo" name="rcm_testo" rows="6" required
				minlength="<?php echo esc_attr( RCM_REC_LEN_MIN ); ?>"
				maxlength="<?php echo esc_attr( RCM_REC_LEN_MAX ); ?>"><?php echo esc_textarea( $val( 'testo' ) ); ?></textarea>
			<span class="rcm-rec-aiuto">Da <?php echo esc_html( RCM_REC_LEN_MIN ); ?> a <?php echo esc_html( RCM_REC_LEN_MAX ); ?> caratteri.</span>
		</p>

		<p class="rcm-rec-consenso">
			<input type="checkbox" id="rcm-consenso" name="rcm_consenso" value="1" required>
			<label for="rcm-consenso">
				Acconsento alla pubblicazione di nome, citt&agrave; e testo sul sito e ho letto la
				<a href="/privacy-policy/" target="_blank" rel="noopener">privacy policy</a>.
				Posso chiederne la rimozione quando voglio scrivendo a
				<a href="mailto:info@romaclubmatera.it">info@romaclubmatera.it</a>.
			</label>
		</p>

		<?php
		// trappola per i bot: campo vero, nascosto, che un umano non compila mai
		?>
		<p class="rcm-rec-onore" aria-hidden="true">
			<label for="rcm-sito">Lascia vuoto questo campo</label>
			<input type="text" id="rcm-sito" name="rcm_sito" tabindex="-1" autocomplete="off">
		</p>

		<?php rcm_rec_turnstile(); ?>

		<p><button type="submit" class="rcm-rec-invia">Invia la recensione</button></p>
	</form>
	<?php
	return ob_get_clean();
}

function rcm_rec_messaggio_errore( $codice ) {
	$messaggi = array(
		'campi'     => 'Mancano dei campi obbligatori: ricontrolla e riprova.',
		'corto'     => 'La recensione e\' troppo corta: servono almeno ' . RCM_REC_LEN_MIN . ' caratteri.',
		'email'     => 'L\'indirizzo e-mail non sembra valido.',
		'consenso'  => 'Senza il consenso alla pubblicazione non possiamo mettere online la recensione.',
		'turnstile' => 'La verifica antispam non e\' andata a buon fine. Ricarica la pagina e riprova.',
		'doppia'    => 'Abbiamo gia\' ricevuto una recensione da questo indirizzo. Scrivici a info@romaclubmatera.it se vuoi modificarla.',
	);
	return isset( $messaggi[ $codice ] ) ? $messaggi[ $codice ] : 'Qualcosa non ha funzionato. Riprova fra poco.';
}

/**
 * Il widget lo disegna il plugin Turnstile tramite il suo hook per i moduli su
 * misura: cosi' rispetta le impostazioni del pannello (aspetto, lingua, utenti
 * esclusi, comportamento se Cloudflare e' irraggiungibile) e registra il widget
 * per il rendering "explicit". Se il plugin sparisce, ripiego sul div nudo.
 */
function rcm_rec_turnstile() {
	// Nota: il plugin espone anche l'action cfturnstile_display_widget, ma il suo
	// callback *restituisce* la stringa invece di stamparla, e do_action butta via
	// i valori di ritorno: si ottiene un div vuoto. Lo shortcode funziona.
	if ( shortcode_exists( 'simple-turnstile' ) ) {
		echo '<div class="rcm-rec-turnstile">' . do_shortcode( '[simple-turnstile]' ) . '</div>';
		return;
	}
	$chiave = get_option( 'cfturnstile_key' );
	if ( ! $chiave ) {
		return;
	}
	printf(
		'<div class="cf-turnstile rcm-rec-turnstile" data-sitekey="%s"></div>',
		esc_attr( $chiave )
	);
}

function rcm_rec_turnstile_valido() {
	// la verifica del plugin conosce anche whitelist e failsafe: se c'e', vince lei
	if ( function_exists( 'cfturnstile_check' ) ) {
		$esito = cfturnstile_check();
		return is_array( $esito ) && ! empty( $esito['success'] );
	}
	$segreto = get_option( 'cfturnstile_secret' );
	if ( ! $segreto ) {
		// chiavi non configurate: non blocco nessuno, tanto niente va online senza approvazione
		return true;
	}
	$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
	if ( '' === $token ) {
		return false;
	}
	$risposta = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			// niente remoteip: l'IP non serve a Cloudflare per validare e non lo mandiamo
			'body'    => array(
				'secret'   => $segreto,
				'response' => $token,
			),
		)
	);
	if ( is_wp_error( $risposta ) ) {
		// Cloudflare irraggiungibile: meglio far passare e moderare che perdere la recensione
		return true;
	}
	$corpo = json_decode( wp_remote_retrieve_body( $risposta ), true );
	return ! empty( $corpo['success'] );
}

/* -------------------------------------------------------------------------
 * Ricezione del modulo
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_nopriv_rcm_recensione', 'rcm_rec_ricevi' );
add_action( 'admin_post_rcm_recensione', 'rcm_rec_ricevi' );
function rcm_rec_ricevi() {
	$da = isset( $_POST['rcm_da'] ) ? esc_url_raw( wp_unslash( $_POST['rcm_da'] ) ) : home_url( '/' );

	if ( ! isset( $_POST['rcm_rec_invio_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rcm_rec_invio_nonce'] ) ), 'rcm_rec_invio' ) ) {
		rcm_rec_torna( $da, 'scaduto', array() );
	}

	$dati = array(
		'nome'  => isset( $_POST['rcm_nome'] ) ? sanitize_text_field( wp_unslash( $_POST['rcm_nome'] ) ) : '',
		'citta' => isset( $_POST['rcm_citta'] ) ? sanitize_text_field( wp_unslash( $_POST['rcm_citta'] ) ) : '',
		'email' => isset( $_POST['rcm_email'] ) ? sanitize_email( wp_unslash( $_POST['rcm_email'] ) ) : '',
		'voto'  => isset( $_POST['rcm_voto'] ) ? max( 1, min( 5, (int) $_POST['rcm_voto'] ) ) : 0,
		'testo' => isset( $_POST['rcm_testo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rcm_testo'] ) ) : '',
	);

	// il campo trappola: se e' pieno e' un bot, ma gli rispondo "ok" cosi' non riprova
	if ( ! empty( $_POST['rcm_sito'] ) ) {
		rcm_rec_torna( $da, 'ok', array() );
	}

	if ( '' === $dati['nome'] || '' === $dati['testo'] || 0 === $dati['voto'] ) {
		rcm_rec_torna( $da, 'campi', $dati );
	}
	if ( ! is_email( $dati['email'] ) ) {
		rcm_rec_torna( $da, 'email', $dati );
	}
	if ( mb_strlen( $dati['testo'] ) < RCM_REC_LEN_MIN ) {
		rcm_rec_torna( $da, 'corto', $dati );
	}
	if ( empty( $_POST['rcm_consenso'] ) ) {
		rcm_rec_torna( $da, 'consenso', $dati );
	}
	if ( ! rcm_rec_turnstile_valido() ) {
		rcm_rec_torna( $da, 'turnstile', $dati );
	}
	if ( rcm_rec_gia_inviata( $dati['email'] ) ) {
		rcm_rec_torna( $da, 'doppia', $dati );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'      => RCM_REC_CPT,
			// niente va online da solo: si pubblica solo dopo lettura
			'post_status'    => 'pending',
			'post_title'     => $dati['nome'],
			'post_content'   => mb_substr( $dati['testo'], 0, RCM_REC_LEN_MAX ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		rcm_rec_torna( $da, 'errore', $dati );
	}

	update_post_meta( $post_id, '_rcm_voto', $dati['voto'] );
	update_post_meta( $post_id, '_rcm_citta', $dati['citta'] );
	update_post_meta( $post_id, '_rcm_email', $dati['email'] );
	update_post_meta( $post_id, '_rcm_consenso', current_time( 'mysql' ) );

	rcm_rec_avvisa( $post_id, $dati );
	rcm_rec_torna( $da, 'ok', array() );
}

/** Una recensione a indirizzo: non blinda niente, ma toglie i doppioni per sbaglio. */
function rcm_rec_gia_inviata( $email ) {
	$trovate = get_posts(
		array(
			'post_type'      => RCM_REC_CPT,
			'post_status'    => array( 'pending', 'publish', 'draft' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_rcm_email',
			'meta_value'     => $email,
		)
	);
	return ! empty( $trovate );
}

function rcm_rec_avvisa( $post_id, $dati ) {
	$oggetto = sprintf( '[%s] Nuova recensione da %s', get_bloginfo( 'name' ), $dati['nome'] );
	$corpo   = sprintf(
		"E' arrivata una nuova recensione, in attesa di approvazione.\n\n" .
		"Nome:  %s\nCitta': %s\nVoto:  %s\nE-mail: %s\n\n%s\n\n" .
		"Approvala (o cestinala) qui:\n%s\n",
		$dati['nome'],
		$dati['citta'] ? $dati['citta'] : '-',
		str_repeat( '*', $dati['voto'] ) . ' (' . $dati['voto'] . '/5)',
		$dati['email'],
		$dati['testo'],
		admin_url( 'post.php?post=' . $post_id . '&action=edit' )
	);
	wp_mail( rcm_rec_destinatario(), $oggetto, $corpo );
}

/**
 * Torna al modulo con l'esito. In caso di errore i valori scritti finiscono in
 * un transient di dieci minuti, richiamato da un token nell'URL: cosi' chi ha
 * appena scritto mezza pagina non se la ritrova cancellata.
 */
function rcm_rec_torna( $da, $esito, $dati ) {
	$args = array( 'recensione' => $esito );
	if ( 'ok' !== $esito && ! empty( $dati ) ) {
		$token = wp_generate_password( 12, false, false );
		set_transient( 'rcm_rec_bozza_' . $token, $dati, 10 * MINUTE_IN_SECONDS );
		$args['rec_token'] = $token;
	}
	wp_safe_redirect( add_query_arg( $args, $da ) . '#recensioni' );
	exit;
}

/* -------------------------------------------------------------------------
 * Striscia sopra il footer
 * ---------------------------------------------------------------------- */

function rcm_rec_pubblicate( $quante ) {
	return get_posts(
		array(
			'post_type'      => RCM_REC_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $quante,
			'orderby'        => 'rand',
		)
	);
}

// thewebs_before_footer sta subito dopo la chiusura di #inner-wrap: la striscia
// finisce fra il contenuto e il footer, a tutta larghezza, su ogni pagina
add_action( 'thewebs_before_footer', 'rcm_rec_striscia' );
function rcm_rec_striscia() {
	if ( is_admin() ) {
		return;
	}
	// sulla pagina Recensioni le schede ci sono gia' sopra il modulo: la striscia
	// ripeterebbe le stesse parole a due centimetri di distanza
	if ( is_page( 'recensioni' ) ) {
		return;
	}
	$anteprima = rcm_rec_anteprima_attiva();
	if ( ! $anteprima && rcm_rec_conteggio() < RCM_REC_SOGLIA ) {
		return;
	}
	$recensioni = $anteprima ? rcm_rec_esempi() : rcm_rec_pubblicate( RCM_REC_QUANTE );
	if ( count( $recensioni ) < RCM_REC_SOGLIA ) {
		return;
	}

	// ~9 secondi a scheda: abbastanza lento da poterle leggere passando
	$durata = max( 30, count( $recensioni ) * 9 );
	?>
	<section class="rcm-recensioni" id="recensioni-striscia" aria-label="Cosa dicono i soci">
		<?php if ( $anteprima ) : ?>
			<p class="rcm-recensioni-anteprima">Anteprima: recensioni di esempio, le vedi solo tu perche' sei collegato.</p>
		<?php endif; ?>
		<h2 class="rcm-recensioni-titolo">Cosa dicono i soci</h2>
		<div class="rcm-recensioni-vista">
			<ul class="rcm-recensioni-pista" style="--rcm-rec-durata: <?php echo esc_attr( $durata ); ?>s">
				<?php
				rcm_rec_schede( $recensioni, false );
				// la lista e' ripetuta identica: lo scorrimento al -50% si richiude
				// senza salti. Il doppione e' nascosto ai lettori di schermo.
				rcm_rec_schede( $recensioni, true );
				?>
			</ul>
		</div>
		<?php
		$pagina = get_page_by_path( 'recensioni' );
		if ( $pagina ) :
			?>
			<p class="rcm-recensioni-invito">
				<a href="<?php echo esc_url( get_permalink( $pagina ) ); ?>">Lascia anche tu la tua recensione</a>
			</p>
		<?php endif; ?>
	</section>
	<?php
}

function rcm_rec_schede( $recensioni, $duplicato ) {
	foreach ( $recensioni as $recensione ) {
		$voto  = (int) apply_filters( 'rcm_recensioni_meta', get_post_meta( $recensione->ID, '_rcm_voto', true ), $recensione, 'voto' );
		$citta = (string) apply_filters( 'rcm_recensioni_meta', get_post_meta( $recensione->ID, '_rcm_citta', true ), $recensione, 'citta' );
		?>
		<li class="rcm-recensione"<?php echo $duplicato ? ' aria-hidden="true"' : ''; ?>>
			<p class="rcm-recensione-voto">
				<span aria-hidden="true"><?php echo esc_html( str_repeat( '★', $voto ) . str_repeat( '☆', 5 - $voto ) ); ?></span>
				<span class="screen-reader-text"><?php echo esc_html( $voto . ' stelle su 5' ); ?></span>
			</p>
			<blockquote class="rcm-recensione-testo"><?php echo esc_html( $recensione->post_content ); ?></blockquote>
			<p class="rcm-recensione-firma">
				<span class="rcm-recensione-nome"><?php echo esc_html( $recensione->post_title ); ?></span>
				<?php if ( $citta ) : ?>
					<span class="rcm-recensione-citta"><?php echo esc_html( $citta ); ?></span>
				<?php endif; ?>
			</p>
		</li>
		<?php
	}
}

/** Shortcode per mostrare le recensioni dentro una pagina, non solo nella striscia. */
add_shortcode( 'rcm_recensioni', 'rcm_rec_shortcode_elenco' );
function rcm_rec_shortcode_elenco( $atts ) {
	$atts       = shortcode_atts( array( 'quante' => RCM_REC_QUANTE ), $atts, 'rcm_recensioni' );
	$recensioni = rcm_rec_pubblicate( (int) $atts['quante'] );
	if ( ! $recensioni ) {
		return '';
	}
	ob_start();
	echo '<ul class="rcm-recensioni-elenco">';
	rcm_rec_schede( $recensioni, false );
	echo '</ul>';
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Anteprima
 * ---------------------------------------------------------------------- */

/**
 * Finche' le recensioni vere non ci sono, la striscia non compare e non c'e'
 * modo di vedere che aspetto avra'. Con ?rcm_anteprima=1, e solo per chi e'
 * loggato e puo' scrivere, la striscia si mostra con tre schede finte tenute
 * in memoria: non entra niente nel database e nessun visitatore le vede mai.
 */
function rcm_rec_anteprima_attiva() {
	return isset( $_GET['rcm_anteprima'] ) && '1' === $_GET['rcm_anteprima'] && current_user_can( 'edit_posts' );
}

function rcm_rec_esempi() {
	$esempi = array(
		array( 'Esempio Uno', 'Matera', 5, 'Testo di esempio per vedere come viene la striscia. Non e\' una recensione vera e non sta nel database: sparisce togliendo rcm_anteprima dall\'indirizzo.' ),
		array( 'Esempio Due', 'Altamura', 4, 'Secondo testo finto, piu' . "'" . ' corto, per controllare che schede di lunghezza diversa restino allineate.' ),
		array( 'Esempio Tre', 'Potenza', 5, 'Terzo testo finto, un po\' piu\' lungo degli altri due, cosi\' si vede fin dove arriva una scheda prima di andare a capo e se il nome resta in fondo.' ),
	);
	$finte = array();
	foreach ( $esempi as $i => $e ) {
		$finte[] = (object) array(
			'ID'           => -1 - $i,
			'post_title'   => $e[0],
			'post_content' => $e[3],
			'rcm_citta'    => $e[1],
			'rcm_voto'     => $e[2],
		);
	}
	return $finte;
}

// in anteprima le schede leggono i valori dall'oggetto finto invece che dal database
add_filter( 'rcm_recensioni_meta', 'rcm_rec_meta_anteprima', 10, 3 );
function rcm_rec_meta_anteprima( $valore, $recensione, $campo ) {
	if ( isset( $recensione->{ 'rcm_' . $campo } ) ) {
		return $recensione->{ 'rcm_' . $campo };
	}
	return $valore;
}
