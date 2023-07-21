<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux;

enum CallbackType
{
    case Defer;
    case Delay;
    case Repeat;
    case Readable;
    case Writable;
    case Signal;
}