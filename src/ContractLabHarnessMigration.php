<?php
/**
 * Versioned migration inventory for the former rendering harness.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Proves semantic migration coverage before the standalone harness is retired.
 *
 * The inventory is maintainer evidence only. It records the authoritative
 * source revision and each legacy test identity, but it does not load wp-env,
 * copy test fixtures, or preserve a second rendering runtime.
 */
final class ContractLabHarnessMigration {

	public const MIGRATION_VERSION = '1';

	private const SOURCE_REPOSITORY = 'honestlydesign/etch-builders-rendering-tests';

	private const SOURCE_REVISION = '3f2eb0834df421169baf653f76218a1e4292719a';

	private const SOURCE_TEST_COUNT = 221;

	/** @var array<int, string> */
	private const PROFILE_IDS = array( 'base' );

	/**
	 * The authoritative main-branch test inventory at SOURCE_REVISION. Test
	 * method granularity remains auditable while the maintained contract uses
	 * named semantic outcomes instead of one runtime test per method.
	 *
	 * @var array<string, array{outcome: string, tests: string}>
	 */
	private const CURRENT_SUITES = array(
		'ComponentBlockRenderingTest' => array(
			'outcome' => 'component-style-handoff',
			'tests'   => 'test_new_creates_component_block|test_ref_sets_component_id|test_ref_by_key_resolves_to_ref|test_attribute_sets_single_attribute|test_attributes_sets_multiple|test_json_attribute_serializes_array|test_prop_string_sets_value|test_prop_boolean_sets_value|test_prop_expression_sets_dynamic_value|test_prop_raw_sets_value|test_prop_class_resolves_to_style_ids|test_prop_class_passes_dynamic_token|test_prop_class_passes_runtime_token|test_prop_object_sets_complex_value|test_register_style_adds_style|test_with_empty_default_slot_sets_flag|test_with_empty_slot_named|test_component_block_with_multiple_props',
		),
		'ComponentRenderingTest' => array(
			'outcome' => 'component-style-handoff',
			'tests'   => 'test_new_creates_component|test_key_sets_identifier|test_dev_only_sets_flag|test_dev_only_false_by_default|test_should_skip_registration_when_dev_only_in_prod|test_blocks_sets_content|test_get_properties_returns_array|test_add_style_registers_style|test_add_style_does_not_force_readonly|test_enqueue_style_registers_asset|test_enqueue_script_registers_asset|test_register_stylesheets_does_not_crash',
		),
		'ConditionBlockRenderingTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_new_creates_condition_block|test_condition_with_truthy_string_renders_children|test_condition_with_falsy_string_hides_children|test_condition_string_renders_children|test_condition_operator_renders_children|test_condition_not_equal_renders_when_different|test_condition_greater_than_renders|test_condition_is_truthy_renders|test_condition_is_falsy_hides|test_condition_with_multiple_children|test_nested_conditions|test_condition_no_condition_attribute_renders_children',
		),
		'DynamicElementBlockRenderingTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_new_with_tag_div|test_tag_sets_html_tag|test_tag_section|test_tag_span|test_tag_header_footer|test_attribute_sets_a_single_attribute|test_attribute_sets_data_attribute|test_attribute_sets_alpine_directive|test_attributes_sets_multiple|test_style_attaches_a_style_id|test_styles_attaches_multiple|test_dynamic_tag_serialization|test_dynamic_tag_with_content|test_nested_dynamic_elements|test_dynamic_element_with_text_child|test_dynamic_element_with_linked_styles',
		),
		'DynamicImageBlockRenderingTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_new_creates_dynamic_image_block|test_attribute_sets_alt|test_attribute_sets_class|test_attributes_sets_multiple|test_media_id_sets_attachment_id|test_media_id_string_value|test_use_srcset_enables|test_use_srcset_disables|test_maximum_size_sets_value|test_maximum_size_thumbnail|test_style_attaches_a_style_id|test_styles_attaches_multiple|test_image_inside_element|test_image_with_all_attributes',
		),
		'ElementBlockRenderingTest' => array(
			'outcome' => 'block-wire-round-trip-core',
			'tests'   => 'test_tag_div_renders|test_tag_article_renders|test_tag_section_renders|test_tag_span_renders|test_tag_void_img_renders|test_tag_void_br_renders|test_attribute_sets_a_single_attribute|test_attribute_sets_data_attribute|test_attribute_null_value_omits_attribute|test_attributes_sets_multiple_via_attributes_factory|test_class_adds_a_single_class|test_classes_adds_multiple_classes|test_class_links_style_for_emission|test_content_adds_text_child|test_child_adds_a_nested_block|test_children_adds_multiple_nested_blocks|test_raw_content_adds_html_directly|test_style_attaches_a_style_id|test_styles_attaches_multiple_style_ids|test_json_attribute_serializes_array_as_json|test_is_etch_section_attaches_section_style|test_is_etch_section_container_attaches_container_style|test_metadata_sets_custom_data|test_metadata_name_sets_element_name|test_hidden_renders_differently|test_script_registers_inline_javascript|test_option_sets_a_custom_option|test_nested_section_with_container_and_children|test_deeply_nested_elements_render_in_order|test_class_with_compound_selector_renders|test_dynamic_attribute_value_serializes_correctly',
		),
		'LoopBlockRenderingTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_new_creates_loop_block|test_target_main_query|test_target_dynamic_expression|test_loop_id_with_registered_preset|test_item_id_sets_context_key|test_index_id_sets_context_key|test_param_sets_single_param|test_params_sets_multiple|test_loop_with_seeded_posts|test_loop_with_json_data|test_nested_loops|test_loop_with_condition_child',
		),
		'LoopPresetRenderingTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_new_with_wp_query|test_new_with_wp_terms|test_new_with_wp_users|test_new_with_json|test_new_with_main_query|test_id_sets_preset_id|test_global_sets_flag|test_overwrite_sets_flag|test_to_array_returns_config|test_register_internal_adds_to_registry|test_registered_keys_lists_all|test_is_registered_key_false_for_unknown|test_snapshot_captures_registry|test_restore_replaces_registry|test_reset_clears_registry|test_register_persists_to_option',
		),
		'PatternRenderingTest' => array(
			'outcome' => 'component-style-handoff',
			'tests'   => 'test_new_creates_pattern|test_key_sets_identifier|test_category_sets_single_category|test_categories_sets_multiple|test_blocks_sets_content|test_add_blocks_sets_content|test_add_style_registers_style|test_register_stylesheets_does_not_crash|test_reset_styles_clears_styles',
		),
		'RawHtmlBlockRenderingTest' => array(
			'outcome' => 'block-wire-round-trip-core',
			'tests'   => 'test_new_creates_a_raw_html_block|test_content_renders_html_markup|test_content_renders_complex_html|test_content_empty_string|test_content_with_scripts|test_content_with_attributes|test_raw_html_inside_element|test_raw_html_with_dynamic_expression_serialization|test_raw_html_self_closing_tags',
		),
		'RenderingInfrastructureTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_renders_element_with_linked_style_emission|test_seed_post_creates_a_published_post|test_seed_term_creates_a_category|test_seed_user_creates_an_editor|test_tear_down_cleans_up_seeded_data',
		),
		'SlotContentBlockRenderingTest' => array(
			'outcome' => 'component-style-handoff',
			'tests'   => 'test_slot_content_new|test_slot_content_name_default|test_slot_content_name_custom|test_slot_content_with_child|test_slot_content_renders|test_slot_placeholder_new|test_slot_placeholder_name_default|test_slot_placeholder_name_custom|test_slot_placeholder_renders|test_slot_content_inside_component_block',
		),
		'SmokeTest' => array(
			'outcome' => 'frontend-core-composite',
			'tests'   => 'test_etch_element_renders_non_empty_html|test_environment_is_configured_with_wp_storage|test_etch_plugin_is_active',
		),
		'StyleRenderingTest' => array(
			'outcome' => 'component-style-handoff',
			'tests'   => 'test_class_style_emits_in_style_tag|test_id_style_emits_with_hash_selector|test_element_style_emits_with_where_selector|test_bem_child_ampersand_expands|test_bem_modifier_ampersand_expands|test_hover_pseudo_class_emitted|test_media_query_emitted|test_to_rem_function_converts|test_to_rem_various_values|test_type_class_renders_dot_selector|test_type_attribute_emitted_as_mandatory|test_collection_field_stored|test_name_field_sets_display_name|test_readonly_flag_stored|test_not_readonly_by_default|test_overwrite_on_register_replaces_existing|test_multiple_styles_ids_emit_all|test_empty_css_body_not_emitted',
		),
		'StylesheetRenderingTest' => array(
			'outcome' => 'component-style-handoff',
			'tests'   => 'test_new_creates_a_stylesheet|test_css_sets_stylesheet_content|test_name_sets_display_name|test_css_file_loads_from_path|test_overwrite_sets_flag|test_dev_only_sets_flag|test_dev_only_false_by_default|test_to_array_returns_all_fields|test_register_references_succeeds|test_register_references_empty_prunes_owner|test_register_custom_media_adds_name|test_declared_custom_media_returns_all|test_custom_media_snapshot_and_restore|test_reset_custom_media_clears_all|test_reset_active_owner_keys_does_not_crash',
		),
		'SvgBlockRenderingTest' => array(
			'outcome' => 'block-wire-round-trip-core',
			'tests'   => 'test_new_creates_svg_block|test_attribute_sets_src|test_attribute_sets_viewbox|test_attribute_sets_fill|test_attributes_sets_multiple|test_metadata_sets_custom_data|test_metadata_name_sets_element_name|test_style_attaches_a_style_id|test_styles_attaches_multiple|test_is_ide_etch_placeholder_sets_metadata|test_svg_inside_element',
		),
		'TextBlockRenderingTest' => array(
			'outcome' => 'block-wire-round-trip-core',
			'tests'   => 'test_new_creates_a_text_block|test_content_renders_plain_text|test_content_with_html_entities|test_content_empty_string|test_content_multiline|test_content_with_special_chars|test_text_block_inside_element|test_text_block_with_dynamic_expression_serialization|test_text_block_with_modifier_expression_serialization|test_text_block_renders_wp_shortcodes',
		),
	);

	/** @var array<string, string> */
	private const RETIRED_TESTS = array(
		'ComponentRenderingTest::test_register_stylesheets_does_not_crash' => 'A no-crash helper assertion is not a public integration outcome; package coverage remains the authority.',
		'ElementBlockRenderingTest::test_script_registers_inline_javascript' => 'The legacy assertion covers inline JavaScript, not the supported file-based marker flow; it is not claimed as JavaScript parity.',
		'PatternRenderingTest::test_register_stylesheets_does_not_crash' => 'A no-crash helper assertion is not a public integration outcome; package coverage remains the authority.',
		'RenderingInfrastructureTest::test_seed_post_creates_a_published_post' => 'WordPress test-data seeding is harness infrastructure, not a Builder-caused compatibility outcome.',
		'RenderingInfrastructureTest::test_seed_term_creates_a_category' => 'WordPress test-data seeding is harness infrastructure, not a Builder-caused compatibility outcome.',
		'RenderingInfrastructureTest::test_seed_user_creates_an_editor' => 'WordPress test-data seeding is harness infrastructure, not a Builder-caused compatibility outcome.',
		'RenderingInfrastructureTest::test_tear_down_cleans_up_seeded_data' => 'Harness teardown bookkeeping is not part of the public Builder-to-Etch contract.',
		'SmokeTest::test_environment_is_configured_with_wp_storage' => 'Environment wiring is covered by Contract Lab doctor and adapters, not a rendering fixture.',
		'SmokeTest::test_etch_plugin_is_active' => 'Plugin activation is a Contract Lab profile prerequisite, not a semantic rendering outcome.',
		'StylesheetRenderingTest::test_reset_active_owner_keys_does_not_crash' => 'A no-crash cache helper assertion is not a public integration outcome; package coverage remains the authority.',
	);

	/**
	 * @param array<int, string>                          $profile_ids
	 * @param array<int, ContractLabHarnessOutcome>      $outcomes
	 * @param array<int, ContractLabHarnessCase>         $cases
	 */
	private function __construct(
		private readonly string $source_repository,
		private readonly string $source_revision,
		private readonly int $source_test_count,
		private readonly array $profile_ids,
		private readonly array $outcomes,
		private readonly array $cases
	) {
	}

	/**
	 * Build and validate one complete migration inventory.
	 *
	 * @param array<int, string>                     $profile_ids
	 * @param array<int, ContractLabHarnessOutcome> $outcomes
	 * @param array<int, ContractLabHarnessCase>    $cases
	 */
	public static function new(
		string $source_repository,
		string $source_revision,
		int $source_test_count,
		array $profile_ids,
		array $outcomes,
		array $cases
	): self {
		if ( self::SOURCE_REPOSITORY !== $source_repository || 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/D', $source_repository ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness source repository must be the canonical repository identity.' );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{7,64}$/D', $source_revision ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness source revision must be a hexadecimal commit identifier.' );
		}
		if ( $source_test_count < 1 ) {
			throw new InvalidArgumentException( 'Contract Lab harness source test count must be positive.' );
		}
		$profile_ids = self::validate_profile_ids( $profile_ids );
		if ( ! in_array( 'base', $profile_ids, true ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness migration requires the named base Contract Profile.' );
		}
		if ( array() === $outcomes || array() === $cases ) {
			throw new InvalidArgumentException( 'Contract Lab harness migration requires outcomes and an inventory of cases.' );
		}

		$outcome_by_id = array();
		foreach ( $outcomes as $outcome ) {
			if ( ! $outcome instanceof ContractLabHarnessOutcome ) {
				throw new InvalidArgumentException( 'Contract Lab harness migration outcomes must contain outcome values.' );
			}
			if ( isset( $outcome_by_id[ $outcome->id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness migration has duplicate outcome ID "%s".', $outcome->id() ) );
			}
			if ( ! in_array( $outcome->profile_id(), $profile_ids, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness outcome "%s" references an unknown Contract Profile "%s".', $outcome->id(), $outcome->profile_id() ) );
			}
			$outcome_by_id[ $outcome->id() ] = $outcome;
		}

		$case_ids       = array();
		$profile_usage  = array();
		foreach ( $cases as $case ) {
			if ( ! $case instanceof ContractLabHarnessCase ) {
				throw new InvalidArgumentException( 'Contract Lab harness migration cases must contain case values.' );
			}
			if ( isset( $case_ids[ $case->source_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness migration has duplicate source test "%s".', $case->source_id() ) );
			}
			$case_ids[ $case->source_id() ] = true;
			if ( $case->is_retained_contract() ) {
				$outcome = $outcome_by_id[ (string) $case->outcome_id() ] ?? null;
				if ( null === $outcome ) {
					throw new InvalidArgumentException( sprintf( 'Retained Contract Lab harness case "%s" references an unknown outcome.', $case->source_id() ) );
				}
				$profile_usage[ $outcome->profile_id() ] = true;
			}
		}
		if ( count( $cases ) !== $source_test_count ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab harness inventory count %d does not match source test count %d.', count( $cases ), $source_test_count ) );
		}

		return new self( $source_repository, $source_revision, $source_test_count, $profile_ids, array_values( $outcomes ), array_values( $cases ) );
	}

	/**
	 * Return the complete main-branch migration baseline.
	 */
	public static function current(): self {
		$outcomes = array(
			ContractLabHarnessOutcome::new( 'runtime-shape-core', 'base', array( 'runtime-shape' ) ),
			ContractLabHarnessOutcome::new( 'block-wire-round-trip-core', 'base', array( 'block-wire-round-trip' ) ),
			ContractLabHarnessOutcome::new( 'component-style-handoff', 'base', array( 'persistence-handoff', 'block-wire-round-trip' ) ),
			ContractLabHarnessOutcome::new( 'frontend-core-composite', 'base', array( 'frontend-composite' ), array( 'marketing-home' ) ),
			ContractLabHarnessOutcome::new( 'browser-save-document', 'base', array( 'browser-preservation' ), array(), array( 'document-preservation' ) ),
			ContractLabHarnessOutcome::new( 'browser-save-component', 'base', array( 'browser-preservation' ), array(), array( 'component-preservation' ) ),
			ContractLabHarnessOutcome::new( 'browser-save-pattern', 'base', array( 'browser-preservation' ), array(), array( 'pattern-preservation' ) ),
			ContractLabHarnessOutcome::new( 'browser-save-global-asset', 'base', array( 'browser-preservation' ), array(), array( 'global-asset-preservation' ) ),
			ContractLabHarnessOutcome::new( 'javascript-marketing-ready', 'base', array( 'javascript-marker' ), array( 'marketing-home' ), array(), 'marketing-ready' ),
		);
		$cases = array();
		foreach ( self::CURRENT_SUITES as $suite => $declaration ) {
			$tests = explode( '|', $declaration['tests'] );
			foreach ( $tests as $test ) {
				$key = $suite . '::' . $test;
				$cases[] = isset( self::RETIRED_TESTS[ $key ] )
					? ContractLabHarnessCase::retired( $suite, $test, self::RETIRED_TESTS[ $key ] )
					: ContractLabHarnessCase::retained_contract( $suite, $test, $declaration['outcome'] );
			}
		}

		return self::new( self::SOURCE_REPOSITORY, self::SOURCE_REVISION, self::SOURCE_TEST_COUNT, self::PROFILE_IDS, $outcomes, $cases );
	}

	/**
	 * Rehydrate and validate a canonical migration projection.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$expected = array( 'cases', 'migration_version', 'outcomes', 'profile_ids', 'source_repository', 'source_revision', 'source_test_count' );
		$actual   = array_keys( $record );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new InvalidArgumentException( 'Contract Lab harness migration has an unknown or missing field.' );
		}
		if ( self::MIGRATION_VERSION !== ( $record['migration_version'] ?? null ) || ! is_string( $record['source_repository'] ?? null ) || ! is_string( $record['source_revision'] ?? null ) || ! is_int( $record['source_test_count'] ?? null ) || ! is_array( $record['profile_ids'] ?? null ) || ! is_array( $record['outcomes'] ?? null ) || ! is_array( $record['cases'] ?? null ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness migration has an invalid field shape or version.' );
		}
		$outcomes = array();
		foreach ( $record['outcomes'] as $outcome ) {
			if ( ! is_array( $outcome ) ) {
				throw new InvalidArgumentException( 'Contract Lab harness migration outcomes must be object records.' );
			}
			$outcomes[] = ContractLabHarnessOutcome::from_array( $outcome );
		}
		$cases = array();
		foreach ( $record['cases'] as $case ) {
			if ( ! is_array( $case ) ) {
				throw new InvalidArgumentException( 'Contract Lab harness migration cases must be object records.' );
			}
			$cases[] = ContractLabHarnessCase::from_array( $case );
		}

		$migration = self::new( $record['source_repository'], $record['source_revision'], $record['source_test_count'], $record['profile_ids'], $outcomes, $cases );
		if ( $migration->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab harness migration must be canonical.' );
		}

		return $migration;
	}

	public function source_repository(): string {
		return $this->source_repository;
	}

	public function source_revision(): string {
		return $this->source_revision;
	}

	public function source_test_count(): int {
		return $this->source_test_count;
	}

	/**
	 * @return array<int, string>
	 */
	public function profile_ids(): array {
		return $this->profile_ids;
	}

	/**
	 * @return array<int, ContractLabHarnessCase>
	 */
	public function cases(): array {
		return $this->cases;
	}

	/**
	 * @return array{migration_version: string, source_repository: string, source_revision: string, source_test_count: int, inventoried_test_count: int, retained_contract_count: int, retired_count: int, outcome_count: int, status: string}
	 */
	public function parity(): array {
		$retained = count( array_filter( $this->cases, static fn ( ContractLabHarnessCase $case ): bool => $case->is_retained_contract() ) );
		$retired  = count( $this->cases ) - $retained;

		return array(
			'migration_version'       => self::MIGRATION_VERSION,
			'source_repository'       => $this->source_repository,
			'source_revision'         => $this->source_revision,
			'source_test_count'       => $this->source_test_count,
			'inventoried_test_count'  => count( $this->cases ),
			'retained_contract_count' => $retained,
			'retired_count'           => $retired,
			'outcome_count'           => count( $this->outcomes ),
			'status'                  => count( $this->cases ) === $this->source_test_count ? 'complete' : 'incomplete',
		);
	}

	/**
	 * Confirm every declared outcome reuses the current composite seams.
	 *
	 * @param array<string, ContractLabIntegrationOutcome> $outcomes
	 */
	public function assert_contract_surface(
		ContractLabFrontendFixtureCatalog $fixtures,
		ContractLabBrowserSentinelCatalog $sentinels,
		array $outcomes,
		ContractLabJavascriptMarker $javascript_marker
	): void {
		foreach ( $this->outcomes as $outcome ) {
			$outcome->assert_contract_surface( $fixtures, $sentinels, $outcomes, $javascript_marker );
		}
	}

	/**
	 * Confirm that every migrated outcome points at an actual manifest profile.
	 */
	public function assert_manifest_profiles( ContractLabManifest $manifest ): void {
		$profile_ids = array_map(
			static fn ( ContractLabProfile $profile ): string => $profile->id(),
			$manifest->profiles()
		);
		foreach ( $this->profile_ids as $profile_id ) {
			if ( ! in_array( $profile_id, $profile_ids, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness migration references a profile absent from the manifest: "%s".', $profile_id ) );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'migration_version'  => self::MIGRATION_VERSION,
			'source_repository'  => $this->source_repository,
			'source_revision'    => $this->source_revision,
			'source_test_count'  => $this->source_test_count,
			'profile_ids'        => $this->profile_ids,
			'outcomes'           => array_map( static fn ( ContractLabHarnessOutcome $outcome ): array => $outcome->to_array(), $this->outcomes ),
			'cases'              => array_map( static fn ( ContractLabHarnessCase $case ): array => $case->to_array(), $this->cases ),
		);
	}

	/**
	 * @param array<int, mixed> $profile_ids
	 * @return array<int, string>
	 */
	private static function validate_profile_ids( array $profile_ids ): array {
		if ( array() === $profile_ids || ! array_is_list( $profile_ids ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness migration profiles must be a non-empty ordered list.' );
		}
		$validated = array();
		$seen      = array();
		foreach ( $profile_ids as $profile_id ) {
			if ( ! is_string( $profile_id ) ) {
				throw new InvalidArgumentException( 'Contract Lab harness migration profile IDs must be strings.' );
			}
			ContractLabManifestSafety::assert_stable_id( $profile_id, 'Contract Lab harness migration profile ID' );
			if ( isset( $seen[ $profile_id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness migration has duplicate profile ID "%s".', $profile_id ) );
			}
			$seen[ $profile_id ] = true;
			$validated[] = $profile_id;
		}

		return $validated;
	}
}
