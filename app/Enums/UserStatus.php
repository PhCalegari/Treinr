<?php

namespace App\Enums;

enum UserStatus: string
{
    /** Cadastrou-se e aguarda a aprovação de um administrador. */
    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';
}
