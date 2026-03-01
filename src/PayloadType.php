<?php

declare(strict_types=1);

namespace Dozor;

enum PayloadType: string
{
    case Text = 'TEXT';
    case Json = 'JSON';
}
