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
