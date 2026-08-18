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
	 * True when Pro auto-generate-on-publish is actually live. The per-post
	 * "Disable AI summary on publish" checkbox is only meaningful then.
	 */
	private static function is_summary_autogen_enabled(): bool {
		return function_exists( 'rnrd_is_pro' ) && rnrd_is_pro()
			&& 'on' === get_option( RNRD_OPT_AUTO_GENERATE, 'off' );
	}

	/**
	 * One-line frontend placement for the generate meta boxes.
	 */
	private static function placement_label( bool $auto, string $position, string $shortcode ): string {
		if ( ! $auto ) {
			return sprintf(
				/* translators: %s: shortcode like [rankready_summary] */
				__( 'Manual: block, Elementor widget, or %s shortcode', 'rankready-ai-llm-seo' ),
				$shortcode
			);
		}
		return 'after' === $position
			? __( 'Auto-display: after content', 'rankready-ai-llm-seo' )
			: __( 'Auto-display: before content', 'rankready-ai-llm-seo' );
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
	 * Display + current model footer shared by the Summary and FAQ boxes.
	 */
	private static function render_footer( bool $has_key, string $placement, string $display_url ): void {
		$model_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=settings' );
		?>
		<div class="rnrd-mb__meta">
			<div class="rnrd-mb__meta-row">
				<span class="rnrd-mb__field-label"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></span>
				<p class="rnrd-mb__meta-v">
					<?php echo esc_html( $placement ); ?>
					<a href="<?php echo esc_url( $display_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Change →', 'rankready-ai-llm-seo' ); ?></a>
				</p>
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
		$summary_auto = 'on' === (string) get_option( RNRD_OPT_AUTO_DISPLAY, 'off' );
		$summary_pos  = (string) get_option( RNRD_OPT_DISPLAY_POSITION, 'before' );
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
				self::placement_label( $summary_auto, $summary_pos, RNRD_Shortcode::tag( RNRD_Shortcode::SUMMARY ) ),
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
		$faq_auto      = 'on' === (string) get_option( RNRD_OPT_FAQ_AUTO_DISPLAY, 'off' );
		$faq_pos       = (string) get_option( RNRD_OPT_FAQ_POSITION, 'after' );
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
				self::placement_label( $faq_auto, $faq_pos, RNRD_Shortcode::tag( RNRD_Shortcode::FAQ ) ),
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
