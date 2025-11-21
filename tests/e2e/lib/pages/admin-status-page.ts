import type { Locator, Page } from '@playwright/test';

const selectors = {
	wrapper: '.wrap',
	heading: '.wrap h1',
	salesforceInstanceUrlInput: 'input[name="salesforce_instance_url"]',
	salesforceAccessTokenInput: 'input[name="salesforce_access_token"]',
	salesforceLabel: 'th[scope="row"]',
	actionsStatusTable: '.widefat',
};

export class AdminStatusPage {
	private readonly page: Page;
	public readonly wrapper: Locator;
	public readonly heading: Locator;
	public readonly salesforceInstanceUrlInput: Locator;
	public readonly salesforceAccessTokenInput: Locator;
	public readonly salesforceLabel: Locator;
	public readonly actionsStatusTable: Locator;

	/**
	 * Constructs an instance of the Admin Status page.
	 *
	 * @param { Page } page Playwright page instance
	 */
	constructor( page: Page ) {
		this.page = page;
		this.wrapper = page.locator( selectors.wrapper );
		this.heading = page.locator( selectors.heading );
		this.salesforceInstanceUrlInput = page.locator( selectors.salesforceInstanceUrlInput );
		this.salesforceAccessTokenInput = page.locator( selectors.salesforceAccessTokenInput );
		this.salesforceLabel = page.locator( selectors.salesforceLabel );
		this.actionsStatusTable = page.locator( selectors.actionsStatusTable );
	}

	/**
	 * Navigate directly to the admin status settings page.
	 */
	public async visit(): Promise<void> {
		await this.page.goto( '/wp-admin/options-general.php?page=vip-agentforce-integration-status' );
		await this.wrapper.waitFor();
		await this.heading.waitFor();
	}
}
