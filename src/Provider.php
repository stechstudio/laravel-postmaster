<?php

namespace STS\Postmaster;

use Illuminate\Http\Request;
use STS\Postmaster\Contracts\Adapter;
use STS\Postmaster\Exceptions\InvalidEventException;

/**
 * One configured email provider: an adapter class that turns a webhook payload
 * into an EmailEvent, plus the authorizer that gates inbound requests for that
 * provider. ProviderRegistry resolves these by name from config — there is no
 * "default" provider; the {provider} route segment always picks the one.
 */
class Provider
{
    protected string $name;

    /** @var class-string<Adapter> */
    protected string $adapterClass;

    /** @var callable */
    protected $authorizer;

    protected string $onInvalid;

    /** @var array<int, EmailEvent> */
    protected array $events = [];

    /**
     * @param class-string<Adapter> $adapterClass
     */
    public function __construct(string $name, string $adapterClass, callable $authorizer, string $onInvalid = 'log')
    {
        $this->name = $name;
        $this->adapterClass = $adapterClass;
        $this->authorizer = $authorizer;
        $this->onInvalid = $onInvalid;
    }

    /**
     * Whether the request passes this provider's configured authorizer.
     */
    public function passesAuthorization(Request $request): bool
    {
        return (bool) call_user_func($this->authorizer, $request, $this->adapterClass);
    }

    /**
     * @param array<int|string, mixed> $payload
     */
    public function adapt(array $payload): static
    {
        $class = $this->adapterClass;

        foreach ($this->wrapPayload($payload) as $data) {
            // Some providers pack per-recipient data into a single event
            // (SES's delivery.recipients[] array, for one). Give the
            // adapter a chance to fan that out into one event per recipient.
            foreach ($class::expand($data) as $expanded) {
                $adapter = new $class($expanded);
                $event = EmailEvent::create($adapter);

                if ($event) {
                    $this->events[] = $event;
                } else {
                    $this->handleInvalid($adapter);
                }
            }
        }

        return $this;
    }

    /**
     * Handle a payload that no adapter could turn into a valid event.
     */
    protected function handleInvalid(Adapter $adapter): void
    {
        if ($this->onInvalid === 'ignore') {
            return;
        }

        if ($this->onInvalid === 'throw') {
            throw new InvalidEventException($adapter->payload());
        }

        logger()->warning('Dropped invalid email event payload', [
            'provider' => $this->name,
            'payload'  => $adapter->payload(),
        ]);
    }

    /**
     * Some providers (like SendGrid) send multiple events at once. If we see that the array is
     * is NOT associative (numerically incrementally indexed) then it's already a multi-event
     * submission. Otherwise we'll wrap it, so we have a consistent array to loop through.
     *
     * @param  array<int|string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    protected function wrapPayload(array $payload): array
    {
        return array_keys($payload) == range(0, count($payload) - 1)
            ? $payload
            : [$payload];
    }

    /**
     * @return array<int, EmailEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Fire every adapted event. The umbrella EmailEvent goes out first, so
     * the package's own listeners (UpdateMessageFromEvent in particular)
     * correlate it to its persisted message before any targeted variant
     * fires. The targeted variant — EmailBounced, EmailDelivered, and the
     * rest — fires immediately after, carrying the same adapter and the
     * already-correlated EmailMessage so listeners on the specific class
     * see the same payload the umbrella listener does.
     */
    public function dispatch(): static
    {
        foreach ($this->events as $event) {
            event($event);

            if ($class = $event->specificEventClass()) {
                $specific = new $class($event->adapter());

                if ($message = $event->emailMessage()) {
                    $specific->setEmailMessage($message);
                }

                event($specific);
            }
        }

        return $this;
    }
}
