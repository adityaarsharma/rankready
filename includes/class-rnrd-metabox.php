<?php
/**
 * Post-edit meta boxes — AI Summary, AI FAQ, and AI Visibility.
 *
 * Registered only on the post types that enable each feature. Generate
 * controls use assets/metabox.js (REST); Display / exclude / snippet save
 * through save_post with per-box nonces so a missing box cannot clear meta.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Metabox {

	private const SETTINGS_SLUG = 'rankready-ai-llm-seo';

	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
		add_action( 'add_meta_boxes', array( self::class, 'register_postbox_classes' ), 20 );
		add_action( 'save_post',      array( self::class, 'save' ) );
	}

	/**
	 * Unique post-type slugs from one or more option lists.
	 *
	 * @param array<int,mixed> ...$lists
	 * @return string[]
	 */
	private static function unique_post_types( ...$lists ): array {
		$pts = array();
		foreach ( $lists as $list ) {
			foreach ( (array) $list as $pt ) {
				if ( is_string( $pt ) && '' !== $pt ) {
					$pts[ $pt ] = true;
				}
			}
		}
		return array_keys( $pts );
	}

	/**
	 * Union of every post type RankReady actually targets. Used by admin-asset
	 * enqueue so RankReady chrome never loads on CPTs with no RankReady UI.
	 *
	 * @return string[] List of post-type slugs.
	 */
	public static function get_post_types(): array {
		return self::unique_post_types(
			get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ),
			get_option( RNRD_OPT_FAQ_POST_TYPES, array( 'post' ) ),
			get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ),
			get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) )
		);
	}

	public static function register(): void {
		foreach ( self::unique_post_types( get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ) ) as $pt ) {
			add_meta_box(
				'rnrd_summary_meta',
				__( 'RankReady: AI Summary', 'rankready-ai-llm-seo' ),
				array( self::class, 'render_summary' ),
				$pt,
				'side',
				'default'
			);
		}

		foreach ( self::unique_post_types( get_option( RNRD_OPT_FAQ_POST_TYPES, array( 'post' ) ) ) as $pt ) {
			add_meta_box(
				'rnrd_faq_meta',
				__( 'RankReady: AI FAQ', 'rankready-ai-llm-seo' ),
				array( self::class, 'render_faq' ),
				$pt,
				'side',
				'default'
			);
		}

		foreach ( self::unique_post_types(
			get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ),
			get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) )
		) as $pt ) {
			add_meta_box(
				'rnrd_visibility_meta',
				__( 'RankReady: AI Visibility', 'rankready-ai-llm-seo' ),
				array( self::class, 'render_visibility' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Whether the current post-edit screen is the block editor.
	 *
	 * Classic Editor (plugin or per-post switch) stays false so those boxes
	 * keep WordPress's default open state.
	 */
	private static function is_block_editor_screen(): bool {
		if ( ! function_exists( 'use_block_editor_for_post' ) ) {
			return false;
		}
		$post = get_post();
		if ( $post instanceof WP_Post ) {
			return (bool) use_block_editor_for_post( $post );
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ! empty( $screen->is_block_editor );
	}

	/**
	 * Mark RankReady boxes so block-editor CSS can target them, and start
	 * them collapsed in Gutenberg (native document panels start closed).
	 */
	public static function register_postbox_classes(): void {
		$ids = array( 'rnrd_summary_meta', 'rnrd_faq_meta', 'rnrd_visibility_meta' );
		foreach ( self::get_post_types() as $pt ) {
			foreach ( $ids as $id ) {
				add_filter( "postbox_classes_{$pt}_{$id}", array( self::class, 'postbox_classes' ) );
			}
		}
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function postbox_classes( array $classes ): array {
		$classes[] = 'rnrd-postbox';
		if ( self::is_block_editor_screen() ) {
			$classes[] = 'closed';
		}
		return $classes;
	}

	/**
	 * True when Pro auto-generate-on-publish is actually live. The per-post
	 * "Disable AI summary on publish" checkbox is only meaningful then.
	 */
	private static function is_summary_autogen_enabled(): bool {
		return function_exists( 'rnrd_is_pro' ) && rnrd_is_pro()
			&& 'on' === get_option( RNRD_OPT_AUTO_GENERATE, 'off' );
	}

	/**
	 * HTML-page placement for the generate meta boxes.
	 *
	 * @param string $mode off|before|after|both
	 */
	private static function placement_label( bool $enabled, string $mode, string $shortcode ): string {
		if ( ! $enabled ) {
			return __( 'Hidden on frontend', 'rankready-ai-llm-seo' );
		}
		switch ( $mode ) {
			case 'before':
				return __( 'Auto-display: before content', 'rankready-ai-llm-seo' );
			case 'after':
				return __( 'Auto-display: after content', 'rankready-ai-llm-seo' );
			case 'both':
				return __( 'Auto-display: before and after content', 'rankready-ai-llm-seo' );
			case 'off':
			default:
				return sprintf(
					/* translators: %s: shortcode like [rankready_summary] */
					__( 'Manual: block, Elementor widget, or %s shortcode', 'rankready-ai-llm-seo' ),
					$shortcode
				);
		}
	}

	/**
	 * Fixed Markdown / OKF injection. Not tied to Auto Display.
	 *
	 * @param string $kind summary|faq
	 */
	private static function markdown_placement_label( string $kind ): string {
		return 'faq' === $kind
			? __( 'Always after the body', 'rankready-ai-llm-seo' )
			: __( 'Always before the body', 'rankready-ai-llm-seo' );
	}

	/**
	 * Short model name, e.g. "Claude Haiku 4.5".
	 */
	private static function model_short_label(): string {
		if ( ! class_exists( 'RNRD_LLM' ) ) {
			return '';
		}
		$provider    = RNRD_LLM::get_active_provider();
		$model_id    = RNRD_LLM::get_model( $provider );
		$models      = RNRD_LLM::get_models_for( $provider );
		$model_label = isset( $models[ $model_id ] ) ? (string) $models[ $model_id ] : $model_id;
		$short       = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', $model_label ) );
		return '' !== $short ? $short : $model_id;
	}

	/**
	 * Placement + model footer shared by the Summary and FAQ boxes.
	 * Collapsed by default; open when AI setup is missing so the warning is visible.
	 */
	private static function render_footer( bool $has_key, string $html_placement, string $md_placement, string $display_url ): void {
		$model_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=settings' );
		?>
		<details class="rnrd-mb__meta<?php echo $has_key ? '' : ' rnrd-mb__meta--warn'; ?>"<?php echo $has_key ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'Placement & model', 'rankready-ai-llm-seo' ); ?></summary>
			<div class="rnrd-mb__meta-body">
				<div class="rnrd-mb__meta-row">
					<span class="rnrd-mb__field-label"><?php esc_html_e( 'HTML', 'rankready-ai-llm-seo' ); ?></span>
					<p class="rnrd-mb__meta-v">
						<?php echo esc_html( $html_placement ); ?>
						<a href="<?php echo esc_url( $display_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Change →', 'rankready-ai-llm-seo' ); ?></a>
					</p>
				</div>
				<div class="rnrd-mb__meta-row">
					<span class="rnrd-mb__field-label"><?php esc_html_e( 'Markdown / OKF', 'rankready-ai-llm-seo' ); ?></span>
					<p class="rnrd-mb__meta-v"><?php echo esc_html( $md_placement ); ?></p>
				</div>
				<div class="rnrd-mb__meta-row<?php echo $has_key ? '' : ' rnrd-mb__meta-row--warn'; ?>">
					<span class="rnrd-mb__field-label"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></span>
					<?php if ( ! $has_key ) : ?>
						<p class="rnrd-mb__meta-v">
							<?php esc_html_e( 'AI setup needed. Add a provider and API key to generate.', 'rankready-ai-llm-seo' ); ?>
							<a href="<?php echo esc_url( $model_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Settings →', 'rankready-ai-llm-seo' ); ?></a>
						</p>
					<?php else : ?>
						<p class="rnrd-mb__meta-v">
							<?php echo esc_html( self::model_short_label() ); ?>
							<a href="<?php echo esc_url( $model_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Change →', 'rankready-ai-llm-seo' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</details>
		<?php
	}

	public static function render_summary( $post ): void {
		$disabled      = (bool) get_post_meta( $post->ID, RNRD_META_DISABLE, true );
		$summary       = (string) get_post_meta( $post->ID, RNRD_META_SUMMARY, true );
		$generated     = (int) get_post_meta( $post->ID, RNRD_META_GENERATED, true );
		$decoded       = class_exists( 'RNRD_Generator' ) ? RNRD_Generator::decode_summary( $summary ) : array( 'type' => 'empty', 'data' => array() );
		$summary_items = array();
		if ( 'bullets' === $decoded['type'] && ! empty( $decoded['data'] ) ) {
			$summary_items = array_values( (array) $decoded['data'] );
		} elseif ( 'text' === $decoded['type'] && '' !== trim( (string) $decoded['data'] ) ) {
			$summary_items = array( (string) $decoded['data'] );
		}
		$has_summary  = ! empty( $summary_items );
		$has_key      = class_exists( 'RNRD_LLM' ) && RNRD_LLM::active_provider_ready();
		$can_generate = $has_key && (int) $post->ID > 0;
		$summary_place = class_exists( 'RNRD_Summary' ) ? RNRD_Summary::get_auto_display() : 'off';
		$gen_label    = $has_summary ? __( 'Regenerate Summary', 'rankready-ai-llm-seo' ) : __( 'Generate Summary', 'rankready-ai-llm-seo' );
		$autogen_on   = self::is_summary_autogen_enabled();

		wp_nonce_field( 'rnrd_summary_meta', 'rnrd_summary_nonce' );
		?>
		<div class="rnrd-mb">
			<?php if ( $autogen_on && $disabled ) : ?>
				<p class="rnrd-mb__visibility-warn">
					<?php esc_html_e( 'AI summary is disabled on publish for this post.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( (int) $post->ID < 1 ) : ?>
				<p class="rnrd-mb__hint"><?php esc_html_e( 'Save the post first, then generate.', 'rankready-ai-llm-seo' ); ?></p>
			<?php endif; ?>

			<div
				class="rnrd-mb__gen-wrap"
				data-rnrd-mb-gen="summary"
				data-post-id="<?php echo esc_attr( (string) (int) $post->ID ); ?>"
				data-generated="<?php echo esc_attr( (string) $generated ); ?>"
				data-has-key="<?php echo $has_key ? '1' : '0'; ?>"
				data-type-enabled="1"
				data-has-content="<?php echo $has_summary ? '1' : '0'; ?>"
			>
				<p class="rnrd-mb__desc"><?php esc_html_e( 'Key Takeaways — the lines ChatGPT and Perplexity quote directly.', 'rankready-ai-llm-seo' ); ?></p>

				<details class="rnrd-mb__reveal"<?php echo $has_summary ? '' : ' hidden'; ?>>
					<summary><?php esc_html_e( 'Show summary', 'rankready-ai-llm-seo' ); ?></summary>
					<div class="rnrd-mb__preview">
						<ul data-rnrd-mb-list>
							<?php foreach ( $summary_items as $bullet ) : ?>
								<li><?php echo esc_html( $bullet ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</details>

				<button
					type="button"
					class="button button-secondary rnrd-mb__gen"
					data-rnrd-gen
					<?php disabled( ! $can_generate ); ?>
				><?php echo esc_html( $gen_label ); ?></button>

				<p class="rnrd-mb__error" hidden></p>

				<p class="rnrd-mb__hint rnrd-mb__generated"<?php echo $generated ? '' : ' hidden'; ?>>
					<?php
					if ( $generated ) {
						printf(
							/* translators: %s: human-readable age like "3 minutes" */
							esc_html__( 'Summary generated %s ago', 'rankready-ai-llm-seo' ),
							esc_html( human_time_diff( $generated ) )
						);
					}
					?>
				</p>
			</div>

			<?php if ( $autogen_on ) : ?>
				<div class="rnrd-mb__field rnrd-mb__opt">
					<label>
						<input type="checkbox" name="rnrd_disable_summary" value="1" <?php checked( $disabled ); ?> />
						<?php esc_html_e( 'Disable AI summary on publish', 'rankready-ai-llm-seo' ); ?>
					</label>
				</div>
			<?php endif; ?>

			<?php
			self::render_footer(
				$has_key,
				self::placement_label(
					class_exists( 'RNRD_Summary' ) && RNRD_Summary::is_enabled(),
					$summary_place,
					RNRD_Shortcode::tag( RNRD_Shortcode::SUMMARY )
				),
				self::markdown_placement_label( 'summary' ),
				admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=content&sub=summary' )
			);
			?>
		</div>
		<?php
	}

	public static function render_faq( $post ): void {
		$faq_items     = class_exists( 'RNRD_Faq' ) ? RNRD_Faq::get_faq_data( (int) $post->ID ) : array();
		$faq_items     = is_array( $faq_items ) ? $faq_items : array();
		$has_faq       = ! empty( $faq_items );
		$faq_generated = (int) get_post_meta( $post->ID, RNRD_META_FAQ_GENERATED, true );
		$has_key       = class_exists( 'RNRD_LLM' ) && RNRD_LLM::active_provider_ready();
		$can_generate  = $has_key && (int) $post->ID > 0;
		$faq_place     = class_exists( 'RNRD_Faq' ) ? RNRD_Faq::get_auto_display() : 'off';
		$gen_label     = $has_faq ? __( 'Regenerate FAQ', 'rankready-ai-llm-seo' ) : __( 'Generate FAQ', 'rankready-ai-llm-seo' );
		?>
		<div class="rnrd-mb">
			<?php if ( (int) $post->ID < 1 ) : ?>
				<p class="rnrd-mb__hint"><?php esc_html_e( 'Save the post first, then generate.', 'rankready-ai-llm-seo' ); ?></p>
			<?php endif; ?>

			<div
				class="rnrd-mb__gen-wrap"
				data-rnrd-mb-gen="faq"
				data-post-id="<?php echo esc_attr( (string) (int) $post->ID ); ?>"
				data-generated="<?php echo esc_attr( (string) $faq_generated ); ?>"
				data-has-key="<?php echo $has_key ? '1' : '0'; ?>"
				data-type-enabled="1"
				data-has-content="<?php echo $has_faq ? '1' : '0'; ?>"
			>
				<p class="rnrd-mb__desc"><?php esc_html_e( 'Discover real user questions and answer them with AI — quotable Q&A that can boost your citation rate.', 'rankready-ai-llm-seo' ); ?></p>

				<details class="rnrd-mb__reveal"<?php echo $has_faq ? '' : ' hidden'; ?>>
					<summary><?php esc_html_e( 'Show FAQ', 'rankready-ai-llm-seo' ); ?></summary>
					<div class="rnrd-mb__preview">
						<ul class="rnrd-mb__faq-list" data-rnrd-mb-list>
							<?php
							foreach ( $faq_items as $faq_row ) :
								$q = isset( $faq_row['question'] ) ? (string) $faq_row['question'] : '';
								$a = isset( $faq_row['answer'] ) ? wp_strip_all_tags( (string) $faq_row['answer'] ) : '';
								if ( '' === $q ) {
									continue;
								}
								?>
								<li>
									<strong><?php echo esc_html( $q ); ?></strong>
									<?php if ( '' !== $a ) : ?>
										<span class="rnrd-mb__faq-a"><?php echo esc_html( $a ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</details>

				<button
					type="button"
					class="button button-secondary rnrd-mb__gen"
					data-rnrd-gen
					<?php disabled( ! $can_generate ); ?>
				><?php echo esc_html( $gen_label ); ?></button>

				<p class="rnrd-mb__error" hidden></p>

				<p class="rnrd-mb__hint rnrd-mb__generated"<?php echo $faq_generated ? '' : ' hidden'; ?>>
					<?php
					if ( $faq_generated ) {
						printf(
							/* translators: %s: human-readable age like "3 minutes" */
							esc_html__( 'FAQ generated %s ago', 'rankready-ai-llm-seo' ),
							esc_html( human_time_diff( $faq_generated ) )
						);
					}
					?>
				</p>
			</div>

			<?php
			self::render_footer(
				$has_key,
				self::placement_label(
					class_exists( 'RNRD_Faq' ) && RNRD_Faq::is_enabled(),
					$faq_place,
					RNRD_Shortcode::tag( RNRD_Shortcode::FAQ )
				),
				self::markdown_placement_label( 'faq' ),
				admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=content&sub=faq' )
			);
			?>
		</div>
		<?php
	}

	public static function render_visibility( $post ): void {
		$llms_excluded   = '1' === (string) get_post_meta( $post->ID, RNRD_META_LLMS_EXCLUDE, true );
		$snippet_pref    = (string) get_post_meta( $post->ID, RNRD_META_MAX_SNIPPET, true );
		$snippet_default = 'on' === get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' );

		wp_nonce_field( 'rnrd_visibility_meta', 'rnrd_visibility_nonce' );
		?>
		<div class="rnrd-mb">
			<?php if ( $llms_excluded ) : ?>
				<p class="rnrd-mb__visibility-warn">
					<?php esc_html_e( 'This post is excluded from AI surfaces.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php endif; ?>

			<div class="rnrd-mb__field">
				<label>
					<input type="checkbox" name="rnrd_llms_exclude" value="1" <?php checked( $llms_excluded ); ?> />
					<?php esc_html_e( 'Exclude this post from AI surfaces (llms.txt, Markdown, WebMCP, OKF)', 'rankready-ai-llm-seo' ); ?>
				</label>
			</div>

			<div class="rnrd-mb__field">
				<label class="rnrd-mb__field-label" for="rnrd_max_snippet"><?php esc_html_e( 'AI snippet', 'rankready-ai-llm-seo' ); ?></label>
				<select name="rnrd_max_snippet" id="rnrd_max_snippet">
					<option value="" <?php selected( $snippet_pref, '' ); ?>>
						<?php
						printf(
						/* translators: %s: name of the default summary mode */
							esc_html__( 'Use default (%s)', 'rankready-ai-llm-seo' ),
							$snippet_default ? esc_html__( 'Allow full snippet', 'rankready-ai-llm-seo' ) : esc_html__( 'Standard snippet', 'rankready-ai-llm-seo' )
						);
						?>
					</option>
					<option value="on" <?php selected( $snippet_pref, 'on' ); ?>>
						<?php esc_html_e( 'Allow full snippet (max-snippet:-1)', 'rankready-ai-llm-seo' ); ?>
					</option>
					<option value="off" <?php selected( $snippet_pref, 'off' ); ?>>
						<?php esc_html_e( 'Standard snippet only', 'rankready-ai-llm-seo' ); ?>
					</option>
				</select>
				<p class="rnrd-mb__hint"><?php esc_html_e( 'How much of this page AI engines may quote.', 'rankready-ai-llm-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	public static function save( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$summary_ok = isset( $_POST['rnrd_summary_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rnrd_summary_nonce'] ) ), 'rnrd_summary_meta' );
		$vis_ok     = isset( $_POST['rnrd_visibility_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rnrd_visibility_nonce'] ) ), 'rnrd_visibility_meta' );

		if ( ! $summary_ok && ! $vis_ok ) {
			return;
		}

		if ( $summary_ok && self::is_summary_autogen_enabled() ) {
			$disabled = isset( $_POST['rnrd_disable_summary'] ) ? '1' : '';
			update_post_meta( $post_id, RNRD_META_DISABLE, $disabled );
		}

		if ( $vis_ok ) {
			$llms_excluded = isset( $_POST['rnrd_llms_exclude'] ) ? '1' : '';
			update_post_meta( $post_id, RNRD_META_LLMS_EXCLUDE, $llms_excluded );

			$snippet = isset( $_POST['rnrd_max_snippet'] ) ? sanitize_key( wp_unslash( $_POST['rnrd_max_snippet'] ) ) : '';
			if ( ! in_array( $snippet, array( '', 'on', 'off' ), true ) ) {
				$snippet = '';
			}
			if ( '' === $snippet ) {
				delete_post_meta( $post_id, RNRD_META_MAX_SNIPPET );
			} else {
				update_post_meta( $post_id, RNRD_META_MAX_SNIPPET, $snippet );
			}
		}
	}
}
