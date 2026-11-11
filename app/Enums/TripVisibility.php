<?php

namespace App\Enums;

enum TripVisibility: string
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';
}