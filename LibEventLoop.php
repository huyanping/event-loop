<?php

namespace React\EventLoop;

use Event;
use EventBase;
use React\EventLoop\Tick\NextTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;
use SplObjectStorage;

/**
 * An ext-libevent based event-loop.
 */
class LibEventLoop implements LoopInterface
{
    const MICROSECONDS_PER_SECOND = 1000000;

    private $eventBase;
    private $nextTickQueue;
    private $timerCallback;
    private $timerEvents;
    private $streamCallback;
    private $streamEvents = [];
    private $streamFlags = [];
    private $readListeners = [];
    private $writeListeners = [];
    private $running;

    public function __construct()
    {
        $this->eventBase = event_base_new();
        $this->nextTickQueue = new NextTickQueue($this);
        $this->timerEvents = new SplObjectStorage;

        $this->createTimerCallback();
        $this->createStreamCallback();
    }

    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, $listener)
    {
        $key = (int) $stream;

        if (!isset($this->readListeners[$key])) {
            $this->readListeners[$key] = $listener;
            $this->subscribeStreamEvent($stream, EV_READ);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, $listener)
    {
        $key = (int) $stream;

        if (!isset($this->writeListeners[$key])) {
            $this->writeListeners[$key] = $listener;
            $this->subscribeStreamEvent($stream, EV_WRITE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->readListeners[$key])) {
            unset($this->readListeners[$key]);
            $this->unsubscribeStreamEvent($stream, EV_READ);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->writeListeners[$key])) {
            unset($this->writeListeners[$key]);
            $this->unsubscribeStreamEvent($stream, EV_WRITE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->streamEvents[$key])) {

            $event = $this->streamEvents[$key];
            event_del($event);
            event_free($event);

            unset(
                $this->streamFlags[$key],
                $this->streamEvents[$key],
                $this->readListeners[$key],
                $this->writeListeners[$key]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, $callback)
    {
        $timer = new Timer($this, $interval, $callback, false);

        $this->scheduleTimer($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);

        $this->scheduleTimer($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        if ($this->isTimerActive($timer)) {
            $event = $this->timerEvents[$timer];

            event_del($event);
            event_free($event);

            $this->timerEvents->detach($timer);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timerEvents->contains($timer);
    }

    /**
     * {@inheritdoc}
     */
    public function nextTick(callable $listener)
    {
        $this->nextTickQueue->add($listener);
    }

    /**
     * {@inheritdoc}
     */
    public function tick()
    {
        $this->nextTickQueue->tick();

        event_base_loop($this->eventBase, EVLOOP_ONCE | EVLOOP_NONBLOCK);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->running = true;

        while ($this->running) {

            $this->nextTickQueue->tick();

            if (!$this->streamEvents && !$this->timerEvents->count()) {
                break;
            }

            event_base_loop($this->eventBase, EVLOOP_ONCE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->running = false;
    }

    /**
     * Schedule a timer for execution.
     *
     * @param TimerInterface $timer
     */
    protected function scheduleTimer(TimerInterface $timer)
    {
        $this->timerEvents[$timer] = $event = event_timer_new();

        event_timer_set($event, $this->timerCallback, $timer);

        event_base_set($event, $this->eventBase);

        event_add(
            $event,
            $timer->getInterval() * self::MICROSECONDS_PER_SECOND
        );
    }

    /**
     * Create a new ext-libevent event resource, or update the existing one.
     *
     * @param stream  $stream
     * @param integer $flag   EV_READ or EV_WRITE
     */
    protected function subscribeStreamEvent($stream, $flag)
    {
        $key = (int) $stream;

        if (isset($this->streamEvents[$key])) {
            $event = $this->streamEvents[$key];

            event_del($event);

            event_set(
                $event,
                $stream,
                EV_PERSIST | ($this->streamFlags[$key] |= $flag),
                $this->streamCallback
            );
        } else {
            $this->streamEvents[$key] = $event = event_new();

            event_set(
                $event,
                $stream,
                EV_PERSIST | ($this->streamFlags[$key] = $flag),
                $this->streamCallback
            );

            event_base_set($event, $this->eventBase);
        }

        event_add($event);
    }

    /**
     * Update the ext-libevent event resource for this stream to stop listening to
     * the given event type, or remove it entirely if it's no longer needed.
     *
     * @param stream  $stream
     * @param integer $flag   EV_READ or EV_WRITE
     */
    protected function unsubscribeStreamEvent($stream, $flag)
    {
        $key = (int) $stream;

        $flags = $this->streamFlags[$key] &= ~$flag;

        if (0 === $flags) {
            $this->removeStream($stream);

            return;
        }

        $event = $this->streamEvents[$key];

        event_del($event);

        event_set($event, $stream, EV_PERSIST | $flags, $this->streamCallback);

        event_add($event);
    }

    /**
     * Create a callback used as the target of timer events.
     *
     * A reference is kept to the callback for the lifetime of the loop
     * to prevent "Cannot destroy active lambda function" fatal error from
     * the event extension.
     */
    protected function createTimerCallback()
    {
        $this->timerCallback = function ($_, $_, $timer) {

            call_user_func($timer->getCallback(), $timer);

            // Timer already cancelled ...
            if (!$this->isTimerActive($timer)) {
                return;

            // Reschedule periodic timers ...
            } elseif ($timer->isPeriodic()) {
                event_add(
                    $this->timerEvents[$timer],
                    $timer->getInterval() * self::MICROSECONDS_PER_SECOND
                );

            // Clean-up one shot timers ...
            } else {
                $this->cancelTimer($timer);
            }

        };
    }

    /**
     * Create a callback used as the target of stream events.
     *
     * A reference is kept to the callback for the lifetime of the loop
     * to prevent "Cannot destroy active lambda function" fatal error from
     * the event extension.
     */
    protected function createStreamCallback()
    {
        $this->streamCallback = function ($stream, $flags) {

            $key = (int) $stream;

            if (
                EV_READ === (EV_READ & $flags)
                && isset($this->readListeners[$key])
            ) {
                call_user_func($this->readListeners[$key], $stream, $this);
            }

            if (
                EV_WRITE === (EV_WRITE & $flags)
                && isset($this->writeListeners[$key])
            ) {
                call_user_func($this->writeListeners[$key], $stream, $this);
            }

        };
    }
}
