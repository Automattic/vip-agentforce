/**
 * CMP Manager for Agentforce.
 */
export const loadAgentforceSDK = () => {
	if ( document.getElementById( 'agentforce-sdk' ) ) {
		return;
	}
	if ( ! window.afConsentData || ! window.afConsentData.sdkUrl ) {
		return;
	}

	const script = document.createElement( 'script' );
	script.id = 'agentforce-sdk';
	script.src = window.afConsentData.sdkUrl;
	script.async = true;
	document.head.appendChild( script );
	window.AFConsentGranted = true;
};

export const unloadAgentforceSDK = () => {
	const script = document.getElementById( 'agentforce-sdk' );
	if ( script ) {
		script.remove();
	}
	if ( window.embeddedservice_bootstrap && window.embeddedservice_bootstrap.utilAPI ) {
		window.embeddedservice_bootstrap.utilAPI.removeAllComponents();
	}
	window.AFConsentGranted = false;
};
