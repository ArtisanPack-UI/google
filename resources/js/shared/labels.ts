/**
 * User-facing label bundle for the React and Vue connection-management
 * components.
 *
 * The Blade (Livewire) surface runs strings through Laravel's `__()` and
 * `trans_choice()`. The React and Vue surfaces can't call those directly, so
 * they accept a labels prop with the same set of strings translated by the
 * host application (typically by injecting the map with `@js(__(...))` from a
 * Blade layout, or by wiring an i18n library into the component tree).
 *
 * The defaults below are English fallbacks that keep the components usable
 * out of the box in a monolingual app. Ship any subset of overrides through
 * `labels={}` — anything you omit falls back to the default.
 *
 * @since 1.0.0
 */

export type GoogleConnectionLabels = {
	loading: string;
	retry: string;
	errorFallback: string;

	connectedAs: string;
	googleAccountFallback: string;
	noAccountConnected: string;

	connectButton: string;
	reauthorizeButton: string;
	disconnectButton: string;
	disconnectingButton: string;

	/**
	 * Label for the reauthorization banner. Receives the delta count so the
	 * host app can translate the pluralization.
	 */
	needsReauthorize: ( count: number ) => string;

	/**
	 * Label for the collapsed "granted scopes" list. Receives the count.
	 */
	grantedScopesTitle: ( count: number ) => string;

	/**
	 * Label for the "scopes that will be requested" list (disconnected state).
	 */
	requiredScopesTitle: ( count: number ) => string;
};

/**
 * Default English labels. Every component starts from this shape and merges
 * host-supplied overrides on top, so callers only need to override the strings
 * they care about.
 *
 * @since 1.0.0
 */
export const defaultGoogleConnectionLabels: GoogleConnectionLabels = {
	loading: 'Loading Google connection…',
	retry: 'Retry',
	errorFallback: 'Unable to load Google connection status.',

	connectedAs: 'Connected as',
	googleAccountFallback: 'Google account',
	noAccountConnected: 'No Google account connected.',

	connectButton: 'Connect Google',
	reauthorizeButton: 'Reauthorize',
	disconnectButton: 'Disconnect',
	disconnectingButton: 'Disconnecting…',

	needsReauthorize: ( count ) =>
		count === 1
			? '1 new scope requires reauthorization.'
			: `${ count } new scopes require reauthorization.`,

	grantedScopesTitle: ( count ) => `Granted scopes (${ count })`,

	requiredScopesTitle: ( count ) => `Scopes that will be requested (${ count })`,
};

/**
 * Merge caller-supplied labels onto the defaults. Undefined values fall
 * through to the default so callers can pass a partial map.
 *
 * @since 1.0.0
 */
export function mergeGoogleConnectionLabels(
	overrides?: Partial<GoogleConnectionLabels>,
): GoogleConnectionLabels {
	if ( ! overrides ) {
		return defaultGoogleConnectionLabels;
	}

	return { ...defaultGoogleConnectionLabels, ...overrides };
}
