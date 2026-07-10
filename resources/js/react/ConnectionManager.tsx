/**
 * React connection-management component.
 *
 * Mirrors the Livewire component shipped in `resources/views/livewire/` and
 * the Vue component in `../vue/`. Scope-limited to connect / disconnect /
 * status — no per-service enable/disable toggles.
 *
 * @since 1.0.0
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import {
	disconnectGoogleConnection,
	fetchGoogleConnectionStatus,
	GoogleConnectionApiError,
	type GoogleConnectionStatus,
} from '../shared/api';
import {
	mergeGoogleConnectionLabels,
	type GoogleConnectionLabels,
} from '../shared/labels';

export type GoogleConnectionManagerProps = {
	/**
	 * Override the JSON status endpoint. Defaults to `/google/auth/status`.
	 */
	statusUrl?: string;

	/**
	 * Override the CSRF token. Read from the `<meta name="csrf-token">` tag
	 * when omitted.
	 */
	csrfToken?: string | null;

	/**
	 * Localized labels. Any subset — omitted keys fall back to the English
	 * defaults from `shared/labels.ts`. Typically wired from Blade with
	 * `@js([ 'connectButton' => __('Connect Google'), ... ])`.
	 */
	labels?: Partial<GoogleConnectionLabels>;

	/**
	 * Fired after a successful disconnect. Useful for triggering a route
	 * change or a global refresh.
	 */
	onDisconnected?: () => void;

	/**
	 * Fired the first time a status payload is fetched, and again after every
	 * refresh. Handy for hoisting state into a parent component.
	 */
	onStatusChanged?: ( status: GoogleConnectionStatus ) => void;

	/**
	 * Custom class name applied to the root element.
	 */
	className?: string;
};

type LoadState =
	| { kind: 'loading' }
	| { kind: 'error'; message: string }
	| { kind: 'loaded'; status: GoogleConnectionStatus };

export function GoogleConnectionManager( props: GoogleConnectionManagerProps ) {
	const [ state, setState ] = useState<LoadState>( { kind: 'loading' } );
	const [ isDisconnecting, setIsDisconnecting ] = useState( false );

	const { statusUrl, csrfToken, labels: labelOverrides, className } = props;

	// Callback identity changes on every parent render for inline arrow
	// functions. Stash the current values in refs so `load` and
	// `handleDisconnect` can invoke the latest callback without listing it as
	// a dep — otherwise a parent that hoists status into its own state via
	// `onStatusChanged` would trigger a fresh fetch on every render (and, if
	// that fetch updated the parent, an infinite loop).
	const onStatusChangedRef = useRef( props.onStatusChanged );
	const onDisconnectedRef  = useRef( props.onDisconnected );
	useEffect( () => {
		onStatusChangedRef.current = props.onStatusChanged;
	}, [ props.onStatusChanged ] );
	useEffect( () => {
		onDisconnectedRef.current = props.onDisconnected;
	}, [ props.onDisconnected ] );

	const labels = mergeGoogleConnectionLabels( labelOverrides );

	const load = useCallback( async () => {
		setState( { kind: 'loading' } );
		try {
			const status = await fetchGoogleConnectionStatus( { statusUrl } );
			setState( { kind: 'loaded', status } );
			onStatusChangedRef.current?.( status );
		} catch ( error ) {
			setState( {
				kind: 'error',
				message:
					error instanceof GoogleConnectionApiError
						? error.message
						: labels.errorFallback,
			} );
		}
	}, [ statusUrl, labels.errorFallback ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	const status = state.kind === 'loaded' ? state.status : null;

	const handleDisconnect = useCallback( async () => {
		if ( ! status ) {
			return;
		}

		setIsDisconnecting( true );
		try {
			await disconnectGoogleConnection( {
				disconnectUrl: status.urls.disconnect,
				csrfToken,
			} );
			onDisconnectedRef.current?.();
			await load();
		} catch {
			// Force a refresh so the UI reflects whatever the server actually did.
			await load();
		} finally {
			setIsDisconnecting( false );
		}
	}, [ status, csrfToken, load ] );

	const rootClassName = [ 'google-connection-manager', className ]
		.filter( Boolean )
		.join( ' ' );

	if ( state.kind === 'loading' ) {
		return (
			<div className={ rootClassName } aria-busy="true">
				<p>{ labels.loading }</p>
			</div>
		);
	}

	if ( state.kind === 'error' ) {
		return (
			<div className={ rootClassName }>
				<p className="google-connection-manager__flash google-connection-manager__flash--error">
					{ state.message }
				</p>
				<button type="button" onClick={ () => void load() }>
					{ labels.retry }
				</button>
			</div>
		);
	}

	const { status: current } = state;

	if ( ! current.connected ) {
		return (
			<div className={ rootClassName }>
				<div className="google-connection-manager__status">
					<p>{ labels.noAccountConnected }</p>
				</div>
				<div className="google-connection-manager__actions">
					<a
						href={ current.urls.connect }
						className="google-connection-manager__button google-connection-manager__button--primary"
					>
						{ labels.connectButton }
					</a>
				</div>
				<ScopeList
					label={ labels.requiredScopesTitle( current.requiredScopes.length ) }
					scopes={ current.requiredScopes }
				/>
			</div>
		);
	}

	return (
		<div className={ rootClassName }>
			<div className="google-connection-manager__status">
				<p className="google-connection-manager__label">{ labels.connectedAs }</p>
				<p className="google-connection-manager__email">
					{ current.email ?? labels.googleAccountFallback }
				</p>
			</div>

			{ current.needsReauthorize && (
				<div className="google-connection-manager__notice">
					<p>{ labels.needsReauthorize( current.missingScopes.length ) }</p>
					<a
						href={ current.urls.reauthorize }
						className="google-connection-manager__button google-connection-manager__button--primary"
					>
						{ labels.reauthorizeButton }
					</a>
				</div>
			) }

			<div className="google-connection-manager__actions">
				<a
					href={ current.urls.reauthorize }
					className="google-connection-manager__button"
				>
					{ labels.reauthorizeButton }
				</a>
				<button
					type="button"
					className="google-connection-manager__button google-connection-manager__button--danger"
					onClick={ () => void handleDisconnect() }
					disabled={ isDisconnecting }
				>
					{ isDisconnecting ? labels.disconnectingButton : labels.disconnectButton }
				</button>
			</div>

			<ScopeList
				label={ labels.grantedScopesTitle( current.grantedScopes.length ) }
				scopes={ current.grantedScopes }
			/>
		</div>
	);
}

function ScopeList( { label, scopes }: { label: string; scopes: string[] } ) {
	if ( scopes.length === 0 ) {
		return null;
	}

	return (
		<details className="google-connection-manager__scopes">
			<summary>{ label }</summary>
			<ul>
				{ scopes.map( ( scope ) => (
					<li key={ scope }>{ scope }</li>
				) ) }
			</ul>
		</details>
	);
}
