export default {
	id: 'discover-routes',
	tags: [],
	acceptElicitation: true,
	task:
		'Count how many published posts this site has, then set the site tagline to exactly that number. ' +
		'The tagline should contain nothing but the number.',

	async setup( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/settings', {} );

		for ( const title of [ 'One', 'Two', 'Three' ] ) {
			await wp.rest( 'POST', '/wp/v2/posts', { title, status: 'publish' } );
		}
		await wp.rest( 'POST', '/wp/v2/settings', { description: 'Just another site' } );

		return { original: data?.description || '' };
	},

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/settings', {} );

		if ( ( data?.description || '' ).trim() !== '3' ) {
			return { pass: false, reason: `The tagline is "${ data?.description }", not "3".` };
		}

		return { pass: true };
	},

	async teardown( wp, { original } ) {
		await wp.rest( 'POST', '/wp/v2/settings', { description: original } );
	},
};
