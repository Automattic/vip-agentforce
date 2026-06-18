/**
 * CookieYes CMP integration for Agentforce.
 */

/**
 * Internal dependencies
 */
import { loadAgentforceSDK, unloadAgentforceSDK } from './cmp-manager';

const CONSENT_CATEGORY =
	(window.vipAgentforceConsentData &&
		window.vipAgentforceConsentData.cookieyesCategory) ||
	'functional';

let hasLoggedMissingCategoryWarning = false;

const warnIfCategoryMissing = (categories) => {
	if (
		hasLoggedMissingCategoryWarning ||
		!categories ||
		Object.prototype.hasOwnProperty.call(categories, CONSENT_CATEGORY)
	) {
		return;
	}

	hasLoggedMissingCategoryWarning = true;
	if (window.console && typeof window.console.warn === 'function') {
		window.console.warn(
			`Agentforce CookieYes consent category "${CONSENT_CATEGORY}" was not found.`
		);
	}
};

// Check CookieYes consent and load/unload SDK
const checkCookieYesConsent = () => {
	try {
		const consent =
			typeof window.getCkyConsent === 'function'
				? window.getCkyConsent()
				: null;
		warnIfCategoryMissing(consent && consent.categories);
		if (
			consent &&
			consent.categories &&
			consent.categories[CONSENT_CATEGORY] === true
		) {
			loadAgentforceSDK();
		} else {
			unloadAgentforceSDK();
		}
	} catch (error) {
		// Silent fail
	}
};

// On DOM ready, check consent
document.addEventListener('DOMContentLoaded', checkCookieYesConsent);

// Listen for CookieYes consent changes
document.addEventListener('cky-consent-updated', checkCookieYesConsent);
