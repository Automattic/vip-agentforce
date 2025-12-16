export interface AgentforceConfig {
	salesforce_instance_url: string;
}
export const DEFAULT_CONFIG: AgentforceConfig = {
	salesforce_instance_url: 'https://your-salesforce-instance-url.com',
};
export function getAgentforceConfig(
	overrides?: Partial<AgentforceConfig>,
): AgentforceConfig {
	return {
		...DEFAULT_CONFIG,
		...overrides,

	};
}

export function getAgentforceConfigHeaders(
	partialConfig?: Partial<AgentforceConfig>,
): { [key: string]: string } {
	return {
		'X-Integration-Test': 'true',
		'X-Integration-Test-Configs': Buffer.from(
			JSON.stringify( getAgentforceConfig( partialConfig ) ),
			'utf8',
		).toString( 'base64' ),
	};
}
