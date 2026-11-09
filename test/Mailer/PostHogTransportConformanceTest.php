<?php

namespace PostHog\Test\Mailer;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Mailer\PostHogTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\RemoteTemplateEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * The adapter contract's conformance cases, sent through the real Emails sender with HTTP stubbed.
 * Case 06 doesn't apply: Symfony Mailer has no name of its own for an email.
 */
final class PostHogTransportConformanceTest extends TestCase
{
    use SendsThroughStubbedHttp;

    private const TEMPLATE_ID = '0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e';
    private const RENDERED = [
        'to' => 'ana@example.com',
        'from' => 'Shop <shop@example.com>',
        'subject' => 'Your order shipped',
        'html' => '<p>On its way.</p>',
        'text' => 'On its way.',
    ];

    private static function shipped(): Email
    {
        return (new Email())
            ->from(new Address('shop@example.com', 'Shop'))
            ->to(new Address('ana@example.com', 'Ana'))
            ->subject('Your order shipped')
            ->html('<p>On its way.</p>')
            ->text('On its way.');
    }

    public static function sends(): array
    {
        return [
            '01 rendered minimal' => [
                fn() => self::shipped(),
                self::RENDERED + ['template_key' => 'posthog-php-mailer'],
                [],
            ],
            '02 display names' => [
                fn() => self::shipped()->cc(new Address('bo@example.com', 'Bo'))
                    ->replyTo(new Address('help@example.com', 'Help')),
                self::RENDERED + [
                    'cc' => ['bo@example.com'],
                    'reply_to' => ['help@example.com'],
                    'template_key' => 'posthog-php-mailer',
                ],
                [],
            ],
            '03 cc, bcc and reply-to' => [
                fn() => self::shipped()->cc('bo@example.com')->bcc('audit@example.com')
                    ->replyTo('a@example.com', 'b@example.com'),
                self::RENDERED + [
                    'cc' => ['bo@example.com'],
                    'bcc' => ['audit@example.com'],
                    'reply_to' => ['a@example.com', 'b@example.com'],
                    'template_key' => 'posthog-php-mailer',
                ],
                [],
            ],
            '04 attachments and inline' => [
                fn() => self::shipped()->html('<img src="cid:logo">')
                    ->addPart(new DataPart(base64_decode('JVBERi0xLjQK'), 'invoice.pdf', 'application/pdf'))
                    ->addPart((new DataPart(base64_decode('iVBORw0KGgo='), 'logo.png', 'image/png'))
                        ->asInline()->setName('logo')),
                ['html' => '<img src="cid:logo">'] + self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [
                        ['filename' => 'invoice.pdf', 'content' => 'JVBERi0xLjQK', 'content_type' => 'application/pdf'],
                        [
                            'filename' => 'logo.png',
                            'content' => 'iVBORw0KGgo=',
                            'content_type' => 'image/png',
                            'content_id' => 'logo',
                        ],
                    ],
                ],
                [],
            ],
            '05 PostHog options' => [
                fn() => self::withHeaders(self::shipped(), [
                    'X-PostHog-Distinct-Id' => 'user_42',
                    'X-PostHog-Template-Key' => 'order-shipped',
                    'X-PostHog-Idempotency-Key' => 'order-981-shipped',
                    'X-PostHog-Capture-Engagement' => 'false',
                ]),
                self::RENDERED + [
                    'template_key' => 'order-shipped',
                    'distinct_id' => 'user_42',
                    'capture_engagement' => false,
                ],
                ['Idempotency-Key' => 'order-981-shipped'],
            ],
            'a sender name with a comma, quoted' => [
                fn() => self::shipped()->from(new Address('shop@example.com', 'Shop, Inc')),
                ['from' => '"Shop, Inc" <shop@example.com>'] + self::RENDERED
                    + ['template_key' => 'posthog-php-mailer'],
                [],
            ],
            'an inline part named in the HTML' => [
                fn() => self::shipped()->html('<img src="cid:logo">')
                    ->embed(base64_decode('iVBORw0KGgo='), 'logo', 'image/png'),
                ['html' => '<img src="cid:logo">'] + self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [[
                        'filename' => 'logo',
                        'content' => 'iVBORw0KGgo=',
                        'content_type' => 'image/png',
                        'content_id' => 'logo',
                    ]],
                ],
                [],
            ],
            'an attached part the HTML refers to sends inline' => [
                fn() => self::shipped()->html('<img src="cid:logo.png">')
                    ->attach(base64_decode('iVBORw0KGgo='), 'logo.png', 'image/png'),
                ['html' => '<img src="cid:logo.png">'] + self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [self::logo('logo.png', 'logo.png')],
                ],
                [],
            ],
            'an inline part the HTML refers to by its content ID' => [
                fn() => self::shipped()->html('<img src="cid:logo@shop.example">')->addPart(
                    (new DataPart(base64_decode('iVBORw0KGgo='), 'logo.png', 'image/png'))
                        ->asInline()->setContentId('logo@shop.example')
                ),
                ['html' => '<img src="cid:logo@shop.example">'] + self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [self::logo('logo.png', 'logo@shop.example')],
                ],
                [],
            ],
            'a name that only starts the content ID the HTML refers to' => [
                fn() => self::shipped()->html('<img src="cid:logo\'v2@shop.example">')->addPart(
                    (new DataPart(base64_decode('iVBORw0KGgo='), 'logo.png', 'image/png'))
                        ->asInline()->setName('logo')->setContentId('logo\'v2@shop.example')
                ),
                ['html' => '<img src="cid:logo\'v2@shop.example">'] + self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [self::logo('logo.png', 'logo\'v2@shop.example')],
                ],
                [],
            ],
            'a part the HTML refers to from CSS' => [
                fn() => self::shipped()->html('<div style="background-image: url(\'cid:logo\')"></div>')
                    ->embed(base64_decode('iVBORw0KGgo='), 'logo', 'image/png'),
                ['html' => '<div style="background-image: url(\'cid:logo\')"></div>'] + self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [self::logo('logo', 'logo')],
                ],
                [],
            ],
            'an inline part with its own content ID the HTML does not refer to' => [
                fn() => self::shipped()->addPart(
                    (new DataPart(base64_decode('iVBORw0KGgo='), 'logo.png', 'image/png'))
                        ->asInline()->setContentId('logo@shop.example')
                ),
                self::RENDERED + [
                    'template_key' => 'posthog-php-mailer',
                    'attachments' => [self::logo('logo.png', 'logo@shop.example')],
                ],
                [],
            ],
            '14 X-Track leaves engagement capture to its own header' => [
                fn() => self::withHeaders(self::shipped(), [
                    'X-PostHog-Capture-Engagement' => 'false',
                    'X-Track' => 'opens=true; clicks=true',
                ]),
                self::RENDERED + ['template_key' => 'posthog-php-mailer', 'capture_engagement' => false],
                [],
            ],
            '14 X-Track with clicks off, which PostHog never tracks' => [
                fn() => self::withHeaders(self::shipped(), ['X-Track' => 'opens=true; clicks=false']),
                self::RENDERED + ['template_key' => 'posthog-php-mailer'],
                [],
            ],
        ];
    }

    /**
     * @param Closure(): Email $email
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    #[DataProvider('sends')]
    public function testAnEmailSendsOneRequest(Closure $email, array $body, array $headers): void
    {
        $sent = $this->transport()->send($email());

        $this->assertCount(1, $this->requests);
        $this->assertEquals($body + ['capture_engagement' => true], $this->body());
        $this->assertMatchesRegularExpression(
            '#^posthog-php-mailer/[0-9A-Za-z.+-]+$#',
            $this->header('X-PostHog-Integration')
        );
        foreach ($headers as $name => $value) {
            $this->assertSame($value, $this->header($name));
        }
        $this->assertSame('01a18356-c56c-0001-e494-7666bc915188', $sent->getMessageId());
    }

    public function testAPartSymfonyGaveAContentIdSendsUnderTheNameTheHtmlRefersTo(): void
    {
        $email = self::shipped()->html('<img src="cid:logo">')
            ->embed(base64_decode('iVBORw0KGgo='), 'logo', 'image/png');
        $email->toString();
        $this->assertTrue($email->getAttachments()[0]->hasContentId(), 'Rendering gave the part a content ID.');
        if ($email->getAttachments()[0]->getName() !== 'logo') {
            $this->markTestSkipped('Symfony Mime before 6.4.37 and 7.4.9 renames the part when the app renders the email.');
        }

        $this->transport()->send($email);

        $this->assertSame('<img src="cid:logo">', $this->body()['html']);
        $this->assertSame('logo', $this->body()['attachments'][0]['content_id']);
    }

    public function testADistinctIdThatIsNotAsciiReachesPostHogUnchanged(): void
    {
        $this->transport()->send(self::withHeaders(self::shipped(), ['X-PostHog-Distinct-Id' => '用户42']));

        $this->assertSame('用户42', $this->body()['distinct_id']);
    }

    public function testAMultipartEmailWithAnAttachmentAndAnInlineImageSends(): void
    {
        $email = self::shipped()->html('<p>On its way.</p><img src="cid:logo">')
            ->attach(base64_decode('JVBERi0xLjQK'), 'invoice.pdf', 'application/pdf')
            ->embed(base64_decode('iVBORw0KGgo='), 'logo', 'image/png');

        $this->transport()->send($email);

        $this->assertSame(['invoice.pdf', 'logo'], array_column($this->body()['attachments'], 'filename'));
        $this->assertSame('logo', $this->body()['attachments'][1]['content_id']);
        $this->assertArrayNotHasKey('content_id', $this->body()['attachments'][0]);
    }

    public function testACustomBodyThatHoldsTheEmailsOwnPartsSends(): void
    {
        $email = self::shipped()->attach(base64_decode('JVBERi0xLjQK'), 'invoice.pdf', 'application/pdf');
        $email->setBody(new MixedPart(
            new AlternativePart(new TextPart('On its way.'), new TextPart('<p>On its way.</p>', 'utf-8', 'html')),
            $email->getAttachments()[0]
        ));

        $this->transport()->send($email);

        $this->assertSame('<p>On its way.</p>', $this->body()['html']);
        $this->assertSame('On its way.', $this->body()['text']);
        $this->assertSame(['invoice.pdf'], array_column($this->body()['attachments'], 'filename'));
    }

    public function testABodyFromAStreamThatCannotRewindSendsInFull(): void
    {
        $html = self::streamThatCannotRewind('<p>On its way.</p><img src="cid:logo">');
        $text = self::streamThatCannotRewind('On its way.');
        $email = self::shipped()->html($html)->text($text)
            ->embed(base64_decode('iVBORw0KGgo='), 'logo', 'image/png');

        $this->transport()->send($email);

        $this->assertSame('<p>On its way.</p><img src="cid:logo">', $this->body()['html']);
        $this->assertSame('On its way.', $this->body()['text']);
        $this->assertSame('logo', $this->body()['attachments'][0]['content_id']);
        $this->assertSame($html, $email->getHtmlBody(), 'The app\'s own email keeps its body.');
    }

    /**
     * @return resource
     */
    private static function streamThatCannotRewind(string $content)
    {
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($write, $content);
        fclose($write);
        self::assertFalse(stream_get_meta_data($read)['seekable']);

        return $read;
    }

    public function testTheReservedHeadersNeverGoOut(): void
    {
        $this->transport()->send(self::withHeaders(self::shipped(), [
            'X-PostHog-Distinct-Id' => 'user_42',
            'X-PostHog-Template-Key' => 'order-shipped',
            'X-PostHog-Idempotency-Key' => 'order-981-shipped',
            'X-PostHog-Capture-Engagement' => 'true',
        ]));

        $postHogHeaders = array_values(array_filter(
            array_map(static fn(string $line): string => explode(':', $line, 2)[0], $this->requests[0]['headers']),
            static fn(string $name): bool => stripos($name, 'X-PostHog-') === 0
        ));
        $this->assertSame(['X-PostHog-Integration'], $postHogHeaders);
        $this->assertArrayNotHasKey('headers', $this->body());
    }

    public function testCase07TheDefaultTemplateKeyComesFromConfig(): void
    {
        $this->transport(defaultTemplateKey: 'transactional')->send(self::shipped());

        $this->assertSame('transactional', $this->body()['template_key']);
    }

    public static function refusals(): array
    {
        return [
            '08 invalid template key' => [
                fn() => self::withHeaders(self::shipped(), ['X-PostHog-Template-Key' => 'order shipped!']),
                'invalid_template_key',
            ],
            '09 multiple to' => [fn() => self::shipped()->addTo('bo@example.com'), 'multiple_to'],
            '09 no to' => [fn() => self::shipped()->to()->cc('bo@example.com'), 'multiple_to'],
            '10 inline without cid' => [
                fn() => self::shipped()->addPart((new DataPart(base64_decode('iVBORw0KGgo='), 'logo.png', 'image/png'))
                    ->asInline()),
                'inline_without_cid',
            ],
            '10 inline without a name' => [
                fn() => self::shipped()->addPart((new DataPart(base64_decode('iVBORw0KGgo='), null, 'image/png'))
                    ->asInline()),
                'inline_without_cid',
            ],
            'an engagement header that is not true or false' => [
                fn() => self::withHeaders(self::shipped(), ['X-PostHog-Capture-Engagement' => 'yes']),
                'unsupported_header',
            ],
            '11 a custom header' => [
                fn() => self::withHeaders(self::shipped(), ['X-Campaign' => 'spring']),
                'unsupported_header',
            ],
            '12 tags' => [
                function () {
                    $email = self::shipped();
                    $email->getHeaders()->add(new TagHeader('receipts'));
                    return $email;
                },
                'tags',
            ],
            '12 metadata' => [
                fn() => self::withHeaders(self::shipped(), ['X-Metadata-Order' => '981']),
                'metadata',
            ],
            '14 open tracking off, even with engagement capture off' => [
                fn() => self::withHeaders(self::shipped(), [
                    'X-PostHog-Capture-Engagement' => 'false',
                    'X-Track' => 'opens=false; clicks=false',
                ]),
                'open_tracking_off',
            ],
            '14 open tracking off' => [
                fn() => self::withHeaders(self::shipped(), ['X-Track' => 'opens=false; clicks=true']),
                'open_tracking_off',
            ],
            '14 open tracking off in capitals' => [
                fn() => self::withHeaders(self::shipped(), ['X-Track' => 'Opens=FALSE']),
                'open_tracking_off',
            ],
            '14 open tracking off as 0' => [
                fn() => self::withHeaders(self::shipped(), ['X-Track' => 'opens=0']),
                'open_tracking_off',
            ],
            '14 open tracking off next to a value that is not ASCII' => [
                fn() => self::withHeaders(self::shipped(), ['X-Track' => 'note=é; opens=false']),
                'open_tracking_off',
            ],
            '14 open tracking off in a second X-Track' => [
                fn() => self::withHeaders(
                    self::withHeaders(self::shipped(), ['X-Track' => 'opens=true']),
                    ['X-Track' => 'opens=false']
                ),
                'open_tracking_off',
            ],
            'an AMP alternative set as the body' => [
                fn() => self::shipped()->setBody(new AlternativePart(
                    new TextPart('On its way.'),
                    new TextPart('<p>On its way.</p>', 'utf-8', 'html'),
                    new TextPart('<p>On its way.</p>', 'utf-8', 'x-amp-html')
                )),
                'unsupported_part',
            ],
            'a body part that is not an attachment' => [
                fn() => self::shipped()->setBody(new MixedPart(
                    new TextPart('On its way.'),
                    new DataPart(base64_decode('JVBERi0xLjQK'), 'invoice.pdf', 'application/pdf')
                )),
                'unsupported_part',
            ],
            'a second text part' => [
                fn() => self::shipped()->setBody(new MixedPart(new TextPart('On its way.'), new TextPart('Again.'))),
                'unsupported_part',
            ],
            'a text part that differs from the email\'s text' => [
                fn() => self::shipped()->setBody(new AlternativePart(
                    new TextPart('Something else.'),
                    new TextPart('<p>On its way.</p>', 'utf-8', 'html')
                )),
                'unsupported_part',
            ],
            'an HTML part when the email has no HTML body' => [
                fn() => self::shipped()->html(null)->setBody(new AlternativePart(
                    new TextPart('On its way.'),
                    new TextPart('<p>On its way.</p>', 'utf-8', 'html')
                )),
                'unsupported_part',
            ],
            'a priority other than normal' => [
                fn() => self::shipped()->priority(Email::PRIORITY_HIGH),
                'unsupported_priority',
            ],
            'two From addresses' => [fn() => self::shipped()->addFrom('ops@example.com'), 'unsupported_header'],
        ];
    }

    /**
     * @param Closure(): Email $email
     */
    #[DataProvider('refusals')]
    public function testAnEmailPostHogCannotCarryIsRefusedBeforeAnyRequest(Closure $email, string $code): void
    {
        $this->assertRefused($code, fn() => $this->transport()->send($email()));
    }

    public function testCase13AnEnvelopeThatDiffersFromTheHeadersIsRefused(): void
    {
        $envelope = new Envelope(new Address('bounce@example.com'), [new Address('ana@example.com')]);

        $this->assertRefused('envelope_mismatch', fn() => $this->transport()->send(self::shipped(), $envelope));
    }

    public function testCase15ARemoteTemplateEmailSendsTheTemplate(): void
    {
        $this->requireRemoteTemplates();
        $email = (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to(new Address('ana@example.com', 'Ana'))
            ->template(self::TEMPLATE_ID, ['order' => '981']);
        $email->getHeaders()->addTextHeader('X-PostHog-Distinct-Id', 'user_42');

        $this->transport()->send($email);

        $this->assertEquals([
            'to' => 'ana@example.com',
            'template_id' => self::TEMPLATE_ID,
            'message_data' => ['order' => '981'],
            'distinct_id' => 'user_42',
        ], $this->body());
        $this->assertMatchesRegularExpression('#^posthog-php-mailer/#', $this->header('X-PostHog-Integration'));
    }

    public function testATemplateEmailChecksButDoesNotSendTheRenderedOptions(): void
    {
        $this->requireRemoteTemplates();
        $email = self::withHeaders(
            (new RemoteTemplateEmail())->from('shop@example.com')->to('ana@example.com')->template(self::TEMPLATE_ID),
            ['X-PostHog-Template-Key' => 'order-shipped', 'X-PostHog-Capture-Engagement' => 'false']
        );

        $this->transport()->send($email);

        $this->assertEquals(
            ['to' => 'ana@example.com', 'template_id' => self::TEMPLATE_ID, 'message_data' => []],
            $this->body()
        );
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function templateRefusals(): array
    {
        return [
            'an invalid template key' => [['X-PostHog-Template-Key' => 'order shipped!'], 'invalid_template_key'],
            'an engagement header that is not true or false' => [
                ['X-PostHog-Capture-Engagement' => 'yes'],
                'unsupported_header',
            ],
            'open tracking off' => [['X-Track' => 'opens=false'], 'open_tracking_off'],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('templateRefusals')]
    public function testATemplateEmailStillRefusesABadOption(array $headers, string $code): void
    {
        $this->requireRemoteTemplates();
        $email = self::withHeaders(
            (new RemoteTemplateEmail())->from('shop@example.com')->to('ana@example.com')->template(self::TEMPLATE_ID),
            $headers
        );

        $this->assertRefused($code, fn() => $this->transport()->send($email));
    }

    public function testCase16ATemplateIsRefusedWhenTemplatesAreOff(): void
    {
        $this->requireRemoteTemplates();
        $email = (new RemoteTemplateEmail())->from('shop@example.com')->to('ana@example.com')
            ->template(self::TEMPLATE_ID);

        $this->assertRefused('template_unsupported', fn() => $this->transport(templates: false)->send($email));
    }

    public function testCase17ATemplateWithContentIsRefused(): void
    {
        $this->requireRemoteTemplates();
        $email = (new RemoteTemplateEmail())->from(new Address('shop@example.com', 'Shop'))
            ->to(new Address('ana@example.com', 'Ana'))->subject('Your order shipped')
            ->template(self::TEMPLATE_ID);

        $this->assertRefused('template_with_content', fn() => $this->transport()->send($email));
    }

    private function requireRemoteTemplates(): void
    {
        if (!class_exists(RemoteTemplateEmail::class)) {
            $this->markTestSkipped('RemoteTemplateEmail arrives in Symfony Mailer 8.2.');
        }
    }

    private function assertRefused(string $code, Closure $send): void
    {
        try {
            $send();
            $this->fail("The email was not refused with $code.");
        } catch (LogicException $refusal) {
            $this->assertStringStartsWith("PostHog cannot send this email: $code. ", $refusal->getMessage());
        }
        $this->assertSame([], $this->requests);
    }

    /**
     * @return array<string, string>
     */
    private static function logo(string $filename, string $contentId): array
    {
        return [
            'filename' => $filename,
            'content' => 'iVBORw0KGgo=',
            'content_type' => 'image/png',
            'content_id' => $contentId,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private static function withHeaders(Email $email, array $headers): Email
    {
        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }
}
