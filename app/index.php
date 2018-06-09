<?php

@error_reporting( E_ALL ); // Error engine - always ON!
@ini_set( 'display_errors', FALSE ); // Error display - OFF in production env or real server
@ini_set( 'log_errors', TRUE ); // Error logging
@ini_set( 'expose_php', FALSE ); // Do not print x-powered by headers
@ini_set( 'session.use_strict_mode', TRUE ); // Reject uninitialized session IDs
@ini_set( 'session.cookie_lifetime', 0 ); // Session cookie only for one session
@ini_set( 'session.cookie_secure', TRUE ); // Send cookie over HTTPS only
@ini_set( 'session.cookie_httponly', TRUE ); // Disallow JS access to the cookie
@ini_set( 'session.name', 'CSRF-prevention' ); // Use custom session name
@ini_set( 'session.cache_expire', 1 ); // time-to-live for cached session pages in minutes
@ini_set( 'session.gc_maxlifetime', 10 ); // number of seconds after which data will be seen as 'garbage' and potentially cleaned up

// Always returns JSON
header( 'Content-Type: application/json' );

// Read settings file
$cfgstring = file_get_contents( __DIR__ . '/.mail.cfg' );
if ( ! $cfgstring ) {
	servererror( 'cant read config' );
}
$cfg = json_decode( $cfgstring, true );
if ( ! $cfg ) {
        servererror( 'cant parse config' );
}

switch ( $_SERVER['REQUEST_URI'] ) {
	case '/app/contact/message':
		require_once __DIR__ . '/vendor/autoload.php';
		message();
		break;
	case '/app/token':
		token( 'message' );
		break;
	default:
		fourzerofour();
}

function badrequest( $msg ) {
	destroysession();
	http_response_code( 400 );
	die( json_encode( $msg ) );
}

function forbidden( $msg ) {
	destroysession();
	http_response_code( 403 );
	die( json_encode( $msg ) );
}

function fourzerofour() {
	destroysession();
	http_response_code( 404 );
	die( json_encode( 'Error 404: The requested URL ' . $_SERVER['REQUEST_URI'] . ' was not found on this server.' ) );
}

function servererror( $msg ) {
	destroysession();
	http_response_code( 500 );
	die( json_encode( $msg ) );
}

function destroysession() {
	// Reset to an empty array
	$_SESSION = [];

	// Delete session cookie -- we want to be GDPR compliant
	if ( ini_get( 'session.use_cookies' ) ) {
		$params = session_get_cookie_params();
		setcookie( session_name(), '', time() - 42000, $params['path'],
			$params['domain'], $params['secure'], $params['httponly']
		);
	}

	// Get rid of the session itself
	session_destroy();
}

function getPIN() {
	return $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'];
}

function message() {
	// Resume session or start a new one
	session_start();

	// Verify IP and UA
	$plainTextPIN = getPIN();
	if ( empty( $_SESSION['PIN'] ) || ! password_verify( $plainTextPIN, $_SESSION['PIN'] ) ) {
		forbidden( 'IP or UA mismatch' );
	}

	// Verify token
	if ( !empty( $_POST['token'] ) ) {
		$calc = hash_hmac( 'sha256', 'message', $_SESSION['token'] );
		 if ( hash_equals( $calc, $_POST['token'] ) ) {
				// Proceed to process the form data
				unset( $_SESSION['token'] );
				sendMessage();
				die();
		 } else {
				// Log this as a warning and keep an eye on these attempts
				forbidden( 'token invalid' );
		 }
	}
	forbidden( 'token missing' );
}

function sendMessage() {
	global $cfg;

	// Create the Transport
	$transport = new Swift_SendmailTransport( '/usr/sbin/sendmail -bs' );

	// Create the Mailer using your created Transport
	$mailer = new Swift_Mailer( $transport );

	if ( empty( $_POST['email'] ) || empty( $_POST['name'] ) || empty( $_POST['subject'] ) || empty( $_POST['message'] ) ) {
		badrequest( 'missing email, name, subject or message' );
	}

	// Verify configuration
	$expected = ['from', 'to', 'sign', 'crypt-cert'];
	foreach ( $expected as $k ) {
		if ( ! isset( $cfg[$k] ) ) {
			servererror( 'expected ' . $k );
		}
	}
	if ( ! file_exists( $cfg['sign']['cert'] ) ) {
		servererror( 'sign cert missing' );
	}
        if ( ! file_exists( $cfg['sign']['key'] ) ) {
                servererror( 'sign key missing' );
        }
        if ( ! file_exists( $cfg['crypt-cert'] ) ) {
                servererror( 'crypt cert missing' );
        }

	// Create a message
	$message = ( new Swift_Message( $_POST['subject'] ) )
	  ->setFrom( [$cfg['from']['address'] => $cfg['from']['display']] )
	  ->setTo( [$cfg['to']['address'] => $cfg['to']['display']] )
	  ->setReplyTo( [$_POST['email'] => $_POST['name']] )
	  ->setBody( $_POST['message'] );

	$headers = $message->getHeaders();
	$headers->addTextHeader( 'User-Agent', $_SERVER['HTTP_USER_AGENT'] );
	$headers->addTextHeader( 'X-Mailer', 'https://wohlpa.de/#contact' );
	$headers->addTextHeader( 'Received',
		"from {$_SERVER['REMOTE_ADDR']} by {$_SERVER['SERVER_ADDR']} ({$_SERVER['HTTP_HOST']}) with Swift " . Swift::VERSION . '; ' . date('D, j M Y H:i:s O') );

	$smimeSigner = new Swift_Signers_SMimeSigner();
	$smimeSigner->setSignCertificate( $cfg['sign']['cert'], $cfg['sign']['key'] );
	$smimeSigner->setEncryptCertificate( $cfg['crypt-cert'] );
	$message->attachSigner( $smimeSigner );

	// Send the message
	$result = $mailer->send( $message );

	if ( !$result ) {
		servererror( 'cant send message' );
	}
	destroysession();
	echo json_encode( 'ok' );
}

function token( $purpose = 'message' ) {
	session_start();
	if ( empty( $_SESSION['token'] ) ) {
		 $_SESSION['token'] = bin2hex( random_bytes( 32 ) );
	}
	// GDPR: Avoid storing IP or UA in a way anybody can read it
	$_SESSION['PIN'] = password_hash( getPIN(), PASSWORD_DEFAULT );
	if ( empty( $_SESSION['PIN'] ) || ! $_SESSION['PIN'] ) {
		servererror( 'cannot pin session' );
	}
	$token = hash_hmac( 'sha256', $purpose, $_SESSION['token'] );
	echo json_encode( [ 'token' => $token ] );
}

