<?php

namespace App\Domain;

enum TransitionDirection: string
{
    case Forward = 'forward';
    case Backward = 'backward';
}
