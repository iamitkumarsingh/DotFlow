<?php

class DotFlow_AI_Mutation_Audit_Coverage_Tests extends GH_UnitTestCase {

	public function test_every_exposed_ai_mutation_ability_records_an_audit_event() {

		$files = [
			'includes/abilities/broadcasts/cancel-broadcast.php',
			'includes/abilities/broadcasts/send-email-broadcast.php',
			'includes/abilities/campaigns/associate-campaign.php',
			'includes/abilities/campaigns/create-campaign.php',
			'includes/abilities/contacts/add-contact-note.php',
			'includes/abilities/contacts/add-custom-field.php',
			'includes/abilities/contacts/create-contact.php',
			'includes/abilities/contacts/update-contact.php',
			'includes/abilities/contacts/update-custom-field.php',
			'includes/abilities/emails/create-email-template.php',
			'includes/abilities/emails/send-composed-email.php',
			'includes/abilities/emails/send-email-template.php',
			'includes/abilities/emails/send-test-email.php',
			'includes/abilities/emails/update-email-template.php',
			'includes/abilities/extensions/activate-license.php',
			'includes/abilities/extensions/check-license.php',
			'includes/abilities/extensions/install-extension.php',
			'includes/abilities/funnels/activate-flow.php',
			'includes/abilities/funnels/add-to-flow.php',
			'includes/abilities/funnels/create-flow.php',
			'includes/abilities/funnels/deactivate-flow.php',
			'includes/abilities/funnels/discard-flow-changes.php',
			'includes/abilities/funnels/edit-flow.php',
			'includes/abilities/funnels/publish-flow-changes.php',
			'includes/abilities/settings/update-settings.php',
			'includes/abilities/tags/create-tag.php',
			'includes/abilities/utils/upload-media.php',
			'includes/dotflow/abilities/set-message-read-state.php',
		];

		foreach ( $files as $path ) {
			$full_path = GROUNDHOGG_PATH . $path;
			$this->assertFileExists( $full_path, $path . ' must exist.' );

			$contents = file_get_contents( $full_path );
			$this->assertNotFalse( $contents );
			$this->assertStringContainsString( 'Audit::record', $contents, $path . ' must emit a mutation audit event.' );
		}
	}
}
