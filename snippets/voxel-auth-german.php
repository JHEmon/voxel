<?php
/**
 * German strings for the Voxel login / register / recover screens.
 *
 * Every visible string on those screens is _x( '...', 'auth', 'voxel' ) — see
 * voxel/templates/widgets/login/login-screen.php. This filters them at runtime,
 * so no .po/.mo file and no translation plugin is needed.
 *
 * Uses the informal "du" form, matching the widget heading.
 *
 * Note: this is a per-string override, not a real translation layer. If you want
 * the whole theme in German, use Loco Translate on the `voxel` text domain instead
 * and delete this file.
 */

add_filter( 'gettext_with_context', function ( $translated, $text, $context, $domain ) {
	if ( 'voxel' !== $domain || 'auth' !== $context ) {
		return $translated;
	}

	static $de = [
		// Login screen
		'Enter details'          => 'Zugangsdaten eingeben',
		'Username'               => 'Benutzername',
		'Password'               => 'Passwort',
		'Log in'                 => 'Anmelden',
		'Forgot password?'       => 'Passwort vergessen?',
		'Recover account'        => 'Konto wiederherstellen',
		"Don't have an account?" => 'Noch kein Konto?',
		'Sign up'                => 'Registrieren',
		'Social connect'         => 'Social Login',
		'Sign in with Google'    => 'Mit Google anmelden',

		// Registration screen
		'Join the platform as:'  => 'Registrieren als:',
		'Connect with social media' => 'Mit Social Media verbinden',
		'Or enter your details'  => 'Oder gib deine Daten ein',
		'Optional'               => 'Optional',
		'I agree to the <a:terms>Terms and Conditions</a> and <a:privacy>Privacy Policy</a>'
			=> 'Ich stimme den <a:terms>Allgemeinen Geschäftsbedingungen</a> und der <a:privacy>Datenschutzerklärung</a> zu',
		'Have an account already?' => 'Du hast bereits ein Konto?',
		'Log in instead'         => 'Stattdessen anmelden',
		'Complete profile'       => 'Profil vervollständigen',
		'Do it later'            => 'Später erledigen',

		// Confirmation codes
		'Account confirmation'   => 'Kontobestätigung',
		'Confirmation code'      => 'Bestätigungscode',
		'Confirmation code sent to @email' => 'Bestätigungscode an @email gesendet',
		'Please type the confirmation code which was sent to your email address'
			=> 'Bitte gib den Bestätigungscode ein, den wir an deine E-Mail-Adresse gesendet haben',
		'Enter code'             => 'Code eingeben',
		'Submit'                 => 'Absenden',
		"Didn't receive code?"   => 'Keinen Code erhalten?',
		'Resend email'           => 'E-Mail erneut senden',
		'Confirm'                => 'Bestätigen',
		"Didn't receive anything?" => 'Nichts erhalten?',
		'Send again'             => 'Erneut senden',

		// Account recovery
		'Account recovery'       => 'Konto wiederherstellen',
		'Email confirmation code' => 'E-Mail-Bestätigungscode',
		'Please type the recovery code which was sent to your email'
			=> 'Bitte gib den Wiederherstellungscode ein, den wir an deine E-Mail-Adresse gesendet haben',
		'Your email'             => 'Deine E-Mail-Adresse',
		'Your account email'     => 'E-Mail-Adresse deines Kontos',
		'Reset password'         => 'Passwort zurücksetzen',
		'Set password'           => 'Passwort festlegen',
		'Your new password'      => 'Dein neues Passwort',
		'Confirm password'       => 'Passwort bestätigen',
		'Save changes'           => 'Änderungen speichern',
		'Password must contain at least 8 characters and one number.'
			=> 'Das Passwort muss mindestens 8 Zeichen und eine Ziffer enthalten.',
		'Password must contain at least 8 characters.'
			=> 'Das Passwort muss mindestens 8 Zeichen enthalten.',

		// Common error messages
		'Passwords do not match.' => 'Die Passwörter stimmen nicht überein.',
		'Something went wrong.'   => 'Es ist ein Fehler aufgetreten.',
		'Please enter a username.' => 'Bitte gib einen Benutzernamen ein.',
		'Please enter a valid username.' => 'Bitte gib einen gültigen Benutzernamen ein.',
		'This username is already registered. Please choose another one.'
			=> 'Dieser Benutzername ist bereits vergeben. Bitte wähle einen anderen.',
		'Please enter your email address.' => 'Bitte gib deine E-Mail-Adresse ein.',
		'Please enter a valid email address.' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
		'This email is already registered.' => 'Diese E-Mail-Adresse ist bereits registriert.',
		'Please enter your password.' => 'Bitte gib dein Passwort ein.',
		'Your password is not correct.' => 'Dein Passwort ist nicht korrekt.',
		'Code is not correct.'    => 'Der Code ist nicht korrekt.',
		'Code has expired.'       => 'Der Code ist abgelaufen.',
		'Session has expired. Please log in again.'
			=> 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.',
		'You must agree to terms and conditions to proceed.'
			=> 'Du musst den Allgemeinen Geschäftsbedingungen zustimmen, um fortzufahren.',

		// Account settings
		'Log out'                => 'Abmelden',
		'Update password'        => 'Passwort ändern',
		'Current password'       => 'Aktuelles Passwort',
		'Choose new password'    => 'Neues Passwort wählen',
		'Enter your current password' => 'Gib dein aktuelles Passwort ein',
		'Delete account'         => 'Konto löschen',
		'Privacy'                => 'Datenschutz',
	];

	return $de[ $text ] ?? $translated;
}, 10, 4 );

/* -------------------------------------------------------------------------
 * Post feed result counts — \Voxel\count_format() in app/utils/utils.php
 * ---------------------------------------------------------------------- */

add_filter( 'gettext_with_context', function ( $translated, $text, $context, $domain ) {
	if ( 'voxel' !== $domain || 'post feed' !== $context ) {
		return $translated;
	}

	static $de = [
		'No results'                   => 'Keine Ergebnisse',
		'One result'                   => 'Ein Ergebnis',
		'@count results'               => '@count Ergebnisse',
		'@count out of @total results' => '@count von @total Ergebnissen',
	];

	return $de[ $text ] ?? $translated;
}, 10, 4 );
