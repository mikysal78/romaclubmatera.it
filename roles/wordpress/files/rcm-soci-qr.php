<?php
/**
 * Plugin Name: RCM - QR code della tessera
 * Description: Un QR code per ogni socio, con lo scudo del Club al centro. Il socio lo trova nell'area soci sotto la tessera; il Club lo scarica in SVG (stampa) o PNG, anche tutti insieme in uno ZIP. Scansionato da un gestore collegato al sito mostra se la tessera e' valida; a chiunque altro non mostra nessun dato.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * COME FUNZIONA, E PERCHE' COSI'
 *
 * - Nel QR c'e' solo un indirizzo del sito con un codice casuale del socio
 *   (128 bit, colonna qr_token della tabella soci): nessun dato personale, e
 *   niente da indovinare. Se una tessera o un telefono si perde, "Rigenera"
 *   cambia il codice e il QR vecchio non vale piu'.
 * - La verifica e' la pagina stessa: il gestore inquadra il QR con la
 *   fotocamera del telefono, su cui e' collegato al sito (amministratore o
 *   ruolo "Gestore soci"), e vede VALIDA / SCADUTA con nome, tessera e
 *   prenotazioni delle prossime partite. Chi non e' del Club vede solo
 *   "verifica riservata al Club": il QR si puo' fotografare, i dati no.
 * - Correzione d'errore H (30%): lo scudo al centro copre moduli che il
 *   lettore ricostruisce. Le misure (spazio 11x13 moduli, scudo largo 8) sono
 *   quelle provate con zbarimg su 10 codici diversi, da 250 a 1200 px, con
 *   sfocatura e rotazione: 60 letture su 60. Con lo scudo piu' grande la
 *   lettura saltava. Se si cambiano, vanno riprovate.
 * - La libreria e' chillerlan/php-qrcode (MIT), in rcm-soci-qr/vendor,
 *   installata con Composer: vedi rcm-soci-qr/LEGGIMI.txt.
 */

defined( 'ABSPATH' ) || exit;

const RCM_QR_PARAM       = 'rcm_tessera';
const RCM_QR_SPAZIO_L    = 11;  // moduli lasciati liberi al centro, in larghezza
const RCM_QR_SPAZIO_A    = 13;  // e in altezza (lo scudo e' piu' alto che largo)
const RCM_QR_SCUDO       = 8;   // larghezza dello scudo, in moduli
const RCM_QR_COLORE      = '#2a0a10';
const RCM_QR_COLORE_OCCHI = '#8e1f2f';

function rcm_qr_dir() {
	return WPMU_PLUGIN_DIR . '/rcm-soci-qr/';
}

function rcm_qr_carica_libreria() {
	static $ok = null;
	if ( null === $ok ) {
		$auto = rcm_qr_dir() . 'vendor/autoload.php';
		$ok   = is_readable( $auto );
		if ( $ok ) {
			require_once $auto;
		}
	}
	return $ok;
}

/* -------------------------------------------------------------------------
 * Il codice del socio
 * ---------------------------------------------------------------------- */

/** Il codice nel QR; lo crea la prima volta, o nuovo se $nuovo. */
function rcm_qr_token( $socio, $nuovo = false ) {
	if ( ! $nuovo && ! empty( $socio->qr_token ) ) {
		return $socio->qr_token;
	}
	global $wpdb;
	$token = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	$wpdb->update( rcm_compleanni_tabella(), array( 'qr_token' => $token ), array( 'id' => $socio->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$socio->qr_token = $token;
	return $token;
}

function rcm_qr_url( $socio ) {
	return add_query_arg( RCM_QR_PARAM, rcm_qr_token( $socio ), home_url( '/' ) );
}

function rcm_qr_socio_da_token( $token ) {
	if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9_-]{20,32}$/', $token ) ) {
		return null;
	}
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . rcm_compleanni_tabella() . ' WHERE qr_token = %s', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/* -------------------------------------------------------------------------
 * Il disegno
 * ---------------------------------------------------------------------- */

function rcm_qr_opzioni( $extra = array() ) {
	$scuro = RCM_QR_COLORE;
	$occhi = RCM_QR_COLORE_OCCHI;
	return new chillerlan\QRCode\QROptions(
		array_merge(
			array(
				'eccLevel'             => chillerlan\QRCode\Common\EccLevel::H,
				'addLogoSpace'         => true,
				'logoSpaceWidth'       => RCM_QR_SPAZIO_L,
				'logoSpaceHeight'      => RCM_QR_SPAZIO_A,
				'addQuietzone'         => true,
				'quietzoneSize'        => 4,
				'outputBase64'         => false,
				'drawLightModules'     => false,
				'svgUseFillAttributes' => true,
				'connectPaths'         => true,
				'keepAsSquare'         => array(
					chillerlan\QRCode\Data\QRMatrix::M_FINDER_DARK,
					chillerlan\QRCode\Data\QRMatrix::M_FINDER_DOT,
					chillerlan\QRCode\Data\QRMatrix::M_ALIGNMENT_DARK,
				),
				'moduleValues'         => array(
					chillerlan\QRCode\Data\QRMatrix::M_FINDER_DARK    => $occhi,
					chillerlan\QRCode\Data\QRMatrix::M_FINDER_DOT     => $occhi,
					chillerlan\QRCode\Data\QRMatrix::M_ALIGNMENT_DARK => $occhi,
					chillerlan\QRCode\Data\QRMatrix::M_DATA_DARK      => $scuro,
					chillerlan\QRCode\Data\QRMatrix::M_TIMING_DARK    => $scuro,
					chillerlan\QRCode\Data\QRMatrix::M_FORMAT_DARK    => $scuro,
					chillerlan\QRCode\Data\QRMatrix::M_VERSION_DARK   => $scuro,
					chillerlan\QRCode\Data\QRMatrix::M_DARKMODULE     => $scuro,
				),
			),
			$extra
		)
	);
}

function rcm_qr_matrice( $url, $opzioni ) {
	$qr = new chillerlan\QRCode\QRCode( $opzioni );
	$qr->addByteSegment( $url );
	return $qr->getQRMatrix();
}

/**
 * Il QR in SVG, scudo al centro.
 *
 * @param object $socio
 * @param string $scudo 'inline' mette lo scudo vettoriale dentro il file (per
 *                      stampare, file autonomo); 'link' lo richiama come
 *                      immagine esterna (nella pagina, dove il browser lo tiene
 *                      in cache una volta per tutti).
 */
function rcm_qr_svg( $socio, $scudo = 'inline' ) {
	if ( ! rcm_qr_carica_libreria() ) {
		return '';
	}
	$opzioni = rcm_qr_opzioni();
	$matrice = rcm_qr_matrice( rcm_qr_url( $socio ), $opzioni );
	$svg     = ( new chillerlan\QRCode\Output\QRMarkupSVG( $opzioni, $matrice ) )->dump();
	$svg     = preg_replace( '/^<\?xml[^>]*>\s*/', '', $svg );
	$svg     = str_replace( '<svg ', '<svg role="img" aria-label="QR code della tessera" ', $svg );

	$n = $matrice->getSize();
	$l = RCM_QR_SCUDO;
	$a = $l * 1611 / 1392; // proporzioni dello scudo
	$x = ( $n - $l ) / 2;
	$y = ( $n - $a ) / 2;
	if ( 'link' === $scudo ) {
		$logo = sprintf( '<image href="%s" x="%.3f" y="%.3f" width="%.3f" height="%.3f"/>', esc_url( WPMU_PLUGIN_URL . '/rcm-soci-qr/scudo.svg' ), $x, $y, $l, $a );
	} else {
		$logo = (string) file_get_contents( rcm_qr_dir() . 'scudo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$logo = preg_replace( '/^<\?xml[^>]*>\s*/', '', $logo );
		$logo = preg_replace( '/<svg /', sprintf( '<svg x="%.3f" y="%.3f" width="%.3f" height="%.3f" ', $x, $y, $l, $a ), $logo, 1 );
	}
	// fondo bianco pieno: sul PNG, sulla stampa e sul tema scuro il QR ha
	// sempre il suo margine chiaro, senza il quale i lettori non lo trovano
	$fondo = sprintf( '<rect width="%d" height="%d" fill="#fff"/>', $n, $n );
	$svg   = preg_replace( '/(<svg[^>]*>)/', '$1' . $fondo, $svg, 1 );
	return str_replace( '</svg>', $logo . '</svg>', $svg );
}

/** Il QR in PNG, $px di lato circa. */
function rcm_qr_png( $socio, $px = 1000 ) {
	if ( ! rcm_qr_carica_libreria() ) {
		return '';
	}
	$rgb = function ( $hex ) {
		return array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) );
	};
	$base    = rcm_qr_opzioni();
	$matrice = rcm_qr_matrice( rcm_qr_url( $socio ), $base );
	$n       = $matrice->getSize();
	$scala   = max( 4, (int) round( $px / $n ) );
	$valori  = array();
	foreach ( $base->moduleValues as $k => $v ) {
		$valori[ $k ] = $rgb( $v );
	}
	$opzioni = rcm_qr_opzioni(
		array(
			'outputInterface' => chillerlan\QRCode\Output\QRGdImagePNG::class,
			'scale'           => $scala,
			'returnResource'  => true,
			'moduleValues'    => $valori,
			'bgColor'         => array( 255, 255, 255 ),
			'imageTransparent' => false,
			'drawLightModules' => false,
		)
	);
	$img = ( new chillerlan\QRCode\Output\QRGdImagePNG( $opzioni, $matrice ) )->dump();

	$logo = imagecreatefrompng( rcm_qr_dir() . 'scudo.png' );
	if ( $logo ) {
		$l = (int) round( RCM_QR_SCUDO * $scala );
		$a = (int) round( $l * imagesy( $logo ) / imagesx( $logo ) );
		imagealphablending( $img, true );
		imagecopyresampled( $img, $logo, (int) ( ( $n * $scala - $l ) / 2 ), (int) ( ( $n * $scala - $a ) / 2 ), 0, 0, $l, $a, imagesx( $logo ), imagesy( $logo ) );
	}
	ob_start();
	imagepng( $img, null, 9 );
	return (string) ob_get_clean();
}

function rcm_qr_nome_file( $socio, $estensione ) {
	$nome = remove_accents( trim( $socio->cognome . '-' . $socio->nome ) );
	$nome = preg_replace( '/[^A-Za-z0-9]+/', '-', $nome );
	$num  = '' !== (string) $socio->numero_tessera ? '-' . preg_replace( '/[^A-Za-z0-9]+/', '', $socio->numero_tessera ) : '';
	return 'QR-' . trim( $nome, '-' ) . $num . '.' . $estensione;
}

/* -------------------------------------------------------------------------
 * Nell'area soci, sotto la tessera
 * ---------------------------------------------------------------------- */

// Priorita' 5: prima delle prenotazioni (10), subito dopo la tessera.
add_action( 'rcm_as_sezioni', 'rcm_qr_sezione_socio', 5, 1 );
function rcm_qr_sezione_socio( $socio ) {
	$svg = rcm_qr_svg( $socio, 'link' );
	if ( '' === $svg ) {
		return;
	}
	?>
	<section class="rcm-as-scheda rcm-qr">
		<div class="rcm-qr-codice"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG generato qui dalla libreria ?></div>
		<div class="rcm-qr-testo">
			<h2 class="rcm-as-titolo">Il tuo QR code</h2>
			<p>Mostralo all'ingresso della sede e alla salita sul pullman: il Club lo inquadra e vede subito se la tessera è valida.</p>
			<p class="rcm-as-nota">È personale. Se perdi il telefono scrivi al Club, che lo cambia: quello vecchio smette di funzionare.</p>
		</div>
	</section>
	<?php
}

/* -------------------------------------------------------------------------
 * La verifica: quello che si apre inquadrando il QR
 * ---------------------------------------------------------------------- */

// Priorita' 0: prima di tutto, anche del redirect canonico di WordPress.
add_action( 'template_redirect', 'rcm_qr_verifica', 0 );
function rcm_qr_verifica() {
	if ( ! isset( $_GET[ RCM_QR_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$token = sanitize_text_field( wp_unslash( $_GET[ RCM_QR_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true );

	if ( ! current_user_can( RCM_COMPLEANNI_CAP ) ) {
		$qui = add_query_arg( RCM_QR_PARAM, rawurlencode( $token ), home_url( '/' ) );
		rcm_qr_pagina(
			'club',
			'Tessera del Roma Club Matera',
			'<p>La verifica delle tessere è riservata al Club.</p><p><a class="rcm-qr-bottone" href="' . esc_url( wp_login_url( $qui ) ) . '">Accedi per verificare</a></p>'
		);
	}

	$socio = rcm_qr_socio_da_token( $token );
	if ( ! $socio ) {
		rcm_qr_pagina( 'no', 'QR non riconosciuto', '<p>Questo QR non corrisponde a nessun socio. Può essere stato <strong>sostituito</strong> (tessera o telefono perso) o non essere del Club.</p>' );
	}

	$tip    = rcm_soci_tipologie();
	$valida = rcm_soci_tessera_valida( $socio );
	$righe  = array(
		'Tessera'  => $tip[ $socio->tipologia ] ?? 'nessuna',
		'Numero'   => '' !== (string) $socio->numero_tessera ? $socio->numero_tessera : 'VIRTUAL',
		'Stagione' => '' !== (string) $socio->stagione ? $socio->stagione : '—',
	);
	$html = '<p class="rcm-qr-nome">' . esc_html( $socio->nome . ' ' . $socio->cognome ) . '</p><dl>';
	foreach ( $righe as $k => $v ) {
		$html .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
	}
	$html .= '</dl>';

	// Le prenotazioni delle prossime partite: servono alla salita sul pullman
	if ( function_exists( 'rcm_pr_prenotazioni_socio' ) ) {
		$stati = rcm_pr_stati();
		$voci  = '';
		foreach ( rcm_pr_prenotazioni_socio( $socio->id ) as $p ) {
			$partita = rcm_pr_partita( $p->evento_id );
			if ( ! $partita || ! $partita->futura || 'annullato' === $p->stato ) {
				continue;
			}
			$cosa = array();
			if ( $p->biglietto ) {
				$cosa[] = 'biglietto (' . $p->settore . ')';
			}
			if ( $p->pullman ) {
				$cosa[] = 'pullman';
			}
			$altri = rcm_pr_persone( $p );
			$voci .= sprintf(
				'<li class="rcm-qr-pren rcm-qr-pren--%s"><strong>%s</strong> · %s<br>%s: %s%s</li>',
				esc_attr( $p->stato ),
				esc_html( $partita->titolo ),
				esc_html( wp_date( 'j/n', $partita->quando->getTimestamp() ) ),
				esc_html( $stati[ $p->stato ] ),
				esc_html( implode( ' + ', $cosa ) ),
				$altri ? '<br>con ' . esc_html( rcm_pr_elenco_persone( $p ) ) : ''
			);
		}
		$html .= $voci ? '<h2>Prenotazioni</h2><ul>' . $voci . '</ul>' : '<p class="rcm-qr-nessuna">Nessuna prenotazione per le prossime partite.</p>';
	}

	if ( $valida ) {
		rcm_qr_pagina( 'si', 'Tessera valida', $html );
	}
	$perche = empty( $socio->tipologia ) || ! isset( $tip[ $socio->tipologia ] ) ? 'Nessuna tessera annuale' : 'Tessera scaduta';
	rcm_qr_pagina( 'no', $perche, $html );
}

/** Una pagina a tutto schermo, fatta per il telefono di chi controlla. */
function rcm_qr_pagina( $esito, $titolo, $corpo ) {
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	$ora = wp_date( 'd/m/Y H:i' );
	?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $titolo ); ?> · Roma Club Matera</title>
<style>
	:root { --ok: #1f7a34; --no: #b3261e; --club: #8e1f2f; --oro: #f0bc42; --testo: #1d1d1f; --fondo: #f6f2ea; }
	* { box-sizing: border-box; }
	body { margin: 0; font: 17px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--testo); background: var(--fondo); }
	.testata { padding: 28px 20px 24px; color: #fff; text-align: center; }
	.esito-si .testata { background: var(--ok); }
	.esito-no .testata { background: var(--no); }
	.esito-club .testata { background: var(--club); }
	.icona { font-size: 64px; line-height: 1; }
	h1 { margin: 10px 0 0; font-size: 30px; letter-spacing: .01em; }
	.ora { margin-top: 6px; opacity: .85; font-size: 14px; font-variant-numeric: tabular-nums; }
	main { max-width: 520px; margin: 0 auto; padding: 20px; }
	.rcm-qr-nome { font-size: 26px; font-weight: 700; margin: 0 0 12px; }
	dl { margin: 0 0 20px; display: grid; gap: 6px; }
	dl div { display: flex; justify-content: space-between; gap: 12px; padding: 10px 14px; background: #fff; border-radius: 8px; }
	dt { color: #666; }
	dd { margin: 0; font-weight: 600; text-align: right; }
	h2 { font-size: 16px; text-transform: uppercase; letter-spacing: .06em; color: #666; margin: 0 0 8px; }
	ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
	.rcm-qr-pren { padding: 10px 14px; background: #fff; border-radius: 8px; border-left: 5px solid var(--oro); }
	.rcm-qr-pren--confermato { border-left-color: var(--ok); }
	.rcm-qr-nessuna { color: #666; }
	.rcm-qr-bottone { display: inline-block; padding: 14px 22px; background: var(--club); color: #fff; border-radius: 8px; text-decoration: none; font-weight: 700; }
	footer { text-align: center; color: #888; font-size: 13px; padding: 10px 20px 30px; }
</style>
</head>
<body class="esito-<?php echo esc_attr( $esito ); ?>">
	<header class="testata">
		<div class="icona" aria-hidden="true"><?php echo 'si' === $esito ? '&#10004;' : ( 'no' === $esito ? '&#10008;' : '&#9679;' ); ?></div>
		<h1><?php echo esc_html( $titolo ); ?></h1>
		<div class="ora">Verificata il <?php echo esc_html( $ora ); ?></div>
	</header>
	<main><?php echo $corpo; // phpcs:ignore WordPress.Security.EscapeOutput -- composto con esc_* dal chiamante ?></main>
	<footer>Roma Club Matera &ldquo;Francesco Totti&rdquo;</footer>
</body>
</html>
	<?php
	exit;
}

/* -------------------------------------------------------------------------
 * In bacheca: scaricare, rigenerare, ZIP
 * ---------------------------------------------------------------------- */

function rcm_qr_url_azione( $azione, $args = array() ) {
	return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $azione ), $args ), admin_url( 'admin-post.php' ) ), $azione );
}

add_action(
	'rcm_soci_azioni_riga',
	function ( $socio ) {
		printf(
			' | QR: <a href="%s">SVG</a> · <a href="%s">PNG</a>',
			esc_url( rcm_qr_url_azione( 'rcm_qr_scarica', array( 'id' => $socio->id, 'formato' => 'svg' ) ) ),
			esc_url( rcm_qr_url_azione( 'rcm_qr_scarica', array( 'id' => $socio->id, 'formato' => 'png' ) ) )
		);
	}
);

add_action(
	'rcm_soci_link_elenco',
	function () {
		printf( ' · <a href="%s">Scarica i QR dei soci con tessera valida (ZIP)</a>', esc_url( rcm_qr_url_azione( 'rcm_qr_zip' ) ) );
	}
);

// Nel modulo del socio: rigenerare il QR (tessera o telefono persi)
add_action(
	'rcm_soci_modulo_campi',
	function ( $socio ) {
		if ( ! $socio ) {
			return;
		}
		?>
		<tr><th>QR code</th><td>
			<a class="button" href="<?php echo esc_url( rcm_qr_url_azione( 'rcm_qr_scarica', array( 'id' => $socio->id, 'formato' => 'svg' ) ) ); ?>">Scarica SVG</a>
			<a class="button" href="<?php echo esc_url( rcm_qr_url_azione( 'rcm_qr_scarica', array( 'id' => $socio->id, 'formato' => 'png' ) ) ); ?>">Scarica PNG</a>
			<a class="button" href="<?php echo esc_url( rcm_qr_url_azione( 'rcm_qr_rigenera', array( 'id' => $socio->id ) ) ); ?>" onclick="return confirm('Il QR attuale smetterà di funzionare, anche se è già stampato. Continuare?')">Rigenera</a>
			<p class="description">Rigenera solo se il socio ha perso la tessera o il telefono: il QR vecchio non vale più, e quello nuovo va ristampato.</p>
		</td></tr>
		<?php
	}
);

function rcm_qr_controlla( $azione ) {
	if ( ! current_user_can( RCM_COMPLEANNI_CAP ) ) {
		wp_die( 'Non hai i permessi per questa operazione.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( $azione );
	if ( ! rcm_qr_carica_libreria() ) {
		wp_die( 'Manca la libreria dei QR (rcm-soci-qr/vendor): rieseguire il ruolo wordpress.', '', array( 'response' => 500 ) );
	}
}

add_action( 'admin_post_rcm_qr_scarica', 'rcm_qr_scarica' );
function rcm_qr_scarica() {
	rcm_qr_controlla( 'rcm_qr_scarica' );
	$socio = function_exists( 'rcm_as_socio' ) ? rcm_as_socio( absint( $_GET['id'] ?? 0 ) ) : null;
	if ( ! $socio ) {
		wp_die( 'Socio non trovato.', '', array( 'response' => 404 ) );
	}
	$png = 'png' === sanitize_key( wp_unslash( $_GET['formato'] ?? '' ) );
	nocache_headers();
	header( 'Content-Type: ' . ( $png ? 'image/png' : 'image/svg+xml' ) );
	header( 'Content-Disposition: attachment; filename="' . rcm_qr_nome_file( $socio, $png ? 'png' : 'svg' ) . '"' );
	echo $png ? rcm_qr_png( $socio ) : '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . rcm_qr_svg( $socio ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

add_action( 'admin_post_rcm_qr_rigenera', 'rcm_qr_rigenera' );
function rcm_qr_rigenera() {
	rcm_qr_controlla( 'rcm_qr_rigenera' );
	$id    = absint( $_GET['id'] ?? 0 );
	$socio = function_exists( 'rcm_as_socio' ) ? rcm_as_socio( $id ) : null;
	if ( $socio ) {
		rcm_qr_token( $socio, true );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=rcm-soci&modifica=' . $id . '&qr=nuovo#rcm-modulo-socio' ) );
	exit;
}

add_action( 'admin_post_rcm_qr_zip', 'rcm_qr_zip' );
function rcm_qr_zip() {
	rcm_qr_controlla( 'rcm_qr_zip' );
	global $wpdb;
	$soci = $wpdb->get_results( 'SELECT * FROM ' . rcm_compleanni_tabella() . ' WHERE ' . rcm_soci_sql_tessera_valida() . ' ORDER BY cognome, nome' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! $soci ) {
		wp_die( 'Nessun socio con tessera valida.', '', array( 'response' => 404 ) );
	}
	$tmp = wp_tempnam( 'rcm-qr' );
	$zip = new ZipArchive();
	$zip->open( $tmp, ZipArchive::OVERWRITE );
	$usati = array();
	foreach ( $soci as $s ) {
		$base = rcm_qr_nome_file( $s, '' );
		if ( isset( $usati[ $base ] ) ) { // omonimi senza numero
			$base = rtrim( $base, '.' ) . '-' . $s->id . '.';
		}
		$usati[ $base ] = true;
		$zip->addFromString( 'SVG/' . $base . 'svg', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . rcm_qr_svg( $s ) );
		$zip->addFromString( 'PNG/' . $base . 'png', rcm_qr_png( $s ) );
	}
	$zip->close();
	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="QR-soci-' . str_replace( '/', '-', rcm_soci_stagione_corrente() ) . '.zip"' );
	header( 'Content-Length: ' . filesize( $tmp ) );
	readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	wp_delete_file( $tmp );
	exit;
}
