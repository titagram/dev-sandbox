<?php

namespace App\Enums;

enum SourceStatus: string
{
    case VerifiedFromCode = 'verified_from_code';
    case DeveloperProvided = 'developer_provided';
    case AiGenerated = 'ai_generated';
    case NeedsVerification = 'needs_verification';
    case Stale = 'stale';
    case ConflictWithCode = 'conflict_with_code';
}
