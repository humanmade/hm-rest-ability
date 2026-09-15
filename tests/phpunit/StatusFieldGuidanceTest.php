<?php
/**
 * Unit tests for inc/status-field-guidance.php.
 */

namespace HM\RestAbility\Tests;

use function HM\StatusFieldGuidance\add_guidance;
use function HM\StatusFieldGuidance\has_publishable_status_field;

class StatusFieldGuidanceTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/status-field-guidance.php' );
	}

	public function test_has_publishable_status_field_is_true_for_a_post_status_enum(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'status' => [
						'type' => 'string',
						'enum' => [ 'publish', 'future', 'draft', 'pending', 'private' ],
					],
				],
			],
		];

		$this->assertTrue( has_publishable_status_field( $handlers ) );
	}

	public function test_has_publishable_status_field_is_false_without_a_status_arg(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'title' => [ 'type' => 'string' ],
				],
			],
		];

		$this->assertFalse( has_publishable_status_field( $handlers ) );
	}

	public function test_has_publishable_status_field_is_false_without_an_enum(): void {
		// Shaped like the REST comments endpoint's `status` arg: a plain
		// string with no enum, since `comment_approved` isn't set from this
		// param the same way a post's status is.
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'status' => [ 'type' => 'string' ],
				],
			],
		];

		$this->assertFalse( has_publishable_status_field( $handlers ) );
	}

	public function test_has_publishable_status_field_is_false_when_the_enum_lacks_publish(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'status' => [
						'type' => 'string',
						'enum' => [ 'open', 'closed' ],
					],
				],
			],
		];

		$this->assertFalse( has_publishable_status_field( $handlers ) );
	}

	public function test_has_publishable_status_field_checks_every_handler(): void {
		$handlers = [
			[
				'methods' => [ 'GET' => true ],
				'args'    => [],
			],
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'status' => [
						'type' => 'string',
						'enum' => [ 'draft', 'publish' ],
					],
				],
			],
		];

		$this->assertTrue( has_publishable_status_field( $handlers ) );
	}

	public function test_add_guidance_leaves_guidance_untouched_without_a_status_field(): void {
		$this->assertSame( 'Existing guidance.', add_guidance( 'Existing guidance.', '/wp/v2/foo', [] ) );
	}

	public function test_add_guidance_is_the_whole_string_when_nothing_else_applies(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'status' => [
						'type' => 'string',
						'enum' => [ 'draft', 'publish' ],
					],
				],
			],
		];

		$guidance = add_guidance( '', '/wp/v2/posts', $handlers );

		$this->assertStringContainsString( 'already defaults to draft', $guidance );
	}

	public function test_add_guidance_appends_to_guidance_already_assembled(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'status' => [
						'type' => 'string',
						'enum' => [ 'draft', 'publish' ],
					],
				],
			],
		];

		$guidance = add_guidance( 'This changes site settings or access.', '/wp/v2/posts', $handlers );

		$this->assertStringContainsString( 'This changes site settings or access.', $guidance );
		$this->assertStringContainsString( 'already defaults to draft', $guidance );
	}
}
