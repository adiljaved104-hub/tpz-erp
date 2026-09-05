<?php

namespace App\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Team = 'team';
    case Context = 'context';
    case Channel = 'channel';
}
