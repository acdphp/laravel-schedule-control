<?php

namespace Acdphp\SchedulePolice\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class BaseEvent
{
    use InteractsWithSockets;
    use Dispatchable;

    public function __construct(protected string $action, protected string $command)
    {
    }
}