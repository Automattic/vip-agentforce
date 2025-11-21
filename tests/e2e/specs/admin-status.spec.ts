import { expect, test } from '@playwright/test';

import { getAgentForceConfig } from '../lib/config-helper';
import { AdminStatusPage } from '../lib/pages/admin-status-page';

const agentForceConfig = getAgentForceConfig();

test.describe( 'Admin Status Page', () => {
	test( 'displays Salesforce instance details from the integration config', async ( { page } ) => {
		const adminStatusPage = new AdminStatusPage( page );
		const expectedSalesforceUrl = agentForceConfig.salesforce_instance_url ?? '';

		await test.step( 'Visit the admin status settings page', () => {
			return adminStatusPage.visit();
		} );

		await test.step( 'Verify the page heading and layout elements render', async () => {
			await expect( adminStatusPage.wrapper ).toBeVisible();
			await expect( adminStatusPage.heading ).toHaveText( 'VIP Agentforce Settings' );
			await expect( adminStatusPage.salesforceInstanceUrlInput ).toBeVisible();
			await expect( adminStatusPage.actionsStatusTable ).toBeVisible();
		} );

		await test.step( 'Verify Salesforce instance configuration form is shown', async () => {
			await expect( adminStatusPage.salesforceLabel.filter( { hasText: 'Salesforce Instance URL' } ) ).toBeVisible();
			if ( expectedSalesforceUrl ) {
				await expect( adminStatusPage.salesforceInstanceUrlInput ).toHaveValue( expectedSalesforceUrl );
			}
		} );
	} );
} );
