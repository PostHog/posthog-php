# Contributing

Thanks for your interest in improving the PostHog PHP SDK.

## Development setup

1. Install [PHP](https://www.php.net/manual/en/install.php) and [Composer](https://getcomposer.org/download/).
2. Install dependencies:

   ```bash
   php composer.phar update
   ```

3. Run the test suite:

   ```bash
   bin/test
   ```

   This script runs `./vendor/bin/phpunit --verbose test`.

## Pull requests

Please follow the existing project conventions and include tests when you change behavior.
