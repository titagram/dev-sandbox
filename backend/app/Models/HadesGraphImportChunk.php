<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HadesGraphImportChunk extends Model
{
    use HasUlids;

    public const string COMPRESSION_GZIP = 'gzip';

    public const string KIND_NODES = 'nodes';

    public const string KIND_ENTRYPOINTS = 'entrypoints';

    public const string KIND_STRUCTURES = 'structures';

    public const string KIND_EDGES = 'edges';

    public const string KIND_FLOWS = 'flows';

    public const string KIND_FLOW_STEPS = 'flow_steps';

    public const string KIND_UNCERTAINTIES = 'uncertainties';

    /**
     * @var list<string>
     */
    public const array KINDS = [
        self::KIND_ENTRYPOINTS,
        self::KIND_NODES,
        self::KIND_STRUCTURES,
        self::KIND_EDGES,
        self::KIND_FLOWS,
        self::KIND_FLOW_STEPS,
        self::KIND_UNCERTAINTIES,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hades_graph_import_chunks';

    protected $fillable = [
        'id',
        'graph_import_id',
        'chunk_index',
        'kind',
        'sha256',
        'record_count',
        'uncompressed_bytes',
        'compression',
        'compressed_sha256',
        'compressed_bytes',
        'storage_disk',
        'storage_path',
        'received_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'record_count' => 'integer',
            'uncompressed_bytes' => 'integer',
            'compressed_bytes' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function graphImport(): BelongsTo
    {
        return $this->belongsTo(HadesGraphImport::class, 'graph_import_id');
    }
}
