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
 * Reads the application password the blueprint creates at boot.
 *
 * The blueprint writes it into the mounted plugin directory, so it lands on
 * the host filesystem next to this repo.
 *
 * @return {{user: string, password: string}} The admin credentials.
 */
function readCredentials() {
	const file = path.resolve( process.cwd(), CREDENTIALS_FILE );

	if ( ! fs.existsSync( file ) ) {
		throw new Error(
			`No ${ CREDENTIALS_FILE } found. It is written by the blueprint's final runPHP step, so the Playground instance may not have finished booting.`
		);
	}

	return JSON.parse( fs.readFileSync( file, 'utf8' ) );
}

/**
 * Returns the headers needed to authenticate as the admin user.
 *
 * @return {{Authorization: string, Cookie: string}} Request headers.
 */
function authHeaders() {
	const { user, password } = readCredentials();
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
