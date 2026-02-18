export interface AgentforceConfig {
	salesforce_instance_url: string;
	ingestion_api_token?: string;
	ingestion_api_endpoint?: string;
	agentforce_embedding_script?: string;
	agentforce_js_sdk_activated?: boolean;
}

const DEFAULT_EMBEDDING_SCRIPT = `<script type="text/javascript">
function initEmbeddedMessaging() {
	window.__agentforceInitCalled = true;
}
</script>
<script type="text/javascript" src="https://example.local/assets/js/bootstrap.min.js" onload="initEmbeddedMessaging()"></script>`;

export const DEFAULT_CONFIG: AgentforceConfig = {
	salesforce_instance_url: 'https://your-salesforce-instance-url.com',
	agentforce_js_sdk_activated: true,
	agentforce_embedding_script: DEFAULT_EMBEDDING_SCRIPT,
};
export function getAgentforceConfig(overrides?: Partial<AgentforceConfig>): AgentforceConfig {
	return {
		...DEFAULT_CONFIG,
		...overrides,
	};
}

export function getAgentforceConfigHeaders(partialConfig?: Partial<AgentforceConfig>): {
	[key: string]: string;
} {
	return {
		'X-Integration-Test': 'true',
		'X-Integration-Test-Configs': Buffer.from(
			JSON.stringify(getAgentforceConfig(partialConfig)),
			'utf8'
		).toString('base64'),
	};
}
