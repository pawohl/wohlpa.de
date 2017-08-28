/*jshint jquery: true*/
/*global alert: false*/
( function( $, w ) {
	'use strict';

	// Privacy policy (but not limited to)
	$( '.lod-content' )
		.on( 'click', function( e ) {
			e.preventDefault();

			var $elem = $( this ),
				contentLocation = $elem.data( 'location' ),
				contentElementId = $elem.data( 'element-id' ),
				$contentElement = $( document.getElementById( contentElementId ) );
			$elem.text( 'Loading …' );
			$.get( contentLocation )
				.done( function( data ) {
					$contentElement.html( data );
				} )
				.fail( function() {
					$elem.off( 'click' )[0]
						.click();
				} );
		} );

	// Contact form
	var $contactForm = $( '#contact-form' ),
		$confirmPp = $contactForm.find( '.confirm-pp' ),
		$sendSuccess = $contactForm.find( '.send-success' ),
		$sendMessage = $contactForm.find( '.send-message' ),
		$messageCopy = $contactForm.find( '.message-copy' ),
		$agree = $( '#agree' ),
		$cancel = $( '#cancel' ),
		agreeText = $agree.text();

	$contactForm.on( 'submit', function( e ) {
		e.preventDefault();
		$confirmPp.fadeIn();
		$sendMessage.hide();
		$sendSuccess.hide();
	} ).find('input,textarea,button').removeAttr('disabled');
	$cancel.on( 'click', function( e ) {
		e.preventDefault();
		$confirmPp.fadeOut();
		$sendMessage.show();
	} );
	$agree.on( 'click', function( e ) {
		e.preventDefault();
		$agree.attr( 'disabled', 'disabled' )
			.text( 'Sending …' );
		$cancel.hide();
		obtainToken();
	} );

	function obtainToken() {
		$.post( '/app/token' )
			.done( function( r ) {
				var formData = {};
				$contactForm.find( 'input,textarea' )
					.each( function( i, elem ) {
						if ( elem.value !== undefined ) {
							formData[ elem.name ] = elem.value;
						}
					} );
				formData.token = r.token;
				sendMesage( formData );
			} )
			.fail( messageHTTPError );
	}

	function messageHTTPError( jqXHR, textStatus, errorThrown ) {
		$agree.removeAttr( 'disabled' )
			.text( agreeText );
		$cancel.show();
		alert( 'Sending the message failed. Please email me.\n' + textStatus + ': ' + errorThrown );
	}

	function sendMesage( formData ) {
		$.post( '/app/contact/message', formData )
			.done( function( r ) {
				$sendMessage.show();
				$confirmPp.hide();
				$sendSuccess.fadeIn();
				$messageCopy.text( formData.subject + '\n' + formData.message );
				$contactForm.find( '#subject,#message' )
					.val( '' );
				$agree.removeAttr( 'disabled' )
					.text( agreeText );
			} )
			.fail( messageHTTPError );
	}
}( jQuery, window ) );

