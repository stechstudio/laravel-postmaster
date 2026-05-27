<?php

namespace STS\Postmaster;

use Illuminate\Http\Request;
use STS\Postmaster\Exceptions\InvalidEventException;

/**
 *
 */
class Provider
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $adapterClass;

    /** @var callable */
    protected $authorizer;

    /** @var string */
    protected $onInvalid;

    /** @var array */
    protected $events = [];

    /**
     * @param               $name
     * @param               $adapterClass
     * @param callable      $authorizer
     * @param string        $onInvalid
     */
    public function __construct( $name, $adapterClass, callable $authorizer, $onInvalid = 'log' )
    {
        $this->name = $name;
        $this->adapterClass = $adapterClass;
        $this->authorizer = $authorizer;
        $this->onInvalid = $onInvalid;
    }

    /**
     * Whether the request passes this provider's configured authorizer.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function passesAuthorization( Request $request )
    {
        return (bool) call_user_func($this->authorizer, $request, $this->adapterClass);
    }

    /**
     * @param array $payload
     *
     * @return $this
     */
    public function adapt( array $payload )
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
     *
     * @param Contracts\Adapter $adapter
     *
     * @return void
     */
    protected function handleInvalid( $adapter )
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
     * @param array $payload
     *
     * @return array
     */
    protected function wrapPayload( array $payload )
    {
        return array_keys($payload) == range(0, count($payload) - 1)
            ? $payload
            : [$payload];
    }

    /**
     * @return EmailEvent[]
     */
    public function getEvents()
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
     *
     * @return $this
     */
    public function dispatch()
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