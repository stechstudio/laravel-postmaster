<?php

namespace STS\Postmaster;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;
use STS\Postmaster\Concerns\HasStatusPredicates;
use STS\Postmaster\Contracts\Adapter;
use STS\Postmaster\Models\EmailMessage;

/**
 * A normalized inbound email event. Every webhook becomes one of these
 * regardless of provider; consumers see the same shape whatever the source.
 */
class EmailEvent
{
    use Dispatchable;
    use HasStatusPredicates;

    const string STATUS_ACCEPTED   = "accepted";
    const string STATUS_SENT       = "sent";
    // Terminal status for a message intercepted by sandbox delivery mode: it
    // was recorded but never handed to a provider, so no webhooks will follow.
    const string STATUS_SANDBOXED  = "sandboxed";
    // Terminal status for a send we refused on the way out — the recipient
    // is on our suppression list — when block_suppressed is enabled.
    const string STATUS_BLOCKED    = "blocked";
    // Terminal status for a send through Laravel's "log" mail driver. Local
    // dev typically has MAIL_MAILER=log, so without this every recorded row
    // would sit at STATUS_SENT forever (no webhook will ever land).
    const string STATUS_LOGGED     = "logged";
    // Terminal status for a send through Laravel's "array" mail driver
    // (what Mail::fake() and assertion-style tests use). No I/O happened.
    const string STATUS_CAPTURED   = "captured";
    const string STATUS_DEFERRED   = "deferred";
    const string STATUS_DELIVERED  = "delivered";
    const string STATUS_BOUNCED    = "bounced";
    const string STATUS_DROPPED    = "dropped";
    const string STATUS_COMPLAINED = "complained";
    const string STATUS_OPENED     = "opened";
    const string STATUS_CLICKED    = "clicked";

    const string BOUNCE_HARD  = "hard";  // permanent — safe to suppress
    const string BOUNCE_SOFT  = "soft";  // transient — retry later
    const string BOUNCE_BLOCK = "block"; // blocked by reputation/policy

    protected Adapter $adapter;

    /**
     * The persisted email record this event was correlated to, by provider
     * message id. Set by UpdateMessageFromEvent; read by app listeners via
     * the emailMessage() accessor below.
     */
    protected ?EmailMessage $emailMessage = null;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    public static function create(Adapter $adapter): ?self
    {
        return $adapter->isValid()
            ? new self($adapter)
            : null;
    }

    public function adapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * The persisted email record this event was correlated to, by provider
     * message id. Gives any listener of your own a path back to the
     * originating message — and, through its related() / recipient()
     * relations, to the app models it was sent for:
     *
     *     $event->emailMessage()?->related
     *     $event->emailMessage()?->recipient
     *
     * Null when persistence is disabled, when the event carries no message
     * id, or for a listener that runs before the package's
     * UpdateMessageFromEvent (which is registered first, so an app listener
     * runs after it by default).
     */
    public function emailMessage(): ?EmailMessage
    {
        return $this->emailMessage;
    }

    /**
     * Associate the event with its persisted record. Called by
     * UpdateMessageFromEvent after the correlation lookup.
     */
    public function setEmailMessage(EmailMessage $message): void
    {
        $this->emailMessage = $message;
    }

    public function provider(): string
    {
        return $this->adapter->provider();
    }

    /**
     * The normalized lifecycle status — one of the STATUS_* constants.
     */
    public function status(): ?string
    {
        return $this->adapter->status();
    }

    /**
     * Used by HasStatusPredicates to drive the is*() methods.
     */
    protected function currentStatus(): ?string
    {
        return $this->status();
    }

    /**
     * The targeted event class for this event's status — fired alongside the
     * umbrella EmailEvent by Provider::dispatch() so callers can listen on a
     * specific class (`EmailBounced::class`) instead of the umbrella plus a
     * predicate. Returns null for statuses with no dedicated class.
     *
     * @return class-string<EmailEvent>|null
     */
    public function specificEventClass(): ?string
    {
        return match ($this->status()) {
            self::STATUS_DELIVERED  => EmailDelivered::class,
            self::STATUS_BOUNCED    => EmailBounced::class,
            self::STATUS_COMPLAINED => EmailComplained::class,
            self::STATUS_DROPPED    => EmailDropped::class,
            self::STATUS_OPENED     => EmailOpened::class,
            self::STATUS_CLICKED    => EmailClicked::class,
            default                 => null,
        };
    }

    public function providerMessageId(): ?string
    {
        return $this->adapter->providerMessageId();
    }

    /**
     * The address this event is about.
     */
    public function toAddress(): ?string
    {
        return $this->adapter->toAddress();
    }

    /**
     * When the event happened, per the provider — or null if no usable
     * timestamp was supplied.
     */
    public function occurredAt(): ?DateTimeImmutable
    {
        return $this->adapter->occurredAt();
    }

    public function response(): mixed
    {
        return $this->adapter->response();
    }

    public function reason(): mixed
    {
        return $this->adapter->reason();
    }

    public function code(): mixed
    {
        return $this->adapter->code();
    }

    public function tags(): Collection
    {
        return $this->adapter->tags();
    }

    public function data(): Collection
    {
        return $this->adapter->data();
    }

    public function bounceType(): ?string
    {
        return $this->adapter->bounceType();
    }

    public function isPermanent(): bool
    {
        return $this->adapter->isPermanent();
    }

    /**
     * The URL clicked on a click event (null for other events, or providers
     * that don't expose one).
     */
    public function clickedUrl(): ?string
    {
        return $this->adapter->clickedUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->adapter->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider'            => $this->adapter->provider(),
            'status'              => $this->adapter->status(),
            'provider_message_id' => $this->adapter->providerMessageId(),
            'to_address'          => $this->adapter->toAddress(),
            'occurred_at'         => $this->adapter->occurredAt()?->format(DateTimeInterface::ATOM),
            'bounce_type'         => $this->adapter->bounceType(),
            'reason'              => $this->adapter->reason(),
            'response'            => $this->adapter->response(),
            'code'                => $this->adapter->code(),
            'clicked_url'         => $this->adapter->clickedUrl(),
            'tags'                => $this->adapter->tags()->toArray(),
            'data'                => $this->adapter->data()->toArray(),
        ];
    }
}
