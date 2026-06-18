import { expect, test } from '@playwright/test';

import { getAgentforceConfigHeaders } from '../lib/config-helper';
import { CmpSettingsPage } from '../lib/pages/cmp-settings-page';

declare global {
	interface Window {
		AgentforceCMP: {
			loadSDK: () => void;
			unloadSDK: () => void;
		};
		AFConsentGranted?: boolean;
		vipAgentforceConsentData?: {
			embedding?: {
				bootstrapSrc?: string;
			};
			prechatFields?: Record<string, unknown>;
		};
		initEmbeddedMessaging?: () => void;
		embeddedservice_bootstrap?: {
			settings: {
				restrictSessionOnMessagingChannel?: boolean;
			};
			prechatAPI: {
				setHiddenPrechatFields: (
					fields: Record<string, unknown>
				) => void;
			};
			utilAPI: {
				removeAllComponents: () => void;
			};
		};
	}
}

test.describe('CMP Settings', () => {
	test('saves consent settings and ignores inactive provider drafts', async ({
		page,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);

		await test.step('Visit CMP settings page', async () => {
			await cmpSettings.visit();
			await expect(cmpSettings.heading).toHaveText('Agentforce Settings');
		});

		await test.step('Save a OneTrust value', async () => {
			await cmpSettings.setConsentType('OneTrust');

			await expect(cmpSettings.onetrustRow).toBeVisible();
			await expect(cmpSettings.cookiebotRow).toBeHidden();
			await expect(cmpSettings.iubendaRow).toBeHidden();

			await cmpSettings.onetrustGroupId.fill('C0099');
			await cmpSettings.save();
		});

		await test.step('Save a CookieBot value and clear inactive OneTrust value', async () => {
			await cmpSettings.setConsentType('CookieBot');

			await expect(cmpSettings.onetrustRow).toBeHidden();
			await expect(cmpSettings.cookiebotRow).toBeVisible();
			await expect(cmpSettings.iubendaRow).toBeHidden();

			await cmpSettings.cookiebotCategory.fill('marketing');
			await cmpSettings.save();

			await expect(cmpSettings.consentType).toHaveValue('CookieBot');
			await expect(cmpSettings.onetrustRow).toBeHidden();
			await expect(cmpSettings.cookiebotRow).toBeVisible();
			await expect(cmpSettings.iubendaRow).toBeHidden();
			await expect(cmpSettings.cookiebotCategory).toHaveValue(
				'marketing'
			);

			await cmpSettings.setConsentType('OneTrust');
			await expect(cmpSettings.onetrustRow).toBeVisible();
			await expect(cmpSettings.onetrustGroupId).not.toHaveValue(
				'C0099'
			);
		});

		await test.step('Ignore draft provider values when saving a different consent type', async () => {
			await cmpSettings.setConsentType('CookieBot');
			await cmpSettings.cookiebotCategory.fill('preferences');

			await cmpSettings.setConsentType('Custom');
			await cmpSettings.save();

			await expect(cmpSettings.consentType).toHaveValue('Custom');

			await cmpSettings.setConsentType('CookieBot');
			await expect(cmpSettings.cookiebotCategory).not.toHaveValue(
				'preferences'
			);
		});
	});

	test('custom consent exposes SDK control API on the frontend', async ({
		page,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);
		const bootstrapSrc =
			'https://example.my.site.com/assets/js/bootstrap.min.js';

		await test.step('Configure Custom consent with embedding script', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType('Custom');

			await cmpSettings.save();
		});

		await test.step('Load frontend and use the exposed API to load/unload the SDK', async () => {
			await page.goto('/');

			const apiExists = await page.evaluate(
				() => typeof window.AgentforceCMP === 'object'
			);
			expect(apiExists).toBe(true);

			const afterLoad = await page.evaluate(() => {
				window.AgentforceCMP.loadSDK();
				return {
					hasScript: Boolean(
						document.getElementById('agentforce-sdk')
					),
					sdkSrc:
						document
							.getElementById('agentforce-sdk')
							?.getAttribute('src') || '',
					consent: window.AFConsentGranted === true,
				};
			});

			expect(afterLoad.hasScript).toBe(true);
			expect(afterLoad.consent).toBe(true);
			expect(afterLoad.sdkSrc).toContain(bootstrapSrc);

			const afterUnload = await page.evaluate(() => {
				window.AgentforceCMP.unloadSDK();
				return {
					hasScript: Boolean(
						document.getElementById('agentforce-sdk')
					),
					consent: window.AFConsentGranted === true,
				};
			});

			expect(afterUnload.hasScript).toBe(false);
			expect(afterUnload.consent).toBe(false);
		});
	});

	test('custom consent keeps messaging sessions isolated without prechat fields', async ({
		page,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);

		await test.step('Configure Custom consent', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType('Custom');
			await cmpSettings.save();
		});

		await test.step('Verify session isolation is applied once per load and listeners do not stack', async () => {
			await page.goto('/');

			const state = await page.evaluate(() => {
				const calls = {
					sessionIsolation: 0,
					hiddenPrechat: 0,
					removeAllComponents: 0,
				};

				const createBootstrap = () => {
					return {
						settings: {
							get restrictSessionOnMessagingChannel() {
								return undefined;
							},
							set restrictSessionOnMessagingChannel(_value) {
								calls.sessionIsolation += 1;
							},
						},
						prechatAPI: {
							setHiddenPrechatFields: () => {
								calls.hiddenPrechat += 1;
							},
						},
						utilAPI: {
							removeAllComponents: () => {
								calls.removeAllComponents += 1;
							},
						},
					};
				};

				window.vipAgentforceConsentData = {
					...(window.vipAgentforceConsentData || {}),
					embedding: {
						...(window.vipAgentforceConsentData?.embedding || {}),
						bootstrapSrc: 'data:text/javascript,',
					},
					prechatFields: undefined,
				};
				window.initEmbeddedMessaging = () => {};
				window.embeddedservice_bootstrap = createBootstrap();

				window.AgentforceCMP.loadSDK();
				window.dispatchEvent(new Event('onEmbeddedMessagingReady'));
				window.AgentforceCMP.unloadSDK();

				window.embeddedservice_bootstrap = createBootstrap();
				window.AgentforceCMP.loadSDK();
				window.dispatchEvent(new Event('onEmbeddedMessagingReady'));

				return {
					hasScript: Boolean(
						document.getElementById('agentforce-sdk')
					),
					consent: window.AFConsentGranted === true,
					...calls,
				};
			});

			expect(state.hasScript).toBe(true);
			expect(state.consent).toBe(true);
			expect(state.sessionIsolation).toBe(2);
			expect(state.hiddenPrechat).toBe(0);
			expect(state.removeAllComponents).toBe(1);
		});
	});

	test('debug preview auto-injects only for authorized logged-in users', async ({
		page,
		browser,
		baseURL,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);

		await test.step('Configure Custom consent', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType('Custom');
			await cmpSettings.save();
		});

		await test.step('Authorized logged-in user with debug query gets SDK injected', async () => {
			await page.goto('/?vip_agentforce_debug=true');
			await expect
				.poll(() =>
					page.evaluate(() =>
						Boolean(document.getElementById('agentforce-sdk'))
					)
				)
				.toBe(true);

			const debugState = await page.evaluate(() => ({
				hasScript: Boolean(document.getElementById('agentforce-sdk')),
				consent: window.AFConsentGranted === true,
			}));

			expect(debugState.hasScript).toBe(true);
			expect(debugState.consent).toBe(true);
		});

		await test.step('Logged-in user without debug query does not auto-inject', async () => {
			await page.goto('/');
			await page.waitForLoadState('domcontentloaded');

			const noDebugHasScript = await page.evaluate(() =>
				Boolean(document.getElementById('agentforce-sdk'))
			);
			expect(noDebugHasScript).toBe(false);
		});

		await test.step('Anonymous user with debug query does not bypass CMP', async () => {
			const anonymousContext = await browser.newContext({
				baseURL: baseURL as string,
				ignoreHTTPSErrors: true,
				extraHTTPHeaders: getAgentforceConfigHeaders(),
				storageState: {
					cookies: [],
					origins: [],
				},
			});
			const anonymousPage = await anonymousContext.newPage();

			try {
				await anonymousPage.goto('/wp-login.php?action=logout');
				const logoutLink = anonymousPage
					.locator('a[href*="action=logout"]')
					.first();
				if ((await logoutLink.count()) > 0) {
					await logoutLink.click();
				}

				await anonymousPage.goto('/wp-admin/');
				await expect(anonymousPage).toHaveURL(/wp-login\.php/);

				await anonymousPage.goto('/?vip_agentforce_debug=true');
				await anonymousPage.waitForLoadState('domcontentloaded');

				const anonymousState = await anonymousPage.evaluate(() => ({
					hasScript: Boolean(
						document.getElementById('agentforce-sdk')
					),
					consent: window.AFConsentGranted === true,
				}));

				expect(anonymousState.hasScript).toBe(false);
				expect(anonymousState.consent).toBe(false);
			} finally {
				await anonymousContext.close();
			}
		});
	});
});
