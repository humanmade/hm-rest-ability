/**
 * Playwright globalTeardown: shuts down the Playground instance started by
 * global-setup.js. Prefers Symbol.asyncDispose (newer API) with a fallback
 * to server.close() for older @wp-playground/cli versions.
 */
module.exports = async () => {
	const cli = globalThis.__wpPlayground;
	if ( ! cli ) return;
	if ( typeof cli[ Symbol.asyncDispose ] === 'function' ) {
		await cli[ Symbol.asyncDispose ]();
	} else {
		await cli.server?.close();
	}
};
