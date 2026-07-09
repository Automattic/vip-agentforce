/**
 * Custom Agentforce chat launcher.
 *
 * Renders a branded, host-owned button that opens the Messaging-for-Web
 * conversation via the Launch Chat API, replacing Salesforce's default
 * floating action button.
 *
 * This follows Salesforce's supported contract for a custom launcher:
 *   - `hideChatButtonOnLoad` (set in the init script) hides the default button
 *     on load.
 *   - `onEmbeddedMessagingButtonCreated` is the documented precondition for
 *     calling `launchChat()`, and the point where `hideChatButton()` can keep
 *     the default button suppressed.
 *   - The default button is hidden through the `hideChatButton()` API.
 */
const LAUNCHER_ID = 'vip-agentforce-launcher';

// Trusted, author-controlled markup. The label is set separately via
// textContent so customer-configured text can never inject HTML.
const ICON_SVG =
	'<svg class="vip-agentforce-launcher__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM7 9h10v2H7V9zm6 4H7v-2h6v2zm4-6H7V5h10v2z"/></svg>';

let launcherButton;
const handlers = {};

const getLauncherConfig = () => window.vipAgentforceConsentData?.launcher;

const getUtilAPI = () => window.embeddedservice_bootstrap?.utilAPI;

const showLauncher = () => {
	if (launcherButton) {
		launcherButton.hidden = false;
	}
};

const hideLauncher = () => {
	if (launcherButton) {
		launcherButton.hidden = true;
	}
};

// Keep Salesforce's own button hidden via the supported API. It is a no-op while
// the chat window is open, which is fine - the default button only matters when
// the window is closed.
const hideDefaultButton = () => {
	try {
		getUtilAPI()?.hideChatButton?.();
	} catch (error) {
		// Silent fail - hiding is best-effort.
	}
};

const handleClick = () => {
	const utilAPI = getUtilAPI();
	if (!utilAPI || typeof utilAPI.launchChat !== 'function') {
		return;
	}

	// Keep the launcher visible while the chat window boots - the maximized
	// event hides it once the window is actually on screen. Disable it in the
	// meantime so a second click cannot double-launch.
	launcherButton.disabled = true;
	Promise.resolve(utilAPI.launchChat())
		.catch(() => {})
		.finally(() => {
			if (launcherButton) {
				launcherButton.disabled = false;
			}
		});
};

const createLauncherButton = (config) => {
	const alignment =
		config.alignment === 'bottom-left' ? 'bottom-left' : 'bottom-right';

	const button = document.createElement('button');
	button.type = 'button';
	button.id = LAUNCHER_ID;
	button.className = `vip-agentforce-launcher vip-agentforce-launcher--${alignment}`;
	button.setAttribute('aria-label', config.label);
	button.innerHTML = ICON_SVG;

	const labelSpan = document.createElement('span');
	labelSpan.className = 'vip-agentforce-launcher__label';
	labelSpan.textContent = config.label;
	button.appendChild(labelSpan);

	// Stay hidden until the bootstrap reports its button has been created.
	button.hidden = true;
	button.addEventListener('click', handleClick);

	return button;
};

export const setupCustomLauncher = () => {
	const config = getLauncherConfig();
	if (!config) {
		return;
	}

	if (document.getElementById(LAUNCHER_ID)) {
		return;
	}

	launcherButton = createLauncherButton(config);
	document.body.appendChild(launcherButton);

	// The bootstrap's button is the launchChat() precondition: once it exists we
	// suppress it and reveal our own.
	handlers.buttonCreated = () => {
		hideDefaultButton();
		showLauncher();
	};
	// Hide our launcher while the conversation window is open.
	handlers.maximized = hideLauncher;
	// When the window is minimized or closed, the default button can reappear -
	// re-hide it and bring our launcher back.
	handlers.restore = () => {
		hideDefaultButton();
		showLauncher();
	};

	window.addEventListener(
		'onEmbeddedMessagingButtonCreated',
		handlers.buttonCreated
	);
	window.addEventListener(
		'onEmbeddedMessagingWindowMaximized',
		handlers.maximized
	);
	window.addEventListener(
		'onEmbeddedMessagingWindowMinimized',
		handlers.restore
	);
	window.addEventListener(
		'onEmbeddedMessagingWindowClosed',
		handlers.restore
	);
};

export const teardownCustomLauncher = () => {
	if (handlers.buttonCreated) {
		window.removeEventListener(
			'onEmbeddedMessagingButtonCreated',
			handlers.buttonCreated
		);
	}

	if (handlers.maximized) {
		window.removeEventListener(
			'onEmbeddedMessagingWindowMaximized',
			handlers.maximized
		);
	}

	if (handlers.restore) {
		window.removeEventListener(
			'onEmbeddedMessagingWindowMinimized',
			handlers.restore
		);
		window.removeEventListener(
			'onEmbeddedMessagingWindowClosed',
			handlers.restore
		);
	}

	handlers.buttonCreated = undefined;
	handlers.maximized = undefined;
	handlers.restore = undefined;

	if (launcherButton) {
		launcherButton.removeEventListener('click', handleClick);
		launcherButton.remove();
		launcherButton = undefined;
	}
};
