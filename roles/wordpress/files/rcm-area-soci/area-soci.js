/*
 * Area soci: l'orologio sotto la tessera. Scorre ogni secondo, cosi' chi
 * controlla all'ingresso distingue la pagina vera da uno screenshot.
 */
( function () {
	var el = document.querySelector( '[data-rcm-orologio]' );
	if ( ! el ) {
		return;
	}
	function due( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}
	function aggiorna() {
		var d = new Date();
		el.textContent = due( d.getDate() ) + '/' + due( d.getMonth() + 1 ) + '/' + d.getFullYear() +
			' ' + due( d.getHours() ) + ':' + due( d.getMinutes() ) + ':' + due( d.getSeconds() );
	}
	aggiorna();
	setInterval( aggiorna, 1000 );
}() );

/*
 * Prenotazioni: le righe per le altre persone. Senza JavaScript si vedono
 * tutte; con, si parte dalla prima vuota e si aggiungono una alla volta.
 */
( function () {
	document.querySelectorAll( '.rcm-pr-persone' ).forEach( function ( gruppo ) {
		var vuote  = Array.prototype.slice.call( gruppo.querySelectorAll( '.rcm-pr-persona[data-vuota]' ) );
		var bottone = gruppo.querySelector( '.rcm-pr-aggiungi' );
		if ( ! bottone || vuote.length < 2 ) {
			return;
		}
		vuote.slice( 1 ).forEach( function ( riga ) {
			riga.hidden = true;
		} );
		bottone.hidden = false;
		bottone.addEventListener( 'click', function () {
			var prossima = gruppo.querySelector( '.rcm-pr-persona[hidden]' );
			if ( prossima ) {
				prossima.hidden = false;
				prossima.querySelector( 'input' ).focus();
			}
			if ( ! gruppo.querySelector( '.rcm-pr-persona[hidden]' ) ) {
				bottone.hidden = true;
			}
		} );
	} );
}() );
