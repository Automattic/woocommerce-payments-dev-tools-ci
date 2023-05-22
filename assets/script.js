document.addEventListener( 'DOMContentLoaded', function() {
	/**
	 * This will copy an element text to clipboard
	 * Compatible with both input elements and regular elements
	 *
	 * @param element reference OR string
	 */

	function copyToClipboard( e ) {
		const targetId = e.currentTarget.getAttribute( 'data-copy-target' );
		const targetElement = document.getElementById( targetId );
		const isInputElement = targetElement instanceof HTMLInputElement;
		let element;

		if ( isInputElement ) {
			element = targetElement;
		} else {
			// if the element we are trying to copy from
			// is not an input element
			// Create a temporary hidden input element
			// and set the corresponding value
			element = document.createElement( 'input' );
			element.setAttribute( 'type', 'text' );
			element.setAttribute( 'display', 'none' );
			const content = targetElement.innerHTML;

			element.setAttribute( 'value', content );
			document.body.appendChild( element );
		}

		element.select();
		document.execCommand( 'copy' );
		if ( ! isInputElement ) {
			element.parentElement.removeChild( element );
		}
	}

	document.querySelectorAll( '#copyButton' ).forEach( ( el ) => {
		el.addEventListener( 'click', copyToClipboard );
	} );

	// Handle show/hide the WCPay Subscriptions Renewal Testing group depending on WCPay Subscriptions feature flag being enabled or not.
	document.querySelector( '#_wcpay_feature_subscriptions' ).addEventListener( 'change', ( evt ) => {
		document.querySelector( '.wcpay-subscriptions-settings' ).classList.toggle('hide-if-js', ! evt.target.checked );
	} );

	// Handle show/hide the WCPay Renewal Testing Clock settings depending on WCPay Subscriptions Renewal Testing being enabled or not.
	document.querySelector( '#wcpaydev_wcpay_billing_clock' ).addEventListener( 'change', ( evt ) => {
		document.querySelector( '.wcpay-subscriptions-renewal-testing-settings' ).classList.toggle('hide-if-js', ! evt.target.checked );
	} );
} );
