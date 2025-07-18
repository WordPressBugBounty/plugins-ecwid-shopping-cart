<?php

if ( version_compare( get_bloginfo( 'version' ), '4.0' ) >= 0 ) {

	class Ecwid_Floating_Minicart {

		const OPTION_WIDGET_DISPLAY    = 'ec_show_floating_cart_widget';
		const OPTION_FIXED_POSITION    = 'ec_store_cart_widget_fixed_position';
		const OPTION_ICON              = 'ec_store_cart_widget_icon';
		const OPTION_FIXED_SHAPE       = 'ec_store_cart_widget_fixed_shape';
		const OPTION_LAYOUT            = 'ec_store_cart_widget_layout';
		const OPTION_SHOW_EMPTY_CART   = 'ec_store_cart_widget_show_empty_cart';
		const OPTION_HORIZONTAL_INDENT = 'ec_store_cart_widget_horizontal_indent';
		const OPTION_VERTICAL_INDENT   = 'ec_store_cart_widget_vertical_indent';

		const DISPLAY_NONE  = 'do_not_show';
		const DISPLAY_STORE = 'show_on_store_pages';
		const DISPLAY_ALL   = 'show_on_all_pages';

		const CUSTOMIZE_ID = 'ec-customize-cart';

		public function __construct() {
			add_action( 'template_redirect', array( $this, 'init' ) );
		}

		public function init() {
			if ( $this->is_enabled() ) {
				add_action( 'wp_footer', array( $this, 'display' ) );
				add_filter( 'ecwid_has_widgets_on_page', '__return_true' );
			}
		}

		public function get_display_value() {
			$display = get_option( self::OPTION_WIDGET_DISPLAY, self::DISPLAY_STORE );

			if ( ! array_key_exists( $display, self::get_display_options() ) ) {
				$display = self::DISPLAY_NONE;
			}

			return $display;
		}

		public function is_enabled() {
			if ( post_password_required() ) {
				return false;
			}

			$display = $this->get_display_value();

			if ( $display === self::DISPLAY_NONE && ! is_customize_preview() ) {
				return false;
			}

			if ( $display === self::DISPLAY_STORE && ! Ecwid_Store_Page::is_store_page() && ! is_customize_preview() ) {
				return false;
			}

			if ( isset( $_REQUEST['legacy-widget-preview'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}

			return true;
		}

		public function display() {

			$display = $this->get_display_value();

			echo ecwid_get_scriptjs_code(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$position   = esc_attr( get_option( self::OPTION_FIXED_POSITION ) );
			$shape      = esc_attr( get_option( self::OPTION_FIXED_SHAPE ) );
			$layout     = esc_attr( get_option( self::OPTION_LAYOUT ) );
			$show_empty = esc_attr( get_option( self::OPTION_SHOW_EMPTY_CART ) ? 'TRUE' : 'FALSE' );
			$icon       = esc_attr( get_option( self::OPTION_ICON ) );

			$hindent = esc_attr( get_option( self::OPTION_HORIZONTAL_INDENT ) );
			$vindent = esc_attr( get_option( self::OPTION_VERTICAL_INDENT ) );

			$customize_id   = is_customize_preview() ? 'id="' . self::CUSTOMIZE_ID . '"' : '';
			$customize_hide = is_customize_preview() && $display === self::DISPLAY_NONE ? 'style="display:none"' : '';

			?>
			<div <?php echo esc_attr( $customize_id ); ?> <?php echo esc_attr( $customize_hide ); ?> class='ec-cart-widget' 
				data-fixed='true' 
				data-fixed-position='<?php echo esc_attr( $position ); ?>' 
				data-fixed-shape='<?php echo esc_attr( $shape ); ?>'
				data-horizontal-indent="<?php echo esc_attr( $hindent ); ?>" 
				data-vertical-indent="<?php echo esc_attr( $vindent ); ?>" 
				data-layout='<?php echo esc_attr( $layout ); ?>' 
				data-show-empty-cart='<?php echo esc_attr( $show_empty ); ?>'
				data-show-buy-animation='true'
				data-icon='<?php echo esc_attr( $icon ); ?>'
			></div>

			<script data-cfasync="false" data-no-optimize="1">
			if (typeof Ecwid != 'undefined'){
				Ecwid.init();
			}
			</script>
			<?php
		}

		public static function create_default_options() {
			$options = self::get_default_options();
			if ( ! ecwid_is_recent_installation() ) {
				$options[ self::OPTION_WIDGET_DISPLAY ] = self::DISPLAY_NONE;
			}

			foreach ( $options as $name => $value ) {
				add_option( $name, $value );
			}
		}

		protected static function get_default_options() {
			return array(
				self::OPTION_WIDGET_DISPLAY    => self::DISPLAY_STORE,
				self::OPTION_SHOW_EMPTY_CART   => true,
				self::OPTION_LAYOUT            => 'MEDIUM_ICON_COUNTER',
				self::OPTION_FIXED_SHAPE       => 'PILL',
				self::OPTION_FIXED_POSITION    => 'BOTTOM_RIGHT',
				self::OPTION_ICON              => 'BAG',
				self::OPTION_HORIZONTAL_INDENT => '30',
				self::OPTION_VERTICAL_INDENT   => '30',
			);
		}

		public static function get_display_options() {
			return array(
				self::DISPLAY_NONE  => __( 'Do not show', 'ecwid-shopping-cart' ),
				self::DISPLAY_STORE => __( 'Show on store pages', 'ecwid-shopping-cart' ),
				self::DISPLAY_ALL   => __( 'Show on all pages', 'ecwid-shopping-cart' ),
			);
		}

		public static function get_layouts() {
			return array(
				'SMALL_ICON'                => __( 'Small icon', 'ecwid-shopping-cart' ),
				'SMALL_ICON_COUNTER'        => __( 'Small icon and item count', 'ecwid-shopping-cart' ),
				'COUNTER_ONLY'              => __( 'Item count only', 'ecwid-shopping-cart' ),
				'TITLE_COUNTER'             => __( 'Label and item count', 'ecwid-shopping-cart' ),
				'MEDIUM_ICON_COUNTER'       => __( 'Icon and item count', 'ecwid-shopping-cart' ),
				'MEDIUM_ICON_TITLE_COUNTER' => __( 'Icon, label and item count', 'ecwid-shopping-cart' ),
				'BIG_ICON_TITLE_SUBTOTAL'   => __( 'Icon, label, item count and subtotal', 'ecwid-shopping-cart' ),
				'BIG_ICON_DETAILS_SUBTOTAL' => __( 'Icon, label, item count, subtotal and link', 'ecwid-shopping-cart' ),
			);
		}

		public static function get_icons() {
			return array(
				'BAG'    => __( 'Bag', 'ecwid-shopping-cart' ),
				'CART'   => __( 'Cart', 'ecwid-shopping-cart' ),
				'BASKET' => __( 'Basket', 'ecwid-shopping-cart' ),
			);
		}

		public static function get_fixed_shapes() {
			return array(
				'RECT' => __( 'Rectangle', 'ecwid-shopping-cart' ),
				'PILL' => __( 'Pill', 'ecwid-shopping-cart' ),
				''     => __( 'No border', 'ecwid-shopping-cart' ),
			);
		}

		public static function get_fixed_positions() {
			return array(
				'BOTTOM_RIGHT' => __( 'Bottom right', 'ecwid-shopping-cart' ),
				'TOP_RIGHT'    => __( 'Top right', 'ecwid-shopping-cart' ),
				'TOP_LEFT'     => __( 'Top left', 'ecwid-shopping-cart' ),
				'BOTTOM_LEFT'  => __( 'Bottom left', 'ecwid-shopping-cart' ),
			);
		}
	}

	$minicart = new Ecwid_Floating_Minicart();

}//end if
