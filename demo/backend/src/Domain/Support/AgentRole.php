<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum AgentRole: string
{
    case Agent = 'agent';
    case Lead = 'lead';
    case Manager = 'manager';
}
