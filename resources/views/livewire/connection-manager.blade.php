<div class="google-connection-manager">
    @if ( session( 'google.status' ) )
        <div class="google-connection-manager__flash google-connection-manager__flash--{{ session( 'google.status' ) }}">
            @if ( 'connected' === session( 'google.status' ) )
                {{ __( 'Google account connected.' ) }}
            @else
                {{ __( 'Google account disconnected.' ) }}
            @endif
        </div>
    @endif

    @if ( session( 'google.error' ) )
        <div class="google-connection-manager__flash google-connection-manager__flash--error">
            {{ session( 'google.error' ) }}
        </div>
    @endif

    @if ( $state->isConnected )
        <div class="google-connection-manager__status">
            <p class="google-connection-manager__label">{{ __( 'Connected as' ) }}</p>
            <p class="google-connection-manager__email">{{ $state->email ?? __( 'Google account' ) }}</p>
        </div>

        @if ( $state->needsReauthorize )
            <div class="google-connection-manager__notice">
                <p>
                    {{ trans_choice(
                        ':count new scope requires reauthorization.|:count new scopes require reauthorization.',
                        count( $state->missingScopes ),
                        [ 'count' => count( $state->missingScopes ) ]
                    ) }}
                </p>
                <a
                    href="{{ route( 'google.auth.reauthorize' ) }}"
                    class="google-connection-manager__button google-connection-manager__button--primary"
                >
                    {{ __( 'Reauthorize' ) }}
                </a>
            </div>
        @endif

        <div class="google-connection-manager__actions">
            <a
                href="{{ route( 'google.auth.reauthorize' ) }}"
                class="google-connection-manager__button"
            >
                {{ __( 'Reauthorize' ) }}
            </a>

            <form method="POST" action="{{ route( 'google.auth.disconnect' ) }}" class="google-connection-manager__form">
                @csrf
                <button type="submit" class="google-connection-manager__button google-connection-manager__button--danger">
                    {{ __( 'Disconnect' ) }}
                </button>
            </form>
        </div>

        @if ( ! empty( $state->grantedScopes ) )
            <details class="google-connection-manager__scopes">
                <summary>{{ __( 'Granted scopes (:count)', [ 'count' => count( $state->grantedScopes ) ] ) }}</summary>
                <ul>
                    @foreach ( $state->grantedScopes as $scope )
                        <li>{{ $scope }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    @else
        <div class="google-connection-manager__status">
            <p>{{ __( 'No Google account connected.' ) }}</p>
        </div>

        <div class="google-connection-manager__actions">
            <a
                href="{{ route( 'google.auth.connect' ) }}"
                class="google-connection-manager__button google-connection-manager__button--primary"
            >
                {{ __( 'Connect Google' ) }}
            </a>
        </div>

        @if ( ! empty( $state->requiredScopes ) )
            <details class="google-connection-manager__scopes">
                <summary>{{ __( 'Scopes that will be requested (:count)', [ 'count' => count( $state->requiredScopes ) ] ) }}</summary>
                <ul>
                    @foreach ( $state->requiredScopes as $scope )
                        <li>{{ $scope }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif
</div>
