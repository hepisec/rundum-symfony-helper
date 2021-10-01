<?php

namespace Rundum\SymfonyHelperBundle\Service;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EventService {

    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher) {
        $this->dispatcher = $dispatcher;
    }

    public function dispatchAll(array $events) {
        foreach ($events as $event) {
            if ($this->dispatcher->dispatch($event, $event::NAME)->isPropagationStopped()) {
                return false;
            }
        }

        return true;
    }

}
