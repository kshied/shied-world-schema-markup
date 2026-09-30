/**
 * SHIED WORLD Schema Markup - admin bar quick actions.
 *
 * Pure vanilla JavaScript with no jQuery dependency, so the scroll keeps
 * working under deferred, combined, or minified script delivery.
 */
(function () {
	'use strict';

	function init() {
		var node = document.getElementById( 'wp-admin-bar-smsw-toolbar-preview' );

		if ( ! node || node.getAttribute( 'data-smsw-bar-bound' ) === '1' ) {
			return;
		}

		node.setAttribute( 'data-smsw-bar-bound', '1' );

		node.addEventListener( 'click', function ( e ) {
			var link = e.target && e.target.closest ? e.target.closest( 'a' ) : null;

			if ( ! link ) {
				return;
			}

			var target = document.getElementById( 'smsw-blocks' );

			if ( ! target ) {
				return;
			}

			e.preventDefault();

			if ( target.scrollIntoView ) {
				target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			}

			try {
				target.focus( { preventScroll: true } );
			} catch ( err ) {
				target.focus();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();