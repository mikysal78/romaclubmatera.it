<?php
/**
 * Plugin Name: RCM - I cori della Curva Sud
 * Description: I cori che la Curva Sud canta all'Olimpico, divisi per occasione: quando si canta ognuno, su che musica va e da dove arriva. Non sono cori del Club - il Club li canta e li racconta. Lo stile sta in bestfoot-child/assets/css/rcm-custom.css, sezione "Cori".
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * LA REGOLA SUI DIRITTI, E PERCHE' LA FA RISPETTARE IL CODICE
 *
 * La prima versione di questo commento diceva: coro su base di una canzone
 * nota, niente testo. Sbagliato, e appiattiva il problema. Nella quasi
 * totalita' dei cori da stadio LE PAROLE NON SONO QUELLE DELLA CANZONE: la
 * curva prende la musica e ci scrive sopra parole sue. Di Rod Stewart o dei
 * Boney M. si prende in prestito la melodia, e la melodia qui non si riproduce
 * - si nomina soltanto, che e' lecito. Quindi il discrimine vero e' un altro:
 *
 * - il coro E' LA CANZONE, cantata parola per parola (l'inno e "Grazie Roma"
 *   sono Venditti): il testo e' suo, e non si pubblica;
 * - il coro ha PAROLE NATE SUGLI SPALTI su una musica altrui: le parole sono
 *   di chi le canta, e si pubblicano.
 *
 * Resta un margine grigio - un adattamento su musica protetta e' in teoria
 * un'opera derivata - ma sta tutto sul primo caso, non sul secondo.
 *
 * La scelta non e' lasciata a chi scrive: se il coro e' marcato "su base di
 * canzone nota", rcm_cori_testo_pubblicabile() dice no e il testo NON esce
 * dalla pagina, anche se qualcuno l'ha incollato nel campo. Una regola che
 * dipende dal ricordarsela e' una regola che prima o poi salta.
 *
 * La versione ridotta e' anche la migliore. "Questo si canta all'ingresso
 * delle squadre, sta sulle note di Rivers of Babylon e in curva all'inizio se
 * ne suonava solo la musica" vale dieci volte un elenco di testi copiati da un
 * altro sito, e nessun altro sito di Roma Club ce l'ha.
 */

defined( 'ABSPATH' ) || exit;

const RCM_CORI_CPT = 'rcm_coro';

/** Slug della pagina che ospita l'elenco. */
const RCM_CORI_PAGINA = 'cori';

/**
 * Le occasioni, nell'ordine in cui si presentano in pagina.
 *
 * L'ordine segue la partita, non l'alfabeto: si entra, si canta, si segna.
 */
function rcm_cori_occasioni() {
	return array(
		'ingresso'  => 'All&rsquo;ingresso delle squadre',
		'partita'   => 'Durante la partita',
		'gol'       => 'Dopo il gol',
		'trasferta' => 'In trasferta',
		'sede'      => 'In sede',
	);
}

/** I due tipi, che decidono se il testo si pubblica. */
function rcm_cori_tipi() {
	return array(
		'spalti' => 'Parole nate sugli spalti &mdash; il testo si pubblica',
		'base'   => '&Egrave; la canzone stessa, parola per parola &mdash; niente testo',
	);
}

/**
 * Il testo di questo coro si puo' pubblicare?
 *
 * Unico punto in cui si decide. Chiamato sia dalla pagina sia dall'avviso in
 * dashboard, cosi' quello che il club vede scritto e quello che il sito fa
 * sono per forza la stessa cosa.
 */
function rcm_cori_testo_pubblicabile( $post_id ) {
	$tipo = (string) get_post_meta( $post_id, '_rcm_coro_tipo', true );
	return 'spalti' === $tipo;
}

/* -------------------------------------------------------------------------
 * Tipo di contenuto
 * ---------------------------------------------------------------------- */

add_action( 'init', 'rcm_cori_registra_cpt' );
function rcm_cori_registra_cpt() {
	register_post_type(
		RCM_CORI_CPT,
		array(
			'labels'              => array(
				'name'               => 'Cori',
				'singular_name'      => 'Coro',
				'menu_name'          => 'Cori',
				'add_new'            => 'Aggiungi',
				'add_new_item'       => 'Aggiungi un coro',
				'edit_item'          => 'Modifica coro',
				'new_item'           => 'Nuovo coro',
				'view_item'          => 'Vedi coro',
				'search_items'       => 'Cerca fra i cori',
				'not_found'          => 'Nessun coro',
				'not_found_in_trash' => 'Nessun coro nel cestino',
				'all_items'          => 'Tutti i cori',
			),
			// Non ha una pagina sua: esiste per finire nell'elenco. Se un
			// giorno i cori diventano tanti, basta aprirlo (public => true,
			// rewrite => array('slug'=>'cori')) per dare a ognuno un indirizzo.
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-format-audio',
			'menu_position'       => 26,
			'supports'            => array( 'title', 'editor', 'page-attributes' ),
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

add_action( 'add_meta_boxes', 'rcm_cori_metabox' );
function rcm_cori_metabox() {
	add_meta_box( 'rcm-cori-dati', 'Il coro', 'rcm_cori_metabox_html', RCM_CORI_CPT, 'side', 'high' );
	add_meta_box( 'rcm-cori-testo', 'Testo del coro', 'rcm_cori_metabox_testo', RCM_CORI_CPT, 'normal', 'high' );
}

function rcm_cori_metabox_html( $post ) {
	$tipo    = (string) get_post_meta( $post->ID, '_rcm_coro_tipo', true ) ?: 'spalti';
	$quando  = (string) get_post_meta( $post->ID, '_rcm_coro_quando', true ) ?: 'partita';
	$dal     = (string) get_post_meta( $post->ID, '_rcm_coro_dal', true );
	$melodia = (string) get_post_meta( $post->ID, '_rcm_coro_melodia', true );
	wp_nonce_field( 'rcm_cori_salva', 'rcm_cori_nonce' );
	?>
	<p>
		<label for="rcm_coro_quando"><strong>Quando si canta</strong></label><br>
		<select name="rcm_coro_quando" id="rcm_coro_quando" class="widefat">
			<?php foreach ( rcm_cori_occasioni() as $k => $et ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $quando, $k ); ?>>
					<?php echo esc_html( html_entity_decode( $et ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="rcm_coro_dal"><strong>Da quando</strong></label><br>
		<input type="text" class="widefat" name="rcm_coro_dal" id="rcm_coro_dal"
			value="<?php echo esc_attr( $dal ); ?>" placeholder="2014">
		<span class="description">Facoltativo. Un anno, o anche &ldquo;dalla trasferta di Lecce&rdquo;.</span>
	</p>
	<hr>
	<p>
		<label for="rcm_coro_tipo"><strong>Da dove viene</strong></label><br>
		<select name="rcm_coro_tipo" id="rcm_coro_tipo" class="widefat">
			<?php foreach ( rcm_cori_tipi() as $k => $et ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $tipo, $k ); ?>>
					<?php echo esc_html( html_entity_decode( $et ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="rcm_coro_melodia"><strong>Sulla musica di</strong></label><br>
		<input type="text" class="widefat" name="rcm_coro_melodia" id="rcm_coro_melodia"
			value="<?php echo esc_attr( $melodia ); ?>" placeholder="titolo della canzone">
		<span class="description">Si pu&ograve; indicare sempre, anche per i cori con il testo pubblicabile: sapere
			su che musica va &egrave; met&agrave; dell&rsquo;informazione.</span>
	</p>
	<?php
}

function rcm_cori_metabox_testo( $post ) {
	$testo = (string) get_post_meta( $post->ID, '_rcm_coro_testo', true );
	$ok    = rcm_cori_testo_pubblicabile( $post->ID );
	?>
	<?php if ( ! $ok ) : ?>
		<div class="notice notice-warning inline" style="margin:0 0 12px;">
			<p>
				Questo coro &egrave; segnato come <strong>la canzone stessa, cantata parola per parola</strong>,
				quindi <strong>il testo non viene pubblicato</strong> nemmeno se lo scrivi qui sotto: la pagina
				mostrer&agrave; solo il titolo, quando si canta e la musica di riferimento.
				Quelle parole sono dell&rsquo;autore della canzone, e riprodurle senza permesso non &egrave; una zona grigia.
				Se invece le parole sono nate sugli spalti, cambia la voce &ldquo;Da dove viene&rdquo;: sono di chi le canta,
				e si possono pubblicare.
			</p>
		</div>
	<?php endif; ?>
	<textarea name="rcm_coro_testo" id="rcm_coro_testo" class="widefat" rows="8"
		placeholder="Una riga per verso."><?php echo esc_textarea( $testo ); ?></textarea>
	<p class="description">
		Va a capo dove si va a capo cantando. Nel riquadro grande qui sopra, invece, ci sta
		<strong>la storia del coro</strong>: da dove arriva, chi l&rsquo;ha portato in curva, cosa &egrave;
		successo la prima volta. &Egrave; la parte per cui uno la pagina la legge.
	</p>
	<?php
}

add_action( 'save_post_' . RCM_CORI_CPT, 'rcm_cori_salva_metabox' );
function rcm_cori_salva_metabox( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['rcm_cori_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rcm_cori_nonce'] ) ), 'rcm_cori_salva' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['rcm_coro_tipo'] ) ) {
		$tipo = sanitize_key( wp_unslash( $_POST['rcm_coro_tipo'] ) );
		update_post_meta( $post_id, '_rcm_coro_tipo', array_key_exists( $tipo, rcm_cori_tipi() ) ? $tipo : 'base' );
	}
	if ( isset( $_POST['rcm_coro_quando'] ) ) {
		$quando = sanitize_key( wp_unslash( $_POST['rcm_coro_quando'] ) );
		update_post_meta( $post_id, '_rcm_coro_quando', array_key_exists( $quando, rcm_cori_occasioni() ) ? $quando : 'partita' );
	}
	if ( isset( $_POST['rcm_coro_dal'] ) ) {
		update_post_meta( $post_id, '_rcm_coro_dal', sanitize_text_field( wp_unslash( $_POST['rcm_coro_dal'] ) ) );
	}
	if ( isset( $_POST['rcm_coro_melodia'] ) ) {
		update_post_meta( $post_id, '_rcm_coro_melodia', sanitize_text_field( wp_unslash( $_POST['rcm_coro_melodia'] ) ) );
	}
	// Il testo si conserva comunque: se un giorno arriva l'autorizzazione
	// dell'editore, basta cambiare il tipo e ricompare. Cancellarlo qui
	// vorrebbe dire buttare il lavoro di chi l'ha trascritto.
	if ( isset( $_POST['rcm_coro_testo'] ) ) {
		update_post_meta( $post_id, '_rcm_coro_testo', sanitize_textarea_field( wp_unslash( $_POST['rcm_coro_testo'] ) ) );
	}
}

/* -------------------------------------------------------------------------
 * Colonne nell'elenco in dashboard
 * ---------------------------------------------------------------------- */

add_filter( 'manage_' . RCM_CORI_CPT . '_posts_columns', 'rcm_cori_colonne' );
function rcm_cori_colonne( $colonne ) {
	$nuove = array();
	foreach ( $colonne as $k => $v ) {
		$nuove[ $k ] = $v;
		if ( 'title' === $k ) {
			$nuove['rcm_quando'] = 'Quando';
			$nuove['rcm_testo']  = 'Testo';
		}
	}
	return $nuove;
}

add_action( 'manage_' . RCM_CORI_CPT . '_posts_custom_column', 'rcm_cori_colonna_html', 10, 2 );
function rcm_cori_colonna_html( $colonna, $post_id ) {
	if ( 'rcm_quando' === $colonna ) {
		$occ = rcm_cori_occasioni();
		$k   = (string) get_post_meta( $post_id, '_rcm_coro_quando', true );
		echo esc_html( isset( $occ[ $k ] ) ? html_entity_decode( $occ[ $k ] ) : '—' );
	}
	if ( 'rcm_testo' === $colonna ) {
		if ( rcm_cori_testo_pubblicabile( $post_id ) ) {
			echo '<span style="color:#1a7f37;">pubblicato</span>';
		} else {
			$m = (string) get_post_meta( $post_id, '_rcm_coro_melodia', true );
			echo '<span style="color:#8a6d1f;">non si pubblica</span>';
			if ( $m ) {
				echo '<br><small>' . esc_html( $m ) . '</small>';
			}
		}
	}
}

/* -------------------------------------------------------------------------
 * L'elenco in pagina
 * ---------------------------------------------------------------------- */

add_shortcode( 'rcm_cori', 'rcm_cori_shortcode' );
function rcm_cori_shortcode() {
	$cori = get_posts(
		array(
			'post_type'      => RCM_CORI_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		)
	);

	if ( ! $cori ) {
		return '<p class="rcm-cori-vuoto">I cori arrivano presto.</p>';
	}

	// Raggruppati per occasione, nell'ordine della partita.
	$gruppi = array();
	foreach ( $cori as $c ) {
		$k = (string) get_post_meta( $c->ID, '_rcm_coro_quando', true ) ?: 'partita';
		$gruppi[ $k ][] = $c;
	}

	ob_start();
	?>
	<div class="rcm-cori">
		<?php foreach ( rcm_cori_occasioni() as $chiave => $etichetta ) : ?>
			<?php if ( empty( $gruppi[ $chiave ] ) ) { continue; } ?>
			<section class="rcm-cori-gruppo">
				<h2 class="rcm-cori-occasione"><?php echo wp_kses_post( $etichetta ); ?></h2>
				<div class="rcm-cori-schede">
					<?php foreach ( $gruppi[ $chiave ] as $c ) : ?>
						<?php echo rcm_cori_scheda( $c ); // phpcs:ignore WordPress.Security.EscapeOutput -- markup montato qui dentro. ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Una scheda.
 *
 * @param WP_Post $c Il coro.
 */
function rcm_cori_scheda( $c ) {
	$dal     = (string) get_post_meta( $c->ID, '_rcm_coro_dal', true );
	$melodia = (string) get_post_meta( $c->ID, '_rcm_coro_melodia', true );
	$testo   = (string) get_post_meta( $c->ID, '_rcm_coro_testo', true );
	$storia  = trim( (string) $c->post_content );
	$ok      = rcm_cori_testo_pubblicabile( $c->ID );

	ob_start();
	?>
	<article class="rcm-coro">
		<header class="rcm-coro-testa">
			<h3 class="rcm-coro-nome"><?php echo esc_html( $c->post_title ); ?></h3>
			<?php if ( $dal ) : ?>
				<p class="rcm-coro-dal">dal <?php echo esc_html( $dal ); ?></p>
			<?php endif; ?>
		</header>

		<?php
		/*
		 * La melodia e il testo sono due informazioni distinte, e prima stavano
		 * nello stesso ramo. Sbagliato: "si canta sulla musica di X" serve anche
		 * quando il testo si puo' pubblicare - anzi, e' meta' di quello che uno
		 * viene a sapere - e ci sono melodie senza un editore a cui chiedere
		 * niente, come La Marsigliese o Glory Glory Hallelujah. Tenerle nello
		 * stesso ramo obbligava a marcare "niente testo" un coro solo per poter
		 * dire su che musica va.
		 */
		?>
		<?php if ( $melodia ) : ?>
			<p class="rcm-coro-musica">Si canta sulla musica di <strong><?php echo esc_html( $melodia ); ?></strong>.</p>
		<?php endif; ?>

		<?php
		/*
		 * Quando il testo non si pubblica, la scheda non lo dice. Lo dice una
		 * volta l'introduzione della pagina: ripetuto su dieci schede su undici
		 * - tanti sono i cori della Roma che stanno sopra una canzone d'autore -
		 * quel capoverso smetteva di essere un principio e diventava una lagna,
		 * e rubava l'occhio alla storia del coro, che e' la roba per cui uno la
		 * pagina la legge.
		 */
		?>
		<?php if ( $ok && $testo ) : ?>
			<p class="rcm-coro-testo"><?php echo nl2br( esc_html( $testo ) ); ?></p>
		<?php endif; ?>

		<?php if ( $storia ) : ?>
			<div class="rcm-coro-storia"><?php echo wp_kses_post( wpautop( $storia ) ); ?></div>
		<?php endif; ?>
	</article>
	<?php
	return ob_get_clean();
}
