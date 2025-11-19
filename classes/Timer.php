<?php

/**
 * Simple high-resolution timer for performance monitoring
 */
class Timer
{
    private float $startTime;

    public function __construct()
    {
        $this->start();
    }

    public function start(): void
    {
        $this->startTime = hrtime(true);
    }

    /**
     * Returns time in milliseconds since last start()
     *
     * @return float
     */
    public function stop(): float
    {
        return (hrtime(true) - $this->startTime) / 1_000_000;
    }
}
