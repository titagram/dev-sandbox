<?php

namespace App\Enums;

enum SourceType: string
{
    case LocalPluginSnapshot = 'local_plugin_snapshot';
    case LocalPluginDiff = 'local_plugin_diff';
    case LocalAnalyzer = 'local_analyzer';
    case ServerHistory = 'server_history';
    case UserManual = 'user_manual';
    case AiGenerated = 'ai_generated';
}
