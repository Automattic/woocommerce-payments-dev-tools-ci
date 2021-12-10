/**
 * This will copy an element text to clipboard
 * Compatible with both input elements and regular elements
 *
 * @param element reference OR string
 */

function copyToClipboard( e ) {
	let element;
	const isInputElement = e instanceof HTMLInputElement;
	if ( isInputElement ) {
		element = e;
	} else {
		element = document.createElement( 'input' );

		element.setAttribute( 'type', 'text' );
		element.setAttribute( 'display', 'none' );
		let content = e;
		if ( e instanceof HTMLElement ) {
			content = e.innerHTML;
		}
		element.setAttribute( 'value', content );
		document.body.appendChild( element );
	}
	element.select();
	document.execCommand( 'Copy' );
	if ( ! isInputElement ) {
		element.parentElement.removeChild( element );
	}
}
