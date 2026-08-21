// Il CSS su misura del sito stava in "CSS aggiuntivo" del Customizer, cioe' nel
// database. Ora e' un file del tema figlio, versionato nel repo Ansible.
//
// Il CSS del Customizer viene stampato da WordPress su wp_head con priorita' 101,
// cioe' DOPO i fogli di stile per-pagina di Elementor. Diverse regole qui dentro
// (le tabelle SportsPress del calendario, il ruolo sotto le foto del direttivo)
// hanno la stessa specificita' di quelle generate da Elementor e vincevano solo
// perche' arrivavano dopo. Un normale wp_enqueue_style le farebbe uscire PRIMA di
// Elementor e le regole si romperebbero in silenzio: per questo il foglio viene
// registrato e poi stampato a mano nello stesso punto in cui stava prima.
if ( ! function_exists( 'rcm_register_custom_css' ) ) :
	function rcm_register_custom_css() {
		$relative = 'assets/css/rcm-custom.css';
		$path     = trailingslashit( get_stylesheet_directory() ) . $relative;
		if ( ! file_exists( $path ) ) {
			return;
		}
		wp_register_style(
			'rcm-custom',
			trailingslashit( get_stylesheet_directory_uri() ) . $relative,
			array( 'chld_thm_cfg_child' ),
			// versione presa dalla data del file: cambio il CSS, cambia l'URL,
			// cosi' browser e cache non servono la versione vecchia
			filemtime( $path )
		);
		// solo register: se lo mettessi in coda WordPress lo stamperebbe
		// nel giro normale, cioe' prima di Elementor. Lo stampa rcm_print_custom_css().
	}
endif;
add_action( 'wp_enqueue_scripts', 'rcm_register_custom_css', 20 );

if ( ! function_exists( 'rcm_print_custom_css' ) ) :
	function rcm_print_custom_css() {
		wp_print_styles( 'rcm-custom' );
	}
endif;
add_action( 'wp_head', 'rcm_print_custom_css', 101 );
