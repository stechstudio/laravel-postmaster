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
        foreach ($this->wrapPayload($payload) AS $data) {
            $adapter = new $this->adapterClass($data);
            $event = EmailEvent::create($adapter);

            if ($event) {
                $this->events[] = $event;
            } else {
                $this->handleInvalid($adapter);
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
            throw new InvalidEventException($adapter->getPayload());
        }

        logger()->warning('Dropped invalid email event payload', [
            'provider' => $this->name,
            'payload'  => $adapter->getPayload(),
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
     * @return $this
     */
    public function dispatch()
    {
        foreach ($this->events AS $event) {
            event($event);
        }

        return $this;
    }
}