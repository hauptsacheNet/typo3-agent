<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

enum TaskEvent: string
{
    case Cancel = 'cancel';
    case End = 'end';
    case Fail = 'fail';
}
