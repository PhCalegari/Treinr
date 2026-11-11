<?php

namespace App\Enums;

enum Goal: string
{
    case Hypertrophy = 'hypertrophy';
    case WeightLoss = 'weight_loss';
    case Maintenance = 'maintenance';
}
