<?php
/**
 * Elementor Widget — RankReady Author Box.
 *
 * Mirrors the Gutenberg block 1:1 using native Elementor controls so that
 * Elementor Global Fonts and Global Colors work out of the box.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Elementor_Author_Box_Widget extends \Elementor\Widget_Base {

	public function get_name(): string      { return 'rr_author_box'; }
	public function get_title(): string     { return esc_html__( 'RankReady Author Box', 'rankready' ); }
	public function get_icon(): string      { return 'eicon-person'; }
	public function get_categories(): array { return array( 'general' ); }
	public function get_keywords(): array   { return array( 'author', 'box', 'rankready', 'eeat', 'person', 'schema', 'bio' ); }

	protected function register_controls(): void {

		// ═══ CONTENT TAB ══════════════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_content', array(
			'label' => esc_html__( 'Content', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		$this->add_control( 'author_source', array(
			'label'   => esc_html__( 'Author Source', 'rankready' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'post',
			'options' => array(
				'post'     => esc_html__( 'Current post author', 'rankready' ),
				'specific' => esc_html__( 'Specific author', 'rankready' ),
			),
			'description' => esc_html__( 'Defaults to the post author. Use "Specific" to pin a specific user on a landing page.', 'rankready' ),
		) );

		$this->add_control( 'author_id', array(
			'label'       => esc_html__( 'Author (User ID)', 'rankready' ),
			'type'        => \Elementor\Controls_Manager::NUMBER,
			'min'         => 1,
			'condition'   => array( 'author_source' => 'specific' ),
			'description' => esc_html__( 'WordPress user ID. Find it at Users → All Users → hover an author.', 'rankready' ),
		) );

		$this->add_control( 'layout', array(
			'label'   => esc_html__( 'Layout', 'rankready' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'card',
			'options' => array(
				'card'    => esc_html__( 'Card (full box)', 'rankready' ),
				'compact' => esc_html__( 'Compact (small)', 'rankready' ),
				'inline'  => esc_html__( 'Inline byline', 'rankready' ),
			),
			'description' => esc_html__( 'Card = full end-of-article box. Compact = sidebar-ready. Inline = minimal byline row.', 'rankready' ),
		) );

		$this->add_control( 'show_heading', array(
			'label'        => esc_html__( 'Show Heading', 'rankready' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => array( 'layout!' => 'inline' ),
		) );

		$this->add_control( 'heading_text', array(
			'label'       => esc_html__( 'Heading Text', 'rankready' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => esc_html__( 'About the Author', 'rankready' ),
			'condition'   => array( 'show_heading' => 'yes', 'layout!' => 'inline' ),
			'description' => esc_html__( 'Override the site-wide default from RankReady → Author Box settings.', 'rankready' ),
		) );

		$this->add_control( 'heading_tag', array(
			'label'     => esc_html__( 'Heading HTML Tag', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'h3',
			'options'   => array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => 'P' ),
			'condition' => array( 'show_heading' => 'yes', 'layout!' => 'inline' ),
		) );

		$this->add_control( 'fields_heading', array(
			'label' => esc_html__( 'Visible Fields', 'rankready' ),
			'type'  => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		) );

		$toggles = array(
			'show_headshot'    => esc_html__( 'Headshot', 'rankready' ),
			'show_job_title'   => esc_html__( 'Job Title', 'rankready' ),
			'show_employer'    => esc_html__( 'Employer', 'rankready' ),
			'show_years_exp'   => esc_html__( 'Years of Experience', 'rankready' ),
			'show_bio'         => esc_html__( 'Bio', 'rankready' ),
			'show_expertise'   => esc_html__( 'Topics of Expertise', 'rankready' ),
			'show_credentials' => esc_html__( 'Credentials (Education + Certs)', 'rankready' ),
			'show_socials'     => esc_html__( 'Social Links', 'rankready' ),
			'show_reviewed'    => esc_html__( 'Reviewed-By / Last Reviewed', 'rankready' ),
		);
		foreach ( $toggles as $key => $label ) {
			$this->add_control( $key, array(
				'label'        => $label,
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			) );
		}

		$this->end_controls_section();

		// ═══ BOX STYLE ════════════════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_box_style', array(
			'label' => esc_html__( 'Box Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'box_bg', array(
			'label'     => esc_html__( 'Background Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-author-box' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'box_border',
			'selector' => '{{WRAPPER}} .rr-author-box',
		) );

		$this->add_responsive_control( 'box_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .rr-author-box' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'box_padding', array(
			'label'      => esc_html__( 'Padding', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array( '{{WRAPPER}} .rr-author-box' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'box_shadow',
			'selector' => '{{WRAPPER}} .rr-author-box',
		) );

		$this->end_controls_section();

		// ═══ HEADING STYLE ════════════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_heading_style', array(
			'label' => esc_html__( 'Heading Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_heading' => 'yes', 'layout!' => 'inline' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'heading_typography',
			'selector' => '{{WRAPPER}} .rr-ab-heading',
		) );
		$this->add_control( 'heading_color', array(
			'label'     => esc_html__( 'Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-heading' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ═══ NAME STYLE ═══════════════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_name_style', array(
			'label' => esc_html__( 'Name Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'name_typography',
			'selector' => '{{WRAPPER}} .rr-ab-name',
		) );
		$this->add_control( 'name_color', array(
			'label'     => esc_html__( 'Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-name' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'name_hover_color', array(
			'label'     => esc_html__( 'Hover Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-name:hover' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ═══ META STYLE (job title / employer / years) ════════════════════════
		$this->start_controls_section( 'rr_ab_meta_style', array(
			'label' => esc_html__( 'Meta Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'meta_typography',
			'selector' => '{{WRAPPER}} .rr-ab-meta',
		) );
		$this->add_control( 'meta_color', array(
			'label'     => esc_html__( 'Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-meta' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_section();

		// ═══ BIO STYLE ════════════════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_bio_style', array(
			'label' => esc_html__( 'Bio Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_bio' => 'yes' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'bio_typography',
			'selector' => '{{WRAPPER}} .rr-ab-bio',
		) );
		$this->add_control( 'bio_color', array(
			'label'     => esc_html__( 'Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-bio' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_section();

		// ═══ IMAGE STYLE ══════════════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_image_style', array(
			'label' => esc_html__( 'Image Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_headshot' => 'yes' ),
		) );
		$this->add_responsive_control( 'image_size', array(
			'label'      => esc_html__( 'Size', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 32, 'max' => 200 ) ),
			'selectors'  => array( '{{WRAPPER}} .rr-ab-headshot img' => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};object-fit:cover;' ),
		) );
		$this->add_responsive_control( 'image_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', '%' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 200 ), '%' => array( 'min' => 0, 'max' => 50 ) ),
			'selectors'  => array( '{{WRAPPER}} .rr-ab-headshot img' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			'description' => esc_html__( '50% = perfect circle.', 'rankready' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'image_border',
			'selector' => '{{WRAPPER}} .rr-ab-headshot img',
		) );
		$this->end_controls_section();

		// ═══ SOCIAL ICONS STYLE ═══════════════════════════════════════════════
		$this->start_controls_section( 'rr_ab_social_style', array(
			'label' => esc_html__( 'Social Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_socials' => 'yes' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'social_typography',
			'selector' => '{{WRAPPER}} .rr-ab-social',
		) );
		$this->add_control( 'social_color', array(
			'label'     => esc_html__( 'Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-social' => 'color: {{VALUE}};border-color:{{VALUE}};' ),
		) );
		$this->add_control( 'social_hover_color', array(
			'label'     => esc_html__( 'Hover Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-ab-social:hover' => 'color: {{VALUE}};border-color:{{VALUE}};' ),
		) );
		$this->add_responsive_control( 'social_gap', array(
			'label'      => esc_html__( 'Gap', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
			'selectors'  => array( '{{WRAPPER}} .rr-ab-socials' => 'gap:{{SIZE}}{{UNIT}};' ),
		) );
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$source  = isset( $settings['author_source'] ) ? $settings['author_source'] : 'post';
		$post_id = (int) get_the_ID();
		$user_id = 'specific' === $source && ! empty( $settings['author_id'] )
			? (int) $settings['author_id']
			: (int) get_post_field( 'post_author', $post_id );

		if ( $user_id <= 0 ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="rr-author-box rr-ab-placeholder">'
					. esc_html__( 'No author resolved for this post. Pick "Specific" and enter a user ID.', 'rankready' )
					. '</div>';
			}
			return;
		}

		$attrs = array(
			'layout'          => isset( $settings['layout'] ) ? $settings['layout'] : 'card',
			'showHeading'     => 'yes' === ( $settings['show_heading']    ?? 'yes' ),
			'headingText'     => $settings['heading_text'] ?? '',
			'headingTag'      => $settings['heading_tag']  ?? 'h3',
			'showHeadshot'    => 'yes' === ( $settings['show_headshot']    ?? 'yes' ),
			'showJobTitle'    => 'yes' === ( $settings['show_job_title']   ?? 'yes' ),
			'showEmployer'    => 'yes' === ( $settings['show_employer']    ?? 'yes' ),
			'showYearsExp'    => 'yes' === ( $settings['show_years_exp']   ?? 'yes' ),
			'showBio'         => 'yes' === ( $settings['show_bio']         ?? 'yes' ),
			'showExpertise'   => 'yes' === ( $settings['show_expertise']   ?? 'yes' ),
			'showCredentials' => 'yes' === ( $settings['show_credentials'] ?? 'yes' ),
			'showSocials'     => 'yes' === ( $settings['show_socials']     ?? 'yes' ),
			'showReviewed'    => 'yes' === ( $settings['show_reviewed']    ?? 'yes' ),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo RR_Author_Box::render_html( $user_id, $attrs, $post_id );
	}
}
