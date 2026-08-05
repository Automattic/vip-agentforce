/**
 * OneTrust CMP integration for Agentforce SDK.
 */

/**
 * Internal dependencies
 */
import { loadAgentforceSDK, unloadAgentforceSDK } from './cmp-manager';

// Get the consent group ID from the localized data
// The backend default is defined in Assets::DEFAULT_ONETRUST_GROUP_ID
const CONSENT_GROUP_ID =
	(window.vipAgentforceConsentData && window.vipAgentforceConsentData.groupId) || '';

const hasConsent = () => {
	if (typeof window.OnetrustActiveGroups === 'string') {
		const groups = window.OnetrustActiveGroups.split(',');
		return groups.includes(CONSENT_GROUP_ID);
	}
	return false;
};

// One warning per page load, whichever diagnostic fires first - consent callbacks can
// run many times per page and a repeating console warning is noise, not signal.
let hasLoggedWarning = false;

const warn = (message) => {
	if (hasLoggedWarning) {
		return;
	}

	hasLoggedWarning = true;
	if (window.console && typeof window.console.warn === 'function') {
		window.console.warn(message);
	}
};

// Collect every group ID OneTrust knows about, including nested subgroups.
const collectGroupIds = (groups, collected) => {
	if (!Array.isArray(groups)) {
		return collected;
	}

	groups.forEach((group) => {
		if (group && group.OptanonGroupId) {
			collected.add(group.OptanonGroupId);
		}
		collectGroupIds(group && group.SubGroups, collected);
	});

	return collected;
};

// A group that exists but was declined is simply absent from OnetrustActiveGroups,
// so consent state alone cannot tell a declined group from a typo. Check the group
// ID against the full domain data instead, which lists every configured group.
const warnIfGroupMissing = () => {
	if (!CONSENT_GROUP_ID) {
		warn(
			'Agentforce OneTrust Group ID is not configured. The agent will not load.'
		);
		return;
	}

	try {
		if (typeof window.OneTrust?.GetDomainData !== 'function') {
			return;
		}

		const knownGroupIds = collectGroupIds(
			window.OneTrust.GetDomainData()?.Groups,
			new Set()
		);

		if (knownGroupIds.size > 0 && !knownGroupIds.has(CONSENT_GROUP_ID)) {
			warn(
				`Agentforce OneTrust Group ID "${CONSENT_GROUP_ID}" was not found in this site's OneTrust configuration. The agent will not load.`
			);
		}
	} catch (error) {
		// Silent fail - the warning is diagnostic only.
	}
};

const checkOneTrustConsent = () => {
	warnIfGroupMissing();

	if (hasConsent()) {
		loadAgentforceSDK();
	} else {
		unloadAgentforceSDK();
	}
};

// Extend the global OptanonWrapper function
const originalOptanonWrapper = window.OptanonWrapper || function () {};
window.OptanonWrapper = function () {
	// Call the original wrapper if it exists
	originalOptanonWrapper();

	// Check consent after OneTrust is fully loaded
	checkOneTrustConsent();
};

// Listen for OneTrust consent changes
document.addEventListener('OneTrustGroupsUpdated', checkOneTrustConsent);

// Also listen via the official API when available
// This provides redundancy in case the event listener doesn't catch all changes
if (typeof OneTrust !== 'undefined' && typeof OneTrust.OnConsentChanged === 'function') {
	OneTrust.OnConsentChanged(checkOneTrustConsent);
}
