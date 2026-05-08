<?php
/**
 * Author Box — profile fields, Person schema, renderers.
 *
 * Every profile field mapped here emits Schema.org Person data for EEAT.
 * When a major SEO plugin is active, the Person data is merged into its
 * existing schema graph via the filters in class-rr-block.php (rank_math/json_ld,
 * wpseo_schema_graph, aioseo_schema_output, …). When no SEO plugin is active,
 * this class emits a standalone Person node inline in Article.author on singular
 * views, plus an additional node on is_author() archive pages via wp_head.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Author_Box {

	// ── All profile field meta keys (user meta) ──────────────────────────────
	// Kept as a single map so register_meta(), render(), schema build, and
	// uninstall all read from the same source of truth.
	const META_KEYS = array(
		// Identity & work.
		'rr_author_job_title',
		'rr_author_employer',
		'rr_author_employer_url',
		'rr_author_bio',
		'rr_author_headshot',
		'rr_author_headshot_alt',
		// Experience.
		'rr_author_started_year',
		'rr_author_expertise',
		// Credentials.
		'rr_author_credentials_suffix',
		'rr_author_education',          // repeater JSON
		'rr_author_certifications',     // repeater JSON
		'rr_author_memberships',        // repeater JSON
		'rr_author_awards',             // repeater JSON
		// Verified identity (priority sameAs).
		'rr_author_wikidata',
		'rr_author_wikipedia',
		'rr_author_orcid',
		'rr_author_scholar',
		'rr_author_linkedin',
		// Social (lower priority sameAs).
		'rr_author_github',
		'rr_author_youtube',
		'rr_author_twitter',
		'rr_author_website',
		// Contact.
		'rr_author_contact_url',
	);

	// ── Init ──────────────────────────────────────────────────────────────────

	public static function init(): void {
		// Register all user meta as single, REST-exposed so block editor can read them.
		add_action( 'init', array( self::class, 'register_user_meta' ) );

		// Register per-post meta keys (fact-checked / reviewed / last reviewed / disable).
		add_action( 'init', array( self::class, 'register_post_meta' ) );

		// Profile page — the "RankReady Author Box" section on user-edit.php.
		add_action( 'show_user_profile',        array( self::class, 'render_profile_fields' ), 20 );
		add_action( 'edit_user_profile',        array( self::class, 'render_profile_fields' ), 20 );
		add_action( 'personal_options_update',  array( self::class, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_fields' ) );

		// Block + widget — register server-side renderer (block registered in class-rr-block.php).
		// Auto-display via the_content.
		add_filter( 'the_content', array( self::class, 'maybe_auto_display' ), 101 );

		// Standalone Person schema on is_author() archives (wp_head — no visible template override).
		add_action( 'wp_head', array( self::class, 'maybe_inject_archive_person' ), 2 );

		// Merge into SEO plugin Article.author — upgrade the Person with sameAs / knowsAbout / credentials.
		// Covers: Rank Math, Yoast SEO, AIOSEO, SEOPress (free + Pro), The SEO Framework, Slim SEO.
		add_filter( 'rank_math/json_ld',                   array( self::class, 'merge_into_rankmath' ), 100, 2 );
		add_filter( 'wpseo_schema_graph',                  array( self::class, 'merge_into_yoast' ),    100 );
		add_filter( 'aioseo_schema_output',                array( self::class, 'merge_into_aioseo' ),   100 );
		add_filter( 'seopress_schemas_auto_article_json',  array( self::class, 'merge_into_seopress' ), 100 );
		add_filter( 'seopress_pro_get_json_data_article',  array( self::class, 'merge_into_seopress' ), 100 );
		add_filter( 'the_seo_framework_schema_graph_data', array( self::class, 'merge_into_tsf' ),      100 );
		add_filter( 'slim_seo_schema_graph',               array( self::class, 'merge_into_slim_seo' ), 100 );

		// reviewedBy + lastReviewed into Article schema (all SEO plugins).
		// Piggybacks on the existing RR_Block merge_into_article_node helper by providing data through filter.
	}

	// ══════════════════════════════════════════════════════════════════════════
	// META REGISTRATION
	// ══════════════════════════════════════════════════════════════════════════

	public static function register_user_meta(): void {
		$text_keys = array_diff(
			self::META_KEYS,
			array( 'rr_author_education', 'rr_author_certifications', 'rr_author_memberships', 'rr_author_awards', 'rr_author_bio' )
		);

		foreach ( $text_keys as $key ) {
			register_meta( 'user', $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () { return current_user_can( 'edit_users' ); },
			) );
		}

		// Bio = longer text.
		register_meta( 'user', 'rr_author_bio', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => array( self::class, 'sanitize_textarea' ),
			'auth_callback'     => function () { return current_user_can( 'edit_users' ); },
		) );

		// Repeaters = JSON strings.
		foreach ( array( 'rr_author_education', 'rr_author_certifications', 'rr_author_memberships', 'rr_author_awards' ) as $key ) {
			register_meta( 'user', $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_repeater_json' ),
				'auth_callback'     => function () { return current_user_can( 'edit_users' ); },
			) );
		}
	}

	public static function register_post_meta(): void {
		// Author Trust panel is opt-in. When disabled, the fact-checker / reviewer
		// / last-reviewed meta keys are not registered at all — so they don't
		// appear in the REST API, don't show up in the block editor's document
		// meta panel, and never clutter the post editor for users who don't
		// have a formal review process.
		if ( 'on' !== get_option( RR_OPT_AUTHOR_TRUST_ENABLE, 'off' ) ) {
			return;
		}

		$post_types = (array) get_option( RR_OPT_AUTHOR_POST_TYPES, array( 'post' ) );

		foreach ( $post_types as $pt ) {
			register_post_meta( $pt, RR_META_AUTHOR_FACT_CHECKED_BY, array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			) );
			register_post_meta( $pt, RR_META_AUTHOR_REVIEWED_BY, array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			) );
			register_post_meta( $pt, RR_META_AUTHOR_LAST_REVIEWED, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
			) );
			register_post_meta( $pt, RR_META_AUTHOR_DISABLE, array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			) );
		}
	}

	public static function sanitize_textarea( $value ): string {
		return sanitize_textarea_field( (string) $value );
	}

	public static function sanitize_date( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) return '';
		// Only accept YYYY-MM-DD.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Repeaters are stored as JSON strings. Sanitize each row as an array of strings.
	 */
	public static function sanitize_repeater_json( $value ): string {
		if ( empty( $value ) ) return '';
		$decoded = is_string( $value ) ? json_decode( $value, true ) : (array) $value;
		if ( ! is_array( $decoded ) ) return '';

		$clean = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) continue;
			$row_clean = array();
			foreach ( $row as $k => $v ) {
				$row_clean[ sanitize_key( (string) $k ) ] = sanitize_text_field( (string) $v );
			}
			if ( ! empty( array_filter( $row_clean ) ) ) {
				$clean[] = $row_clean;
			}
		}
		return wp_json_encode( $clean );
	}

	/**
	 * Decode a repeater meta to array. Handles both JSON strings and already-decoded arrays.
	 */
	public static function decode_repeater( $value ): array {
		if ( empty( $value ) ) return array();
		if ( is_array( $value ) ) return $value;
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// ══════════════════════════════════════════════════════════════════════════
	// PROFILE PAGE UI
	// ══════════════════════════════════════════════════════════════════════════

	public static function render_profile_fields( $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$m = function ( $key ) use ( $user ) {
			return (string) get_user_meta( $user->ID, $key, true );
		};

		$education     = self::decode_repeater( $m( 'rr_author_education' ) );
		$certifications = self::decode_repeater( $m( 'rr_author_certifications' ) );
		$memberships   = self::decode_repeater( $m( 'rr_author_memberships' ) );
		$awards        = self::decode_repeater( $m( 'rr_author_awards' ) );

		wp_nonce_field( 'rr_save_author_box', 'rr_author_box_nonce' );

		$profile_is_pro = function_exists( 'rr_is_pro' ) && rr_is_pro();
		?>
		<h2 id="rr-author-box"><?php esc_html_e( 'RankReady Author Box', 'rankready' ); ?></h2>
		<p class="description" style="max-width:780px;">
			<?php if ( $profile_is_pro ) : ?>
			<?php esc_html_e( 'Every field below emits Schema.org Person data for Google EEAT and AI citation (ChatGPT, Perplexity, Google AI Overviews). Fill in only what applies. RankReady merges into Rank Math / Yoast / AIOSEO Person schema automatically when those plugins are active.', 'rankready' ); ?>
			<?php else : ?>
			<?php esc_html_e( 'Fill in your basic profile below — these fields power the Author Box display. Full Person JSON-LD schema (credentials, Wikidata, ORCID, sameAs) is coming in RankReady Pro.', 'rankready' ); ?>
			<?php endif; ?>
		</p>

		<table class="form-table" role="presentation">

			<!-- ── Identity & Work — FREE ───────────────────────────────────── -->
			<tr><th colspan="2">
				<h3 style="margin:16px 0 0;"><?php esc_html_e( 'Identity & Work', 'rankready' ); ?></h3>
				<?php if ( ! $profile_is_pro ) : ?>
				<span style="display:inline-block;font-size:9px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:#00a32a;padding:2px 6px;border-radius:3px;vertical-align:middle;margin-left:8px;line-height:1.4;"><?php esc_html_e( 'FREE', 'rankready' ); ?></span>
				<?php endif; ?>
			</th></tr>

			<tr>
				<th><label for="rr_author_job_title"><?php esc_html_e( 'Job Title', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_job_title" id="rr_author_job_title" value="<?php echo esc_attr( $m( 'rr_author_job_title' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Your professional role. Emits as Person.jobTitle — a primary EEAT signal.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_employer"><?php esc_html_e( 'Employer / Company', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_employer" id="rr_author_employer" value="<?php echo esc_attr( $m( 'rr_author_employer' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Organization you work for. Emits as Person.worksFor → Organization entity.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_employer_url"><?php esc_html_e( 'Employer URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_employer_url" id="rr_author_employer_url" value="<?php echo esc_attr( $m( 'rr_author_employer_url' ) ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Organization website. Emits as Person.worksFor.url — connects your author entity to the org entity.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_bio"><?php esc_html_e( 'Bio', 'rankready' ); ?></label></th>
				<td>
					<textarea name="rr_author_bio" id="rr_author_bio" rows="4" class="large-text" maxlength="500"><?php echo esc_textarea( $m( 'rr_author_bio' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Short professional bio (max 500 chars). Emits as Person.description. Keep it factual — AI systems extract this verbatim.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_headshot"><?php esc_html_e( 'Headshot URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_headshot" id="rr_author_headshot" value="<?php echo esc_attr( $m( 'rr_author_headshot' ) ); ?>" class="regular-text" placeholder="https://" />
					<button type="button" class="button rr-media-picker" data-target="rr_author_headshot"><?php esc_html_e( 'Select Image', 'rankready' ); ?></button>
					<p class="description"><?php esc_html_e( 'Real photo, square, at least 400x400px. Emits as Person.image. Avoid avatars and stock photos — AI Overviews favor verifiable faces.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_headshot_alt"><?php esc_html_e( 'Headshot Alt Text', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_headshot_alt" id="rr_author_headshot_alt" value="<?php echo esc_attr( $m( 'rr_author_headshot_alt' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Accessibility text. Typically "Photo of [Your Name]".', 'rankready' ); ?></p>
				</td>
			</tr>

			<!-- ── Experience — FREE ───────────────────────────────────────── -->
			<tr><th colspan="2">
				<h3 style="margin:24px 0 0;"><?php esc_html_e( 'Experience', 'rankready' ); ?></h3>
				<?php if ( ! $profile_is_pro ) : ?>
				<span style="display:inline-block;font-size:9px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:#00a32a;padding:2px 6px;border-radius:3px;vertical-align:middle;margin-left:8px;line-height:1.4;"><?php esc_html_e( 'FREE', 'rankready' ); ?></span>
				<?php endif; ?>
			</th></tr>

			<tr>
				<th><label for="rr_author_started_year"><?php esc_html_e( 'Started in Field (Year)', 'rankready' ); ?></label></th>
				<td>
					<input type="number" name="rr_author_started_year" id="rr_author_started_year" value="<?php echo esc_attr( $m( 'rr_author_started_year' ) ); ?>" min="1950" max="<?php echo (int) gmdate( 'Y' ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Year you started working in this field. RankReady auto-calculates years of experience from this — verifiable, not a vanity counter.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_expertise"><?php esc_html_e( 'Topics of Expertise', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_expertise" id="rr_author_expertise" value="<?php echo esc_attr( $m( 'rr_author_expertise' ) ); ?>" class="large-text" placeholder="WordPress, Elementor, SEO, …" />
					<p class="description"><?php esc_html_e( 'Comma-separated topics. Emits as Person.knowsAbout[] — the highest-signal field for LLM topical clustering. Use 3–8 specific topics.', 'rankready' ); ?></p>
				</td>
			</tr>

			<?php if ( ! $profile_is_pro ) : ?>
			<!-- ── Pro gate separator ───────────────────────────────────────── -->
			<tr>
				<td colspan="2" style="padding:24px 0 8px;">
					<div style="display:grid;grid-template-columns:24px 1fr;column-gap:14px;align-items:start;border:1px solid #e5e5e5;border-left:3px solid #1d2327;border-radius:4px;padding:16px 20px;background:#fff;min-height:72px;box-sizing:border-box;">
						<span class="dashicons dashicons-lock" style="font-size:18px;width:18px;height:18px;color:#8c8f94;margin-top:2px;" aria-hidden="true"></span>
						<div style="min-width:0;">
							<strong style="font-size:13px;font-weight:600;color:#1d2327;display:inline-flex;align-items:center;gap:8px;line-height:1.4;"><?php esc_html_e( 'Full EEAT Schema', 'rankready' ); ?> <span style="display:inline-block;font-size:9px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:#1d2327;padding:2px 6px;border-radius:3px;">PRO</span></strong>
							<p style="margin:6px 0 0;color:#646970;font-size:12px;line-height:1.55;"><?php esc_html_e( 'Credentials, Verified Identity (Wikidata, ORCID, LinkedIn), Social sameAs links, and Contact — these fields will emit Person JSON-LD that AI systems use to verify authorship and increase citation probability.', 'rankready' ); ?></p>
							<span style="display:inline-block;margin-top:8px;font-size:11px;color:#9a6700;font-style:italic;letter-spacing:.01em;"><?php esc_html_e( 'Launching with RankReady Pro.', 'rankready' ); ?></span>
						</div>
					</div>
				</td>
			</tr>
			<?php endif; ?>

			<!-- ── Credentials ─────────────────────────────────────────────── -->
			<?php if ( $profile_is_pro ) : ?>
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Credentials', 'rankready' ); ?> <span style="display:inline-block;font-size:9px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:#1d2327;padding:2px 6px;border-radius:3px;vertical-align:middle;margin-left:8px;line-height:1.4;"><?php esc_html_e( 'PRO', 'rankready' ); ?></span></h3></th></tr>
			<?php endif; ?>
			<?php if ( $profile_is_pro ) : ?>

			<tr>
				<th><label for="rr_author_credentials_suffix"><?php esc_html_e( 'Credentials Suffix', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_credentials_suffix" id="rr_author_credentials_suffix" value="<?php echo esc_attr( $m( 'rr_author_credentials_suffix' ) ); ?>" class="regular-text" placeholder="MD, PhD, MPH" />
					<p class="description"><?php esc_html_e( 'Post-nominal letters displayed next to your name in the author box (e.g. "Jane Smith, MD"). Not required.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Education', 'rankready' ); ?></th>
				<td>
					<div class="rr-repeater" data-repeater="education" data-fields="degree,institution,year">
						<?php self::render_repeater_rows( $education, array( 'degree' => 'Degree (e.g. MSc Computer Science)', 'institution' => 'Institution', 'year' => 'Year' ) ); ?>
					</div>
					<input type="hidden" name="rr_author_education" value="<?php echo esc_attr( wp_json_encode( $education ) ); ?>" />
					<button type="button" class="button rr-repeater-add" data-target="education"><?php esc_html_e( '+ Add Education', 'rankready' ); ?></button>
					<p class="description"><?php esc_html_e( 'Degrees and academic qualifications. Each row emits as Person.alumniOf[] + hasCredential[] (credentialCategory: degree).', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Certifications', 'rankready' ); ?></th>
				<td>
					<div class="rr-repeater" data-repeater="certifications" data-fields="name,issuer,year,url">
						<?php self::render_repeater_rows( $certifications, array( 'name' => 'Certification Name', 'issuer' => 'Issuing Body', 'year' => 'Year', 'url' => 'Verify URL' ) ); ?>
					</div>
					<input type="hidden" name="rr_author_certifications" value="<?php echo esc_attr( wp_json_encode( $certifications ) ); ?>" />
					<button type="button" class="button rr-repeater-add" data-target="certifications"><?php esc_html_e( '+ Add Certification', 'rankready' ); ?></button>
					<p class="description"><?php esc_html_e( 'Professional certifications with issuer and verify URL. Emits as Person.hasCredential[] (credentialCategory: certification).', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Memberships', 'rankready' ); ?></th>
				<td>
					<div class="rr-repeater" data-repeater="memberships" data-fields="name,url">
						<?php self::render_repeater_rows( $memberships, array( 'name' => 'Organization Name', 'url' => 'Organization URL' ) ); ?>
					</div>
					<input type="hidden" name="rr_author_memberships" value="<?php echo esc_attr( wp_json_encode( $memberships ) ); ?>" />
					<button type="button" class="button rr-repeater-add" data-target="memberships"><?php esc_html_e( '+ Add Membership', 'rankready' ); ?></button>
					<p class="description"><?php esc_html_e( 'Professional associations (SPJ, AMA, IEEE, W3C, …). Emits as Person.memberOf[].', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Awards', 'rankready' ); ?></th>
				<td>
					<div class="rr-repeater" data-repeater="awards" data-fields="name,year">
						<?php self::render_repeater_rows( $awards, array( 'name' => 'Award Name', 'year' => 'Year' ) ); ?>
					</div>
					<input type="hidden" name="rr_author_awards" value="<?php echo esc_attr( wp_json_encode( $awards ) ); ?>" />
					<button type="button" class="button rr-repeater-add" data-target="awards"><?php esc_html_e( '+ Add Award', 'rankready' ); ?></button>
					<p class="description"><?php esc_html_e( 'Professional recognition. Emits as Person.award[].', 'rankready' ); ?></p>
				</td>
			</tr>

			<!-- ── Verified Identity (Priority sameAs) ─────────────────────── -->
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Verified Identity (priority sameAs)', 'rankready' ); ?></h3></th></tr>

			<tr>
				<th><label for="rr_author_wikidata"><?php esc_html_e( 'Wikidata QID', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_wikidata" id="rr_author_wikidata" value="<?php echo esc_attr( $m( 'rr_author_wikidata' ) ); ?>" class="regular-text" placeholder="Q12345" />
					<p class="description"><?php esc_html_e( 'Your Wikidata identifier if you have one (format: Q12345). Highest-priority sameAs — the canonical entity URI LLMs actually reuse.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_wikipedia"><?php esc_html_e( 'Wikipedia URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_wikipedia" id="rr_author_wikipedia" value="<?php echo esc_attr( $m( 'rr_author_wikipedia' ) ); ?>" class="regular-text" placeholder="https://en.wikipedia.org/wiki/…" />
					<p class="description"><?php esc_html_e( 'Wikipedia article URL about you, if one exists. Emits early in sameAs[] — strong entity anchor for Google and LLMs.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_orcid"><?php esc_html_e( 'ORCID iD', 'rankready' ); ?></label></th>
				<td>
					<input type="text" name="rr_author_orcid" id="rr_author_orcid" value="<?php echo esc_attr( $m( 'rr_author_orcid' ) ); ?>" class="regular-text" placeholder="0000-0000-0000-0000" />
					<p class="description"><?php esc_html_e( 'ORCID identifier (academic ID). Emits both as sameAs (orcid.org URL) and as identifier PropertyValue — dual emission improves disambiguation.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_scholar"><?php esc_html_e( 'Google Scholar URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_scholar" id="rr_author_scholar" value="<?php echo esc_attr( $m( 'rr_author_scholar' ) ); ?>" class="regular-text" placeholder="https://scholar.google.com/citations?user=…" />
					<p class="description"><?php esc_html_e( 'Google Scholar profile. Emits in sameAs[] — academic authority signal.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_linkedin"><?php esc_html_e( 'LinkedIn URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_linkedin" id="rr_author_linkedin" value="<?php echo esc_attr( $m( 'rr_author_linkedin' ) ); ?>" class="regular-text" placeholder="https://www.linkedin.com/in/…" />
					<p class="description"><?php esc_html_e( 'LinkedIn profile. Perplexity explicitly weights authors with verifiable LinkedIn — this is the single most important social signal for AI citation.', 'rankready' ); ?></p>
				</td>
			</tr>

			<!-- ── Social (Lower Priority sameAs) ──────────────────────────── -->
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Social', 'rankready' ); ?></h3></th></tr>

			<tr>
				<th><label for="rr_author_github"><?php esc_html_e( 'GitHub URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_github" id="rr_author_github" value="<?php echo esc_attr( $m( 'rr_author_github' ) ); ?>" class="regular-text" placeholder="https://github.com/…" />
					<p class="description"><?php esc_html_e( 'GitHub profile. Emits in sameAs[] — technical authority signal.', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_youtube"><?php esc_html_e( 'YouTube URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_youtube" id="rr_author_youtube" value="<?php echo esc_attr( $m( 'rr_author_youtube' ) ); ?>" class="regular-text" placeholder="https://www.youtube.com/@…" />
					<p class="description"><?php esc_html_e( 'YouTube channel. Emits in sameAs[].', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_twitter"><?php esc_html_e( 'X / Twitter URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_twitter" id="rr_author_twitter" value="<?php echo esc_attr( $m( 'rr_author_twitter' ) ); ?>" class="regular-text" placeholder="https://x.com/…" />
					<p class="description"><?php esc_html_e( 'X (Twitter) profile. Emits in sameAs[].', 'rankready' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rr_author_website"><?php esc_html_e( 'Personal Website URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_website" id="rr_author_website" value="<?php echo esc_attr( $m( 'rr_author_website' ) ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Your personal or portfolio site. Emits in sameAs[].', 'rankready' ); ?></p>
				</td>
			</tr>

			<!-- ── Contact ─────────────────────────────────────────────────── -->
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Contact', 'rankready' ); ?></h3></th></tr>

			<tr>
				<th><label for="rr_author_contact_url"><?php esc_html_e( 'Contact Form URL', 'rankready' ); ?></label></th>
				<td>
					<input type="url" name="rr_author_contact_url" id="rr_author_contact_url" value="<?php echo esc_attr( $m( 'rr_author_contact_url' ) ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Contact form page URL. Emits as Person.contactPoint — preferred over raw email (zero scrape risk).', 'rankready' ); ?></p>
				</td>
			</tr>
			<?php endif; // profile_is_pro — credentials + verified identity + social + contact ?>
		</table>

		<script>
		(function(){
			// Repeater add/remove (vanilla, no jQuery).
			function syncHidden(repeater){
				var key = repeater.getAttribute('data-repeater');
				var fields = repeater.getAttribute('data-fields').split(',');
				var rows = repeater.querySelectorAll('.rr-repeater-row');
				var data = [];
				for (var i = 0; i < rows.length; i++) {
					var row = {};
					var has = false;
					for (var j = 0; j < fields.length; j++) {
						var input = rows[i].querySelector('[data-field="' + fields[j] + '"]');
						if (input) {
							row[fields[j]] = input.value;
							if (input.value) has = true;
						}
					}
					if (has) data.push(row);
				}
				var hidden = document.querySelector('input[name="rr_author_' + key + '"]');
				if (hidden) hidden.value = JSON.stringify(data);
			}

			function makeRow(fields, values){
				var row = document.createElement('div');
				row.className = 'rr-repeater-row';
				row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
				for (var i = 0; i < fields.length; i++) {
					var f = fields[i].trim();
					var input = document.createElement('input');
					input.type = 'text';
					input.setAttribute('data-field', f);
					input.placeholder = f.charAt(0).toUpperCase() + f.slice(1);
					input.value = (values && values[f]) || '';
					input.style.flex = '1';
					row.appendChild(input);
				}
				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'button';
				del.textContent = '×';
				del.style.cssText = 'min-width:32px;';
				del.addEventListener('click', function(){
					row.parentNode.removeChild(row);
					syncHidden(row.parentNode || document.querySelector('.rr-repeater'));
				});
				row.appendChild(del);
				return row;
			}

			document.querySelectorAll('.rr-repeater-add').forEach(function(btn){
				btn.addEventListener('click', function(){
					var key = btn.getAttribute('data-target');
					var repeater = document.querySelector('.rr-repeater[data-repeater="' + key + '"]');
					if (!repeater) return;
					var fields = repeater.getAttribute('data-fields').split(',');
					var row = makeRow(fields, {});
					repeater.appendChild(row);
					row.querySelectorAll('input').forEach(function(inp){
						inp.addEventListener('input', function(){ syncHidden(repeater); });
					});
				});
			});

			document.querySelectorAll('.rr-repeater').forEach(function(repeater){
				repeater.querySelectorAll('input').forEach(function(inp){
					inp.addEventListener('input', function(){ syncHidden(repeater); });
				});
				repeater.querySelectorAll('.rr-repeater-row-remove').forEach(function(btn){
					btn.addEventListener('click', function(){
						btn.closest('.rr-repeater-row').remove();
						syncHidden(repeater);
					});
				});
			});

			// Media picker.
			document.querySelectorAll('.rr-media-picker').forEach(function(btn){
				btn.addEventListener('click', function(e){
					e.preventDefault();
					if (typeof wp === 'undefined' || !wp.media) return;
					var frame = wp.media({ title: 'Select Headshot', button: { text: 'Use this image' }, multiple: false });
					frame.on('select', function(){
						var attachment = frame.state().get('selection').first().toJSON();
						var target = document.getElementById(btn.getAttribute('data-target'));
						if (target) target.value = attachment.url;
					});
					frame.open();
				});
			});
		})();
		</script>
		<?php
		// Make sure wp.media is available.
		wp_enqueue_media();
	}

	private static function render_repeater_rows( array $rows, array $field_labels ): void {
		$fields = array_keys( $field_labels );
		if ( empty( $rows ) ) {
			echo '<p class="description" style="font-style:italic;margin:4px 0 8px;">' . esc_html__( 'No entries yet. Click "Add" below.', 'rankready' ) . '</p>';
			return;
		}
		foreach ( $rows as $row ) {
			echo '<div class="rr-repeater-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">';
			foreach ( $fields as $field ) {
				$value = isset( $row[ $field ] ) ? $row[ $field ] : '';
				echo '<input type="text" data-field="' . esc_attr( $field ) . '" placeholder="' . esc_attr( $field_labels[ $field ] ) . '" value="' . esc_attr( $value ) . '" style="flex:1;" />';
			}
			echo '<button type="button" class="button rr-repeater-row-remove" style="min-width:32px;">&times;</button>';
			echo '</div>';
		}
	}

	public static function save_profile_fields( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( empty( $_POST['rr_author_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rr_author_box_nonce'] ) ), 'rr_save_author_box' ) ) {
			return;
		}

		foreach ( self::META_KEYS as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// Nonce + capability checks above. Raw unslashed value is
			// dispatched to a type-specific sanitizer in the if/elseif
			// chain below (sanitize_repeater_json, sanitize_textarea,
			// esc_url_raw, or sanitize_text_field).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = wp_unslash( $_POST[ $key ] );

			if ( in_array( $key, array( 'rr_author_education', 'rr_author_certifications', 'rr_author_memberships', 'rr_author_awards' ), true ) ) {
				update_user_meta( $user_id, $key, self::sanitize_repeater_json( $raw ) );
			} elseif ( 'rr_author_bio' === $key ) {
				update_user_meta( $user_id, $key, self::sanitize_textarea( $raw ) );
			} elseif ( in_array( $key, array( 'rr_author_employer_url', 'rr_author_headshot', 'rr_author_wikipedia', 'rr_author_scholar', 'rr_author_linkedin', 'rr_author_github', 'rr_author_youtube', 'rr_author_twitter', 'rr_author_website', 'rr_author_contact_url' ), true ) ) {
				update_user_meta( $user_id, $key, esc_url_raw( (string) $raw ) );
			} else {
				update_user_meta( $user_id, $key, sanitize_text_field( (string) $raw ) );
			}
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA — Person node builder (shared by all render + merge paths)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Build a Schema.org Person node for a user. Returns null if there's nothing
	 * meaningful to emit (just a bare WP username with no RankReady data).
	 */
	public static function build_person_schema( int $user_id ): ?array {
		if ( $user_id <= 0 ) return null;
		$user = get_userdata( $user_id );
		if ( ! $user ) return null;

		$m = function ( $key ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $key, true );
		};

		$display_name = $user->display_name;
		$suffix       = $m( 'rr_author_credentials_suffix' );
		$full_name    = $suffix ? $display_name . ', ' . $suffix : $display_name;

		$person = array(
			'@type' => 'Person',
			'@id'   => get_author_posts_url( $user_id ) . '#person',
			'name'  => $full_name,
			'url'   => get_author_posts_url( $user_id ),
		);

		// Bio → description.
		$bio = $m( 'rr_author_bio' );
		if ( '' === $bio && $user->description ) {
			$bio = $user->description;
		}
		if ( '' !== $bio ) {
			$person['description'] = wp_strip_all_tags( $bio );
		}

		// Headshot → image (ImageObject).
		$headshot = $m( 'rr_author_headshot' );
		if ( '' !== $headshot ) {
			$person['image'] = array(
				'@type'   => 'ImageObject',
				'url'     => $headshot,
				'caption' => $m( 'rr_author_headshot_alt' ) ?: $display_name,
			);
		} else {
			$avatar = get_avatar_url( $user_id, array( 'size' => 400 ) );
			if ( $avatar ) {
				$person['image'] = array(
					'@type' => 'ImageObject',
					'url'   => $avatar,
				);
			}
		}

		// Job title.
		$job = $m( 'rr_author_job_title' );
		if ( '' !== $job ) $person['jobTitle'] = $job;

		// Employer → worksFor.
		$employer = $m( 'rr_author_employer' );
		if ( '' !== $employer ) {
			$works_for = array( '@type' => 'Organization', 'name' => $employer );
			$emp_url   = $m( 'rr_author_employer_url' );
			if ( '' !== $emp_url ) $works_for['url'] = $emp_url;
			$person['worksFor'] = $works_for;
		}

		// knowsAbout → topics of expertise (highest LLM signal).
		$expertise = $m( 'rr_author_expertise' );
		if ( '' !== $expertise ) {
			$topics = array_filter( array_map( 'trim', explode( ',', $expertise ) ) );
			if ( ! empty( $topics ) ) {
				$person['knowsAbout'] = array_values( $topics );
			}
		}

		// sameAs (priority order: Wikidata → Wikipedia → ORCID → Scholar → LinkedIn → GitHub → YouTube → X → personal site).
		$same_as = array();

		$wikidata = $m( 'rr_author_wikidata' );
		if ( '' !== $wikidata ) {
			// Accept raw QID or full URL.
			$wd_url = preg_match( '/^Q\d+$/', $wikidata )
				? 'https://www.wikidata.org/entity/' . $wikidata
				: $wikidata;
			$same_as[] = $wd_url;
		}
		foreach ( array( 'rr_author_wikipedia', 'rr_author_linkedin', 'rr_author_scholar', 'rr_author_github', 'rr_author_youtube', 'rr_author_twitter', 'rr_author_website' ) as $k ) {
			$v = $m( $k );
			if ( '' !== $v ) $same_as[] = $v;
		}

		// ORCID → sameAs + identifier PropertyValue.
		$orcid = $m( 'rr_author_orcid' );
		if ( '' !== $orcid ) {
			// Accept raw id or full URL.
			$orcid_url = ( 0 === strpos( $orcid, 'http' ) ) ? $orcid : 'https://orcid.org/' . $orcid;
			// Insert ORCID right after Wikipedia/Wikidata (position 2).
			$insert_at = min( 2, count( $same_as ) );
			array_splice( $same_as, $insert_at, 0, array( $orcid_url ) );

			$person['identifier'] = array(
				'@type'       => 'PropertyValue',
				'propertyID'  => 'ORCID',
				'value'       => preg_replace( '#^https?://orcid\.org/#', '', $orcid ),
			);
		}

		// Wikidata identifier PropertyValue.
		if ( '' !== $wikidata ) {
			$qid = preg_match( '/Q\d+/', $wikidata, $mm ) ? $mm[0] : $wikidata;
			if ( ! isset( $person['identifier'] ) ) {
				$person['identifier'] = array(
					'@type'      => 'PropertyValue',
					'propertyID' => 'Wikidata',
					'value'      => $qid,
				);
			}
		}

		if ( ! empty( $same_as ) ) {
			$person['sameAs'] = array_values( array_unique( $same_as ) );
		}

		// Education → alumniOf[] + hasCredential[] (degree).
		$education = self::decode_repeater( $m( 'rr_author_education' ) );
		$alumni    = array();
		$credentials = array();
		foreach ( $education as $row ) {
			if ( empty( $row['institution'] ) ) continue;
			$alumni[] = array( '@type' => 'CollegeOrUniversity', 'name' => $row['institution'] );
			if ( ! empty( $row['degree'] ) ) {
				$credentials[] = array(
					'@type'              => 'EducationalOccupationalCredential',
					'credentialCategory' => 'degree',
					'name'               => $row['degree'],
					'recognizedBy'       => array( '@type' => 'CollegeOrUniversity', 'name' => $row['institution'] ),
				);
			}
		}
		if ( ! empty( $alumni ) )      $person['alumniOf']     = $alumni;

		// Certifications → hasCredential[] (certification).
		$certifications = self::decode_repeater( $m( 'rr_author_certifications' ) );
		foreach ( $certifications as $row ) {
			if ( empty( $row['name'] ) ) continue;
			$cred = array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => 'certification',
				'name'               => $row['name'],
			);
			if ( ! empty( $row['issuer'] ) ) {
				$cred['recognizedBy'] = array( '@type' => 'Organization', 'name' => $row['issuer'] );
			}
			if ( ! empty( $row['url'] ) ) $cred['url'] = $row['url'];
			$credentials[] = $cred;
		}
		if ( ! empty( $credentials ) ) $person['hasCredential'] = $credentials;

		// Memberships → memberOf[].
		$memberships = self::decode_repeater( $m( 'rr_author_memberships' ) );
		$member_of   = array();
		foreach ( $memberships as $row ) {
			if ( empty( $row['name'] ) ) continue;
			$org = array( '@type' => 'Organization', 'name' => $row['name'] );
			if ( ! empty( $row['url'] ) ) $org['url'] = $row['url'];
			$member_of[] = $org;
		}
		if ( ! empty( $member_of ) ) $person['memberOf'] = $member_of;

		// Awards → award[].
		$awards = self::decode_repeater( $m( 'rr_author_awards' ) );
		$award_names = array();
		foreach ( $awards as $row ) {
			if ( empty( $row['name'] ) ) continue;
			$award_names[] = ! empty( $row['year'] ) ? $row['name'] . ' (' . $row['year'] . ')' : $row['name'];
		}
		if ( ! empty( $award_names ) ) $person['award'] = $award_names;

		// Contact point.
		$contact_url = $m( 'rr_author_contact_url' );
		if ( '' !== $contact_url ) {
			$person['contactPoint'] = array(
				'@type'       => 'ContactPoint',
				'contactType' => 'author',
				'url'         => $contact_url,
			);
		}

		// publishingPrinciples — site-wide editorial policy URL.
		$editorial = (string) get_option( RR_OPT_AUTHOR_EDITORIAL_URL, '' );
		if ( '' !== $editorial ) $person['publishingPrinciples'] = $editorial;

		// Filter count: if we only have the baseline (type/@id/name/url), don't bother emitting.
		if ( count( $person ) <= 4 ) {
			return null;
		}

		return $person;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA MERGE — inject Person data into existing SEO plugin schema.
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Walk a schema graph and enhance any Person node whose url matches the
	 * current post author. Uses a SMART merge strategy (1.7.1+):
	 *
	 *   - For fields the user explicitly filled in the RankReady Author Box
	 *     (image, description, jobTitle, worksFor, knowsAbout, contactPoint),
	 *     RankReady's value OVERWRITES whatever the SEO plugin emitted. This
	 *     fixes the long-standing bug where a Gravatar fallback from Rank Math
	 *     would win over an uploaded real headshot, or where duplicate
	 *     twitter.com / x.com entries from Yoast profile fields would persist.
	 *
	 *   - For sameAs, RankReady MERGES its list with whatever already exists,
	 *     then dedupes + normalizes (twitter.com → x.com collapse, www. strip,
	 *     trailing-slash strip) and re-orders by EEAT priority (Wikidata →
	 *     Wikipedia → ORCID → Scholar → LinkedIn → GitHub → YouTube → X →
	 *     personal site). This keeps any extra profile links the SEO plugin
	 *     knows about while guaranteeing zero duplicates.
	 *
	 *   - For everything else (alumniOf, hasCredential, memberOf, award,
	 *     identifier, publishingPrinciples, @id), RankReady adds the field
	 *     only if the SEO plugin hasn't already set it. Additive, non-
	 *     destructive, no-conflict.
	 */
	private static function enhance_graph( array &$graph, int $post_id ): void {
		if ( 'on' !== get_option( RR_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) return;
		$post = get_post( $post_id );
		if ( ! $post ) return;

		$person = self::build_person_schema( (int) $post->post_author );
		if ( null === $person ) return;

		$author_url = get_author_posts_url( (int) $post->post_author );

		foreach ( $graph as &$node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) continue;
			$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( ! in_array( 'Person', $type, true ) ) continue;

			// Match by URL if present, otherwise enhance any Person node
			// (most SEO plugins only emit one Person per article graph).
			$node_url = isset( $node['url'] ) ? $node['url'] : '';
			if ( $node_url && $node_url !== $author_url ) continue;

			self::smart_merge_person( $node, $person );
		}
		unset( $node );

		// Also add reviewedBy / lastReviewed to Article nodes.
		self::inject_review_signals( $graph, $post_id );
	}

	/**
	 * Smart merge: RankReady's Author Box data wins for the fields the user
	 * explicitly filled in, sameAs is merged-and-deduped, everything else is
	 * additive. See enhance_graph() docblock for the full rationale.
	 *
	 * @param array $node   The existing Person node from the SEO plugin graph (modified in place).
	 * @param array $person The Person data built from RankReady's Author Box fields.
	 */
	private static function smart_merge_person( array &$node, array $person ): void {
		// Fields where RankReady's value WINS when present. These are the
		// Author Box fields the user explicitly filled in — they should never
		// be overridden by SEO plugin defaults (Gravatar, WP bio, etc.).
		$overwrite_keys = array(
			'image',
			'description',
			'jobTitle',
			'worksFor',
			'knowsAbout',
			'contactPoint',
		);

		foreach ( $person as $key => $value ) {
			if ( '@type' === $key ) continue;

			// sameAs: merge both arrays, dedupe, normalize, priority-sort.
			if ( 'sameAs' === $key ) {
				$existing = array();
				if ( isset( $node['sameAs'] ) ) {
					$existing = is_array( $node['sameAs'] ) ? $node['sameAs'] : array( $node['sameAs'] );
				}
				$merged = array_merge( $existing, (array) $value );
				$node['sameAs'] = self::normalize_same_as( $merged );
				continue;
			}

			// Fields where RankReady's value always wins.
			if ( in_array( $key, $overwrite_keys, true ) ) {
				$node[ $key ] = $value;
				continue;
			}

			// Everything else: additive (alumniOf, hasCredential, memberOf,
			// award, identifier, publishingPrinciples, @id, name, url).
			if ( ! isset( $node[ $key ] ) || ( is_array( $node[ $key ] ) && empty( $node[ $key ] ) ) ) {
				$node[ $key ] = $value;
			}
		}
	}

	/**
	 * Dedupe + normalize + priority-sort a sameAs URL list.
	 *
	 *   - Collapses twitter.com and x.com for the same handle into one x.com URL
	 *   - Strips "www." from the host
	 *   - Strips trailing slash from the path
	 *   - Lowercases the host for comparison
	 *   - Orders by EEAT priority: Wikidata → Wikipedia → ORCID → Scholar →
	 *     LinkedIn → Muck Rack → GitHub → YouTube → X → everything else
	 *
	 * @param array $urls Raw URL list (may contain duplicates and variants).
	 * @return array Canonicalized, deduped, priority-sorted URL list.
	 */
	private static function normalize_same_as( array $urls ): array {
		$seen   = array();
		$output = array();

		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) continue;

			$parts = wp_parse_url( $url );
			if ( empty( $parts['host'] ) ) continue;

			$host = strtolower( $parts['host'] );
			$host = preg_replace( '/^www\./', '', $host );
			$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
			// Preserve query string — Google Scholar, Twitter search, etc. use it as the author identifier.
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

			// Collapse twitter.com + x.com + mobile variants — same platform, same handle.
			if ( in_array( $host, array( 'twitter.com', 'x.com', 'mobile.twitter.com', 'mobile.x.com' ), true ) ) {
				$host = 'x.com';
			}

			$dedupe_key = $host . $path . $query;
			if ( isset( $seen[ $dedupe_key ] ) ) continue;
			$seen[ $dedupe_key ] = true;

			// Canonical rebuild: always https, normalized host, path, preserved query.
			$output[] = 'https://' . $host . $path . $query;
		}

		// Priority sort by domain. Lower rank = higher priority.
		$priority_map = array(
			'wikidata.org'         => 1,
			'wikipedia.org'        => 2,
			'orcid.org'            => 3,
			'scholar.google.com'   => 4,
			'scholar.google'       => 4,
			'linkedin.com'         => 5,
			'muckrack.com'         => 6,
			'github.com'           => 7,
			'youtube.com'          => 8,
			'x.com'                => 9,
		);

		$rank = function ( $url ) use ( $priority_map ) {
			foreach ( $priority_map as $domain => $r ) {
				if ( false !== strpos( $url, $domain ) ) return $r;
			}
			return 999;
		};

		usort( $output, function ( $a, $b ) use ( $rank ) {
			$ra = $rank( $a );
			$rb = $rank( $b );
			if ( $ra === $rb ) return strcmp( $a, $b );
			return $ra - $rb;
		} );

		return $output;
	}

	private static function inject_review_signals( array &$graph, int $post_id ): void {
		$reviewed_by_id    = (int) get_post_meta( $post_id, RR_META_AUTHOR_REVIEWED_BY, true );
		$fact_checked_by_id = (int) get_post_meta( $post_id, RR_META_AUTHOR_FACT_CHECKED_BY, true );
		$last_reviewed     = (string) get_post_meta( $post_id, RR_META_AUTHOR_LAST_REVIEWED, true );

		if ( ! $reviewed_by_id && ! $fact_checked_by_id && '' === $last_reviewed ) return;

		$reviewers = array();
		foreach ( array( $reviewed_by_id, $fact_checked_by_id ) as $uid ) {
			if ( $uid <= 0 ) continue;
			$r = self::build_person_schema( $uid );
			if ( null !== $r ) $reviewers[] = $r;
		}

		$article_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' );

		foreach ( $graph as &$node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) continue;
			$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( ! array_intersect( $type, $article_types ) ) continue;

			if ( ! empty( $reviewers ) && empty( $node['reviewedBy'] ) ) {
				$node['reviewedBy'] = 1 === count( $reviewers ) ? $reviewers[0] : $reviewers;
			}
			if ( '' !== $last_reviewed && empty( $node['lastReviewed'] ) ) {
				$node['lastReviewed'] = $last_reviewed;
			}
		}
		unset( $node );
	}

	public static function merge_into_rankmath( $data, $jsonld ) {
		if ( ! is_singular() || ! is_array( $data ) ) return $data;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $data, $post_id );
		return $data;
	}
	public static function merge_into_yoast( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}
	public static function merge_into_aioseo( $graphs ) {
		if ( ! is_singular() || ! is_array( $graphs ) ) return $graphs;
		$post_id = get_queried_object_id();
		if ( ! $post_id ) return $graphs;
		foreach ( $graphs as &$graph ) {
			if ( is_array( $graph ) && isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
				self::enhance_graph( $graph['@graph'], $post_id );
			} elseif ( is_array( $graph ) && isset( $graph['@type'] ) ) {
				$single = array( &$graph );
				self::enhance_graph( $single, $post_id );
			}
		}
		unset( $graph );
		return $graphs;
	}
	public static function merge_into_seopress( $schema ) {
		if ( ! is_singular() || ! is_array( $schema ) ) return $schema;
		if ( 'on' !== get_option( RR_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) return $schema;
		$post_id = get_queried_object_id();
		if ( ! $post_id ) return $schema;

		// SEOPress (free + Pro) passes the Article schema directly with an
		// inline `author` Person instead of a separate @graph node. Use the
		// same smart-merge strategy as enhance_graph() so the headshot and
		// sameAs dedupe work identically here.
		if ( isset( $schema['author'] ) && is_array( $schema['author'] ) ) {
			$person = self::build_person_schema( (int) get_post_field( 'post_author', $post_id ) );
			if ( null !== $person ) {
				self::smart_merge_person( $schema['author'], $person );
			}
		}
		return $schema;
	}
	public static function merge_into_tsf( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}
	public static function merge_into_slim_seo( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA — standalone Person node on is_author() archive (no template override)
	// ══════════════════════════════════════════════════════════════════════════

	public static function maybe_inject_archive_person(): void {
		if ( ! is_author() ) return;
		if ( 'on' !== get_option( RR_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) return;

		$user_id = (int) get_queried_object_id();
		if ( $user_id <= 0 ) return;

		$person = self::build_person_schema( $user_id );
		if ( null === $person ) return;

		$profile = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'ProfilePage',
			'mainEntity' => $person,
		);

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// RENDER — HTML output for block, widget, and auto-display
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Render the author box. Called by the Gutenberg block render_callback,
	 * the Elementor widget, and the auto-display filter.
	 *
	 * @param int   $user_id  Author user ID.
	 * @param array $attrs    Block/widget attributes.
	 * @param int   $post_id  Current post ID (for per-post reviewed-by data).
	 */
	public static function render_html( int $user_id, array $attrs = array(), int $post_id = 0 ): string {
		if ( $user_id <= 0 ) return '';
		$user = get_userdata( $user_id );
		if ( ! $user ) return '';

		$layout        = isset( $attrs['layout'] ) ? sanitize_key( $attrs['layout'] ) : (string) get_option( RR_OPT_AUTHOR_LAYOUT, 'card' );
		$layout        = in_array( $layout, array( 'card', 'compact', 'inline' ), true ) ? $layout : 'card';
		$show_heading  = isset( $attrs['showHeading'] ) ? (bool) $attrs['showHeading'] : true;
		$heading_text  = isset( $attrs['headingText'] ) && '' !== $attrs['headingText']
			? sanitize_text_field( $attrs['headingText'] )
			: (string) get_option( RR_OPT_AUTHOR_HEADING, 'About the Author' );
		$heading_tag   = isset( $attrs['headingTag'] ) && '' !== $attrs['headingTag']
			? $attrs['headingTag']
			: (string) get_option( RR_OPT_AUTHOR_HEADING_TAG, 'h3' );
		$heading_tag   = in_array( $heading_tag, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ? $heading_tag : 'h3';

		$show_headshot   = isset( $attrs['showHeadshot'] )   ? (bool) $attrs['showHeadshot']   : true;
		$show_title      = isset( $attrs['showJobTitle'] )   ? (bool) $attrs['showJobTitle']   : true;
		$show_employer   = isset( $attrs['showEmployer'] )   ? (bool) $attrs['showEmployer']   : true;
		$show_bio        = isset( $attrs['showBio'] )        ? (bool) $attrs['showBio']        : true;
		$show_expertise  = isset( $attrs['showExpertise'] )  ? (bool) $attrs['showExpertise']  : true;
		$show_socials    = isset( $attrs['showSocials'] )    ? (bool) $attrs['showSocials']    : true;
		$show_credentials = isset( $attrs['showCredentials'] ) ? (bool) $attrs['showCredentials'] : true;
		$show_reviewed   = isset( $attrs['showReviewed'] )   ? (bool) $attrs['showReviewed']   : true;
		$show_years_exp  = isset( $attrs['showYearsExp'] )   ? (bool) $attrs['showYearsExp']   : true;

		$m = function ( $key ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $key, true );
		};

		$display_name = $user->display_name;
		$suffix       = $m( 'rr_author_credentials_suffix' );
		$name_html    = esc_html( $display_name ) . ( $suffix ? ' <span class="rr-ab-suffix">' . esc_html( $suffix ) . '</span>' : '' );
		$name_link    = get_author_posts_url( $user_id );

		// Headshot.
		$headshot = $m( 'rr_author_headshot' );
		if ( '' === $headshot ) $headshot = get_avatar_url( $user_id, array( 'size' => 200 ) );
		$headshot_alt = $m( 'rr_author_headshot_alt' ) ?: $display_name;

		// Years of experience (derived).
		$started_year = (int) $m( 'rr_author_started_year' );
		$years_exp    = ( $started_year > 1950 && $started_year <= (int) gmdate( 'Y' ) )
			? ( (int) gmdate( 'Y' ) - $started_year )
			: 0;

		// Reviewed / fact-checked signals.
		$reviewed_by_id     = $post_id ? (int) get_post_meta( $post_id, RR_META_AUTHOR_REVIEWED_BY, true ) : 0;
		$fact_checked_by_id = $post_id ? (int) get_post_meta( $post_id, RR_META_AUTHOR_FACT_CHECKED_BY, true ) : 0;
		$last_reviewed      = $post_id ? (string) get_post_meta( $post_id, RR_META_AUTHOR_LAST_REVIEWED, true ) : '';

		ob_start();
		?>
		<div class="rr-author-box rr-ab-<?php echo esc_attr( $layout ); ?>"
			<?php echo self::inline_style( $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $show_heading && 'inline' !== $layout ) : ?>
				<<?php echo esc_attr( $heading_tag ); ?> class="rr-ab-heading"><?php echo esc_html( $heading_text ); ?></<?php echo esc_attr( $heading_tag ); ?>>
			<?php endif; ?>

			<div class="rr-ab-inner">
				<?php if ( $show_headshot && $headshot ) : ?>
					<div class="rr-ab-headshot">
						<img src="<?php echo esc_url( $headshot ); ?>" alt="<?php echo esc_attr( $headshot_alt ); ?>" loading="lazy" />
					</div>
				<?php endif; ?>

				<div class="rr-ab-body">
					<div class="rr-ab-name-row">
						<a class="rr-ab-name" href="<?php echo esc_url( $name_link ); ?>" rel="author"><?php echo $name_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					</div>

					<?php if ( $show_title || $show_employer || ( $show_years_exp && $years_exp > 0 ) ) : ?>
						<div class="rr-ab-meta">
							<?php
							$meta_parts = array();
							if ( $show_title && $m( 'rr_author_job_title' ) ) {
								$meta_parts[] = esc_html( $m( 'rr_author_job_title' ) );
							}
							if ( $show_employer && $m( 'rr_author_employer' ) ) {
								$emp_name = esc_html( $m( 'rr_author_employer' ) );
								$emp_url  = $m( 'rr_author_employer_url' );
								$meta_parts[] = $emp_url ? '<a href="' . esc_url( $emp_url ) . '" rel="noopener">' . $emp_name . '</a>' : $emp_name;
							}
							if ( $show_years_exp && $years_exp > 0 ) {
								/* translators: %d number of years */
								$meta_parts[] = esc_html( sprintf( _n( '%d year experience', '%d years experience', $years_exp, 'rankready' ), $years_exp ) );
							}
							echo implode( ' <span class="rr-ab-sep">·</span> ', $meta_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					<?php endif; ?>

					<?php
					$bio = $m( 'rr_author_bio' );
					if ( '' === $bio ) $bio = $user->description;
					if ( $show_bio && $bio && 'inline' !== $layout ) :
					?>
						<p class="rr-ab-bio"><?php echo esc_html( $bio ); ?></p>
					<?php endif; ?>

					<?php if ( $show_expertise && 'card' === $layout && $m( 'rr_author_expertise' ) ) : ?>
						<div class="rr-ab-expertise">
							<?php
							$topics = array_filter( array_map( 'trim', explode( ',', $m( 'rr_author_expertise' ) ) ) );
							foreach ( $topics as $topic ) {
								echo '<span class="rr-ab-topic">' . esc_html( $topic ) . '</span>';
							}
							?>
						</div>
					<?php endif; ?>

					<?php if ( $show_credentials && 'card' === $layout ) : ?>
						<?php
						$education = self::decode_repeater( $m( 'rr_author_education' ) );
						$certs     = self::decode_repeater( $m( 'rr_author_certifications' ) );
						if ( ! empty( $education ) || ! empty( $certs ) ) :
						?>
							<div class="rr-ab-credentials">
								<?php foreach ( $education as $row ) : if ( empty( $row['degree'] ) && empty( $row['institution'] ) ) continue; ?>
									<div class="rr-ab-cred-row">
										<span class="rr-ab-cred-icon" aria-hidden="true">🎓</span>
										<?php echo esc_html( trim( ( $row['degree'] ?? '' ) . ( ! empty( $row['institution'] ) ? ' · ' . $row['institution'] : '' ) ) ); ?>
									</div>
								<?php endforeach; ?>
								<?php foreach ( $certs as $row ) : if ( empty( $row['name'] ) ) continue; ?>
									<div class="rr-ab-cred-row">
										<span class="rr-ab-cred-icon" aria-hidden="true">✓</span>
										<?php echo esc_html( trim( $row['name'] . ( ! empty( $row['issuer'] ) ? ' · ' . $row['issuer'] : '' ) ) ); ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $show_socials ) : ?>
						<?php
						$socials = self::get_social_links( $user_id );
						if ( ! empty( $socials ) ) :
						?>
							<div class="rr-ab-socials">
								<?php foreach ( $socials as $label => $url ) : ?>
									<a class="rr-ab-social rr-ab-social-<?php echo esc_attr( sanitize_key( $label ) ); ?>" href="<?php echo esc_url( $url ); ?>" rel="noopener me" target="_blank" aria-label="<?php echo esc_attr( $label ); ?>">
										<?php echo esc_html( $label ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $show_reviewed && ( $reviewed_by_id || $fact_checked_by_id || $last_reviewed ) ) : ?>
						<div class="rr-ab-reviewed">
							<?php
							$parts = array();
							if ( $fact_checked_by_id ) {
								$u = get_userdata( $fact_checked_by_id );
								if ( $u ) {
									$parts[] = esc_html__( 'Fact-checked by', 'rankready' ) . ' <a href="' . esc_url( get_author_posts_url( $fact_checked_by_id ) ) . '">' . esc_html( $u->display_name ) . '</a>';
								}
							}
							if ( $reviewed_by_id ) {
								$u = get_userdata( $reviewed_by_id );
								if ( $u ) {
									$parts[] = esc_html__( 'Reviewed by', 'rankready' ) . ' <a href="' . esc_url( get_author_posts_url( $reviewed_by_id ) ) . '">' . esc_html( $u->display_name ) . '</a>';
								}
							}
							if ( $last_reviewed ) {
								$ts = strtotime( $last_reviewed );
								if ( $ts ) {
									$parts[] = esc_html__( 'Last reviewed', 'rankready' ) . ' <time datetime="' . esc_attr( $last_reviewed ) . '">' . esc_html( wp_date( get_option( 'date_format' ), $ts ) ) . '</time>';
								}
							}
							echo implode( ' <span class="rr-ab-sep">·</span> ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					<?php endif; ?>

					<?php
					$factcheck_url = (string) get_option( RR_OPT_AUTHOR_FACTCHECK_URL, '' );
					$editorial_url = (string) get_option( RR_OPT_AUTHOR_EDITORIAL_URL, '' );
					if ( 'card' === $layout && ( $factcheck_url || $editorial_url ) ) :
					?>
						<div class="rr-ab-policy">
							<?php if ( $editorial_url ) : ?>
								<a href="<?php echo esc_url( $editorial_url ); ?>" rel="noopener"><?php esc_html_e( 'Editorial policy', 'rankready' ); ?></a>
							<?php endif; ?>
							<?php if ( $editorial_url && $factcheck_url ) : ?> <span class="rr-ab-sep">·</span> <?php endif; ?>
							<?php if ( $factcheck_url ) : ?>
								<a href="<?php echo esc_url( $factcheck_url ); ?>" rel="noopener"><?php esc_html_e( 'How we fact-check', 'rankready' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function get_social_links( int $user_id ): array {
		$m = function ( $key ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $key, true );
		};

		$links = array();
		$candidates = array(
			'LinkedIn' => 'rr_author_linkedin',
			'X'        => 'rr_author_twitter',
			'GitHub'   => 'rr_author_github',
			'YouTube'  => 'rr_author_youtube',
			'Scholar'  => 'rr_author_scholar',
			'ORCID'    => 'rr_author_orcid',
			'Website'  => 'rr_author_website',
		);

		foreach ( $candidates as $label => $key ) {
			$val = $m( $key );
			if ( '' === $val ) continue;
			if ( 'ORCID' === $label && 0 !== strpos( $val, 'http' ) ) {
				$val = 'https://orcid.org/' . $val;
			}
			$links[ $label ] = $val;
		}
		return $links;
	}

	/**
	 * Build the inline style="..." attribute from block attributes.
	 * Uses CSS variables so style.css does the layout.
	 */
	private static function inline_style( array $attrs ): string {
		$vars = array();

		$map = array(
			'boxBgColor'        => '--rr-ab-bg',
			'boxBorderColor'    => '--rr-ab-border',
			'boxBorderRadius'   => array( '--rr-ab-radius', 'px' ),
			'boxPadding'        => array( '--rr-ab-padding', 'px' ),
			'headingColor'      => '--rr-ab-heading-color',
			'headingFontSize'   => array( '--rr-ab-heading-size', 'px' ),
			'headingFontFamily' => '--rr-ab-heading-ff',
			'headingFontWeight' => '--rr-ab-heading-fw',
			'nameColor'         => '--rr-ab-name-color',
			'nameFontSize'      => array( '--rr-ab-name-size', 'px' ),
			'nameFontFamily'    => '--rr-ab-name-ff',
			'nameFontWeight'    => '--rr-ab-name-fw',
			'metaColor'         => '--rr-ab-meta-color',
			'metaFontSize'      => array( '--rr-ab-meta-size', 'px' ),
			'metaFontFamily'    => '--rr-ab-meta-ff',
			'bioColor'          => '--rr-ab-bio-color',
			'bioFontSize'       => array( '--rr-ab-bio-size', 'px' ),
			'bioFontFamily'     => '--rr-ab-bio-ff',
			'bioLineHeight'     => '--rr-ab-bio-lh',
			'imageSize'         => array( '--rr-ab-img-size', 'px' ),
			'imageRadius'       => array( '--rr-ab-img-radius', 'px' ),
			'socialColor'       => '--rr-ab-social-color',
			'socialSize'        => array( '--rr-ab-social-size', 'px' ),
		);

		foreach ( $map as $attr => $var ) {
			if ( empty( $attrs[ $attr ] ) ) continue;
			$value = $attrs[ $attr ];
			if ( is_array( $var ) ) {
				$vars[] = $var[0] . ':' . (float) $value . $var[1];
			} else {
				$vars[] = $var . ':' . esc_attr( (string) $value );
			}
		}

		if ( empty( $vars ) ) return '';
		return ' style="' . esc_attr( implode( ';', $vars ) ) . '"';
	}

	// ══════════════════════════════════════════════════════════════════════════
	// AUTO-DISPLAY via the_content filter
	// ══════════════════════════════════════════════════════════════════════════

	public static function maybe_auto_display( $content ) {
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) return $content;
		if ( 'on' !== get_option( RR_OPT_AUTHOR_ENABLE, 'on' ) ) return $content;

		$position = (string) get_option( RR_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
		if ( 'off' === $position ) return $content;

		$post_id = get_the_ID();
		if ( ! $post_id ) return $content;

		$post = get_post( $post_id );
		if ( ! $post ) return $content;

		// Per-post opt-out.
		if ( get_post_meta( $post_id, RR_META_AUTHOR_DISABLE, true ) ) return $content;

		// Post type allowlist.
		$allowed = (array) get_option( RR_OPT_AUTHOR_POST_TYPES, array( 'post' ) );
		if ( ! in_array( $post->post_type, $allowed, true ) ) return $content;

		// Skip if the block is already in content.
		if ( has_block( 'rankready/author-box', $post ) ) return $content;

		$html = self::render_html( (int) $post->post_author, array(), $post_id );
		if ( '' === $html ) return $content;

		if ( 'before' === $position ) return $html . $content;
		if ( 'after' === $position )  return $content . $html;
		if ( 'both' === $position )   return $html . $content . $html;

		return $content;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BLOCK render callback (registered from class-rr-block.php)
	// ══════════════════════════════════════════════════════════════════════════

	public static function render_block( $attrs, $content = '', $block = null ): string {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';

		$source  = isset( $attrs['authorSource'] ) ? $attrs['authorSource'] : 'post';
		$user_id = 'specific' === $source && ! empty( $attrs['authorId'] )
			? (int) $attrs['authorId']
			: (int) get_post_field( 'post_author', $post_id );

		if ( $user_id <= 0 ) return '';

		return self::render_html( $user_id, (array) $attrs, (int) $post_id );
	}

	/**
	 * Block attributes array — exposed for register_block_type in class-rr-block.php.
	 */
	public static function block_attributes(): array {
		return array(
			// Source.
			'authorSource'      => array( 'type' => 'string',  'default' => 'post' ),
			'authorId'          => array( 'type' => 'number',  'default' => 0 ),
			// Layout + content toggles.
			'layout'            => array( 'type' => 'string',  'default' => 'card' ),
			'showHeading'       => array( 'type' => 'boolean', 'default' => true ),
			'headingText'       => array( 'type' => 'string',  'default' => '' ),
			'headingTag'        => array( 'type' => 'string',  'default' => '' ),
			'showHeadshot'      => array( 'type' => 'boolean', 'default' => true ),
			'showJobTitle'      => array( 'type' => 'boolean', 'default' => true ),
			'showEmployer'      => array( 'type' => 'boolean', 'default' => true ),
			'showYearsExp'      => array( 'type' => 'boolean', 'default' => true ),
			'showBio'           => array( 'type' => 'boolean', 'default' => true ),
			'showExpertise'     => array( 'type' => 'boolean', 'default' => true ),
			'showCredentials'   => array( 'type' => 'boolean', 'default' => true ),
			'showSocials'       => array( 'type' => 'boolean', 'default' => true ),
			'showReviewed'      => array( 'type' => 'boolean', 'default' => true ),
			// Box styles.
			'boxBgColor'        => array( 'type' => 'string',  'default' => '' ),
			'boxBorderColor'    => array( 'type' => 'string',  'default' => '' ),
			'boxBorderRadius'   => array( 'type' => 'number',  'default' => 0 ),
			'boxPadding'        => array( 'type' => 'number',  'default' => 0 ),
			// Heading typography.
			'headingColor'      => array( 'type' => 'string',  'default' => '' ),
			'headingFontSize'   => array( 'type' => 'number',  'default' => 0 ),
			'headingFontFamily' => array( 'type' => 'string',  'default' => '' ),
			'headingFontWeight' => array( 'type' => 'string',  'default' => '' ),
			// Name typography.
			'nameColor'         => array( 'type' => 'string',  'default' => '' ),
			'nameFontSize'      => array( 'type' => 'number',  'default' => 0 ),
			'nameFontFamily'    => array( 'type' => 'string',  'default' => '' ),
			'nameFontWeight'    => array( 'type' => 'string',  'default' => '' ),
			// Meta typography.
			'metaColor'         => array( 'type' => 'string',  'default' => '' ),
			'metaFontSize'      => array( 'type' => 'number',  'default' => 0 ),
			'metaFontFamily'    => array( 'type' => 'string',  'default' => '' ),
			// Bio typography.
			'bioColor'          => array( 'type' => 'string',  'default' => '' ),
			'bioFontSize'       => array( 'type' => 'number',  'default' => 0 ),
			'bioFontFamily'     => array( 'type' => 'string',  'default' => '' ),
			'bioLineHeight'     => array( 'type' => 'number',  'default' => 0 ),
			// Image.
			'imageSize'         => array( 'type' => 'number',  'default' => 0 ),
			'imageRadius'       => array( 'type' => 'number',  'default' => 0 ),
			// Social icons.
			'socialColor'       => array( 'type' => 'string',  'default' => '' ),
			'socialSize'        => array( 'type' => 'number',  'default' => 0 ),
		);
	}
}
