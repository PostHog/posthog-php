<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;

class JsonSnapshotTestCaseTest extends JsonSnapshotTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sdkReleaseVersions(): array
    {
        return [
            'current release' => ['4.13.2'],
            'future release' => ['5.0.0'],
        ];
    }

    #[DataProvider('sdkReleaseVersions')]
    public function testNormalizesSdkReleaseVersionsAtSemanticKeys(string $version): void
    {
        self::assertSame(
            [
                '$lib_version' => '<sdk-version>',
                'User-Agent' => 'posthog-php/<sdk-version>',
            ],
            $this->normalizeSnapshotValue([
                '$lib_version' => $version,
                'User-Agent' => 'posthog-php/' . $version,
            ])
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function userAgentHeaderNames(): array
    {
        return [
            'canonical' => ['User-Agent'],
            'lowercase' => ['user-agent'],
            'uppercase' => ['USER-AGENT'],
        ];
    }

    #[DataProvider('userAgentHeaderNames')]
    public function testNormalizesUserAgentCaseInsensitively(string $headerName): void
    {
        self::assertSame(
            [$headerName => 'posthog-php/<sdk-version>'],
            $this->normalizeSnapshotValue([$headerName => 'posthog-php/4.13.2'])
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSdkVersions(): array
    {
        return [
            'non-version' => ['not-a-version'],
            'trailing content' => ['4.13.2 extra'],
            'missing patch version' => ['4.13'],
            'empty version' => [''],
        ];
    }

    #[DataProvider('malformedSdkVersions')]
    public function testPreservesMalformedSdkVersionsAndRedactsCredentials(string $version): void
    {
        self::assertSame(
            [
                '$lib_version' => $version,
                'User-Agent' => 'posthog-php/' . $version,
                'api_key' => '<redacted>',
                'personal_api_key' => '<redacted>',
                'secret_key' => '<redacted>',
            ],
            $this->normalizeSnapshotValue([
                '$lib_version' => $version,
                'User-Agent' => 'posthog-php/' . $version,
                'api_key' => 'project-secret',
                'personal_api_key' => 'personal-secret',
                'secret_key' => 'webhook-secret',
            ])
        );
    }

    public function testPreservesSdkLikeCustomerPayloadValues(): void
    {
        $value = [
            'body' => [
                'event' => 'posthog-php/custom-value',
                'properties' => [
                    'integration' => 'posthog-php/4.13.2',
                ],
            ],
            'headers' => [
                'User-Agent' => 'custom-client/1.0',
                'X-SDK' => 'posthog-php/4.13.2',
            ],
        ];

        self::assertSame($value, $this->normalizeSnapshotValue($value));
    }
}
