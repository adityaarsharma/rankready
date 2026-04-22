<?php
/**
 * Elementor Widget — RankReady FAQ.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Elementor_Faq_Widget extends \Elementor\Widget_Base {

	public function get_name(): string        { return 'rr_faq'; }
	public function get_title(): string       { return esc_html__( 'FAQ (RankReady)', 'rankready' ); }
	public function get_icon(): string        { return 'eicon-help-o'; }
	public function get_categories(): array   { return array( 'general' ); }
	public function get_keywords(): array     { return array( 'faq', 'questions', 'ai', 'rankready', 'schema', 'seo' ); }

	protected function register_controls(): void {

		// ── Content tab ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_content', array(
			'label' => esc_html__( 'Content', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		$this->add_control( 'show_title', array(
			'label'        => esc_html__( 'Show Title', 'rankready' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'Yes', 'rankready' ),
			'label_off'    => esc_html__( 'No', 'rankready' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->add_control( 'title_text', array(
			'label'       => esc_html__( 'Title Text', 'rankready' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => 'Frequently Asked Questions',
			'placeholder' => 'Frequently Asked Questions',
			'condition'   => array( 'show_title' => 'yes' ),
		) );

		$this->add_control( 'heading_tag', array(
			'label'     => esc_html__( 'Title Tag', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'h3',
			'options'   => array(
				'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4',
				'h5' => 'H5', 'h6' => 'H6',
			),
			'condition' => array( 'show_title' => 'yes' ),
		) );

		$this->add_control( 'show_reviewed', array(
			'label'        => esc_html__( 'Show "Last Reviewed" Date', 'rankready' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'Yes', 'rankready' ),
			'label_off'    => esc_html__( 'No', 'rankready' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->end_controls_section();

		// ── Box Style tab ─────────────────────────────────────────────────────
		$this->start_controls_section( 'section_box_style', array(
			'label' => esc_html__( 'Box Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'bg_color', array(
			'label'     => esc_html__( 'Background Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-faq-wrapper' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'box_border',
			'selector' => '{{WRAPPER}} .rr-faq-wrapper',
		) );

		$this->add_responsive_control( 'border_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 20 ) ),
			'selectors'  => array( '{{WRAPPER}} .rr-faq-wrapper' => 'border-radius: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'padding', array(
			'label'      => esc_html__( 'Padding', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array(
				'{{WRAPPER}} .rr-faq-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();

		// ── Title Style ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_title_style', array(
			'label'     => esc_html__( 'Title Style', 'rankready' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_title' => 'yes' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'title_typography',
			'selector' => '{{WRAPPER}} .rr-faq-title',
		) );

		$this->add_control( 'title_color', array(
			'label'     => esc_html__( 'Title Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-faq-title' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ── Question Style ────────────────────────────────────────────────────
		$this->start_controls_section( 'section_question_style', array(
			'label' => esc_html__( 'Question Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'question_typography',
			'selector' => '{{WRAPPER}} .rr-faq-question',
		) );

		$this->add_control( 'question_color', array(
			'label'     => esc_html__( 'Question Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-faq-question' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ── Answer Style ──────────────────────────────────────────────────────
		$this->start_controls_section( 'section_answer_style', array(
			'label' => esc_html__( 'Answer Style', 'rankready' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'answer_typography',
			'selector' => '{{WRAPPER}} .rr-faq-answer',
		) );

		$this->add_control( 'answer_color', array(
			'label'     => esc_html__( 'Answer Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-faq-answer' => 'color: {{VALUE}};' ),
		) );

		$this->add_control( 'divider_color', array(
			'label'     => esc_html__( 'Divider Color', 'rankready' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rr-faq-item' => 'border-bottom-color: {{VALUE}};' ),
		) );

		$this->add_responsive_control( 'answer_spacing', array(
			'label'      => esc_html__( 'Item Spacing', 'rankready' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'selectors'  => array( '{{WRAPPER}} .rr-faq-item' => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$post_id  = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$faq_data = RR_Faq::get_faq_data( $post_id );

		if ( empty( $faq_data ) ) {
			if ( isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				$tag = RR_Block::validate_heading_tag( isset( $settings['heading_tag'] ) ? $settings['heading_tag'] : 'h3' );
				echo '<div class="rr-faq-wrapper">';
				if ( 'yes' === ( $settings['show_title'] ?? 'yes' ) ) {
					$title = ! empty( $settings['title_text'] ) ? $settings['title_text'] : 'Frequently Asked Questions';
					echo '<' . esc_attr( $tag ) . ' class="rr-faq-title">' . esc_html( $title ) . '</' . esc_attr( $tag ) . '>';
				}
				echo '<div class="rr-faq-list">'
					. '<div class="rr-faq-item">'
					. '<h4 class="rr-faq-question" style="opacity:0.4;">'
					. esc_html__( 'FAQ will appear here after generating. Go to RankReady > FAQ Generator tab or use the Gutenberg block to generate.', 'rankready' )
					. '</h4></div></div></div>';
			}
			return;
		}

		$show_title = 'yes' === ( $settings['show_title'] ?? 'yes' );
		$title_text = ! empty( $settings['title_text'] ) ? sanitize_text_field( $settings['title_text'] ) : __( 'Frequently Asked Questions', 'rankready' );
		$tag        = RR_Block::validate_heading_tag( ! empty( $settings['heading_tag'] ) ? $settings['heading_tag'] : 'h3' );
		$show_reviewed = 'yes' === ( $settings['show_reviewed'] ?? 'yes' );

		echo '<div class="rr-faq-wrapper">';

		if ( $show_title ) {
			echo '<' . esc_attr( $tag ) . ' class="rr-faq-title">'
				. esc_html( $title_text )
				. '</' . esc_attr( $tag ) . '>';
		}

		echo '<div class="rr-faq-list">';

		foreach ( $faq_data as $item ) {
			$q = isset( $item['question'] ) ? $item['question'] : '';
			$a = isset( $item['answer'] ) ? $item['answer'] : '';
			if ( empty( $q ) || empty( $a ) ) {
				continue;
			}

			echo '<div class="rr-faq-item">';
			echo '<h4 class="rr-faq-question">' . esc_html( $q ) . '</h4>';
			echo '<p class="rr-faq-answer">' . wp_kses_post( RR_Faq::convert_markdown_links( $a ) ) . '</p>';
			echo '</div>';
		}

		echo '</div>';

		if ( $show_reviewed ) {
			$modified_ts = get_the_modified_time( 'U', $post_id );
			if ( ! empty( $modified_ts ) ) {
				$date = wp_date( get_option( 'date_format' ), (int) $modified_ts );
				echo '<p class="rr-faq-reviewed">'
					. esc_html( sprintf( __( 'Last reviewed: %s', 'rankready' ), $date ) )
					. '</p>';
			}
		}

		echo '</div>';
	}
}
