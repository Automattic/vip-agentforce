export interface AgentForceConfig {
	salesforce_instance_url: string;
}
export const DEFAULT_CONFIG: AgentForceConfig = {
	salesforce_instance_url: 'https://your-salesforce-instance-url.com',
};
export function getAgentForceConfig(
	overrides?: Partial<AgentForceConfig>,
): AgentForceConfig {
	return {
		...DEFAULT_CONFIG,
		...overrides,

	};
}

export function getAgentForceConfigHeaders(
	partialConfig?: Partial<AgentForceConfig>,
): { [key: string]: string } {
	return {
		'X-Integration-Test': 'true',
		'X-Integration-Test-Configs': Buffer.from(
			JSON.stringify( getAgentForceConfig( partialConfig ) ),
			'utf8',
		).toString( 'base64' ),
	};
}
