<?php
/**
 * Schema.org JSON-LD emission and SEO-plugin merge layer.
 *
 * Settings toggles live under AI Content → Schema. Feature classes
 * (RNRD_Summary, RNRD_Faq, RNRD_Author_Box) supply data; this class
 * decides what to emit based on those toggles and active SEO plugins.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Schema {

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'maybe_inject_article' ), 1 );
		add_action( 'wp_head', array( self::class, 'inject_faq_page' ), 20 );

		add_filter( 'rank_math/json_ld', array( self::class, 'merge_rankmath_schema' ), 99, 2 );
		add_filter( 'wpseo_schema_graph', array( self::class, 'merge_yoast_schema' ), 99 );
		add_filter( 'aioseo_schema_output', array( self::class, 'merge_aioseo_schema' ), 99 );
		add_filter( 'seopress_schemas_auto_article_json', array( self::class, 'merge_seopress_schema' ), 99 );
		add_filter( 'seopress_pro_get_json_data_article', array( self::class, 'merge_seopress_schema' ), 99 );
		add_filter( 'the_seo_framework_schema_graph_data', array( self::class, 'merge_tsf_schema' ), 99 );
		add_filter( 'slim_seo_schema_graph', array( self::class, 'merge_slim_seo_schema' ), 99 );
		add_filter( 'sq_json_ld_data', array( self::class, 'merge_squirrly_schema' ), 99 );
	}

	/**
	 * Whether a major SEO plugin is active (Article schema delegated to it).
	 */
	public static function has_active_seo_plugin(): bool {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return true;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return true;
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return true;
		}
		if ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) {
			return true;
		}
		if ( function_exists( 'the_seo_framework' ) ) {
			return true;
		}
		if ( defined( 'SLIM_SEO_VER' ) ) {
			return true;
		}
		if ( defined( 'SQ_VERSION' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Standalone Article JSON-LD when no SEO plugin handles Article schema.
	 */
	public static function maybe_inject_article(): void {
		if ( 'on' !== get_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' ) ) {
			return;
		}

		$force_standalone = (bool) apply_filters( 'rankready_force_standalone_schema', false );
		if ( ! $force_standalone && self::has_active_seo_plugin() ) {
			return;
		}

		if ( ! is_singular() || ! apply_filters( 'rankready_inject_schema', true ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}
		if ( ! class_exists( 'RNRD_Summary' ) || ! RNRD_Summary::is_enabled() || ! RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			return;
		}

		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return;
		}

		$summary     = RNRD_Generator::decode_summary( $raw );
		$description = '';

		if ( 'bullets' === $summary['type'] && is_array( $summary['data'] ) ) {
			$description = implode( ' ', $summary['data'] );
		} elseif ( 'text' === $summary['type'] ) {
			$description = (string) $summary['data'];
		}

		if ( empty( $description ) ) {
			return;
		}

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => get_the_title( $post_id ),
			'description'   => $description,
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_post_time( 'c', true, $post ),
			'inLanguage'    => RNRD_LLM::detect_content_language( (int) $post_id )['code'],
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url(),
			),
		);

		$schema = array_merge( $schema, self::build_article_properties( $post_id, $summary ) );

		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$schema['keywords'] = implode( ', ', $tags );
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			if ( $img ) {
				$schema['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $img[0],
					'width'  => $img[1],
					'height' => $img[2],
				);
			}
		}

		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_img = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $logo_img ) {
				$schema['publisher']['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_img[0],
				);
			}
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $schema, JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * FAQPage JSON-LD on singular views (RNRD_Faq::build_faq_schema_array supplies data).
	 */
	public static function inject_faq_page(): void {
		if ( ! class_exists( 'RNRD_Faq' ) || ! RNRD_Faq::is_enabled() ) {
			return;
		}
		if ( 'on' !== get_option( RNRD_OPT_SCHEMA_FAQ, 'on' ) ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}

		if ( ! is_post_type_viewable( $post->post_type ) || ! RNRD_Faq::is_post_type_enabled( $post->post_type ) ) {
			return;
		}

		if ( get_post_meta( $post->ID, RNRD_META_FAQ_DISABLE, true ) ) {
			return;
		}

		if ( self::has_existing_faq_schema( $post ) ) {
			return;
		}

		$schema = RNRD_Faq::build_faq_schema_array( $post->ID );
		if ( empty( $schema ) ) {
			return;
		}

		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Check if another plugin already outputs FAQ schema for this post.
	 */
	private static function has_existing_faq_schema( WP_Post $post ): bool {
		$content = $post->post_content;

		if ( false !== strpos( $content, 'rank-math/faq-block' ) ) {
			return true;
		}
		if ( false !== strpos( $content, 'yoast-seo/faq' ) || false !== strpos( $content, 'yoast/faq' ) ) {
			return true;
		}
		if ( false !== strpos( $content, 'aioseo/faq' ) ) {
			return true;
		}

		return false;
	}

	private static function merge_into_article_node( array &$nodes, int $post_id ): void {
		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return;
		}

		$summary  = RNRD_Generator::decode_summary( $raw );
		$ai_props = self::build_article_properties( $post_id, $summary );
		$article_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' );

		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
				continue;
			}

			$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( ! array_intersect( $type, $article_types ) ) {
				continue;
			}

			foreach ( $ai_props as $prop => $value ) {
				if ( ! isset( $node[ $prop ] ) ) {
					$node[ $prop ] = $value;
				}
			}
			break;
		}
		unset( $node );
	}

	public static function merge_rankmath_schema( $data, $jsonld ): array {
		if ( ! is_singular() || ! is_array( $data ) ) {
			return $data;
		}
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			self::merge_into_article_node( $data, $post_id );
		}
		return $data;
	}

	public static function merge_yoast_schema( $graph ): array {
		if ( ! is_singular() || ! is_array( $graph ) ) {
			return $graph;
		}
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			self::merge_into_article_node( $graph, $post_id );
		}
		return $graph;
	}

	public static function merge_aioseo_schema( $graphs ): array {
		if ( ! is_singular() || ! is_array( $graphs ) ) {
			return $graphs;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $graphs;
		}

		foreach ( $graphs as &$graph ) {
			if ( is_array( $graph ) && isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
				self::merge_into_article_node( $graph['@graph'], $post_id );
			} elseif ( is_array( $graph ) && isset( $graph['@type'] ) ) {
				$single = array( &$graph );
				self::merge_into_article_node( $single, $post_id );
			}
		}
		unset( $graph );

		return $graphs;
	}

	public static function merge_seopress_schema( $schema ) {
		if ( ! is_singular() || ! is_array( $schema ) ) {
			return $schema;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $schema;
		}

		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return $schema;
		}

		$summary  = RNRD_Generator::decode_summary( $raw );
		$ai_props = self::build_article_properties( $post_id, $summary );

		foreach ( $ai_props as $prop => $value ) {
			if ( ! isset( $schema[ $prop ] ) ) {
				$schema[ $prop ] = $value;
			}
		}

		return $schema;
	}

	public static function merge_tsf_schema( $graph ): array {
		if ( ! is_singular() || ! is_array( $graph ) ) {
			return $graph;
		}
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			self::merge_into_article_node( $graph, $post_id );
		}
		return $graph;
	}

	public static function merge_slim_seo_schema( $graph ): array {
		if ( ! is_singular() || ! is_array( $graph ) ) {
			return $graph;
		}
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			self::merge_into_article_node( $graph, $post_id );
		}
		return $graph;
	}

	public static function merge_squirrly_schema( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) {
			return $graph;
		}
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			self::merge_into_article_node( $graph, $post_id );
		}
		return $graph;
	}

	/**
	 * AI-friendly Article properties merged into standalone or SEO-plugin graphs.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $summary Decoded summary from RNRD_Generator::decode_summary().
	 */
	public static function build_article_properties( int $post_id, array $summary ): array {
		$props = array();
		$post  = get_post( $post_id );
		$summary_type_ok = $post instanceof WP_Post
			&& class_exists( 'RNRD_Summary' )
			&& RNRD_Summary::is_enabled()
			&& RNRD_Summary::is_post_type_enabled( $post->post_type );
		$faq_type_ok = $post instanceof WP_Post
			&& class_exists( 'RNRD_Faq' )
			&& RNRD_Faq::is_enabled()
			&& RNRD_Faq::is_post_type_enabled( $post->post_type );

		if ( 'on' === get_option( RNRD_OPT_SCHEMA_SPEAKABLE, 'on' ) ) {
			$speakable_selectors = array( 'h1', '.entry-title' );
			if ( $summary_type_ok && ! empty( get_post_meta( $post_id, RNRD_META_SUMMARY, true ) ) ) {
				$speakable_selectors[] = '.rnrd-summary';
			}
			if ( $faq_type_ok && ! empty( get_post_meta( $post_id, RNRD_META_FAQ, true ) ) ) {
				$speakable_selectors[] = '.rnrd-faq-wrapper';
			}
			$props['speakable'] = array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => $speakable_selectors,
			);
		}

		if ( $summary_type_ok && 'bullets' === $summary['type'] && is_array( $summary['data'] ) && ! empty( $summary['data'] ) ) {
			$label = (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) );
			$props['hasPart'] = array(
				array(
					'@type'       => 'WebPageElement',
					'cssSelector' => '.rnrd-summary',
					'name'        => $label,
					'text'        => implode( '. ', $summary['data'] ) . '.',
				),
			);

			$faq_data = get_post_meta( $post_id, RNRD_META_FAQ, true );
			if ( $faq_type_ok && ! empty( $faq_data ) ) {
				$faq_items = json_decode( $faq_data, true );
				if ( is_array( $faq_items ) && ! empty( $faq_items ) ) {
					$faq_text = array();
					foreach ( array_slice( $faq_items, 0, 5 ) as $item ) {
						if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
							$faq_text[] = $item['question'] . ' ' . $item['answer'];
						}
					}
					if ( ! empty( $faq_text ) ) {
						$props['hasPart'][] = array(
							'@type'       => 'WebPageElement',
							'cssSelector' => '.rnrd-faq-wrapper',
							'name'        => __( 'Frequently Asked Questions', 'rankready-ai-llm-seo' ),
							'text'        => implode( ' ', $faq_text ),
						);
					}
				}
			}
		}

		if ( $summary_type_ok && 'bullets' === $summary['type'] && is_array( $summary['data'] ) && ! empty( $summary['data'] ) ) {
			$props['abstract'] = implode( '. ', $summary['data'] ) . '.';
		} elseif ( $summary_type_ok && 'text' === $summary['type'] && ! empty( $summary['data'] ) ) {
			$props['abstract'] = (string) $summary['data'];
		}

		$about = array();
		if ( $post ) {
			$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
			foreach ( $taxonomies as $tax ) {
				if ( ! $tax->hierarchical || ! $tax->public ) {
					continue;
				}
				$terms = get_the_terms( $post_id, $tax->name );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( 'uncategorized' === $term->slug ) {
							continue;
						}
						$term_url = get_term_link( $term );
						if ( is_wp_error( $term_url ) ) {
							continue;
						}
						$about[] = array(
							'@type' => 'Thing',
							'name'  => $term->name,
							'url'   => $term_url,
						);
						if ( count( $about ) >= 5 ) {
							break 2;
						}
					}
				}
			}
		}
		if ( ! empty( $about ) ) {
			$props['about'] = count( $about ) === 1 ? $about[0] : $about;
		}

		$mentions = array();
		if ( $post ) {
			$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
			foreach ( $taxonomies as $tax ) {
				if ( $tax->hierarchical || ! $tax->public ) {
					continue;
				}
				$terms = get_the_terms( $post_id, $tax->name );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$term_url = get_term_link( $term );
						if ( is_wp_error( $term_url ) ) {
							continue;
						}
						$mentions[] = array(
							'@type' => 'Thing',
							'name'  => $term->name,
							'url'   => $term_url,
						);
						if ( count( $mentions ) >= 8 ) {
							break 2;
						}
					}
				}
			}
		}
		if ( ! empty( $mentions ) ) {
			$props['mentions'] = $mentions;
		}

		$modified_ts = get_the_modified_time( 'U', $post_id );
		if ( ! empty( $modified_ts ) ) {
			$props['lastReviewed'] = gmdate( 'Y-m-d', (int) $modified_ts );
		}

		if ( $post ) {
			$author_name = get_the_author_meta( 'display_name', $post->post_author );
			$author_url  = get_author_posts_url( $post->post_author );
			$author_desc = get_the_author_meta( 'description', $post->post_author );

			if ( ! empty( $author_name ) ) {
				$reviewed_by = array(
					'@type' => 'Person',
					'name'  => $author_name,
					'url'   => $author_url,
				);
				if ( ! empty( $author_desc ) ) {
					$reviewed_by['description'] = wp_trim_words( $author_desc, 30 );
				}
				$props['reviewedBy'] = $reviewed_by;
			}
		}

		if ( $post && ! empty( $post->post_content ) ) {
			$links = self::extract_content_links( $post->post_content, $post_id );

			if ( ! empty( $links['internal'] ) ) {
				$props['significantLink'] = array_slice( $links['internal'], 0, 10 );
			}
			if ( ! empty( $links['external'] ) ) {
				$citations = array();
				foreach ( array_slice( $links['external'], 0, 10 ) as $ext_url ) {
					$citations[] = array(
						'@type' => 'CreativeWork',
						'url'   => $ext_url,
					);
				}
				$props['citation'] = $citations;
			}
		}

		$features = array();
		if ( $post && ! empty( $post->post_content ) ) {
			if ( preg_match( '/class="[^"]*(?:table-of-contents|toc-widget|ez-toc|lwptoc|rr-toc)[^"]*"/i', $post->post_content )
				|| preg_match( '/<!-- wp:rank-math\/toc-block/i', $post->post_content )
				|| has_block( 'rank-math/toc-block', $post )
			) {
				$features[] = 'tableOfContents';
			}

			if ( preg_match_all( '/<h[23][^>]*>/i', $post->post_content, $h_matches ) && count( $h_matches[0] ) >= 2 ) {
				$features[] = 'structuralNavigation';
			}

			if ( preg_match( '/<img[^>]+alt="[^"]+"/i', $post->post_content ) ) {
				$features[] = 'alternativeText';
			}
		}

		if ( ! empty( $summary['data'] ) ) {
			$features[] = 'longDescription';
		}

		if ( ! empty( $features ) ) {
			$props['accessibilityFeature'] = array_unique( $features );
		}

		return apply_filters( 'rankready_ai_schema_properties', $props, $post_id );
	}

	private static function extract_content_links( string $content, int $post_id ): array {
		$result = array( 'internal' => array(), 'external' => array() );

		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			return $result;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$self_url  = get_permalink( $post_id );
		$seen      = array();

		foreach ( $matches[1] as $url ) {
			$url = esc_url( $url );
			if ( empty( $url ) || isset( $seen[ $url ] ) ) {
				continue;
			}
			if ( $url === $self_url || 0 === strpos( $url, '#' ) ) {
				continue;
			}

			$seen[ $url ] = true;
			$parsed_host  = wp_parse_url( $url, PHP_URL_HOST );

			if ( $parsed_host && $parsed_host === $site_host ) {
				$result['internal'][] = $url;
			} elseif ( $parsed_host && false === strpos( $url, 'javascript:' ) ) {
				$skip = array( 'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'pinterest.com', 'tiktok.com', 'wa.me', 'whatsapp.com', 't.me', 'telegram.org', 'play.google.com', 'apps.apple.com' );
				$is_social = false;
				foreach ( $skip as $s ) {
					if ( false !== strpos( $parsed_host, $s ) ) {
						$is_social = true;
						break;
					}
				}
				if ( ! $is_social ) {
					$result['external'][] = $url;
				}
			}
		}

		return $result;
	}
}
