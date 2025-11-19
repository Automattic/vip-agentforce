import type { Locator, Page } from '@playwright/test';

const selectors = {
	wrapper: '#vip-agentforce-integration-status-wrapper',
	heading: '#vip-agentforce-integration-status-wrapper h1',
	statusCard: '#vip-agentforce-integration-status',
	salesforceLabel: '#vip-agentforce-integration-status strong',
	salesforceValue: '#vip-agentforce-integration-status pre',
};

export class AdminStatusPage {
	private readonly page: Page;
	public readonly wrapper: Locator;
	public readonly heading: Locator;
	public readonly statusCard: Locator;
	public readonly salesforceLabel: Locator;
	public readonly salesforceValue: Locator;

	/**
	 * Constructs an instance of the Admin Status page.
	 *
	 * @param { Page } page Playwright page instance
	 */
	constructor( page: Page ) {
		this.page = page;
		this.wrapper = page.locator( selectors.wrapper );
		this.heading = page.locator( selectors.heading );
		this.statusCard = page.locator( selectors.statusCard );
		this.salesforceLabel = page.locator( selectors.salesforceLabel );
		this.salesforceValue = page.locator( selectors.salesforceValue );
	}

	/**
	 * Navigate directly to the admin status settings page.
	 */
	public async visit(): Promise<void> {
		await this.page.goto( '/wp-admin/options-general.php?page=vip-agentforce-integration-status' );
		await this.wrapper.waitFor();
	}
}
