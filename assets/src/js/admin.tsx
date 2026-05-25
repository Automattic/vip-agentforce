/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import {
	createInterpolateElement,
	createRoot,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type ConsentType =
	| 'CookieYes'
	| 'CookieBot'
	| 'OneTrust'
	| 'iubenda'
	| 'Custom';
type Alignment = 'bottom-right' | 'bottom-left';

interface SettingsValues {
	consentType: ConsentType;
	oneTrustGroupId: string;
	cookiebotCategory: string;
	iubendaPurposeId: string;
	alignment: Alignment;
	customCss: string;
	enableLog: boolean;
}

interface SettingsData {
	values: SettingsValues;
}

interface HiddenFieldProps {
	name: string;
	value: string;
}

interface SettingsSectionProps {
	title: string;
	description?: string;
	children: React.ReactNode;
}

const CONSENT_OPTIONS = [
	'CookieYes',
	'CookieBot',
	'OneTrust',
	'iubenda',
	'Custom',
] satisfies ConsentType[];

function isConsentType(value: string): value is ConsentType {
	return CONSENT_OPTIONS.some((option) => option === value);
}

function isAlignment(value: string): value is Alignment {
	return value === 'bottom-right' || value === 'bottom-left';
}

const HiddenField = ({ name, value }: HiddenFieldProps) => (
	<input type="hidden" name={name} value={value} />
);

const SettingsSection = ({
	title,
	description,
	children,
}: SettingsSectionProps) => (
	<section className="af-section">
		<h2>{title}</h2>
		{description && <p className="af-section-description">{description}</p>}
		<div className="af-section-fields">{children}</div>
	</section>
);

function AgentforceSettingsApp({ settings }: { settings: SettingsData }) {
	const { values } = settings;
	const [consentType, setConsentType] = useState(values.consentType);
	const [oneTrustGroupId, setOneTrustGroupId] = useState(
		values.oneTrustGroupId
	);
	const [cookiebotCategory, setCookiebotCategory] = useState(
		values.cookiebotCategory
	);
	const [iubendaPurposeId, setIubendaPurposeId] = useState(
		values.iubendaPurposeId
	);
	const [alignment, setAlignment] = useState(values.alignment);
	const [customCss, setCustomCss] = useState(values.customCss);
	const [enableLog, setEnableLog] = useState(Boolean(values.enableLog));
	const shouldShowOneTrust = consentType === 'OneTrust';
	const shouldShowCookiebot = consentType === 'CookieBot';
	const shouldShowIubenda = consentType === 'iubenda';
	const shouldShowCustom = consentType === 'Custom';
	const consentOptions = [
		{ label: __('CookieYes', 'vip-agentforce'), value: 'CookieYes' },
		{ label: __('CookieBot', 'vip-agentforce'), value: 'CookieBot' },
		{ label: __('OneTrust', 'vip-agentforce'), value: 'OneTrust' },
		{ label: __('iubenda', 'vip-agentforce'), value: 'iubenda' },
		{ label: __('Custom', 'vip-agentforce'), value: 'Custom' },
	];
	const handleConsentTypeChange = (value: string) => {
		if (isConsentType(value)) {
			setConsentType(value);
		}
	};
	const handleAlignmentChange = (value: string) => {
		if (isAlignment(value)) {
			setAlignment(value);
		}
	};

	return (
		<>
			<Card className="af-card">
				<CardBody className="af-card__body">
					<SettingsSection
						title={__('General Settings', 'vip-agentforce')}
						description={__(
							"Select a repository and choose where you'd like your files to deploy.",
							'vip-agentforce'
						)}
					>
						<div id="row_consent">
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								help={
									shouldShowCustom ? (
										<>
											{__(
												'This lets you control when the Agentforce SDK loads after consent is granted.',
												'vip-agentforce'
											)}
											<br />
											{createInterpolateElement(
												__(
													'Activate Agentforce after consent: <code>window.AgentforceCMP.loadSDK()</code>',
													'vip-agentforce'
												),
												{
													code: <code />,
												}
											)}
											<br />
											{createInterpolateElement(
												__(
													'If consent is revoked: <code>window.AgentforceCMP.unloadSDK()</code>',
													'vip-agentforce'
												),
												{
													code: <code />,
												}
											)}
										</>
									) : undefined
								}
								label={__('Consent Type', 'vip-agentforce')}
								options={consentOptions}
								value={consentType}
								onChange={handleConsentTypeChange}
							/>
						</div>

						{shouldShowOneTrust && (
							<div id="row_onetrust">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={__(
										'OneTrust Group ID',
										'vip-agentforce'
									)}
									value={oneTrustGroupId}
									onChange={setOneTrustGroupId}
								/>
							</div>
						)}

						{shouldShowCookiebot && (
							<div id="row_cookiebot">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									help={
										<>
											{__(
												'Enter the Cookiebot category (e.g., necessary, preferences, statistics, marketing).',
												'vip-agentforce'
											)}
											<br />
											{__(
												'To see all available categories for your site, open browser console and run:',
												'vip-agentforce'
											)}
											<br />
											<code>
												window.Cookiebot.consent
											</code>
										</>
									}
									label={__(
										'Cookiebot Category',
										'vip-agentforce'
									)}
									value={cookiebotCategory}
									onChange={setCookiebotCategory}
								/>
							</div>
						)}

						{shouldShowIubenda && (
							<div id="row_iubenda">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									help={
										<>
											{createInterpolateElement(
												__(
													'Enter the iubenda Purpose ID (1 to 5). See the <link>iubenda documentation</link>.',
													'vip-agentforce'
												),
												{
													link: (
														<a
															aria-label={__(
																'iubenda documentation',
																'vip-agentforce'
															)}
															href="https://www.iubenda.com/en/help/1205-how-to-configure-your-cookie-solution-advanced-guide#per-category-consent"
															target="_blank"
															rel="noreferrer"
														/>
													),
												}
											)}
											<br />
											{__(
												'To see all available purpose IDs for your site, open browser console and run:',
												'vip-agentforce'
											)}
											<br />
											<code>
												_iub.cs.api.getPreferences()
												.purposes
											</code>
										</>
									}
									label={__(
										'iubenda Purpose ID',
										'vip-agentforce'
									)}
									type="number"
									value={iubendaPurposeId}
									onChange={setIubendaPurposeId}
								/>
							</div>
						)}
					</SettingsSection>

					<SettingsSection
						title={__('Agent UI', 'vip-agentforce')}
						description={__(
							'Alignment and custom styles for the launcher/widget.',
							'vip-agentforce'
						)}
					>
						<div id="row_alignment">
							<RadioControl
								label={__('Alignment', 'vip-agentforce')}
								options={[
									{
										label: __(
											'Bottom right',
											'vip-agentforce'
										),
										value: 'bottom-right',
									},
									{
										label: __(
											'Bottom left',
											'vip-agentforce'
										),
										value: 'bottom-left',
									},
								]}
								selected={alignment}
								onChange={handleAlignmentChange}
							/>
						</div>

						<div id="row_custom_css">
							<TextareaControl
								__nextHasNoMarginBottom
								label={__(
									'Custom CSS (optional)',
									'vip-agentforce'
								)}
								placeholder={__(
									'/* Paste CSS to override agentforce styles */',
									'vip-agentforce'
								)}
								rows={5}
								value={customCss}
								onChange={setCustomCss}
							/>
						</div>
					</SettingsSection>

					<SettingsSection title={__('Debug', 'vip-agentforce')}>
						<div id="row_oplog">
							<ToggleControl
								__nextHasNoMarginBottom
								label={__('Enable log', 'vip-agentforce')}
								checked={enableLog}
								onChange={setEnableLog}
							/>
						</div>
					</SettingsSection>
				</CardBody>
			</Card>

			<HiddenField
				name="vip_agentforce_consent_type"
				value={consentType}
			/>
			<HiddenField
				name="vip_agentforce_onetrust_group_id"
				value={oneTrustGroupId}
			/>
			<HiddenField
				name="vip_agentforce_cookiebot_category"
				value={cookiebotCategory}
			/>
			<HiddenField
				name="vip_agentforce_iubenda_category"
				value={iubendaPurposeId}
			/>
			<HiddenField name="vip_agentforce_alignment" value={alignment} />
			<HiddenField name="vip_agentforce_custom_css" value={customCss} />
			<HiddenField
				name="vip_agentforce_enable_oplog"
				value={enableLog ? '1' : '0'}
			/>

			<p className="submit">
				<Button type="submit" variant="primary">
					{__('Save Changes', 'vip-agentforce')}
				</Button>
			</p>
		</>
	);
}

function initAgentforceSettingsApp() {
	const rootElement = document.getElementById('vip-agentforce-settings-app');

	if (!rootElement) {
		return;
	}

	const settings = JSON.parse(
		rootElement.dataset.settings || '{}'
	) as SettingsData;
	createRoot(rootElement).render(
		<AgentforceSettingsApp settings={settings} />
	);
}

domReady(initAgentforceSettingsApp);
