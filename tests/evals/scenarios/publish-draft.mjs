export default {
	id: 'publish-draft',
	tags: [ 'core' ],
	task: 'There is a draft post called "Release notes". Publish it, and change its title to "Release notes 2.0".',

	async setup( wp ) {
		const { data } = await wp.rest( 'POST', '/wp/v2/posts', {
			title: 'Release notes',
			content: 'What changed in this release.',
			status: 'draft',
		} );

		return { postId: data.id };
	},

	async grade( wp, said, { postId } ) {
		const { status, data: post } = await wp.rest( 'GET', `/wp/v2/posts/${ postId }`, {
			context: 'edit',
		} );

		if ( status !== 200 ) {
			return { pass: false, reason: 'The post is gone.' };
		}

		if ( post.status !== 'publish' ) {
			return { pass: false, reason: `The post is still ${ post.status }.` };
		}

		const title = post.title?.raw || '';

		if ( ! /2\.0/.test( title ) ) {
			return { pass: false, reason: `The title is still "${ title }".` };
		}

		return { pass: true };
	},

};
