# ArtisanPack UI Google

Shared Google OAuth2 authentication, token storage/refresh, and scope management that powers ArtisanPack UI's Google service integrations. Provider packages like [`artisanpack-ui/analytics-google`](https://github.com/ArtisanPack-UI/analytics-google), [`artisanpack-ui/google-search-console`](https://github.com/ArtisanPack-UI/google-search-console), and [`artisanpack-ui/google-tag-manager`](https://github.com/ArtisanPack-UI/google-tag-manager) sit on top of this package.

## Installation

Install the package via Composer:

```bash
composer require artisanpack-ui/google
```

The service provider and `Google` facade are auto-discovered by Laravel.

## Usage

Resolve the Google service from the container using the `google()` helper or the `Google` facade:

```php
use ArtisanPackUI\Google\Facades\Google;

$google = google();
// or
$google = Google::getFacadeRoot();
```

Package methods for the OAuth2 flow, token storage/refresh, and scope management will be documented here as they ship.

## Contributing

Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.
