<!--
	Vue connection-management component.

	Mirrors the React component in `../react/` and the Livewire component in
	`resources/views/livewire/`. Scope-limited to connect / disconnect /
	status — no per-service enable/disable toggles.

	@since 1.0.0
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

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

type Props = {
	statusUrl?: string;
	csrfToken?: string | null;
	labels?: Partial<GoogleConnectionLabels>;
};

const props = defineProps<Props>();

const emit = defineEmits<{
	( event: 'disconnected' ): void;
	( event: 'statusChanged', status: GoogleConnectionStatus ): void;
}>();

type LoadState =
	| { kind: 'loading' }
	| { kind: 'error'; message: string }
	| { kind: 'loaded'; status: GoogleConnectionStatus };

const state = ref<LoadState>( { kind: 'loading' } );
const isDisconnecting = ref( false );

const labels = computed( () => mergeGoogleConnectionLabels( props.labels ) );

async function load(): Promise<void> {
	state.value = { kind: 'loading' };
	try {
		const status = await fetchGoogleConnectionStatus( {
			statusUrl: props.statusUrl,
		} );
		state.value = { kind: 'loaded', status };
		emit( 'statusChanged', status );
	} catch ( error ) {
		state.value = {
			kind: 'error',
			message:
				error instanceof GoogleConnectionApiError
					? error.message
					: labels.value.errorFallback,
		};
	}
}

async function handleDisconnect(): Promise<void> {
	if ( state.value.kind !== 'loaded' ) {
		return;
	}

	isDisconnecting.value = true;
	try {
		await disconnectGoogleConnection( {
			disconnectUrl: state.value.status.urls.disconnect,
			csrfToken: props.csrfToken ?? undefined,
		} );
		emit( 'disconnected' );
		await load();
	} catch {
		await load();
	} finally {
		isDisconnecting.value = false;
	}
}

const status = computed( () =>
	state.value.kind === 'loaded' ? state.value.status : null,
);

const requiredScopeLabel = computed( () =>
	status.value
		? labels.value.requiredScopesTitle( status.value.requiredScopes.length )
		: '',
);

const grantedScopeLabel = computed( () =>
	status.value ? labels.value.grantedScopesTitle( status.value.grantedScopes.length ) : '',
);

const reauthorizeMessage = computed( () =>
	status.value ? labels.value.needsReauthorize( status.value.missingScopes.length ) : '',
);

onMounted( () => {
	void load();
} );
</script>

<template>
	<div class="google-connection-manager" :aria-busy="state.kind === 'loading' || undefined">
		<template v-if="state.kind === 'loading'">
			<p>{{ labels.loading }}</p>
		</template>

		<template v-else-if="state.kind === 'error'">
			<p class="google-connection-manager__flash google-connection-manager__flash--error">
				{{ state.message }}
			</p>
			<button type="button" @click="load">{{ labels.retry }}</button>
		</template>

		<template v-else-if="status && ! status.connected">
			<div class="google-connection-manager__status">
				<p>{{ labels.noAccountConnected }}</p>
			</div>
			<div class="google-connection-manager__actions">
				<a
					:href="status.urls.connect"
					class="google-connection-manager__button google-connection-manager__button--primary"
				>
					{{ labels.connectButton }}
				</a>
			</div>
			<details v-if="status.requiredScopes.length" class="google-connection-manager__scopes">
				<summary>{{ requiredScopeLabel }}</summary>
				<ul>
					<li v-for="scope in status.requiredScopes" :key="scope">{{ scope }}</li>
				</ul>
			</details>
		</template>

		<template v-else-if="status">
			<div class="google-connection-manager__status">
				<p class="google-connection-manager__label">{{ labels.connectedAs }}</p>
				<p class="google-connection-manager__email">
					{{ status.email ?? labels.googleAccountFallback }}
				</p>
			</div>

			<div
				v-if="status.needsReauthorize"
				class="google-connection-manager__notice"
			>
				<p>{{ reauthorizeMessage }}</p>
				<a
					:href="status.urls.reauthorize"
					class="google-connection-manager__button google-connection-manager__button--primary"
				>
					{{ labels.reauthorizeButton }}
				</a>
			</div>

			<div class="google-connection-manager__actions">
				<a
					:href="status.urls.reauthorize"
					class="google-connection-manager__button"
				>
					{{ labels.reauthorizeButton }}
				</a>
				<button
					type="button"
					class="google-connection-manager__button google-connection-manager__button--danger"
					:disabled="isDisconnecting"
					@click="handleDisconnect"
				>
					{{ isDisconnecting ? labels.disconnectingButton : labels.disconnectButton }}
				</button>
			</div>

			<details v-if="status.grantedScopes.length" class="google-connection-manager__scopes">
				<summary>{{ grantedScopeLabel }}</summary>
				<ul>
					<li v-for="scope in status.grantedScopes" :key="scope">{{ scope }}</li>
				</ul>
			</details>
		</template>
	</div>
</template>
