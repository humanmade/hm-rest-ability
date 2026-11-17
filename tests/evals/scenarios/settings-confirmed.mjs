export default {
	id: 'settings-confirmed',
	tags: [ 'core' ],
	acceptElicitation: true,
	task: 'Change the site tagline to exactly "Fresh tagline".',

	async setup( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/settings', {} );

		await wp.rest( 'POST', '/wp/v2/settings', { description: 'Original tagline' } );

		return { original: data?.description || '' };
	},

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/settings', {} );

		if ( ( data?.description || '' ).trim() !== 'Fresh tagline' ) {
			return { pass: false, reason: `The tagline is "${ data?.description }", not "Fresh tagline".` };
		}

		return { pass: true };
	},

	async teardown( wp, { original } ) {
		await wp.rest( 'POST', '/wp/v2/settings', { description: original } );
	},
};
