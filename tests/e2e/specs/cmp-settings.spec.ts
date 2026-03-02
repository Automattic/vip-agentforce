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
	}
}

test.describe( 'CMP Settings', () => {
	test( 'saves consent settings and toggles provider fields', async ( { page } ) => {
		const cmpSettings = new CmpSettingsPage( page );

		await test.step( 'Visit CMP settings page', async () => {
			await cmpSettings.visit();
			await expect( cmpSettings.heading ).toHaveText( 'Agentforce Settings' );
			await expect( cmpSettings.sdkActivationStatus ).toHaveAttribute( 'data-status', 'active' );
			await expect( cmpSettings.embeddingScriptStatus ).toHaveAttribute( 'data-status', 'configured' );
		} );

		await test.step( 'Configure Cookiebot consent settings', async () => {
			await cmpSettings.setConsentType( 'CookieBot' );

			await expect( cmpSettings.onetrustRow ).toBeHidden();
			await expect( cmpSettings.cookiebotRow ).toBeVisible();

			await cmpSettings.cookiebotCategory.fill( 'marketing' );
		} );

		await test.step( 'Save settings and verify persisted values', async () => {
			await cmpSettings.save();

			await expect( cmpSettings.consentType ).toHaveValue( 'CookieBot' );
			await expect( cmpSettings.cookiebotCategory ).toHaveValue( 'marketing' );
		} );
	} );

	test( 'custom consent exposes SDK control API on the frontend', async ( { page } ) => {
		const cmpSettings = new CmpSettingsPage( page );
		const bootstrapSrc = 'https://example.local/assets/js/bootstrap.min.js';

		await test.step( 'Configure Custom consent with embedding script', async () => {
			await cmpSettings.visit();
			await expect( cmpSettings.sdkActivationStatus ).toHaveAttribute( 'data-status', 'active' );
			await expect( cmpSettings.embeddingScriptStatus ).toHaveAttribute( 'data-status', 'configured' );
			await cmpSettings.setConsentType( 'Custom' );

			await cmpSettings.save();
		} );

		await test.step( 'Load frontend and use the exposed API to load/unload the SDK', async () => {
			await page.goto( '/' );

			const apiExists = await page.evaluate( () => typeof window.AgentforceCMP === 'object' );
			expect( apiExists ).toBe( true );

			const afterLoad = await page.evaluate( () => {
				window.AgentforceCMP.loadSDK();
				return {
					hasScript: Boolean( document.getElementById( 'agentforce-sdk' ) ),
					sdkSrc: document.getElementById( 'agentforce-sdk' )?.getAttribute( 'src' ) || '',
					consent: window.AFConsentGranted === true,
				};
			} );

			expect( afterLoad.hasScript ).toBe( true );
			expect( afterLoad.consent ).toBe( true );
			expect( afterLoad.sdkSrc ).toContain( bootstrapSrc );

			const afterUnload = await page.evaluate( () => {
				window.AgentforceCMP.unloadSDK();
				return {
					hasScript: Boolean( document.getElementById( 'agentforce-sdk' ) ),
					consent: window.AFConsentGranted === true,
				};
			} );

			expect( afterUnload.hasScript ).toBe( false );
			expect( afterUnload.consent ).toBe( false );
		} );
	} );

	test( 'debug preview auto-injects only for authorized logged-in users', async ( { page, browser, baseURL } ) => {
		const cmpSettings = new CmpSettingsPage( page );

		await test.step( 'Configure Custom consent', async () => {
			await cmpSettings.visit();
			await cmpSettings.setConsentType( 'Custom' );
			await cmpSettings.save();
		} );

		await test.step( 'Authorized logged-in user with debug query gets SDK injected', async () => {
			await page.goto( '/?vip_agentforce_debug=true' );
			await expect
				.poll( () => page.evaluate( () => Boolean( document.getElementById( 'agentforce-sdk' ) ) ) )
				.toBe( true );

			const debugState = await page.evaluate( () => ( {
				hasScript: Boolean( document.getElementById( 'agentforce-sdk' ) ),
				consent: window.AFConsentGranted === true,
			} ) );

			expect( debugState.hasScript ).toBe( true );
			expect( debugState.consent ).toBe( true );
		} );

		await test.step( 'Logged-in user without debug query does not auto-inject', async () => {
			await page.goto( '/' );
			await page.waitForLoadState( 'domcontentloaded' );

			const noDebugHasScript = await page.evaluate(
				() => Boolean( document.getElementById( 'agentforce-sdk' ) )
			);
			expect( noDebugHasScript ).toBe( false );
		} );

		await test.step( 'Anonymous user with debug query does not bypass CMP', async () => {
			const anonymousContext = await browser.newContext( {
				baseURL: baseURL as string,
				ignoreHTTPSErrors: true,
				extraHTTPHeaders: getAgentforceConfigHeaders(),
				storageState: {
					cookies: [],
					origins: [],
				},
			} );
			const anonymousPage = await anonymousContext.newPage();

			try {
				await anonymousPage.goto( '/?vip_agentforce_debug=true' );
				await anonymousPage.waitForLoadState( 'domcontentloaded' );

				const anonymousState = await anonymousPage.evaluate( () => ( {
					isLoggedIn: Boolean( document.getElementById( 'wpadminbar' ) ),
					hasScript: Boolean( document.getElementById( 'agentforce-sdk' ) ),
					consent: window.AFConsentGranted === true,
				} ) );

				expect( anonymousState.isLoggedIn ).toBe( false );
				expect( anonymousState.hasScript ).toBe( false );
				expect( anonymousState.consent ).toBe( false );
			} finally {
				await anonymousContext.close();
			}
		} );
	} );
} );
