export default {
	id: 'discover-routes',
	tags: [],
	task:
		'Count how many published posts this site has, then set the site tagline to exactly that number. ' +
		'The tagline should contain nothing but the number.',

	async setup( wp ) {
		for ( const title of [ 'One', 'Two', 'Three' ] ) {
			await wp.rest( 'POST', '/wp/v2/posts', { title, status: 'publish' } );
		}
		await wp.rest( 'POST', '/wp/v2/settings', { description: 'Just another site' } );
	},

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/settings', {} );

		if ( ( data?.description || '' ).trim() !== '3' ) {
			return { pass: false, reason: `The tagline is "${ data?.description }", not "3".` };
		}

		return { pass: true };
	},

};
