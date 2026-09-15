const fs = require( 'node:fs' );
const path = require( 'node:path' );

const CREDENTIALS_FILE = '.playground-credentials.json';

/**
 * Suppresses Playground's auto-login redirect.
 *
 * The blueprint's `login` step logs the browser in on the first request of
 * every new context. That cookie breaks application password auth:
 * wp_validate_application_password() returns early once another handler has
 * identified a user, and REST cookie auth then resets the user to 0 because
 * no nonce was sent. The result is a 401 despite valid credentials.
 *
 * A real MCP client sends no cookies, so suppressing auto-login is also the
 * more faithful simulation.
 */
const NO_AUTO_LOGIN_COOKIE = 'playground_auto_login_already_happened=1';

/**
 * Reads the application passwords the blueprint creates at boot.
 *
 * The blueprint writes them into the mounted plugin directory, so they land on
 * the host filesystem next to this repo.
 *
 * @param {string} role Account to read: `admin` or `subscriber`.
 * @return {{user: string, password: string}} That account's credentials.
 */
function readCredentials( role = 'admin' ) {
	const file = path.resolve( process.cwd(), CREDENTIALS_FILE );

	if ( ! fs.existsSync( file ) ) {
		throw new Error(
			`No ${ CREDENTIALS_FILE } found. It is written by the blueprint's final runPHP step, so the Playground instance may not have finished booting.`
		);
	}

	const accounts = JSON.parse( fs.readFileSync( file, 'utf8' ) );

	if ( ! accounts[ role ] ) {
		throw new Error(
			`No credentials for "${ role }". Found: ${ Object.keys( accounts ).join( ', ' ) }`
		);
	}

	return accounts[ role ];
}

/**
 * Returns the headers needed to authenticate as one of the test accounts.
 *
 * @param {string} role Account to authenticate as: `admin` or `subscriber`.
 * @return {{Authorization: string, Cookie: string}} Request headers.
 */
function authHeaders( role = 'admin' ) {
	const { user, password } = readCredentials( role );
	const token = Buffer.from( `${ user }:${ password }` ).toString( 'base64' );

	return {
		Authorization: `Basic ${ token }`,
		Cookie: NO_AUTO_LOGIN_COOKIE,
	};
}

/**
 * Returns headers for a request that should not be authenticated.
 *
 * @return {{Cookie: string}} Request headers.
 */
function anonymousHeaders() {
	return { Cookie: NO_AUTO_LOGIN_COOKIE };
}

module.exports = {
	readCredentials,
	authHeaders,
	anonymousHeaders,
	CREDENTIALS_FILE,
	NO_AUTO_LOGIN_COOKIE,
};
