/**
 * Shared client for the Google connection JSON endpoints.
 *
 * Both the React and Vue connection-management components consume this
 * module. Framework-specific code stays presentation-only; anything that
 * hits the backend lives here.
 *
 * @since 1.0.0
 */

export type GoogleConnectionUrls = {
	connect: string;
	reauthorize: string;
	disconnect: string;
};

export type GoogleConnectionStatus = {
	connected: boolean;
	email: string | null;
	grantedScopes: string[];
	requiredScopes: string[];
	missingScopes: string[];
	needsReauthorize: boolean;
	disconnectReason: string | null;
	urls: GoogleConnectionUrls;
};

export type FetchStatusOptions = {
	/**
	 * Endpoint URL that returns the JSON status payload. Defaults to
	 * `/google/auth/status`; override when the route prefix has been
	 * changed via `google.routes.prefix`.
	 */
	statusUrl?: string;

	/**
	 * `fetch`-compatible init merged onto the request. Set `credentials`
	 * or headers as needed for your app.
	 */
	init?: RequestInit;
};

const defaultInit: RequestInit = {
	credentials: 'same-origin',
	headers: {
		Accept: 'application/json',
	},
};

/**
 * Read the current user's Google connection state.
 *
 * @since 1.0.0
 */
export async function fetchGoogleConnectionStatus(
	options: FetchStatusOptions = {},
): Promise<GoogleConnectionStatus> {
	const url = options.statusUrl ?? '/google/auth/status';

	const response = await fetch( url, mergeInit( defaultInit, options.init ) );

	if ( ! response.ok ) {
		throw new GoogleConnectionApiError(
			`Failed to fetch Google connection status: ${ response.status }`,
			response.status,
		);
	}

	return ( await response.json() ) as GoogleConnectionStatus;
}

export type DisconnectOptions = {
	/**
	 * The `/disconnect` URL. Prefer reading it from the status payload's
	 * `urls.disconnect`, but overridable for tests.
	 */
	disconnectUrl?: string;

	/**
	 * CSRF token to send as `X-CSRF-TOKEN`. Laravel apps typically expose
	 * this via a `<meta name="csrf-token">` tag; components read it there
	 * by default when no explicit value is passed.
	 */
	csrfToken?: string | null;

	init?: RequestInit;
};

/**
 * Ask the backend to mark the current user's connection disconnected.
 *
 * @since 1.0.0
 */
export async function disconnectGoogleConnection(
	options: DisconnectOptions = {},
): Promise<void> {
	const url = options.disconnectUrl ?? '/google/auth/disconnect';

	const token = options.csrfToken ?? readCsrfToken();

	const init: RequestInit = mergeInit( defaultInit, {
		method: 'POST',
		headers: token ? { 'X-CSRF-TOKEN': token } : {},
		...options.init,
	} );

	const response = await fetch( url, init );

	if ( ! response.ok ) {
		throw new GoogleConnectionApiError(
			`Failed to disconnect Google connection: ${ response.status }`,
			response.status,
		);
	}
}

/**
 * Error thrown by any of this module's fetch helpers.
 *
 * @since 1.0.0
 */
export class GoogleConnectionApiError extends Error {
	public readonly status: number;

	constructor( message: string, status: number ) {
		super( message );
		this.name = 'GoogleConnectionApiError';
		this.status = status;
	}
}

function readCsrfToken(): string | null {
	if ( typeof document === 'undefined' ) {
		return null;
	}

	const meta = document.querySelector<HTMLMetaElement>(
		'meta[name="csrf-token"]',
	);

	return meta?.content ?? null;
}

function mergeInit( base: RequestInit, extra?: RequestInit ): RequestInit {
	if ( ! extra ) {
		return base;
	}

	return {
		...base,
		...extra,
		headers: {
			...( base.headers as Record<string, string> | undefined ),
			...( extra.headers as Record<string, string> | undefined ),
		},
	};
}
