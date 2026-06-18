/**
 * iubenda CMP integration for Agentforce SDK
 */

/**
 * Internal dependencies
 */
import { loadAgentforceSDK, unloadAgentforceSDK } from './cmp-manager';

// Get the configured purpose ID from the localized data (default to 2 - Functionality)
const PURPOSE_ID =
	(window.vipAgentforceConsentData &&
		window.vipAgentforceConsentData.iubendaPurposeId) ||
	'2';

const hasPurposeConsent = (preference) =>
	preference?.purposes?.[PURPOSE_ID] === true;

const getIubendaConsentFromApi = () => {
	const api = window._iub?.cs?.api;
	if (!api) {
		return undefined;
	}

	if (typeof api.arePurposesAccepted === 'function') {
		return (
			api.arePurposesAccepted([PURPOSE_ID], { promptIfNot: false }) ===
			true
		);
	}

	if (typeof api.getPurposesState === 'function') {
		const purposes = api.getPurposesState();
		return purposes?.[PURPOSE_ID] === true;
	}

	if (typeof api.getPreferences === 'function') {
		return hasPurposeConsent(api.getPreferences());
	}

	return undefined;
};

// Checks iubenda consent and loads/unloads SDK
const checkIubendaConsent = (preference) => {
	// If preference is passed directly (from event), use it
	if (preference?.purposes) {
		// Check if the configured purpose ID has consent
		if (hasPurposeConsent(preference)) {
			loadAgentforceSDK();
		} else {
			unloadAgentforceSDK();
		}
		return;
	}

	// Fallback: check global _iub object if available
	try {
		const hasConsent = getIubendaConsentFromApi();
		if (hasConsent === true) {
			loadAgentforceSDK();
		} else if (hasConsent === false) {
			unloadAgentforceSDK();
		}
	} catch (error) {
		// Silent fail.
	}
};

const IUBENDA_CALLBACK_NAMES = [
	'onPreferenceExpressed',
	'onPreferenceFirstExpressed',
	'onPreferenceChange',
	'onConsentRead',
];
const wrappedIubendaCallbacks = new Set();

const wrapIubendaCallback = (callbackName) => {
	if (
		!window._iub?.csConfiguration ||
		wrappedIubendaCallbacks.has(callbackName)
	) {
		return;
	}

	window._iub.csConfiguration.callback =
		window._iub.csConfiguration.callback || {};

	const callbacks = window._iub.csConfiguration.callback;
	const originalCallback = callbacks[callbackName];

	callbacks[callbackName] = function (...args) {
		if (typeof originalCallback === 'function') {
			originalCallback.apply(this, args);
		}

		checkIubendaConsent(args[0]);
	};

	wrappedIubendaCallbacks.add(callbackName);
};

const wrapIubendaCallbacks = () => {
	IUBENDA_CALLBACK_NAMES.forEach(wrapIubendaCallback);
};

const waitForIubendaCallbacks = (attemptsRemaining = 10) => {
	wrapIubendaCallbacks();

	if (
		wrappedIubendaCallbacks.size === IUBENDA_CALLBACK_NAMES.length ||
		attemptsRemaining <= 0
	) {
		return;
	}

	window.setTimeout(
		() => waitForIubendaCallbacks(attemptsRemaining - 1),
		250
	);
};

waitForIubendaCallbacks();

// Listen for iubenda custom preference update event
document.addEventListener('iubendaPreferenceUpdate', (event) => {
	if (event.detail) {
		checkIubendaConsent(event.detail);
	}
});

document.addEventListener('DOMContentLoaded', () => {
	waitForIubendaCallbacks();
	checkIubendaConsent();
});
