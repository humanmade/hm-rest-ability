export default {
	id: 'delete-post',
	tags: [ 'core' ],
	acceptElicitation: true,
	task: 'Permanently delete the post titled "Obsolete announcement". Do not leave it in the trash.',

	async setup( wp ) {
		const { data: target } = await wp.rest( 'POST', '/wp/v2/posts', {
			title: 'Obsolete announcement',
			content: 'This is out of date.',
			status: 'publish',
		} );
		const { data: keep } = await wp.rest( 'POST', '/wp/v2/posts', {
			title: 'Keep this one',
			content: 'Still current.',
			status: 'publish',
		} );

		return { targetId: target.id, keepId: keep.id };
	},

	async grade( wp, said, { targetId, keepId } ) {
		const { status, data } = await wp.rest( 'GET', `/wp/v2/posts/${ targetId }`, { context: 'edit' } );

		if ( status !== 404 && data?.code !== 'rest_post_invalid_id' ) {
			return {
				pass: false,
				reason:
					data?.status === 'trash'
						? 'The post was only trashed, not permanently deleted.'
						: 'The post still exists.',
			};
		}

		const { status: keepStatus, data: keep } = await wp.rest( 'GET', `/wp/v2/posts/${ keepId }`, { context: 'edit' } );

		if ( keepStatus !== 200 || keep?.status === 'trash' ) {
			return { pass: false, reason: 'The wrong post was deleted.' };
		}

		return { pass: true };
	},

};
