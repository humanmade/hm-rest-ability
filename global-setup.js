const { runCLI } = require( '@wp-playground/cli' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const crypto = require( 'node:crypto' );

/**
 * Deterministic port from cwd hash so each git worktree gets its own
 * Playground instance. Override with WP_PLAYGROUND_PORT (e.g., in CI).
 * Range: 9400–9499.
 */
function resolvePort() {
	if ( process.env.WP_PLAYGROUND_PORT ) {
		return Number( process.env.WP_PLAYGROUND_PORT );
	}
	const hash = crypto
		.createHash( 'sha1' )
		.update( process.cwd() )
		.digest();
	return 9400 + ( hash.readUInt16BE( 0 ) % 100 );
}

/**
 * Playwright globalSetup: boots a single Playground instance for the whole
 * test run. Writes the server URL to process.env.WP_BASE_URL so all specs
 * (and @wordpress/e2e-test-utils-playwright fixtures) use it automatically.
 *
 * No-ops when WP_BASE_URL is already set, so an externally-managed Playground
 * (CI matrix server, local `npm run playground:start`) is not double-booted.
 *
 * Blueprint is read from ./blueprint.json in the project root. To use a
 * different blueprint, set WP_BLUEPRINT_PATH to an absolute path.
 */
module.exports = async () => {
	if ( process.env.WP_BASE_URL ) return;

	const blueprintPath = process.env.WP_BLUEPRINT_PATH
		? path.resolve( process.env.WP_BLUEPRINT_PATH )
		: path.resolve( process.cwd(), 'blueprint.json' );
	const blueprint = JSON.parse( fs.readFileSync( blueprintPath, 'utf8' ) );
	const port = resolvePort();

	const cli = await runCLI( {
		command: 'server',
		port,
		php: process.env.WP_PLAYGROUND_PHP || undefined,
		wp: process.env.WP_PLAYGROUND_WP || undefined,
		mount: [
			{
				hostPath: process.cwd(),
				vfsPath: '/wordpress/wp-content/plugins/hm-rest-ability',
			},
		],
		blueprint,
	} );

	process.env.WP_BASE_URL = cli.serverUrl;
	globalThis.__wpPlayground = cli;
};
