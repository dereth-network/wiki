/**
 * Citizen-only fixes for this wiki's content. Loaded by DerethTheme's
 * BeforePageDisplay hook when the skin is Citizen.
 */
( function () {
	/**
	 * Citizen shows the table of contents in a column beside the article and
	 * only gives .wikitable tables a horizontal scroller, so a layout table
	 * wider than the article (item pages, some guides) runs underneath the
	 * contents column. Wrap those tables in a scroller too. Also helps phones.
	 *
	 * @param {HTMLElement} content
	 */
	function wrapWideTables( content ) {
		content.querySelectorAll( '.mw-parser-output table' ).forEach( ( table ) => {
			const parent = table.parentElement;
			if ( !parent || parent.closest( 'table, .dereth-overflow, .citizen-overflow-content' ) ) {
				return;
			}
			if ( table.getBoundingClientRect().width <= parent.clientWidth + 1 ) {
				return;
			}
			const wrapper = document.createElement( 'div' );
			wrapper.className = 'dereth-overflow';
			parent.insertBefore( wrapper, table );
			wrapper.appendChild( table );
		} );
	}

	// Any CSS colour (Citizen uses oklch(), dark.css uses color-mix()) to
	// sRGB, by painting it onto a 1px canvas.
	const canvas = document.createElement( 'canvas' );
	canvas.width = canvas.height = 1;
	const ctx = canvas.getContext( '2d', { willReadFrequently: true } );
	const luminanceCache = new Map();

	/**
	 * @param {string} cssColor
	 * @return {number|null} relative luminance 0..1, or null if transparent
	 */
	function luminance( cssColor ) {
		if ( luminanceCache.has( cssColor ) ) {
			return luminanceCache.get( cssColor );
		}
		ctx.clearRect( 0, 0, 1, 1 );
		ctx.fillStyle = '#000';
		ctx.fillStyle = cssColor;
		ctx.fillRect( 0, 0, 1, 1 );
		const [ r8, g8, b8, a ] = ctx.getImageData( 0, 0, 1, 1 ).data;
		let result = null;
		if ( a >= 128 ) {
			const [ r, g, b ] = [ r8, g8, b8 ].map( ( v ) => {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
			} );
			result = 0.2126 * r + 0.7152 * g + 0.0722 * b;
		}
		luminanceCache.set( cssColor, result );
		return result;
	}

	/**
	 * @param {number} a luminance
	 * @param {number} b luminance
	 * @return {number} WCAG contrast ratio
	 */
	function contrast( a, b ) {
		return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
	}

	/**
	 * @param {HTMLElement} el
	 * @return {number} luminance of the first opaque background behind el
	 */
	function backgroundBehind( el ) {
		for ( let node = el; node; node = node.parentElement ) {
			const l = luminance( getComputedStyle( node ).backgroundColor );
			if ( l !== null ) {
				return l;
			}
		}
		return 1;
	}

	const DARK_TEXT = luminance( '#202122' );
	// Roughly what .dereth-lighten produces from a dark colour.
	const LIGHT_TEXT = luminance( '#d0d0d0' );

	/**
	 * Safety net for the dark theme. dark.css darkens the known page-furniture
	 * colours, but templates also use colours that carry meaning (dye
	 * swatches, material colours, stat colours) and keep them. Wherever an
	 * element with its own colours ends up hard to read:
	 * - on a light background: give it dark text (.dereth-on-light; harmless
	 *   in the light theme, so it never needs removing);
	 * - with text too dark for the dark theme: a lighter version of its own
	 *   colour (.dereth-lighten, which dark.css only applies in dark mode).
	 *
	 * @param {HTMLElement} root
	 */
	function fixContrast( root ) {
		if ( luminance( getComputedStyle( document.body ).backgroundColor ) > 0.2 ) {
			return; // light theme: leave the content's own colours alone
		}
		root.querySelectorAll( '[style*="background" i], [style*="color" i], [bgcolor], font[color]' ).forEach( ( el ) => {
			if ( el.classList.contains( 'dereth-on-light' ) || el.classList.contains( 'dereth-lighten' ) ) {
				return;
			}
			const style = getComputedStyle( el );
			const fg = luminance( style.color );
			if ( fg === null ) {
				return;
			}
			const bg = backgroundBehind( el );
			const current = contrast( fg, bg );
			// Text exactly the colour of its box is a deliberate spacer.
			if ( current >= 3 || Math.abs( fg - bg ) < 0.002 ) {
				return;
			}
			// Whichever reads better on this background: dark text, or a
			// lighter version of the element's own colour.
			if ( contrast( DARK_TEXT, bg ) >= contrast( LIGHT_TEXT, bg ) ) {
				el.classList.add( 'dereth-on-light' );
			} else {
				el.style.setProperty( '--dereth-orig-color', style.color );
				el.classList.add( 'dereth-lighten' );
			}
		} );
	}

	function fixAll() {
		[ document.getElementById( 'siteNotice' ), document.getElementById( 'mw-content-text' ) ]
			.forEach( ( root ) => root && fixContrast( root ) );
	}

	mw.hook( 'wikipage.content' ).add( ( $content ) => {
		wrapWideTables( $content[ 0 ] );
		fixAll();
	} );

	// Re-check when the theme changes without a page load: Citizen's theme
	// switcher (a class change on <html>), or the OS flipping while on "auto".
	new MutationObserver( fixAll ).observe( document.documentElement, {
		attributes: true,
		attributeFilter: [ 'class' ]
	} );
	window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', fixAll );
}() );
