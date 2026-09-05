<?php

namespace App\Enums;

enum ChatPermission: string
{
    case View = 'chat.view';
    case Direct = 'chat.direct';
    case Team = 'chat.team';
    case Context = 'chat.context';
    case Manage = 'chat.manage';
    case ChannelCreate = 'chat.channel.create';
    case ChannelManage = 'chat.channel.manage';
    case ChannelArchive = 'chat.channel.archive';
    case ChannelJoin = 'chat.channel.join';
    case Thread = 'chat.thread';
    case React = 'chat.react';
    case Pin = 'chat.pin';
    case Search = 'chat.search';
}
