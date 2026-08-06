/**
 * Cookiebot CMP Integration for Agentforce SDK
 */

/**
 * Internal dependencies
 */
import { loadAgentforceSDK, unloadAgentforceSDK } from './cmp-manager';

// Get the configured category from the localized data (default to preferences - the
// functional bucket the agent's chat cookies belong in)
const CONSENT_CATEGORY =
	(window.vipAgentforceConsentData &&
		window.vipAgentforceConsentData.cookiebotCategory) ||
	'preferences';

// Checks Cookiebot consent and loads/unloads SDK
const checkCookiebotConsent = () => {
	if (
		window.Cookiebot &&
		window.Cookiebot.consent &&
		window.Cookiebot.consent[CONSENT_CATEGORY] === true
	) {
		loadAgentforceSDK();
	} else {
		unloadAgentforceSDK();
	}
};

// Run on page load (if Cookiebot is already initialized)
if (window.Cookiebot && window.Cookiebot.consent) {
	checkCookiebotConsent();
}

// Listen for Cookiebot consent changes
window.addEventListener('CookiebotOnConsentReady', checkCookiebotConsent);
window.addEventListener('CookiebotOnAccept', checkCookiebotConsent);
window.addEventListener('CookiebotOnDecline', checkCookiebotConsent);
