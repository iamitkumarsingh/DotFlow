<?php
/**
 * DenFlow branding tests.
 *
 * @package DenFlow
 */

class DotFlow_DenFlow_Branding_Test extends WP_UnitTestCase {

	public function test_customer_facing_product_name_is_rebranded() {
		$this->assertSame(
			'DenFlow CRM',
			\Groundhogg\denflow_branding_replace_product_name( 'Groundhogg CRM', 'Groundhogg CRM', 'groundhogg' )
		);
	}

	public function test_vendor_and_legal_attribution_are_preserved() {
		$this->assertSame(
			'Groundhogg Inc.',
			\Groundhogg\denflow_branding_replace_product_name( 'Groundhogg Inc.', 'Groundhogg Inc.', 'groundhogg' )
		);
		$this->assertSame(
			'Copyright © Groundhogg Inc. Licensed under GPLv3.',
			\Groundhogg\denflow_branding_replace_product_name( 'Copyright © Groundhogg Inc. Licensed under GPLv3.', 'Copyright © Groundhogg Inc. Licensed under GPLv3.', 'groundhogg' )
		);
	}

	public function test_unrelated_translation_domains_are_unchanged() {
		$this->assertSame(
			'Groundhogg CRM',
			\Groundhogg\denflow_branding_replace_product_name( 'Groundhogg CRM', 'Groundhogg CRM', 'unrelated-plugin' )
		);
	}
}
