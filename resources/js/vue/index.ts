/**
 * Vue entry point for the Google connection-management component.
 *
 * @since 1.0.0
 */

export { default as GoogleConnectionManager } from './ConnectionManager.vue';
export {
	fetchGoogleConnectionStatus,
	disconnectGoogleConnection,
	GoogleConnectionApiError,
	type GoogleConnectionStatus,
	type GoogleConnectionUrls,
} from '../shared/api';
export {
	defaultGoogleConnectionLabels,
	mergeGoogleConnectionLabels,
	type GoogleConnectionLabels,
} from '../shared/labels';
