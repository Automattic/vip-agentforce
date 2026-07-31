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
			cookieyesCategory?: string;
			iubendaPurposeId?: string;
			prechatFields?: Record<string, unknown>;
		};
		getCkyConsent?: () => {
			categories?: Record<string, boolean>;
		};
		_iub?: {
			__agentforceLocalPurposes?: Record<string, boolean>;
			cs?: {
				api?: {
					arePurposesAccepted?: (purposeIds: string[]) => boolean;
					getPurposesState?: () => Record<string, boolean>;
					getPreferences?: () => {
						purposes?: Record<string, boolean>;
					};
				};
			};
			csConfiguration?: {
				callback?: Record<
					string,
					(preference?: {
						purposes?: Record<string, boolean>;
					}) => void
				>;
			};
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
			await expect(cmpSettings.heading).toHaveText('Answers Agent Settings');
		});

		await test.step('Save a OneTrust value', async () => {
			await cmpSettings.setConsentType('OneTrust');

			await expect(cmpSettings.onetrustRow).toBeVisible();
			await expect(cmpSettings.cookieyesRow).toBeHidden();
			await expect(cmpSettings.cookiebotRow).toBeHidden();
			await expect(cmpSettings.iubendaRow).toBeHidden();

			await cmpSettings.onetrustGroupId.fill('C0099');
			await cmpSettings.save();
		});

		await test.step('Save a CookieYes category value', async () => {
			await cmpSettings.setConsentType('CookieYes');

			await expect(cmpSettings.onetrustRow).toBeHidden();
			await expect(cmpSettings.cookieyesRow).toBeVisible();
			await expect(cmpSettings.cookiebotRow).toBeHidden();
			await expect(cmpSettings.iubendaRow).toBeHidden();
			await expect(cmpSettings.cookieyesCategory).toHaveValue(
				'functional'
			);

			await cmpSettings.cookieyesCategory.selectOption('analytics');
			await cmpSettings.save();

			await expect(cmpSettings.consentType).toHaveValue('CookieYes');
			await expect(cmpSettings.cookieyesRow).toBeVisible();
			await expect(cmpSettings.cookieyesCategory).toHaveValue(
				'analytics'
			);
		});

		await test.step('Save a Cookiebot value and clear inactive OneTrust value', async () => {
			await cmpSettings.setConsentType('Cookiebot');

			await expect(cmpSettings.onetrustRow).toBeHidden();
			await expect(cmpSettings.cookieyesRow).toBeHidden();
			await expect(cmpSettings.cookiebotRow).toBeVisible();
			await expect(cmpSettings.iubendaRow).toBeHidden();

			await cmpSettings.cookiebotCategory.selectOption('statistics');
			await cmpSettings.save();

			await expect(cmpSettings.consentType).toHaveValue('Cookiebot');
			await expect(cmpSettings.onetrustRow).toBeHidden();
			await expect(cmpSettings.cookiebotRow).toBeVisible();
			await expect(cmpSettings.iubendaRow).toBeHidden();
			await expect(cmpSettings.cookiebotCategory).toHaveValue(
				'statistics'
			);

			await cmpSettings.setConsentType('OneTrust');
			await expect(cmpSettings.onetrustRow).toBeVisible();
			await expect(cmpSettings.onetrustGroupId).not.toHaveValue('C0099');
		});

		await test.step('Ignore draft provider values when saving a different consent type', async () => {
			// Draft values must differ from each provider's default, otherwise the
			// assertions below cannot tell a discarded draft from a saved one.
			await cmpSettings.setConsentType('Cookiebot');
			await cmpSettings.cookiebotCategory.selectOption('marketing');

			await cmpSettings.setConsentType('CookieYes');
			await cmpSettings.cookieyesCategory.selectOption('performance');

			await cmpSettings.setConsentType('Custom');
			await cmpSettings.save();

			await expect(cmpSettings.consentType).toHaveValue('Custom');

			// Inactive providers render no hidden input, so options.php sanitizes a
			// null value and each setting resets to its default.
			await cmpSettings.setConsentType('Cookiebot');
			await expect(cmpSettings.cookiebotCategory).toHaveValue(
				'preferences'
			);

			await cmpSettings.setConsentType('CookieYes');
			await expect(cmpSettings.cookieyesCategory).toHaveValue(
				'functional'
			);
		});
	});

	test('CookieYes frontend gate uses the configured category', async ({
		page,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);

		await page.addInitScript(() => {
			window.getCkyConsent = () => ({
				categories: {
					advertisement: false,
					analytics: true,
				},
			});
		});

		await test.step('Advertisement setting does not load for analytics-only consent', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType('CookieYes');
			await cmpSettings.cookieyesCategory.selectOption('advertisement');
			await cmpSettings.save();

			await page.goto('/');
			await page.waitForLoadState('domcontentloaded');

			const state = await page.evaluate(() => ({
				configuredCategory:
					window.vipAgentforceConsentData?.cookieyesCategory,
				hasScript: Boolean(document.getElementById('agentforce-sdk')),
				consent: window.AFConsentGranted === true,
			}));

			expect(state.configuredCategory).toBe('advertisement');
			expect(state.hasScript).toBe(false);
			expect(state.consent).toBe(false);
		});

		await test.step('Analytics setting loads for analytics-only consent', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType('CookieYes');
			await cmpSettings.cookieyesCategory.selectOption('analytics');
			await cmpSettings.save();

			await page.goto('/');

			const state = await page.evaluate(() => {
				const script = document.getElementById('agentforce-sdk');

				return {
					configuredCategory:
						window.vipAgentforceConsentData?.cookieyesCategory,
					configuredBootstrapSrc:
						window.vipAgentforceConsentData?.embedding
							?.bootstrapSrc || '',
					hasScript: Boolean(script),
					sdkSrc: script?.getAttribute('src') || '',
					consent: window.AFConsentGranted === true,
				};
			});

			expect(state.configuredCategory).toBe('analytics');
			expect(state.hasScript).toBe(true);
			expect(state.consent).toBe(true);
			expect(state.configuredBootstrapSrc).not.toBe('');
			expect(state.sdkSrc).toBe(state.configuredBootstrapSrc);
		});
	});

	test('iubenda frontend gate wraps late callbacks and unloads on revoke', async ({
		page,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);
		let bootstrapSrc = '';

		await test.step('Configure iubenda consent', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType('iubenda');
			await cmpSettings.iubendaPurpose.fill('2');
			await cmpSettings.save();
		});

		await test.step('Late iubenda callback setup loads and unloads Agentforce', async () => {
			await page.goto('/', { waitUntil: 'domcontentloaded' });

			const initialState = await page.evaluate(() => ({
				configuredPurpose:
					window.vipAgentforceConsentData?.iubendaPurposeId,
				bootstrapSrc:
					window.vipAgentforceConsentData?.embedding
						?.bootstrapSrc || '',
				hasScript: Boolean(document.getElementById('agentforce-sdk')),
				consent: window.AFConsentGranted === true,
			}));

			expect(initialState.configuredPurpose).toBe('2');
			expect(initialState.bootstrapSrc).toContain('bootstrap.min.js');
			expect(initialState.hasScript).toBe(false);
			expect(initialState.consent).toBe(false);
			bootstrapSrc = initialState.bootstrapSrc;

			await page.evaluate(() => {
				const getAcceptedPurposes = () =>
					window._iub?.__agentforceLocalPurposes || {};

				window._iub = window._iub || {};
				window._iub.__agentforceLocalPurposes = {
					2: false,
				};
				window._iub.cs = window._iub.cs || {};
				window._iub.cs.api = {
					arePurposesAccepted: (purposeIds) =>
						purposeIds.every(
							(purposeId) =>
								purposeId === '2' &&
								getAcceptedPurposes()[ '2' ] === true
						),
					getPurposesState: getAcceptedPurposes,
					getPreferences: () => ({
						purposes: getAcceptedPurposes(),
					}),
				};
				window._iub.csConfiguration =
					window._iub.csConfiguration || {};
				window._iub.csConfiguration.callback =
					window._iub.csConfiguration.callback || {};
			});

			await expect
				.poll(() =>
					page.evaluate(
						() =>
							typeof window._iub?.csConfiguration?.callback
								?.onPreferenceExpressed === 'function'
					)
				)
				.toBe(true);

			const afterAccept = await page.evaluate(() => {
				if ( ! window._iub ) {
					return null;
				}

				window._iub.__agentforceLocalPurposes = { 2: true };
				window._iub.csConfiguration?.callback?.onPreferenceExpressed?.({
					purposes: {
						2: true,
					},
				});

				const script = document.getElementById('agentforce-sdk');

				return {
					hasScript: Boolean(script),
					sdkSrc: script?.getAttribute('src') || '',
					consent: window.AFConsentGranted === true,
				};
			});

			expect(afterAccept).not.toBeNull();
			expect(afterAccept?.hasScript).toBe(true);
			expect(afterAccept?.sdkSrc).toContain(bootstrapSrc);
			expect(afterAccept?.consent).toBe(true);

			const afterRevoke = await page.evaluate(() => {
				if ( ! window._iub ) {
					return null;
				}

				window._iub.__agentforceLocalPurposes = { 2: false };
				window._iub.csConfiguration?.callback?.onPreferenceChange?.({
					purposes: {
						2: false,
					},
				});

				return {
					hasScript: Boolean(
						document.getElementById('agentforce-sdk')
					),
					consent: window.AFConsentGranted === true,
				};
			});

			expect(afterRevoke).not.toBeNull();
			expect(afterRevoke?.hasScript).toBe(false);
			expect(afterRevoke?.consent).toBe(false);

			await page.evaluate(() => {
				if ( ! window._iub ) {
					return;
				}

				window._iub.__agentforceLocalPurposes = { 2: true };
			});

			await expect
				.poll(() =>
					page.evaluate(() => {
						const script =
							document.getElementById('agentforce-sdk');

						return {
							hasScript: Boolean(script),
							sdkSrc: script?.getAttribute('src') || '',
							consent: window.AFConsentGranted === true,
						};
					})
				)
				.toMatchObject({
					hasScript: true,
					sdkSrc: expect.stringContaining(bootstrapSrc),
					consent: true,
				});

			await page.evaluate(() => {
				if ( ! window._iub ) {
					return;
				}

				window._iub.__agentforceLocalPurposes = { 2: false };
			});

			await expect
				.poll(() =>
					page.evaluate(() => ({
						hasScript: Boolean(
							document.getElementById('agentforce-sdk')
						),
						consent: window.AFConsentGranted === true,
					}))
				)
				.toMatchObject({
					hasScript: false,
					consent: false,
				});
		});
	});

	test('custom consent exposes SDK control API on the frontend', async ({
		page,
	}) => {
		const cmpSettings = new CmpSettingsPage(page);

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
					configuredBootstrapSrc:
						window.vipAgentforceConsentData?.embedding
							?.bootstrapSrc || '',
					consent: window.AFConsentGranted === true,
				};
			});

			expect(afterLoad.hasScript).toBe(true);
			expect(afterLoad.consent).toBe(true);
			expect(afterLoad.configuredBootstrapSrc).not.toBe('');
			expect(afterLoad.sdkSrc).toBe(afterLoad.configuredBootstrapSrc);

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
