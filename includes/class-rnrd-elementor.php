<?php
/**
 * Elementor Widget — RankReady AI Summary.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name(): string        { return 'rnrd_ai_summary'; }
	public function get_title(): string       { return esc_html__( 'AI Summary', 'rankready-ai-llm-seo' ); }
	public function get_icon(): string        { return 'eicon-bullet-list'; }
	public function get_categories(): array   { return array( 'general' ); }
	public function get_keywords(): array     { return array( 'summary', 'ai', 'rankready-ai-llm-seo', 'takeaways', 'seo' ); }

	protected function register_controls(): void {

		$global_label = (string) get_option( RNRD_OPT_LABEL, 'Key Takeaways' );
		$global_show  = (bool) get_option( RNRD_OPT_SHOW_LABEL, '1' );
		$global_tag   = (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' );

		// ── Content tab ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_content', array(
			'label' => esc_html__( 'Content', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		$this->add_control( 'show_label', array(
			'label'        => esc_html__( 'Show Label', 'rankready-ai-llm-seo' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'Yes', 'rankready-ai-llm-seo' ),
			'label_off'    => esc_html__( 'No', 'rankready-ai-llm-seo' ),
			'return_value' => 'yes',
			'default'      => $global_show ? 'yes' : '',
		) );

		$this->add_control( 'label_text', array(
			'label'       => esc_html__( 'Label Text', 'rankready-ai-llm-seo' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => $global_label,
			'placeholder' => $global_label,
			'condition'   => array( 'show_label' => 'yes' ),
		) );

		$this->add_control( 'heading_tag', array(
			'label'     => esc_html__( 'Label HTML Tag', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => $global_tag,
			'options'   => array(
				'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4',
				'h5' => 'H5', 'h6' => 'H6', 'p'  => 'P',
			),
			'condition' => array( 'show_label' => 'yes' ),
		) );

		$this->end_controls_section();

		// ── Box Style tab ─────────────────────────────────────────────────────
		$this->start_controls_section( 'section_box_style', array(
			'label' => esc_html__( 'Box Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'border_color', array(
			'label'     => esc_html__( 'Border Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-summary' => 'border-left-color: {{VALUE}};' ),
		) );

		$this->add_control( 'bg_color', array(
			'label'     => esc_html__( 'Background Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-summary' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_responsive_control( 'padding', array(
			'label'      => esc_html__( 'Padding', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array(
				'{{WRAPPER}} .rnrd-summary' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
		) );

		$this->add_responsive_control( 'border_width', array(
			'label'      => esc_html__( 'Border Width', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-summary' => 'border-left-width: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'border_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-summary' => 'border-radius: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();

		// ── Label Style ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_label_style', array(
			'label'     => esc_html__( 'Label Style', 'rankready-ai-llm-seo' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_label' => 'yes' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'label_typography',
			'selector' => '{{WRAPPER}} .rnrd-label',
		) );

		$this->add_control( 'label_color', array(
			'label'     => esc_html__( 'Label Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-label' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ── Bullets Style ─────────────────────────────────────────────────────
		$this->start_controls_section( 'section_bullets_style', array(
			'label' => esc_html__( 'Bullets Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'bullets_typography',
			'selector' => '{{WRAPPER}} .rnrd-bullet',
		) );

		$this->add_control( 'bullets_color', array(
			'label'     => esc_html__( 'Text Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-bullet' => 'color: {{VALUE}};' ),
		) );

		$this->add_control( 'marker_color', array(
			'label'     => esc_html__( 'Marker Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-bullet::marker' => 'color: {{VALUE}};' ),
		) );

		$this->add_responsive_control( 'bullet_gap', array(
			'label'      => esc_html__( 'Space Between', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-bullet' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$post_id  = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );

		if ( empty( $raw ) ) {
			if ( isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				$tag = RNRD_Block::validate_heading_tag( isset( $settings['heading_tag'] ) ? $settings['heading_tag'] : get_option( RNRD_OPT_HEADING_TAG, 'h4' ) );
				echo '<div class="rnrd-summary">';
				$show = isset( $settings['show_label'] ) ? $settings['show_label'] : ( get_option( RNRD_OPT_SHOW_LABEL, '1' ) ? 'yes' : '' );
				if ( 'yes' === $show ) {
					$label = isset( $settings['label_text'] ) && '' !== $settings['label_text'] ? $settings['label_text'] : get_option( RNRD_OPT_LABEL, 'Key Takeaways' );
					echo '<' . esc_attr( $tag ) . ' class="rnrd-label">' . esc_html( $label ) . '</' . esc_attr( $tag ) . '>';
				}
				echo '<ul class="rnrd-bullets">'
					. '<li class="rnrd-bullet" style="opacity:0.4;">'
					. esc_html__( 'Summary will appear here after publishing.', 'rankready-ai-llm-seo' )
					. '</li></ul></div>';
			}
			return;
		}

		$summary    = RNRD_Generator::decode_summary( $raw );
		$show_label = 'yes' === ( isset( $settings['show_label'] ) ? $settings['show_label'] : ( get_option( RNRD_OPT_SHOW_LABEL, '1' ) ? 'yes' : '' ) );
		$label_text = sanitize_text_field(
			! empty( $settings['label_text'] )
				? $settings['label_text']
				: (string) get_option( RNRD_OPT_LABEL, 'Key Takeaways' )
		);
		$tag = RNRD_Block::validate_heading_tag(
			! empty( $settings['heading_tag'] )
				? $settings['heading_tag']
				: (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' )
		);

		echo '<div class="rnrd-summary">';

		if ( $show_label && ! empty( $label_text ) ) {
			echo '<' . esc_attr( $tag ) . ' class="rnrd-label">'
				. esc_html( $label_text )
				. '</' . esc_attr( $tag ) . '>';
		}

		if ( 'bullets' === $summary['type'] ) {
			echo '<ul class="rnrd-bullets">';
			foreach ( (array) $summary['data'] as $bullet ) {
				echo '<li class="rnrd-bullet">' . esc_html( $bullet ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="rnrd-text">' . esc_html( $summary['data'] ) . '</p>';
		}

		echo '</div>';
	}
}
