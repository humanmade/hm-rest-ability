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
		// @wp-playground/cli >=2 distributes blueprint steps across its
		// worker-thread pool rather than broadcasting each one to every
		// worker, so a later step (e.g. activating a plugin) can land on a
		// worker that never saw an earlier one (e.g. the rename that put the
		// plugin there). Pin to a single worker so setup stays consistent.
		workers: 1,
		mount: [
			{
				hostPath: process.cwd(),
				vfsPath: '/wordpress/wp-content/plugins/hm-rest-ability',
			},
		],
		blueprint,
	} );

	// runCLI()'s resolved return type (RunCLIServer) only exposes
	// `{ playground, server }` — no server-URL property — so the URL is
	// derived from the port we explicitly requested above instead.
	process.env.WP_BASE_URL = `http://127.0.0.1:${ port }`;
	globalThis.__wpPlayground = cli;
};
