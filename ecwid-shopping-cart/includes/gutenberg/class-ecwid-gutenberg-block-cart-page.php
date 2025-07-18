<?php

class Ecwid_Gutenberg_Block_Cart_Page extends Ecwid_Gutenberg_Block_Store {
	protected $_name = 'cart-page';

	public function get_block_name() {
		return Ecwid_Gutenberg_Block_Base::get_block_name();
	}

	public function get_attributes_for_editor() {
		$attributes = parent::get_attributes_for_editor();

		$overrides = array(
			'show_footer_menu' => false,
		);

		foreach ( $overrides as $name => $editor_default ) {
			$attributes[ $name ]['profile_default'] = $attributes[ $name ]['default'];
			$attributes[ $name ]['default']         = $editor_default;
		}

		return $attributes;
	}


	public function render_callback( $params ) {

		$params['no_html_catalog'] = 1;

		$result = parent::render_callback( $params );

		ob_start();
		?>
		<script data-cfasync="false" data-no-optimize="1">
		Ecwid.OnAPILoaded.add(function() {
			Ecwid.OnPageLoad.add(function(page) {
				if ("CATEGORY" == page.type && 0 == page.categoryId && !page.hasPrevious) {
					Ecwid.openPage("cart");
				}
			})
		});
		</script>
		<?php

		$result .= ob_get_clean();

		return $result;
	}
}
