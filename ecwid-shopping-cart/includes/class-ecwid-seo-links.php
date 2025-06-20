<?php


class Ecwid_Seo_Links {

	const OPTION_ENABLED       = 'ecwid_seo_links_enabled';
	const OPTION_ALL_BASE_URLS = 'ecwid_all_base_urls';

    const OPTION_SLUGS_WITHOUT_IDS_ENABLED = 'ecwid_slugs_without_ids';

    const OPTION_VALUE_ENABLED  = 'Y';
	const OPTION_VALUE_DISABLED = 'N';
	const OPTION_VALUE_AUTO     = '';

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'ecwid_on_fresh_install', array( $this, 'on_fresh_install' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ) );

        add_action( 'update_option_' . self::OPTION_ENABLED, array( $this, 'set_store_url_format' ) );
        add_action( 'update_option_' . self::OPTION_SLUGS_WITHOUT_IDS_ENABLED, array( $this, 'set_store_url_format' ) );

        add_action( 'update_option_' . self::OPTION_SLUGS_WITHOUT_IDS_ENABLED, array( $this, 'clear_static_pages_cache' ), 10, 3 );

        add_action( 'admin_init', array( $this, 'add_slugs_promo_on_permalinks_page' ) );
        
        if( self::is_slugs_without_ids_enabled() ) {
            add_action( 'template_redirect', array( $this, 'prevent_storefront_page' ) );       
        }
	}

	public function init() {

		if ( self::is_enabled() ) {
			add_action( 'rewrite_rules_array', array( $this, 'build_rewrite_rules' ), 10, 1 );

			add_filter( 'redirect_canonical', array( $this, 'redirect_canonical' ), 10, 2 );
			add_action( 'template_redirect', array( $this, 'redirect_escaped_fragment' ) );
			add_filter( 'get_shortlink', array( $this, 'get_shortlink' ) );

			add_action( 'ecwid_inline_js_config', array( $this, 'add_js_config' ) );

			add_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', array( $this, 'is_post_slug_bad' ), 10, 4 );
			add_filter( 'wp_unique_post_slug_is_bad_flat_slug', array( $this, 'is_post_slug_bad' ), 10, 2 );
			add_filter( 'wp_unique_post_slug_is_bad_attachment_slug', array( $this, 'is_post_slug_bad' ), 10, 2 );

			if ( is_admin() ) {
				add_action( 'current_screen', array( $this, 'check_base_urls_on_edit_store_page' ) );
			}
		}
	}

    public function prevent_storefront_page() {
        global $wp_query;

        if ( !self::is_enabled() ) {
            return;
        }

        $page_id = get_queried_object_id();
        
        $page = $wp_query->get_queried_object();
        $page_parent_id = !empty( $page->post_parent ) ? $page->post_parent : 0;

        // $post_types = get_post_types( array( 'public' => true ) );
        $post_types = array( 'post', 'page' );

        if( $page_parent_id > 0 && Ecwid_Store_Page::is_store_page( $page_parent_id ) ) {
            $slug = Ecwid_Static_Page::get_current_storefront_page_slug( $page_parent_id );

            if( ! empty( $slug ) && self::is_noindex_page( $page_parent_id ) ) {
                $wp_page = new WP_Query( array( 
                    'p' => $page_parent_id,
                    'post_type' => $post_types
                ) );
    
                if ( ! $wp_page->have_posts() ) {
                    return;
                }
                
                $wp_page->is_single = $wp_query->is_single;
                $wp_page->is_page = $wp_query->is_page;

                $wp_query = $wp_page;
            }
        }

        if( ! empty( $page_id ) && Ecwid_Store_Page::is_store_page( $page_id ) ) {
            $slug = Ecwid_Static_Page::get_current_storefront_page_slug( $page_id );

            if( empty( $slug ) || self::is_noindex_page( $page_id ) ) {
               return;
            }

            $wp_page = new WP_Query( array( 
                'name' => $slug,
                'post_type' => $post_types
            ) );

            if ( ! $wp_page->have_posts() ) {
                return;
            }
            
            $wp_page->is_single = $wp_query->is_single;
            $wp_page->is_page = $wp_query->is_page;

            $wp_query = $wp_page;
        }
    }

	public function on_save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( Ecwid_Store_Page::is_store_page( $post_id ) ) {
			if ( ! $this->are_base_urls_ok() ) {
				flush_rewrite_rules();
			}
		}
	}

	public function check_base_urls_on_edit_store_page() {

		$current_screen = get_current_screen();

		if ( $current_screen->base != 'post' || ! in_array( $current_screen->post_type, array( 'post', 'page' ) ) ) {
			return;
		}

		$id = ( isset( $_GET['post'] ) ) ? intval( $_GET['post'] ) : false;

		if ( ! $id ) {
			return;
		}

		if ( Ecwid_Store_Page::is_store_page( $id ) ) {
			if ( ! $this->are_base_urls_ok() ) {
				flush_rewrite_rules();
			}
		}
	}

	public function check_base_urls_on_view_store_page_as_admin() {
		$id = get_the_ID();

		if ( Ecwid_Store_Page::is_store_page( $id ) ) {
			if ( ! $this->are_base_urls_ok() ) {
				flush_rewrite_rules();
			}
		}
	}

	public function on_fresh_install() {
		add_option( self::OPTION_ENABLED, 'Y' );
	}

	public function on_plugin_update() {
		add_option( self::OPTION_ENABLED, '' );
	}

	public function redirect_canonical( $redir, $req ) {

		if ( self::is_store_on_home_page() && get_queried_object_id() == get_option( 'page_on_front' ) ) {
			return false;
		}

		return $redir;
	}

	public function redirect_escaped_fragment() {
		if ( ecwid_should_display_escaped_fragment_catalog() ) {
			$params = ecwid_parse_escaped_fragment();

			if ( ! isset( $params['mode'] ) ) {
				return;
			}

			if ( $params['mode'] == 'product' ) {
				$redirect = Ecwid_Store_Page::get_product_url( $params['id'] );
			} elseif ( $params['mode'] == 'category' ) {
				$redirect = Ecwid_Store_Page::get_category_url( $params['id'] );
			}

			if ( isset( $redirect ) ) {
				wp_safe_redirect( $redirect, 301 );
				exit;
			}
		}
	}

	public function get_shortlink( $shortlink ) {
		if ( self::is_product_browser_url() ) {
			return '';
		}

		return $shortlink;
	}

	public function is_post_slug_bad( $value, $slug, $type = '', $parent = '' ) {

		if ( ! self::is_store_on_home_page() ) {
			return $value;
		}

		if ( $this->slug_matches_seo_pattern( $slug ) ) {
			return true;
		}

		return $value;
	}

	public function slug_matches_seo_pattern( $slug ) {
		static $pattern = '';

		if ( ! $pattern ) {
			$patterns = self::get_seo_links_patterns();

            // The '.*' pattern is deleted because it will always be triggered.
            $index = array_search( '.*', $patterns, true );
            if ( $index !== false ) {
                unset( $patterns[$index] );
                $patterns = array_values( $patterns );
            }

            if ( empty( $patterns ) ) {
                return false;
            }

			$pattern = '!(^' . implode( '$|^', $patterns ) . '$)!';
		}

		return preg_match( $pattern, $slug );
	}

	public static function get_seo_links_patterns() {
        $patterns = array();

        if( self::is_slugs_without_ids_enabled() ) {
            $patterns = array(
                '.*',
            );
        }

		$patterns = array_merge( 
            $patterns, 
            array(
                '.*-p([0-9]+)(\/.*|\?.*)?',
                '.*-c([0-9]+)(\/.*|\?.*)?',
                'cart',
                'checkout.*',
                'account',
                'account\/settings',
                'account\/orders',
                'account\/address-book',
                'account\/favorites',
                'account\/registration',
                'account\/resetPassword',
                'account\/reviews',
                'search',
                'search\?.*',
                'signin',
                'signOut',
                'signIn.*',
                'signOut.*',
                'pages\/about',
                'pages\/shipping-payment',
                'pages\/returns',
                'pages\/terms',
                'pages\/privacy-policy',
                'resetPassword.*',
                'checkoutAB.*',
                'checkoutCC.*',
                'checkoutEC.*',
                'checkoutAC.*',
                'downloadError.*',
                'checkoutResult.*',
                'checkoutWait.*',
                'orderFailure.*',
                'FBAutofillCheckout.*',
                'pay.*',
                'repeat-order.*',
                'subscribe.*',
                'unsubscribe.*',
            )
        );

        return $patterns;
	}

	public static function is_store_on_home_page() {
		$front_page = get_option( 'page_on_front' );

		if ( ! $front_page ) {
			return false;
		}

		if ( Ecwid_Store_Page::is_store_page( $front_page ) ) {
			return true;
		}

		return false;
	}

	public function add_js_config( $config ) {

		$page_id = get_queried_object_id();

		$has_store = Ecwid_Store_Page::is_store_page( $page_id );

		if ( ! $has_store ) {
			if ( ! Ecwid_Ajax_Defer_Renderer::is_enabled() ) {
				return $config;
			}
		}

		if ( Ecwid_Api_V3::is_available() && self::is_404_seo_link() ) {
			return $config;
		}

		if ( Ecwid_Ajax_Defer_Renderer::is_enabled() ) {
			$url = esc_js( Ecwid_Store_Page::get_store_url() );
		} else {
			$url = esc_js( get_permalink( $page_id ) );
		}

		$url_relative = wp_make_link_relative( $url );

		$result = self::get_js_config_storefront_urls();

		$result .= "
            window.ec.config.canonical_base_url = '$url';
            window.ec.config.baseUrl = '$url_relative';
            window.ec.storefront = window.ec.storefront || {};
            window.ec.storefront.sharing_button_link = 'DIRECT_PAGE_URL';";

		$config .= $result;

		return $config;
	}

	public static function get_js_config_storefront_urls() {

        $js_code = 'window.ec.config.storefrontUrls = window.ec.config.storefrontUrls || {};' . PHP_EOL;
        $js_code .= 'window.ec.config.storefrontUrls.cleanUrls = true;' . PHP_EOL;

        if( self::is_slugs_without_ids_enabled() ) {
            $js_code .= 'window.ec.config.storefrontUrls.slugsWithoutIds = true;' . PHP_EOL;
        }

		return $js_code;
	}

	public static function is_404_seo_link() {

		if ( ! self::is_product_browser_url() ) {
			return false;
		}

		$params = self::maybe_extract_html_catalog_params();
		if ( ! $params ) {
			return false;
		}

		// Root is always ok
		$is_root_cat = $params['mode'] == 'category' && $params['id'] == 0;
		if ( $is_root_cat ) {
			return false;
		}

		$result = false;

		if ( $params['mode'] == 'product' ) {
			$result = Ecwid_Product::get_by_id( $params['id'] );
		} elseif ( ! $is_root_cat && $params['mode'] == 'category' ) {
			$result = Ecwid_Category::get_by_id( $params['id'] );
		}

		// Can't parse params, assume its ok
		if ( ! $result ) {
			return false;
		}

		// product/category not found, 404
		if ( is_object( $result ) && ( ! isset( $result->id ) || ! $result->enabled ) ) {
			return true;
		}

		return false;
	}

	public static function maybe_extract_html_catalog_params() {

		$current_url = add_query_arg( null, null );
		$matches     = array();
		if ( ! preg_match( self::_get_pb_preg_pattern(), $current_url, $matches ) ) {
			return array();
		}

		$modes = array(
			'p' => 'product',
			'c' => 'category',
		);

		return array(
			'mode' => $modes[ $matches[1] ],
			'id'   => $matches[2],
		);
	}

	public static function is_product_browser_url( $url = '' ) {

        if( Ecwid_Seo_Links::is_slugs_without_ids_enabled() ) {
            $slug = Ecwid_Static_Page::get_current_storefront_page_slug();
            $noindex_pages = Ecwid_Seo_Links::get_noindex_pages();
            
            return ! empty( $slug ) && ! in_array( $slug, $noindex_pages );
        } else {
            if ( ! $url ) {
                $url = add_query_arg( null, null );
            }
    
            return preg_match( self::_get_pb_preg_pattern(), $url );
        }
	}

	public static function is_seo_link() {
		if ( ! Ecwid_Store_Page::is_store_page() ) {
			return false;
		}

		$url = add_query_arg( null, null );

		$link      = urldecode( self::_get_relative_permalink( get_the_ID() ) );
		$site_url  = parse_url( get_bloginfo( 'url' ) );
		$site_path = ( isset( $site_url['path'] ) ) ? $site_url['path'] : '';

		foreach ( self::get_seo_links_patterns() as $pattern ) {
			$pattern = '#' . $site_path . preg_quote( $link ) . $pattern . '#';

			if ( preg_match( $pattern, $url ) ) {
				return true;
			}
		}

		return false;
	}

	protected static function _get_pb_preg_pattern() {
		return '!.*-(p|c)([0-9]+)(\/.*|\?.*)?$!';
	}

	public function build_rewrite_rules( $rules ) {
		$new_rules        = array();
		$additional_rules = array();

		$all_base_urls = $this->_build_all_base_urls();

		foreach ( $all_base_urls as $page_id => $links ) {
			$page_rules = array();
			$patterns   = self::get_seo_links_patterns();

			$post = get_post( $page_id );

			if ( ! $post ) {
				continue;
			}

			$default_post_types = array( 'page', 'post' );
			$allowed_post_types = apply_filters( 'ecwid_seo_allowed_post_types', $default_post_types );

			if ( ! in_array( $post->post_type, $allowed_post_types ) ) {
				continue;
			}

			$param_name = $post->post_type == 'page' ? 'page_id' : 'p';

			foreach ( $links as $link ) {
				$link = trim( $link, '/' );

				$link         = apply_filters( 'ecwid_rewrite_rules_relative_link', $link, $page_id );
				$link_page_id = apply_filters( 'ecwid_rewrite_rules_page_id', $page_id, $link );

				if ( strpos( $link, 'index.php' ) !== 0 ) {
					$link = '^' . preg_quote( $link );
				}

				foreach ( $patterns as $pattern ) {
					$query = 'index.php?' . $param_name . '=' . $link_page_id;

					// adding post_type parameters for non-default types
					if ( ! in_array( $post->post_type, $default_post_types ) ) {
						$query .= '&post_type=' . $post->post_type;
					}

					// $page_rules[ $link . '/' . $pattern . '.*' ] = $query;
					$page_rules[ $link . '/' . $pattern ] = $query;
				}
			}//end foreach

			// subpages will be placed higher in the rule list than parent pages
			$is_subpage = ! empty( $post->post_parent );
			if ( $is_subpage ) {
				$additional_rules = array_merge( $page_rules, $additional_rules );
			} else {
				$additional_rules = array_merge( $additional_rules, $page_rules );
			}
		}//end foreach

		if ( self::is_store_on_home_page() ) {
			$patterns = self::get_seo_links_patterns();
			foreach ( $patterns as $pattern ) {
				$additional_rules[ '^' . $pattern . '$' ] = 'index.php?page_id=' . get_option( 'page_on_front' );
			}
		}

		update_option( self::OPTION_ALL_BASE_URLS, array_merge( $all_base_urls, array( 'home' => self::is_store_on_home_page() ) ) );

        // we put our rules before the default one
        $rewrite_rule = '([^/]+)(?:/([0-9]+))?/?$';

        $position = array_search( $rewrite_rule, array_keys( $rules ) );
        if( $position !== false ) {
            $first_part = array_slice( $rules, 0, $position, true);
            $second_part = array_slice( $rules, $position, count( $rules ) - 1, true);
            
            $new_rules = array_merge( $first_part, $additional_rules, $second_part );
        } else {
            // it's fallback for the case when we can't find the default rule
            $new_rules = array_merge( $additional_rules, $rules );
        }

		return $new_rules;
	}

	public function are_base_urls_ok() {
		if ( ! self::is_feature_available() ) {
			return true;
		}

		$all_base_urls = $this->_build_all_base_urls();

		$flattened = array();
		foreach ( $all_base_urls as $page_id => $links ) {
			foreach ( $links as $link ) {
				$flattened[ $link ] = $link;
			}
		}

		$saved = get_option( self::OPTION_ALL_BASE_URLS );

		if ( empty( $saved ) || ! is_array( $saved ) ) {
			return false;
		}

		$saved_home = false;
		if ( isset( $saved['home'] ) ) {
			$saved_home = $saved['home'];
			unset( $saved['home'] );
		}

		$flattened_saved = array();
		foreach ( $saved as $page_id => $links ) {
			foreach ( $links as $link ) {
				$flattened_saved[ $link ] = $link;
			}
		}

		$rules = get_option( 'rewrite_rules' );

		if ( empty( $rules ) ) {
			return false;
		}

		foreach ( $flattened as $link ) {
			$link = trim( $link, '/' );

			$patterns = self::get_seo_links_patterns();
			$pattern  = $patterns[0];

			$rules_pattern = '^' . $link . '/' . $pattern . '.*';

			if ( ! array_key_exists( $rules_pattern, $rules ) ) {
				return false;
			}
		}

		$are_the_same = array_diff( $flattened, $flattened_saved );

		return empty( $are_the_same ) && $saved_home == self::is_store_on_home_page();
	}

	protected function _build_all_base_urls() {
		$base_urls = array();

		$pages = Ecwid_Store_Page::get_store_pages_array();

		if ( is_array( $pages ) ) {
			foreach ( $pages as $page_id ) {
				if ( ! isset( $base_urls[ $page_id ] ) ) {
					$base_urls[ $page_id ] = array();
				}

				$link = urldecode( self::_get_relative_permalink( $page_id, true ) );

				$base_urls[ $page_id ][] = $link;
			}
		}

		return $base_urls;
	}

	protected static function _get_relative_permalink( $item_id, $not_filter_return_value = false ) {
		$permalink = parse_url( get_permalink( $item_id ) );
		$home_url  = parse_url( home_url() );

		if ( ! isset( $permalink['path'] ) ) {
			$permalink['path'] = '/';
		}
		if ( ! isset( $home_url['path'] ) ) {
			$home_url['path'] = '';
		}

		if ( isset( $home_url['query'] ) ) {
			$home_url['path'] = substr( $home_url['path'], 0, -1 );
		}

		$default_link = substr( $permalink['path'], strlen( $home_url['path'] ) );

		if ( $not_filter_return_value ) {
			return $default_link;
		}

		return apply_filters( 'ecwid_relative_permalink', $default_link, $item_id );
	}

    public static function get_noindex_pages() {
        return array(
			'cart',
			'account',
			'checkout',
			'signin',
			'signOut',
            'resetPassword',
			'search',
			'pages',
			'downloadError',
			'checkoutResult',
			'checkoutWait',
			'orderFailure',
			'pay',
			'repeat-order',
			'subscribe',
			'unsubscribe',
		);
    }

	public static function is_noindex_page( $page_id = 0 ) {

		if ( ! Ecwid_Store_Page::is_store_page( $page_id ) ) {
			return false;
		}

        if( empty( $page_id ) ) {
            $page_id = get_the_ID();
        }

		$relative_permalink = self::_get_relative_permalink( $page_id );

		$noindex_pages = self::get_noindex_pages();

		$home_url = home_url();
		$path     = wp_parse_url( $home_url, PHP_URL_PATH );

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( $path . $relative_permalink === '/' ) {
			$seo_part = $request_uri;
		} else {
			$seo_part = str_replace( $path . $relative_permalink, '', $request_uri );
		}

		foreach ( $noindex_pages as $page ) {
			if ( preg_match( '!' . $page . '([\?\/]+.*|)$' . '!', $seo_part ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_enabled() {
		return self::is_feature_available() && get_option( self::OPTION_ENABLED );
	}

	public static function enable() {
		update_option( self::OPTION_ENABLED, true );
		Ecwid_Store_Page::schedule_flush_rewrites();
		ecwid_invalidate_cache( true );
	}

	public static function disable() {
		update_option( self::OPTION_ENABLED, false );
		Ecwid_Store_Page::schedule_flush_rewrites();
		ecwid_invalidate_cache( true );
	}

	public static function is_feature_available() {
		$permalink = get_option( 'permalink_structure' );

		return $permalink != '';
	}

	public static function is_slugs_editor_available() {
        $is_paid_account = ecwid_is_paid_account();
        $is_slugs_wihtout_ids_enabled = false;

        if ( Ecwid_Api_V3::is_available() ) {
            $api    = new Ecwid_Api_V3();
            $profile = $api->get_store_profile();

            $is_slugs_wihtout_ids_enabled = ! empty( $profile->generalInfo->storefrontUrlSlugFormat ) && $profile->generalInfo->storefrontUrlSlugFormat === 'WITHOUT_IDS';
        }

        if( $is_paid_account && $is_slugs_wihtout_ids_enabled ) {
            return true;
        }

		return false;
	}

    public static function is_slugs_without_ids_enabled() {
        
		if ( ecwid_is_demo_store() ) {
			return false;
		}

        if ( get_option( self::OPTION_SLUGS_WITHOUT_IDS_ENABLED ) === self::OPTION_VALUE_ENABLED ) {
			return true;
		}

		if ( get_option( self::OPTION_SLUGS_WITHOUT_IDS_ENABLED ) === self::OPTION_VALUE_DISABLED ) {
			return false;
		}

		if ( get_option( self::OPTION_SLUGS_WITHOUT_IDS_ENABLED, self::OPTION_VALUE_AUTO ) === self::OPTION_VALUE_AUTO ) {
            $is_old_installation = ecwid_migrations_is_original_plugin_version_older_than( '7.0' );
            
            if( $is_old_installation ) {
                return false;
            } else {
                return true;
            }
		}

        return false;
    }

    public function set_store_url_format() {
        if ( ecwid_is_demo_store() ) {
			return;
		}

        $oauth = new Ecwid_OAuth();
		if ( ! $oauth->has_scope( Ecwid_OAuth::SCOPE_UPDATE_STORE_PROFILE ) ) {
			return;
		}

		$params = array(
			'generalInfo' => array(
				'websitePlatform' => 'wordpress',
			)
		);

        if( self::is_enabled() ) {
            $params['generalInfo']['storefrontUrlFormat'] = 'CLEAN';
        } else {
            $params['generalInfo']['storefrontUrlFormat'] = 'HASH';
        }

        if( self::is_slugs_without_ids_enabled() ) {
            $params['generalInfo']['storefrontUrlSlugFormat'] = 'WITHOUT_IDS';
        } else {
            $params['generalInfo']['storefrontUrlSlugFormat'] = 'WITH_IDS';
        }

        $api = new Ecwid_Api_V3();
		$result = $api->update_store_profile( $params );

		if ( $result ) {
			EcwidPlatform::cache_reset( Ecwid_Api_V3::PROFILE_CACHE_NAME );
		}
    }

    public function clear_static_pages_cache( $old_value, $value, $option ) {
        if( $old_value !== $value ) {
            EcwidPlatform::clear_all_transients();
        }
    }

    public function add_slugs_promo_on_permalinks_page() {

        if( self::is_slugs_editor_available() ) {
            $section_title = __( 'Customize URL slugs for products and categories in your %s store', 'ecwid-shopping-cart' );
            $section_text = __( 'Remove IDs from URL slugs and display customized slugs to boost SEO and create a more user-friendly customer experience. <a %s>Go to URL slugs settings</a>', 'ecwid-shopping-cart' );
        } else {
            $section_title = __( 'Set URL slugs without IDs for products and categories in your %s store', 'ecwid-shopping-cart' );
            $section_text = __( 'Remove IDs from URL slugs in products and categories to boost SEO and create a more user-friendly customer experience. <a %s>Go to URL slugs settings</a>', 'ecwid-shopping-cart' );
        }

        add_settings_section(
            'ec-store-slugs-without-ids-promo',
            sprintf( $section_title, Ecwid_Config::get_brand() ),
            array( $this, 'print_slugs_promo' ),
            'permalink',
            array(
                'after_section' => sprintf( 
                    $section_text,
                    'href="' . admin_url('admin.php?page=ec-storefront-settings#ec-store-slugs-without-ids') . '"'
                )
            )
        );
    }

    public function print_slugs_promo($args) {
        echo $args['after_section'];
    }
}

$ecwid_seo_links = new Ecwid_Seo_Links();
