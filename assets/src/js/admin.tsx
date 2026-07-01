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
type CookieYesCategory =
	| 'necessary'
	| 'functional'
	| 'analytics'
	| 'performance'
	| 'advertisement';
type CookiebotCategory =
	| 'necessary'
	| 'preferences'
	| 'statistics'
	| 'marketing';

interface SettingsValues {
	consentType: ConsentType;
	oneTrustGroupId: string;
	cookieyesCategory: CookieYesCategory;
	cookiebotCategory: CookiebotCategory;
	iubendaPurposeId: string;
	alignment: Alignment;
	customCss: string;
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

const COOKIEYES_CATEGORY_OPTIONS = [
	{ label: __('Necessary', 'vip-agentforce'), value: 'necessary' },
	{ label: __('Functional', 'vip-agentforce'), value: 'functional' },
	{ label: __('Analytics', 'vip-agentforce'), value: 'analytics' },
	{ label: __('Performance', 'vip-agentforce'), value: 'performance' },
	{ label: __('Advertisement', 'vip-agentforce'), value: 'advertisement' },
] satisfies { label: string; value: CookieYesCategory }[];

const COOKIEBOT_CATEGORY_OPTIONS = [
	{ label: __('Necessary', 'vip-agentforce'), value: 'necessary' },
	{ label: __('Preferences', 'vip-agentforce'), value: 'preferences' },
	{ label: __('Statistics', 'vip-agentforce'), value: 'statistics' },
	{ label: __('Marketing', 'vip-agentforce'), value: 'marketing' },
] satisfies { label: string; value: CookiebotCategory }[];

function isConsentType(value: string): value is ConsentType {
	return CONSENT_OPTIONS.some((option) => option === value);
}

function isAlignment(value: string): value is Alignment {
	return value === 'bottom-right' || value === 'bottom-left';
}

function isCookieYesCategory(value: string): value is CookieYesCategory {
	return COOKIEYES_CATEGORY_OPTIONS.some((option) => option.value === value);
}

function isCookiebotCategory(value: string): value is CookiebotCategory {
	return COOKIEBOT_CATEGORY_OPTIONS.some((option) => option.value === value);
}

function isRecord(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null;
}

function isSettingsValues(value: unknown): value is SettingsValues {
	if (!isRecord(value)) {
		return false;
	}

	return (
		typeof value.consentType === 'string' &&
		isConsentType(value.consentType) &&
		typeof value.oneTrustGroupId === 'string' &&
		typeof value.cookieyesCategory === 'string' &&
		isCookieYesCategory(value.cookieyesCategory) &&
		typeof value.cookiebotCategory === 'string' &&
		isCookiebotCategory(value.cookiebotCategory) &&
		typeof value.iubendaPurposeId === 'string' &&
		typeof value.alignment === 'string' &&
		isAlignment(value.alignment) &&
		typeof value.customCss === 'string'
	);
}

function parseSettingsData(
	rawSettings: string | undefined
): SettingsData | null {
	if (!rawSettings) {
		return null;
	}

	try {
		const parsedSettings: unknown = JSON.parse(rawSettings);

		if (
			isRecord(parsedSettings) &&
			isSettingsValues(parsedSettings.values)
		) {
			return {
				values: parsedSettings.values,
			};
		}
	} catch {
		return null;
	}

	return null;
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
	const [cookieyesCategory, setCookieYesCategory] = useState(
		values.cookieyesCategory
	);
	const [cookiebotCategory, setCookiebotCategory] = useState(
		values.cookiebotCategory
	);
	const [iubendaPurposeId, setIubendaPurposeId] = useState(
		values.iubendaPurposeId
	);
	const [alignment, setAlignment] = useState(values.alignment);
	const [customCss, setCustomCss] = useState(values.customCss);
	const shouldShowOneTrust = consentType === 'OneTrust';
	const shouldShowCookieYes = consentType === 'CookieYes';
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
	const handleCookieYesCategoryChange = (value: string) => {
		if (isCookieYesCategory(value)) {
			setCookieYesCategory(value);
		}
	};
	const handleCookiebotCategoryChange = (value: string) => {
		if (isCookiebotCategory(value)) {
			setCookiebotCategory(value);
		}
	};

	return (
		<>
			<Card className="af-card">
				<CardBody className="af-card__body">
					<SettingsSection
						title={__('General Settings', 'vip-agentforce')}
						description={__(
							'Choose how Answers Agent loads based on your consent provider.',
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
								<HiddenField
									name="vip_agentforce_onetrust_group_id"
									value={oneTrustGroupId}
								/>
							</div>
						)}

						{shouldShowCookieYes && (
							<div id="row_cookieyes">
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									help={__(
										'Choose the CookieYes category that must be granted before Answers Agent loads.',
										'vip-agentforce'
									)}
									label={__(
										'CookieYes Category',
										'vip-agentforce'
									)}
									options={COOKIEYES_CATEGORY_OPTIONS}
									value={cookieyesCategory}
									onChange={handleCookieYesCategoryChange}
								/>
								<HiddenField
									name="vip_agentforce_cookieyes_category"
									value={cookieyesCategory}
								/>
							</div>
						)}

						{shouldShowCookiebot && (
							<div id="row_cookiebot">
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									help={__(
										'Choose the Cookiebot category that must be granted before Answers Agent loads.',
										'vip-agentforce'
									)}
									label={__(
										'Cookiebot Category',
										'vip-agentforce'
									)}
									options={COOKIEBOT_CATEGORY_OPTIONS}
									value={cookiebotCategory}
									onChange={handleCookiebotCategoryChange}
								/>
								<HiddenField
									name="vip_agentforce_cookiebot_category"
									value={cookiebotCategory}
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
															rel="noopener noreferrer"
														/>
													),
												}
											)}
											<br />
											{__(
												'Purpose IDs:',
												'vip-agentforce'
											)}
											<ul>
												<li>
													{__(
														'1 - Necessary',
														'vip-agentforce'
													)}
												</li>
												<li>
													{__(
														'2 - Functionality (live chat and support)',
														'vip-agentforce'
													)}
												</li>
												<li>
													{__(
														'3 - Experience (external or social content)',
														'vip-agentforce'
													)}
												</li>
												<li>
													{__(
														'4 - Measurement (analytics and testing)',
														'vip-agentforce'
													)}
												</li>
												<li>
													{__(
														'5 - Marketing (ads and remarketing)',
														'vip-agentforce'
													)}
												</li>
											</ul>
											{__(
												'Choose the category where your Answers Agent widget is listed in iubenda.',
												'vip-agentforce'
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
								<HiddenField
									name="vip_agentforce_iubenda_category"
									value={iubendaPurposeId}
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
				</CardBody>
			</Card>

			<HiddenField
				name="vip_agentforce_consent_type"
				value={consentType}
			/>
			<HiddenField name="vip_agentforce_alignment" value={alignment} />
			<HiddenField name="vip_agentforce_custom_css" value={customCss} />

			<p className="submit">
				<Button type="submit" variant="primary">
					{__('Save Changes', 'vip-agentforce')}
				</Button>
			</p>
		</>
	);
}

function AgentforceSettingsError() {
	return (
		<div className="notice notice-error inline">
			<p>
				{__(
					'Answers Agent settings could not be loaded. Refresh the page and try again.',
					'vip-agentforce'
				)}
			</p>
		</div>
	);
}

function initAgentforceSettingsApp() {
	const rootElement = document.getElementById('vip-agentforce-settings-app');

	if (!rootElement) {
		return;
	}

	const settings = parseSettingsData(rootElement.dataset.settings);

	createRoot(rootElement).render(
		settings ? (
			<AgentforceSettingsApp settings={settings} />
		) : (
			<AgentforceSettingsError />
		)
	);
}

domReady(initAgentforceSettingsApp);
