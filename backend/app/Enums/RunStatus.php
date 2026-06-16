<?php

namespace App\Enums;

enum RunStatus: string
{
    case Created = 'created';
    case Started = 'started';
    case ContextPulled = 'context_pulled';
    case LocalSnapshotReceived = 'local_snapshot_received';
    case Working = 'working';
    case Heartbeat = 'heartbeat';
    case ArtifactUploaded = 'artifact_uploaded';
    case Finished = 'finished';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case Reported = 'reported';
}
