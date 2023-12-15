<?php

namespace Acdphp\SchedulePolice\Services;

use Acdphp\SchedulePolice\Console\Kernel as ControlKernel;
use Acdphp\SchedulePolice\Data\ExecResult;
use Acdphp\SchedulePolice\Data\ScheduledEvent;
use Acdphp\SchedulePolice\Models\StoppedScheduledEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SchedulePoliceService
{
    protected static ?Collection $stoppedEventsCache = null;

    protected array $config;

    public function __construct()
    {
        $this->config = config('schedule-police');
    }

    public function isConfigured(): bool
    {
        return
            is_a('\App\Console\Kernel', ControlKernel::class, true) &&
            Schema::hasTable('stopped_scheduled_events') &&
            file_exists(public_path('vendor/schedule-police'));
    }

    /**
     * @return array|ScheduledEvent[]
     *
     * @throws BindingResolutionException
     */
    public function getScheduledEvents(): array
    {
        app()->make(Kernel::class);
        $schedule = app()->make(Schedule::class);

        // Map events
        $events = array_map(function (Event $event) {
            return new ScheduledEvent(
                key: $this->getEventKey($event),
                event: $event,
                stoppedEvent: $this->stoppedEvent($event),
            );
        }, $schedule->events());

        if ($this->config['sort_by_stopped']) {
            // Sort events by stopped time
            usort($events, function (ScheduledEvent $a, ScheduledEvent $b) {
                return (int) ($a->stoppedEvent?->created_at < $b->stoppedEvent?->created_at);
            });
        }

        return $events;
    }

    public function stopSchedule(string $key, string $expression): void
    {
        StoppedScheduledEvent::firstOrCreate([
            'key' => $key,
            'expression' => $expression,
        ], [
            'by' => Auth::hasUser() ? Auth::user()->{$this->config['causer_key']} : null,
        ]);
    }

    public function startSchedule(string $key, string $expression): void
    {
        StoppedScheduledEvent::where('key', $key)
            ->when(
                $this->config['separate_by_frequency'],
                fn ($q) => $q->where('expression', $expression)
            )
            ->delete();
    }

    public function execCommand(string $command): ExecResult
    {
        if (Str::startsWith($command, $this->config['blacklisted_commands'])) {
            return new ExecResult(
                message: 'Cannot run this command in dashboard because it\'s blacklisted.',
                isError: true
            );
        }

        try {
            $exitCode = Artisan::call($command);
        } catch (Throwable $e) {
            return new ExecResult(
                message: $e->getMessage(),
                isError: true
            );
        }

        return new ExecResult(
            message: Artisan::output(),
            isError: $exitCode !== 0
        );
    }

    public function stoppedEvent(Event $event): ?StoppedScheduledEvent
    {
        if (! static::$stoppedEventsCache) {
            static::$stoppedEventsCache = StoppedScheduledEvent::all();
        }

        return static::$stoppedEventsCache
            ->where('key', $this->getEventKey($event))
            ->when($this->config['separate_by_frequency'], fn ($q) => $q->where('expression', $event->expression))
            ->first();
    }

    protected function getEventKey(Event $event): string
    {
        return Str::of($event->command)
            ->after('artisan\'')
            ->whenEmpty(fn () => Str::of($event->description))
            ->trim();
    }
}
