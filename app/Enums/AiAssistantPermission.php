<?php

namespace App\Enums;

enum AiAssistantPermission: string
{
    case Use = 'ai_assistant.use';
    case ManageSettings = 'ai_assistant.manage_settings';
}
