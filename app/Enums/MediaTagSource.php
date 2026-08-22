<?php

namespace App\Enums;

enum MediaTagSource: string
{
    case Api = 'api';
    case File = 'file';
    case Manual = 'manual';
}
