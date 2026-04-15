<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

enum TaskStatus: int
{
    case Pending = 0;
    case InProgress = 1;
    case Ended = 2;
    case Failed = 3;
}
