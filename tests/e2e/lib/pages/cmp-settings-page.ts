import type { Locator, Page } from '@playwright/test';

const selectors = {
	heading: '.agentforce-wrap h1',
	onetrustRow: '#row_onetrust',
	cookiebotRow: '#row_cookiebot',
	iubendaRow: '#row_iubenda',
	saveButton: 'button[type="submit"]',
	successNotice: '#setting-error-vip_agentforce_message.notice-success',
};

export class CmpSettingsPage {
	private readonly page: Page;
	public readonly heading: Locator;
	public readonly consentType: Locator;
	public readonly onetrustRow: Locator;
	public readonly onetrustGroupId: Locator;
	public readonly cookiebotRow: Locator;
	public readonly cookiebotCategory: Locator;
	public readonly iubendaRow: Locator;
	public readonly iubendaPurpose: Locator;
	public readonly saveButton: Locator;
	public readonly successNotice: Locator;

	constructor(page: Page) {
		this.page = page;
		this.heading = page.locator(selectors.heading);
		this.consentType = page.getByLabel('Consent Type');
		this.onetrustRow = page.locator(selectors.onetrustRow);
		this.onetrustGroupId = page.getByLabel('OneTrust Group ID');
		this.cookiebotRow = page.locator(selectors.cookiebotRow);
		this.cookiebotCategory = page.getByLabel('Cookiebot Category');
		this.iubendaRow = page.locator(selectors.iubendaRow);
		this.iubendaPurpose = page.getByLabel('iubenda Purpose ID');
		this.saveButton = page.locator(selectors.saveButton);
		this.successNotice = page.locator(selectors.successNotice);
	}

	public async visit(): Promise<void> {
		await this.page.goto(
			'/wp-admin/admin.php?page=vip-agentforce-settings'
		);
		await this.heading.waitFor();
	}

	public async setConsentType(
		value: 'CookieYes' | 'CookieBot' | 'OneTrust' | 'iubenda' | 'Custom'
	): Promise<void> {
		await this.consentType.selectOption(value);
	}

	public async save(): Promise<void> {
		await this.saveButton.click();
		await this.successNotice.waitFor();
	}
}
