<?php
/**
 * Frontend renderer for v2 Form Builder fields.
 *
 * Reads the JSON field schema stored by PSBDX_SRM_Form_Builder and outputs
 * the corresponding HTML fields inside the existing modal structure.
 *
 * The old shortcode continues to work for v1 forms so nothing is broken.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Form_Renderer
 *
 * Static helpers consumed by PSBDX_SRM_Shortcodes to render v2 fields.
 *
 * @since 1.3.0
 */
class PSBDX_SRM_Form_Renderer {

	/**
	 * Render all v2 builder fields for a given form.
	 *
	 * Called from the shortcode render method when the form has
	 * `_psrm_form_version = 2`.
	 *
	 * @since  1.3.0
	 * @param  int    $form_id  Report Form post ID.
	 * @param  string $uid      Unique instance suffix (prevents ID collisions).
	 * @return string           HTML output (escaped).
	 */
	public static function render_fields( $form_id, $uid ) {
		$json   = get_post_meta( $form_id, PSBDX_SRM_Form_Builder::FIELDS_META_KEY, true );
		$fields = json_decode( $json, true );

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return '';
		}

		$captcha_provider = PSBDX_SRM_Captcha::active_provider();
		$captcha_site_key = $captcha_provider ? PSBDX_SRM_Captcha::get_opt( $captcha_provider, 'site_key' ) : '';

		$out = '';

		foreach ( $fields as $field ) {
			$type     = sanitize_key( $field['type'] ?? '' );
			$label    = esc_html( $field['label'] ?? '' );
			$handle   = sanitize_key( $field['handle'] ?? '' );
			$required = ! empty( $field['required'] );
			$field_id = 'psrm-field-' . esc_attr( $uid ) . '-' . esc_attr( $handle );

			$req_attr  = $required ? ' required' : '';
			$req_badge = $required
				? '<span class="psbdx-required" aria-label="' . esc_attr__( 'required', 'psbdx-smart-report-management' ) . '">*</span>'
				: '<span class="psbdx-optional">' . esc_html__( 'optional', 'psbdx-smart-report-management' ) . '</span>';

			switch ( $type ) {

				case 'name':
					$out .= '<div class="psbdx-field psrm-field-name-row">';
					$out .= '<div class="psrm-name-col">';
					$out .= '<label for="' . esc_attr( $field_id ) . '-first">'
						. esc_html__( 'First Name', 'psbdx-smart-report-management' )
						. ( $required ? ' <span class="psbdx-required" aria-label="' . esc_attr__( 'required', 'psbdx-smart-report-management' ) . '">*</span>' : '' )
						. '</label>';
					$out .= '<input type="text" id="' . esc_attr( $field_id ) . '-first"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . '_first]"'
						. ' placeholder="' . esc_attr__( 'First Name', 'psbdx-smart-report-management' ) . '"'
						. $req_attr . ' autocomplete="given-name">';
					$out .= '</div>';
					$out .= '<div class="psrm-name-col">';
					$out .= '<label for="' . esc_attr( $field_id ) . '-last">'
						. esc_html__( 'Last Name', 'psbdx-smart-report-management' )
						. ( $required ? ' <span class="psbdx-required" aria-label="' . esc_attr__( 'required', 'psbdx-smart-report-management' ) . '">*</span>' : '' )
						. '</label>';
					$out .= '<input type="text" id="' . esc_attr( $field_id ) . '-last"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . '_last]"'
						. ' placeholder="' . esc_attr__( 'Last Name', 'psbdx-smart-report-management' ) . '"'
						. ' autocomplete="family-name">';
					$out .= '</div>';
					$out .= '</div>';
					break;

				case 'email':
					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<input type="email" id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' placeholder="' . esc_attr__( 'email@example.com', 'psbdx-smart-report-management' ) . '"'
						. ' autocomplete="email"' . $req_attr . '>';
					$out .= '</div>';
					break;

				case 'mobile':
					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<input type="tel" id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' placeholder="' . esc_attr__( '+1 555 000 0000', 'psbdx-smart-report-management' ) . '"'
						. ' autocomplete="tel"' . $req_attr . '>';
					$out .= '</div>';
					break;

				case 'text':
					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<input type="text" id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' placeholder="' . $label . '"' . $req_attr . '>';
					$out .= '</div>';
					break;

				case 'paragraph':
					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<textarea id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' rows="4" placeholder="' . $label . '"' . $req_attr . '></textarea>';
					$out .= '</div>';
					break;

				case 'number':
					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<input type="number" id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' placeholder="0"' . $req_attr . '>';
					$out .= '</div>';
					break;

				case 'select':
					$choices      = is_array( $field['choices'] ?? null ) ? $field['choices'] : array();
					$other_option = ! empty( $field['other_option'] );
					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<select id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' class="psrm-select-field"'
						. ' data-other="' . ( $other_option ? '1' : '0' ) . '"'
						. $req_attr . '>';
					$out .= '<option value="">' . esc_html__( '— Select —', 'psbdx-smart-report-management' ) . '</option>';
					foreach ( $choices as $choice ) {
						$out .= '<option value="' . esc_attr( $choice ) . '">' . esc_html( $choice ) . '</option>';
					}
					if ( $other_option ) {
						$out .= '<option value="__other__">' . esc_html__( 'Other', 'psbdx-smart-report-management' ) . '</option>';
					}
					$out .= '</select>';
					if ( $other_option ) {
						$out .= '<div class="psrm-other-input" style="display:none;" data-for="' . esc_attr( $field_id ) . '">';
						$out .= '<label for="' . esc_attr( $field_id ) . '-other">'
							. esc_html__( 'Please specify', 'psbdx-smart-report-management' )
							. ' <span class="psbdx-required">*</span></label>';
						$out .= '<input type="text" id="' . esc_attr( $field_id ) . '-other"'
							. ' name="psrm_v2[' . esc_attr( $handle ) . '_other]"'
							. ' placeholder="' . esc_attr__( 'Please describe…', 'psbdx-smart-report-management' ) . '">';
						$out .= '</div>';
					}
					$out .= '</div>';
					break;

				case 'radio':
					$choices      = is_array( $field['choices'] ?? null ) ? $field['choices'] : array();
					$other_option = ! empty( $field['other_option'] );
					$out .= '<div class="psbdx-field psrm-choice-field">';
					$out .= '<label>' . $label . ' ' . $req_badge . '</label>';
					$out .= '<div class="psrm-choices-list">';
					foreach ( $choices as $idx => $choice ) {
						$cid  = esc_attr( $field_id ) . '-' . $idx;
						$out .= '<label class="psrm-choice-label"><input type="radio"'
							. ' id="' . $cid . '"'
							. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
							. ' value="' . esc_attr( $choice ) . '"'
							. ( $required && 0 === $idx ? ' required' : '' ) . '>'
							. ' ' . esc_html( $choice ) . '</label>';
					}
					if ( $other_option ) {
						$other_id = esc_attr( $field_id ) . '-other-opt';
						$out .= '<label class="psrm-choice-label"><input type="radio"'
							. ' id="' . $other_id . '"'
							. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
							. ' value="__other__"'
							. ' class="psrm-radio-other-trigger"'
							. ' data-target="' . esc_attr( $field_id ) . '-other-input"> '
							. esc_html__( 'Other', 'psbdx-smart-report-management' ) . '</label>';
						$out .= '<div class="psrm-other-input psrm-other-input-inline" id="' . esc_attr( $field_id ) . '-other-input" style="display:none;">';
						$out .= '<input type="text" name="psrm_v2[' . esc_attr( $handle ) . '_other]"'
							. ' placeholder="' . esc_attr__( 'Please specify…', 'psbdx-smart-report-management' ) . '">';
						$out .= '</div>';
					}
					$out .= '</div>';
					$out .= '</div>';
					break;

				case 'checkbox':
					$choices      = is_array( $field['choices'] ?? null ) ? $field['choices'] : array();
					$other_option = ! empty( $field['other_option'] );
					$out .= '<div class="psbdx-field psrm-choice-field">';
					$out .= '<label>' . $label . ' ' . $req_badge . '</label>';
					$out .= '<div class="psrm-choices-list">';
					foreach ( $choices as $idx => $choice ) {
						$cid  = esc_attr( $field_id ) . '-' . $idx;
						$out .= '<label class="psrm-choice-label"><input type="checkbox"'
							. ' id="' . $cid . '"'
							. ' name="psrm_v2[' . esc_attr( $handle ) . '][]"'
							. ' value="' . esc_attr( $choice ) . '"> '
							. esc_html( $choice ) . '</label>';
					}
					if ( $other_option ) {
						$other_id = esc_attr( $field_id ) . '-other-opt';
						$out .= '<label class="psrm-choice-label"><input type="checkbox"'
							. ' id="' . $other_id . '"'
							. ' class="psrm-checkbox-other-trigger"'
							. ' data-target="' . esc_attr( $field_id ) . '-other-input"> '
							. esc_html__( 'Other', 'psbdx-smart-report-management' ) . '</label>';
						$out .= '<div class="psrm-other-input psrm-other-input-inline" id="' . esc_attr( $field_id ) . '-other-input" style="display:none;">';
						$out .= '<input type="text" name="psrm_v2[' . esc_attr( $handle ) . '_other]"'
							. ' placeholder="' . esc_attr__( 'Please specify…', 'psbdx-smart-report-management' ) . '">';
						$out .= '</div>';
					}
					$out .= '</div>';
					$out .= '</div>';
					break;

				case 'attachment':
					$allowed_types = is_array( $field['allowed_types'] ?? null ) && ! empty( $field['allowed_types'] )
						? $field['allowed_types']
						: PSBDX_SRM_Form_Builder::ATTACHMENT_DEFAULT_TYPES;
					$min_kb  = (int) ( $field['min_size_kb'] ?? 0 );
					$max_kb  = (int) ( $field['max_size_kb'] ?? 5120 );
					$accept  = implode( ',', array_map( function ( $ext ) { return '.' . $ext; }, $allowed_types ) );

					$hint_parts = array(
						sprintf(
							/* translators: %s: comma-separated list of allowed extensions */
							esc_html__( 'Allowed: %s', 'psbdx-smart-report-management' ),
							esc_html( strtoupper( implode( ', ', $allowed_types ) ) )
						),
					);
					if ( $max_kb > 0 ) {
						$hint_parts[] = sprintf(
							/* translators: %s: max size, human readable */
							esc_html__( 'Max size: %s', 'psbdx-smart-report-management' ),
							esc_html( size_format( $max_kb * 1024 ) )
						);
					}
					if ( $min_kb > 0 ) {
						$hint_parts[] = sprintf(
							/* translators: %s: min size, human readable */
							esc_html__( 'Min size: %s', 'psbdx-smart-report-management' ),
							esc_html( size_format( $min_kb * 1024 ) )
						);
					}

					$out .= '<div class="psbdx-field">';
					$out .= '<label for="' . esc_attr( $field_id ) . '">' . $label . ' ' . $req_badge . '</label>';
					$out .= '<input type="file" id="' . esc_attr( $field_id ) . '"'
						. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
						. ' accept="' . esc_attr( $accept ) . '"'
						. $req_attr . '>';
					$out .= '<p class="psrm-field-hint">' . esc_html( implode( ' · ', $hint_parts ) ) . '</p>';
					$out .= '</div>';
					break;

				case 'review':
					$max_stars = (int) ( $field['max_stars'] ?? 5 );
					$max_stars = $max_stars > 0 ? $max_stars : 5;

					$out .= '<div class="psbdx-field psrm-review-field">';
					$out .= '<label>' . $label . ' ' . $req_badge . '</label>';
					$out .= '<div class="psrm-star-rating" data-max="' . esc_attr( $max_stars ) . '">';
					// Rendered high-to-low so the CSS "highlight this star and
					// everything after it in DOM order" sibling trick lights
					// up the correct stars regardless of which one is chosen.
					for ( $star = $max_stars; $star >= 1; $star-- ) {
						$star_id = esc_attr( $field_id ) . '-' . $star;
						$out    .= '<input type="radio"'
							. ' id="' . $star_id . '"'
							. ' name="psrm_v2[' . esc_attr( $handle ) . ']"'
							. ' value="' . esc_attr( $star ) . '"'
							. ( $required && 1 === $star ? ' required' : '' ) . '>';
						$out    .= '<label for="' . $star_id . '" class="psrm-star" title="'
							/* translators: %d: number of stars */
							. esc_attr( sprintf( _n( '%d star', '%d stars', $star, 'psbdx-smart-report-management' ), $star ) )
							. '"></label>';
					}
					$out .= '</div>';
					$out .= '</div>';
					break;

				case 'captcha':
					if ( '' !== $captcha_provider ) {
						$out .= '<div class="psbdx-captcha-widget"'
							. ' data-provider="' . esc_attr( $captcha_provider ) . '"'
							. ' data-sitekey="' . esc_attr( $captcha_site_key ) . '"'
							. ' id="psrm-captcha-' . esc_attr( $uid ) . '"></div>';
					}
					break;
			}
		}

		return $out;
	}
}
