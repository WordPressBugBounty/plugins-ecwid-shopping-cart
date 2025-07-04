<?php

require_once ECWID_PLUGIN_DIR . '/includes/class-ecwid-product-browser.php';

class Ecwid_Gutenberg {

	const STORE_BLOCK         = 'ecwid/store-block';
	const PRODUCT_BLOCK       = 'ecwid/product-block';
	const BUYNOW_BLOCK        = 'ec-store/buynow';
	const PRODUCT_PAGE_BLOCK  = 'ec-store/product-page';
	const CATEGORIES_BLOCK    = 'ec-store/categories';
	const CATEGORY_PAGE_BLOCK = 'ec-store/category-page';
	const CART_PAGE_BLOCK     = 'ec-store/cart';
	const FILTERS_PAGE_BLOCK  = 'ec-store/filters';
	const SEARCH_BLOCK        = 'ec-store/search';
	const MINICART_BLOCK      = 'ec-store/minicart';

	public $_blocks = array();

	public function __construct() {

		if ( isset( $_GET['classic-editor'] ) ) {
			return;
		}

		$blocks = self::get_block_names();

		foreach ( $blocks as $block => $block_name ) {
			require_once __DIR__ . "/class-ecwid-gutenberg-block-$block.php";
			$class_name = 'Ecwid_Gutenberg_Block_' . str_replace( '-', '_', ucfirst( $block ) );

			$this->_blocks[] = new $class_name();
		}

		foreach ( $this->_blocks as $block ) {
			$block->register();
		}

		add_action( 'admin_init', array( $this, 'init_scripts' ) );

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		add_action( 'rest_insert_post', array( $this, 'on_save_post' ), 10, 3 );
		add_action( 'rest_insert_page', array( $this, 'on_save_post' ), 10, 3 );

		$version = get_bloginfo( 'version' );

		if ( strpos( $version, '5.8' ) === 0 || version_compare( $version, '5.8' ) >= 0 ) {
			add_filter( 'block_categories_all', array( $this, 'block_categories' ) );
		} else {
			add_filter( 'block_categories', array( $this, 'block_categories' ) );
		}
	}

	public function init_scripts() {
        $asset_file = include_once ECWID_PLUGIN_DIR . 'js/gutenberg/build/index.asset.php';

		wp_register_script( 
            'ecwid-gutenberg-store', 
            ECWID_PLUGIN_URL . 'js/gutenberg/build/index.js',
            $asset_file['dependencies'],
            get_option( 'ecwid_plugin_version' )
        );

		wp_set_script_translations( 'ecwid-gutenberg-store', 'ecwid-shopping-cart', ECWID_PLUGIN_DIR . '/languages' );
	}

	public function admin_enqueue_scripts() {
		wp_enqueue_script( 'gutenberg-store' );
		EcwidPlatform::enqueue_style( 'store-popup' );
	}

	public function block_categories( $categories ) {

		$store_block = new Ecwid_Gutenberg_Block_Store();

		$ec_category = array(
			'slug'  => 'ec-store',
			'title' => sprintf( __( '%s', 'ecwid-shopping-cart' ), Ecwid_Config::get_brand() ),
			'icon'  => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="#555d66" d="' . $store_block->get_icon_path() . '"/><path d="M19 13H5v-2h14v2z" /></svg>',
		);

		$is_store_page              = Ecwid_Store_Page::is_store_page();
		$installed_within_one_weeks = time() - get_option( 'ecwid_installation_date' ) < WEEK_IN_SECONDS;

		if ( $is_store_page || ( ! $is_store_page && $installed_within_one_weeks ) ) {
			return array_merge( array( $ec_category ), $categories );
		}

		return array_merge( $categories, array( $ec_category ) );
	}

	public function on_save_post( $post, $request, $creating ) {
		if ( strpos( $post->post_content, '<!-- wp:' . self::STORE_BLOCK ) !== false ) {
			Ecwid_Store_Page::add_store_page( $post->ID );
		}
	}

	public function enqueue_block_editor_assets() {
		wp_enqueue_script( 'ecwid-gutenberg-store' );
		wp_enqueue_style( 'ecwid-gutenberg-block', ECWID_PLUGIN_URL . 'js/gutenberg/build/main.css', array(), get_option( 'ecwid_plugin_version' ) );

		$locale_data = '';
		if ( function_exists( 'gutenberg_get_jed_locale_data' ) ) {
			$locale_data = gutenberg_get_jed_locale_data( 'ecwid-shopping-cart' );
		} elseif ( function_exists( 'wp_get_jed_locale_data' ) ) {
			$locale_data = wp_get_jed_locale_data( 'ecwid-shopping-cart' );
		}

		if ( $locale_data ) {
			wp_add_inline_script(
				'ecwid-gutenberg-store',
				'wp.i18n.setLocaleData( ' . json_encode( $locale_data ) . ', "ecwid-shopping-cart"' . ');',
				'before'
			);
		}

		$store_block = new Ecwid_Gutenberg_Block_Store();

		$api = new Ecwid_Api_V3();
		wp_localize_script(
			'ecwid-gutenberg-store',
			'EcwidGutenbergStoreBlockParams',
			$store_block->get_params()
		);

		$block_params = array();
		foreach ( $this->_blocks as $block ) {
			$block_params[ $block->get_block_name() ] = $block->get_params();
		}

		$store_id = get_ecwid_store_id();
		$params   = ecwid_get_scriptjs_params();

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$params .= '&fse=1';
		}

		$scriptjs_url = 'https://' . Ecwid_Config::get_scriptjs_domain() . '/script.js?' . $store_id . $params;

		$minicart_block = new Ecwid_Gutenberg_Block_Minicart();
		$is_demo_store  = ecwid_is_demo_store();
		wp_localize_script(
			'ecwid-gutenberg-store',
			'EcwidGutenbergParams',
			array(
				'blockParams'          => $block_params,
				'minicartAttributes'   => $minicart_block->get_attributes_for_editor(),
				'ecwid_pb_defaults'    => ecwid_get_default_pb_size(),
				'storeImageUrl'        => site_url( '?file=ecwid_store_svg.svg' ),
				'storeBlock'           => self::STORE_BLOCK,
				'productBlockTitle'    => sprintf( __( '%s product', 'ecwid-shopping-cart' ), Ecwid_Config::get_brand() ),
				'productShortcodeName' => Ecwid_Shortcode_Product::get_shortcode_name(),
				'productBlock'         => self::PRODUCT_BLOCK,
				'storeId'              => get_ecwid_store_id(),
				'chooseProduct'        => __( 'Choose product', 'ecwid-shopping-cart' ),
				'editAppearance'       => __( 'Edit Appearance', 'ecwid-shopping-cart' ),
				'yourStoreWill'        => __( 'Your store will be shown here', 'ecwid-shopping-cart' ),
				'storeIdLabel'         => __( 'Store ID', 'ecwid-shopping-cart' ),
				'yourProductLabel'     => __( 'Your product', 'ecwid-shopping-cart' ),
				'isDemoStore'          => $is_demo_store,
				'isApiAvailable'       => Ecwid_Api_V3::is_available(),
				'products'             => $this->_get_products_data(),
				'hasCategories'        => $api->has_public_categories(),
				'isWidgetsScreen'      => get_current_screen()->id == 'widgets',
				'scriptJsUrl'          => $scriptjs_url,
			)
		);
	}

	protected function _get_products_data() {

		$blocks = self::get_blocks_on_page();

		$product_ids = array();

		$product_block      = new Ecwid_Gutenberg_Block_Product();
		$buynow_block       = new Ecwid_Gutenberg_Block_Buynow();
		$product_page_block = new Ecwid_Gutenberg_Block_Product_Page();
		foreach ( $blocks as $block ) {
			if ( in_array(
				$block['blockName'],
				array(
					$product_block->get_block_name(),
					$buynow_block->get_block_name(),
				)
			)
				&& ! empty( $block['attrs']['id'] )
			) {
				$product_ids[] = $block['attrs']['id'];
			}

			if ( $block['blockName'] == $product_page_block->get_block_name() && @$block['attrs']['default_product_id'] ) {
				$product_ids[] = $block['attrs']['default_product_id'];
			}
		}

		if ( empty( $product_ids ) ) {
			return array();
		}

		$result = array();
		foreach ( $product_ids as $id ) {
			$product = Ecwid_Product::get_by_id( $id );

			$result[ $id ] = array(
				'name'     => $product->name,
				'imageUrl' => $product->thumbnailUrl, //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			);
		}

		return $result;
	}

	public static function get_block_names( $return_with_pb_only = false ) {
		// cuz no late static binding sadly
		$blocks_with_productbrowser = array(
			'store'         => self::STORE_BLOCK,
			'product-page'  => self::PRODUCT_PAGE_BLOCK,
			'category-page' => self::CATEGORY_PAGE_BLOCK,
			'filters-page'  => self::FILTERS_PAGE_BLOCK,
			'cart-page'     => self::CART_PAGE_BLOCK,
		);

		$blocks_without_productbrowser = array(
			'product'    => self::PRODUCT_BLOCK,
			'buynow'     => self::BUYNOW_BLOCK,
			'categories' => self::CATEGORIES_BLOCK,
			'search'     => self::SEARCH_BLOCK,
			'minicart'   => self::MINICART_BLOCK,
			'cart-page'  => self::CART_PAGE_BLOCK,
		);

		if ( $return_with_pb_only === true ) {
			return $blocks_with_productbrowser;
		}

		return array_merge( $blocks_with_productbrowser, $blocks_without_productbrowser );
	}

	/**
	 * @param $post
	 *
	 * @return array
	 */
	public static function get_blocks_on_page() {
		$post = get_post();

		if ( ! $post ) {
			return array();
		}

		if ( function_exists( 'gutenberg_parse_blocks' ) ) {
			$blocks = gutenberg_parse_blocks( $post->post_content );
		} else {
			$blocks = parse_blocks( $post->post_content );
		}

		if ( empty( $blocks ) ) {
			return array();
		}

		$result = array();

		$ecwid_blocks = self::get_block_names();
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], $ecwid_blocks ) ) {
				$result[ $block['blockName'] ] = $block;
			}
		}

		return $result;
	}

	protected function _get_version_for_assets( $asset_file_path ) {
		if ( isset( $_SERVER['HTTP_HOST'] ) && $_SERVER['HTTP_HOST'] == 'localhost' ) {
			return filemtime( ECWID_PLUGIN_DIR . '/' . $asset_file_path );
		}

		return get_option( 'ecwid_plugin_version' );
	}

	public static function get_store_block_data_from_current_page() {

		$blocks = self::get_blocks_on_page();

		$store_block = null;
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] == self::STORE_BLOCK ) {
				$store_block = $block;
				break;
			}
		}

		if ( ! $store_block ) {
			return array();
		}

		return $store_block['atts'];
	}

	public static function content_has_productbrowser( $content ) {

		$blocks_with_productbrowser = self::get_block_names( true );

		foreach ( $blocks_with_productbrowser as $block_name ) {
			if ( strpos( $content, $block_name ) !== false ) {
				return true;
			}
		}

		return false;
	}
}

$ecwid_gutenberg = new Ecwid_Gutenberg();
