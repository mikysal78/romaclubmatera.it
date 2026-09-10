<?php
/**
 * Plugin Name: RCM - Cosa si ottiene tesserandosi
 * Description: Il blocco con quote, vantaggi e modalita' di pagamento in cima alla pagina del tesseramento. Lo stile sta in bestfoot-child/assets/css/rcm-custom.css, sezione "Tesseramento".
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * La pagina del tesseramento conteneva l'avviso privacy e il modulo JotForm, e
 * basta. Chi ci arrivava non sapeva quanto costa, cosa cambia fra una tessera e
 * l'altra, come si paga e cosa ottiene: doveva compilare per scoprirlo.
 *
 * C'e' anche un effetto sui motori di ricerca: il modulo lo carica uno script,
 * quindi per Google quella pagina era quasi vuota. L'unico testo indicizzabile
 * era l'avviso privacy, mentre la descrizione Yoast prometteva gia' "tessera,
 * vantaggi per i soci, eventi e trasferte".
 *
 * Il blocco si infila PRIMA del contenuto Elementor, non dentro: cosi' l'ordine
 * della pagina diventa quote e vantaggi, poi l'avviso privacy, poi il modulo -
 * l'avviso resta attaccato al modulo, che e' il punto in cui serve - e il testo
 * resta versionato qui invece di finire dentro un widget nel database.
 */

defined( 'ABSPATH' ) || exit;

/** Slug della pagina che ospita il modulo di tesseramento. */
const RCM_TESS_PAGINA = 'tesseramento-2026-27';

/* -------------------------------------------------------------------------
 * Le quote
 * ---------------------------------------------------------------------- */

/**
 * Le tessere, nell'ordine in cui si mostrano.
 *
 * Le quote sono quelle del modulo JotForm in fondo a questa stessa pagina: se
 * cambiano li', vanno cambiate qui, o la pagina promette un prezzo e il modulo
 * ne chiede un altro. La descrizione puo' mancare: una tessera senza una riga
 * di spiegazione si mostra col solo nome e prezzo, che e' meglio di una frase
 * inventata.
 *
 * La "Tessera Roma" non e' una tessera annuale ridotta: e' il biglietto della
 * singola serata per chi viene da fuori. Per questo sotto l'elenco dei
 * vantaggi c'e' la nota che quell'elenco non la riguarda.
 *
 * @return array[]
 */
function rcm_tess_tessere() {
	$tessere = array(
		array(
			'nome'      => 'Socio Ordinario',
			'quota'     => '40 &euro;',
			'descrizione' => 'La tessera del club: quella della maggior parte dei soci.',
			'evidenza'  => true,
		),
		array(
			'nome'      => 'Tessera Family',
			'quota'     => '60 &euro;',
			'descrizione' => 'Una sola tessera per un intero nucleo familiare, fino a tre persone.',
			'evidenza'  => false,
		),
		array(
			'nome'      => 'Socio Onorario',
			'quota'     => '100 &euro;',
			'descrizione' => 'Per chi sceglie di sostenere il club con una quota pi&ugrave; alta.',
			'evidenza'  => false,
		),
		array(
			'nome'      => 'Tessera Roma',
			'quota'     => '5 &euro;',
			'descrizione' => 'Per l&rsquo;ospite occasionale che viene da fuori: vale per una singola partita vista in sede.',
			'evidenza'  => false,
		),
	);

	return apply_filters( 'rcm_tesseramento_tessere', $tessere );
}

/**
 * I modi per pagare la quota, gli stessi che il modulo fa scegliere in fondo.
 *
 * @return array[]
 */
function rcm_tess_pagamenti() {
	$pagamenti = array(
		array( 'nome' => 'Bonifico', 'nota' => 'Gli estremi te li mandiamo insieme alla conferma dell&rsquo;iscrizione.' ),
		array( 'nome' => 'PayPal', 'nota' => 'Dallo stesso indirizzo con cui ti sei iscritto.' ),
		array( 'nome' => 'Contanti in sede', 'nota' => 'In Via Lupo Protospata 62 bis, quando passi a trovarci.' ),
	);

	return apply_filters( 'rcm_tesseramento_pagamenti', $pagamenti );
}

/* -------------------------------------------------------------------------
 * Il blocco
 * ---------------------------------------------------------------------- */

// Priorita' 20: Elementor monta il suo contenuto su the_content a 9, quindi a
// 20 il blocco si trova davanti alla pagina gia' costruita, non in mezzo ai
// widget.
add_filter( 'the_content', 'rcm_tess_blocco', 20 );

/**
 * @param string $contenuto Il contenuto della pagina.
 * @return string
 */
function rcm_tess_blocco( $contenuto ) {
	if ( ! is_page( RCM_TESS_PAGINA ) || ! in_the_loop() || ! is_main_query() ) {
		return $contenuto;
	}

	ob_start();
	?>
	<div class="rcm-tess">

		<p class="rcm-tess-apertura">
			Tesserarsi vuol dire vedere le partite in sede insieme agli altri romanisti, partire con noi
			in trasferta e passare dal club per i biglietti dell&rsquo;Olimpico. Le tessere annuali
			valgono per tutta la stagione <strong>2026/27</strong>.
		</p>

		<h2 class="rcm-tess-titolo">Le tessere</h2>
		<ul class="rcm-tess-quote">
			<?php foreach ( rcm_tess_tessere() as $t ) : ?>
				<li class="rcm-tess-quota<?php echo $t['evidenza'] ? ' is-evidenza' : ''; ?>">
					<span class="rcm-tess-nome"><?php echo esc_html( html_entity_decode( $t['nome'] ) ); ?></span>
					<span class="rcm-tess-prezzo"><?php echo wp_kses_post( $t['quota'] ); ?></span>
					<?php if ( $t['descrizione'] ) : ?>
						<span class="rcm-tess-dettaglio"><?php echo wp_kses_post( $t['descrizione'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<h2 class="rcm-tess-titolo">Cosa ottieni</h2>
		<ul class="rcm-tess-vantaggi">
			<li>
				<strong>Il gruppo WhatsApp dei tesserati</strong>
				&mdash; riservato ai soci, pi&ugrave; il canale con gli aggiornamenti su trasferte ed eventi.
			</li>
			<li>
				<strong>La sede aperta a ogni partita</strong>
				&mdash; si apre <?php echo (int) apply_filters( 'rcm_evento_apertura_minuti', 30, 0 ); ?> minuti
				prima del fischio d&rsquo;inizio e le serate sono riservate ai tesserati.
				L&rsquo;orario di ogni gara &egrave; sul <a href="<?php echo esc_url( home_url( '/calendario/' ) ); ?>">calendario</a>.
			</li>
			<li>
				<strong>Le trasferte con il club</strong>
				&mdash; al viaggio pensiamo noi.
				Quelle gi&agrave; fatte sono <a href="<?php echo esc_url( home_url( '/trasferte/' ) ); ?>">qui, in fotografia</a>.
			</li>
			<li>
				<strong>I biglietti per l&rsquo;Olimpico</strong>
				&mdash; il club raccoglie la richiesta, verifica la disponibilit&agrave; e ti ricontatta.
				&Egrave; un servizio per i soli tesserati: si parte dalla
				<a href="<?php echo esc_url( home_url( '/biglietti/' ) ); ?>">biglietteria</a>.
			</li>
			<li>
				<strong>Gli auguri di compleanno</strong>
				&mdash; nel giorno del tuo, dal club.
			</li>
			<li>
				<strong>La newsletter</strong>
				&mdash; appuntamenti, trasferte e iniziative, per email.
			</li>
		</ul>
		<p class="rcm-tess-nota">
			L&rsquo;elenco vale per le tessere annuali. La <strong>Tessera Roma</strong> &egrave; un&rsquo;altra
			cosa: copre la singola serata in sede di chi viene da fuori.
		</p>

		<h2 class="rcm-tess-titolo">Come si paga</h2>
		<ul class="rcm-tess-pagamenti">
			<?php foreach ( rcm_tess_pagamenti() as $p ) : ?>
				<li>
					<strong><?php echo esc_html( html_entity_decode( $p['nome'] ) ); ?></strong>
					<span><?php echo wp_kses_post( $p['nota'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="rcm-tess-nota">
			La modalit&agrave; si sceglie in fondo al modulo: la quota si versa dopo, non serve
			averla pronta adesso.
		</p>

		<p class="rcm-tess-avanti">Compila il modulo qui sotto &mdash; sono cinque minuti.</p>

	</div>
	<?php

	return ob_get_clean() . $contenuto;
}
