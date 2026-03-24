/**
 * CMP Manager for Agentforce.
 */
const getEmbeddingConfig = () => window.vipAgentforceConsentData?.embedding;
const getPrechatFields = () => window.vipAgentforceConsentData?.prechatFields;
let onEmbeddedMessagingReadyHandler;

const setupEmbeddedMessagingReadyHandler = () => {
	if (onEmbeddedMessagingReadyHandler) {
		return;
	}

	onEmbeddedMessagingReadyHandler = () => {
		const settings = window.embeddedservice_bootstrap?.settings;
		if (!settings) {
			return;
		}

		settings.restrictSessionOnMessagingChannel = true;

		const prechatFields = getPrechatFields();
		const hasPrechatFields =
			prechatFields && Object.keys(prechatFields).length > 0;

		if (!hasPrechatFields) {
			return;
		}

		try {
			window.embeddedservice_bootstrap?.prechatAPI?.setHiddenPrechatFields?.(
				prechatFields
			);
		} catch (error) {
			// Silent fail — prechat fields are non-critical.
		}
	};

	window.addEventListener(
		'onEmbeddedMessagingReady',
		onEmbeddedMessagingReadyHandler
	);
};

const teardownEmbeddedMessagingReadyHandler = () => {
	if (!onEmbeddedMessagingReadyHandler) {
		return;
	}

	window.removeEventListener(
		'onEmbeddedMessagingReady',
		onEmbeddedMessagingReadyHandler
	);
	onEmbeddedMessagingReadyHandler = undefined;
};

const invokeEmbeddedMessagingInit = () => {
	if (typeof window.initEmbeddedMessaging === 'function') {
		window.initEmbeddedMessaging();
	}
};

export const loadAgentforceSDK = () => {
	if (document.getElementById('agentforce-sdk')) {
		return;
	}

	const embeddingConfig = getEmbeddingConfig();
	if (!embeddingConfig || !embeddingConfig.bootstrapSrc) {
		return;
	}

	setupEmbeddedMessagingReadyHandler();

	const script = document.createElement('script');
	script.id = 'agentforce-sdk';
	script.src = embeddingConfig.bootstrapSrc;
	script.async = true;
	script.onload = invokeEmbeddedMessagingInit;
	document.head.appendChild(script);
	window.AFConsentGranted = true;
};

export const unloadAgentforceSDK = () => {
	const script = document.getElementById('agentforce-sdk');
	if (script) {
		script.remove();
	}
	if (
		window.embeddedservice_bootstrap &&
		window.embeddedservice_bootstrap.utilAPI
	) {
		window.embeddedservice_bootstrap.utilAPI.removeAllComponents();
	}
	teardownEmbeddedMessagingReadyHandler();
	window.AFConsentGranted = false;
};
