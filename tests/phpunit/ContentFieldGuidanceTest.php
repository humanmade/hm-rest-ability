<?php
/**
 * Unit tests for inc/content-field-guidance.php.
 */

namespace HM\RestAbility\Tests;

use function HM\ContentFieldGuidance\add_guidance;
use function HM\ContentFieldGuidance\has_block_content_field;

class ContentFieldGuidanceTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/content-field-guidance.php' );
	}

	public function test_has_block_content_field_is_true_for_editor_backed_post_content(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'content' => [
						'type'       => 'object',
						'properties' => [
							'raw'           => [ 'type' => 'string' ],
							'rendered'      => [ 'type' => 'string' ],
							'block_version' => [ 'type' => 'integer' ],
							'protected'     => [ 'type' => 'boolean' ],
						],
					],
				],
			],
		];

		$this->assertTrue( has_block_content_field( $handlers ) );
	}

	public function test_has_block_content_field_is_false_without_a_content_arg(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'title' => [ 'type' => 'string' ],
				],
			],
		];

		$this->assertFalse( has_block_content_field( $handlers ) );
	}

	public function test_has_block_content_field_is_false_without_block_version(): void {
		// Shaped like the REST comments endpoint's `content` arg: an object
		// with raw and rendered, but no block_version, because a comment
		// isn't edited in the block editor.
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'content' => [
						'type'       => 'object',
						'properties' => [
							'raw'      => [ 'type' => 'string' ],
							'rendered' => [ 'type' => 'string' ],
						],
					],
				],
			],
		];

		$this->assertFalse( has_block_content_field( $handlers ) );
	}

	public function test_has_block_content_field_is_false_for_a_plain_string_content_arg(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'content' => [ 'type' => 'string' ],
				],
			],
		];

		$this->assertFalse( has_block_content_field( $handlers ) );
	}

	public function test_has_block_content_field_checks_every_handler(): void {
		$handlers = [
			[
				'methods' => [ 'GET' => true ],
				'args'    => [],
			],
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'content' => [
						'type'       => 'object',
						'properties' => [
							'block_version' => [ 'type' => 'integer' ],
						],
					],
				],
			],
		];

		$this->assertTrue( has_block_content_field( $handlers ) );
	}

	public function test_add_guidance_leaves_guidance_untouched_without_a_content_field(): void {
		$this->assertSame( 'Existing guidance.', add_guidance( 'Existing guidance.', '/wp/v2/foo', [] ) );
	}

	public function test_add_guidance_is_the_whole_string_when_nothing_else_applies(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'content' => [
						'type'       => 'object',
						'properties' => [
							'block_version' => [ 'type' => 'integer' ],
						],
					],
				],
			],
		];

		$guidance = add_guidance( '', '/wp/v2/posts', $handlers );

		$this->assertStringContainsString( 'holds block markup', $guidance );
	}

	public function test_add_guidance_appends_to_guidance_already_assembled(): void {
		$handlers = [
			[
				'methods' => [ 'POST' => true ],
				'args'    => [
					'content' => [
						'type'       => 'object',
						'properties' => [
							'block_version' => [ 'type' => 'integer' ],
						],
					],
				],
			],
		];

		$guidance = add_guidance( 'This cannot be undone.', '/wp/v2/posts', $handlers );

		$this->assertStringContainsString( 'This cannot be undone.', $guidance );
		$this->assertStringContainsString( 'holds block markup', $guidance );
	}
}
