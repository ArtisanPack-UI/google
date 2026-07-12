---
title: Connection UI
---

# Connection UI

The package ships three interchangeable connection-management components — one Livewire, one React, one Vue — all backed by the same routes and JSON status endpoint. Each shows:

- A **Connect Google** button when the user is disconnected.
- **Connected as {email}**, plus **Disconnect** and **Reauthorize** actions, when connected.
- A prompt to reauthorize when the [scope registry](Scopes) grows.
- The scopes that will be requested (or that have been granted) in a collapsible details block.

Scope-limited to connect / disconnect / status by design — no per-service enable/disable toggles.

Sub-pages:

- [Livewire component](Connection-UI-Livewire)
- [React component](Connection-UI-React)
- [Vue component](Connection-UI-Vue)
- [Building your own](Connection-UI-Custom)

## Data source

Every component consumes the same shape — the JSON payload returned by `GET /google/auth/status`. `GoogleAuthController::status()` builds it from a `ConnectionState`:

```json
{
    "connected": true,
    "email": "you@example.com",
    "grantedScopes": [
        "openid",
        "https://www.googleapis.com/auth/userinfo.email",
        "https://www.googleapis.com/auth/analytics.readonly"
    ],
    "requiredScopes": [
        "openid",
        "https://www.googleapis.com/auth/userinfo.email",
        "https://www.googleapis.com/auth/analytics.readonly",
        "https://www.googleapis.com/auth/webmasters.readonly"
    ],
    "missingScopes": [
        "https://www.googleapis.com/auth/webmasters.readonly"
    ],
    "needsReauthorize": true,
    "disconnectReason": null,
    "urls": {
        "connect": "https://your-app.test/google/auth/connect",
        "reauthorize": "https://your-app.test/google/auth/reauthorize",
        "disconnect": "https://your-app.test/google/auth/disconnect"
    }
}
```

Disconnected state:

```json
{
    "connected": false,
    "email": null,
    "grantedScopes": [],
    "requiredScopes": [ "..." ],
    "missingScopes": [ "..." ],
    "needsReauthorize": false,
    "disconnectReason": "Refresh token revoked or expired.",
    "urls": { "..." }
}
```

Unauthenticated calls get `401` with `{ "message": "Unauthenticated." }`.

## Choosing a component

| Framework | Ship with | Best fit |
|---|---|---|
| [Livewire](Connection-UI-Livewire) | Package (auto-registered when Livewire is installed) | Blade-first apps. Server-side rendering, no bundler config needed. |
| [React](Connection-UI-React) | Package (`resources/js/react/`) | React SPAs and hybrid Blade + Inertia + React apps. |
| [Vue](Connection-UI-Vue) | Package (`resources/js/vue/`) | Vue SPAs and hybrid Blade + Inertia + Vue apps. |
| [Custom](Connection-UI-Custom) | Your code | Any other framework — fetch `/google/auth/status` yourself. |

The Livewire component renders server-side and can be dropped into any Blade view without touching a bundler. The React and Vue components need to be imported through your bundler (or published into your app's tree via `vendor:publish --tag=google-js`).

## Labels and i18n

The Livewire surface runs strings through Laravel's `__()` and `trans_choice()`. The React and Vue components can't call those directly, so they accept a `labels` prop with the same set of strings translated by the host application.

See [React → Labels](Connection-UI-React#labels) and [Vue → Labels](Connection-UI-Vue#labels) for the label map, and `resources/js/shared/labels.ts` for the default English fallbacks.

---
Continue to [API Reference](API-Reference) →
