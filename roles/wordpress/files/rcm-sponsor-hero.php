<?php
/**
 * Plugin Name: RCM - Claim nell'hero della pagina Sponsor
 * Description: Aggiunge sotto il titolo dell'hero della pagina Sponsor la frase in romanesco sul perche' sponsorizzare il club. L'hero e' un template del tema (thewebs), quindi il testo va agganciato all'action thewebs_entry_hero: cosi' resta markup vero e non testo generato dal CSS.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

/** ID della pagina "Sponsor". */
const RCM_SPONSOR_PAGE_ID = 1312;

/**
 * Il titolo e i breadcrumb sono agganciati a priorita' 10 (Thewebs\thewebs_entry_header),
 * quindi con 20 il claim finisce subito sotto.
 */
add_action( 'thewebs_entry_hero', 'rcm_sponsor_hero_claim', 20, 2 );

/**
 * @param string $post_type Tipo di contenuto passato dal tema.
 * @param string $position  Posizione dell'hero passata dal tema.
 */
function rcm_sponsor_hero_claim( $post_type = '', $position = '' ) {
	if ( ! is_page( RCM_SPONSOR_PAGE_ID ) ) {
		return;
	}
	?>
	<p class="rcm-hero-claim">
		<span class="rcm-hero-claim-lead">Nun &egrave; pubblicit&agrave;: &egrave; famija.</span>
		Dar 2012 er Roma Club Matera &laquo;Francesco Totti&raquo; se porta la Roma appresso in ogni trasferta.
		Chi ce mette er nome suo viaggia co&rsquo; noi: su li striscioni, ar sito, su li social e a tavola
		co&rsquo; centinaia de romanisti che nun se scordano chi j&rsquo;&egrave; stato vicino.
	</p>
	<?php
}
