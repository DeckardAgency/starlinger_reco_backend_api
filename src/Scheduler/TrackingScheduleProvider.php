<?php

namespace App\Scheduler;

use App\Message\RefreshPendingTrackingMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Polls the carrier APIs for tracking updates on all pending orders.
 *
 * Runs inside the messenger worker: `messenger:consume scheduler_tracking`
 * (add the transport to the existing async worker command). The schedule is
 * stateful so ticks missed while the worker was down are tracked in the cache
 * instead of silently skipped.
 */
#[AsSchedule('tracking')]
class TrackingScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        #[Autowire('%env(default::TRACKING_REFRESH_INTERVAL)%')]
        private readonly ?string $interval,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every($this->interval ?: '2 hours', new RefreshPendingTrackingMessage()))
            ->stateful($this->cache);
    }
}
