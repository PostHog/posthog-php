<?php

namespace PostHog;

use DateTimeImmutable;
use ReflectionClass;

/**
 * The accepted email. A result means PostHog queued or scheduled the email, not that it was delivered.
 */
class SendEmailResult
{
    private string $id;
    private bool $replayed;
    /** @var list<EmailWarning> */
    private array $warnings;
    private ?DateTimeImmutable $scheduledAt = null;

    /**
     * @internal Created by Emails::send().
     * @param list<EmailWarning> $warnings
     */
    public function __construct(
        string $id,
        bool $replayed = false,
        array $warnings = [],
        ?DateTimeImmutable $scheduledAt = null
    ) {
        $this->id = $id;
        $this->replayed = $replayed;
        $this->warnings = $warnings;
        $this->scheduledAt = $scheduledAt;
    }

    /**
     * @return list<string>
     */
    public function __sleep()
    {
        if (PHP_VERSION_ID >= 80400 && (new ReflectionClass($this))->isUninitializedLazyObject($this)) {
            return self::declaredPropertyNames(new ReflectionClass($this));
        }
        $properties = (array) $this;
        $scheduledAt = "\0" . self::class . "\0scheduledAt";
        if (array_key_exists($scheduledAt, $properties) && $properties[$scheduledAt] === null) {
            unset($properties[$scheduledAt]);
        }

        return array_map(strval(...), array_keys($properties));
    }

    /**
     * @return list<string>
     */
    private static function declaredPropertyNames(ReflectionClass $class): array
    {
        $names = [];
        for (; $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $names[] = match (true) {
                    $property->isPrivate() => "\0{$class->getName()}\0{$property->getName()}",
                    $property->isProtected() => "\0*\0{$property->getName()}",
                    default => $property->getName(),
                };
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The queued email's message ID. Pass it to Emails::get() to read the email's status.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * True when PostHog had already accepted this idempotency key and returned the first send's result.
     */
    public function isReplayed(): bool
    {
        return $this->replayed;
    }

    /**
     * @return list<EmailWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * `scheduled` when PostHog confirmed the email's `scheduled_at`, even if a replay answers after the email
     * went out. `queued` when it goes out now: a send without `scheduled_at`, or one whose `scheduled_at` was at
     * most a minute ahead of the client's clock when it sent the attempt PostHog answered, a replay included.
     */
    public function getStatus(): string
    {
        return $this->scheduledAt === null ? 'queued' : 'scheduled';
    }

    /**
     * When a scheduled email goes out, in UTC, to the millisecond. Null for an email that goes out now.
     */
    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
