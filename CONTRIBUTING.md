# Contributing

Thanks for your interest in improving the PostHog PHP SDK.

## Development setup

1. Install [PHP](https://www.php.net/manual/en/install.php) and [Composer](https://getcomposer.org/download/).
2. Install dependencies using the same command CI uses:

   ```bash
   composer install --prefer-dist --no-progress
   ```

## CI-aligned checks

Run the test command used in CI:

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit.xml --coverage-text
```

CI also runs PHP_CodeSniffer with `phpcs.xml`. You can run an equivalent local check with:

```bash
curl -OL https://squizlabs.github.io/PHP_CodeSniffer/phpcs.phar
php phpcs.phar --standard=phpcs.xml --extensions=php .
```

## Pull requests

Please follow the existing project conventions and include tests when you change behavior.
