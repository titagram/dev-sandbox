<?php

it('publishes the authoritative Hades wiki verification trust contract', function () {
    $spec = json_decode(
        file_get_contents(base_path('docs/hades/openapi-hades-v1.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($spec['paths']['/api/hades/v1/token/verify']['post']['responses']['401']['$ref'])
        ->toBe('#/components/responses/Error')
        ->and($spec['paths']['/api/hades/v1/capabilities']['get']['responses']['200']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/CapabilitiesResponse')
        ->and($spec['paths']['/api/hades/v1/capabilities']['get']['responses'])->toHaveKey('401')
        ->and($spec['components']['schemas']['CapabilitiesResponse']['properties']['capability_names']['description'])
        ->toContain('Effective names granted to this agent')
        ->toContain('verify_project_wiki')
        ->toContain('not escalated automatically');

    expect($spec['paths'])->toHaveKeys([
        '/api/hades/v1/wiki/pages',
        '/api/hades/v1/wiki/pages/{page}',
        '/api/hades/v1/wiki/pages/{page}/verify',
    ])
        ->and($spec['paths']['/api/hades/v1/wiki/pages'])->toHaveKeys(['get', 'post'])
        ->and($spec['paths']['/api/hades/v1/wiki/pages/{page}'])->toHaveKey('get')
        ->and($spec['paths']['/api/hades/v1/wiki/pages/{page}/verify'])->toHaveKey('post');

    $verify = $spec['paths']['/api/hades/v1/wiki/pages/{page}/verify']['post'];
    $request = $verify['requestBody']['content']['application/json']['schema'];
    $refs = $request['properties']['evidence_refs'];
    $evidence = $refs['items'];
    $claims = $evidence['properties']['claims'];
    $claim = $claims['items'];

    expect($request['required'])->toBe([
        'project_id',
        'workspace_binding_id',
        'expected_current_revision_id',
        'evidence_refs',
    ])
        ->and($refs['maxItems'])->toBe(80)
        ->and($refs['description'])->toContain('at most 80 claims')
        ->and($evidence['required'])->toBe(['kind', 'claims'])
        ->and(array_keys($evidence['properties']))->toBe(['kind', 'schema', 'sha256', 'hash', 'path', 'claims'])
        ->and($evidence['additionalProperties'])->toBeFalse()
        ->and($claims['minItems'])->toBe(1)
        ->and($claims['maxItems'])->toBe(8)
        ->and($claim['required'])->toBe(['claim', 'proof'])
        ->and(array_keys($claim['properties']))->toBe(['claim', 'proof'])
        ->and($claim['additionalProperties'])->toBeFalse();

    foreach (['claim', 'proof'] as $field) {
        expect($claim['properties'][$field])
            ->toMatchArray([
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 500,
                'pattern' => '.*\\S.*',
            ]);
    }

    expect($verify['responses']['401']['$ref'])->toBe('#/components/responses/Unauthorized')
        ->and($verify['responses']['403']['content']['application/json']['example']['error']['code'])
        ->toBe('wiki_verification_capability_not_allowed')
        ->and($verify['responses']['422']['$ref'])->toBe('#/components/responses/Error')
        ->and($spec['components']['responses']['Error']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/HadesStructuredErrorResponse');

    $artifactRequest = $spec['components']['schemas']['ArtifactUploadRequest'];
    $artifact422 = $spec['paths']['/api/hades/v1/artifacts']['post']['responses']['422'];
    $artifactCodes = $spec['components']['schemas']['ArtifactUploadErrorResponse']['properties']['error']['properties']['code']['enum'];

    expect($artifactRequest['properties']['sha256']['description'])
        ->toContain('recursively key-sorted compact JSON')
        ->and($artifact422['description'])->toContain('validation')
        ->toContain('artifact-specific')
        ->and($artifact422['content']['application/json']['schema']['oneOf'])->toContain(
            ['$ref' => '#/components/schemas/ArtifactUploadErrorResponse'],
            ['$ref' => '#/components/schemas/LaravelValidationErrorResponse'],
        )
        ->and($artifactCodes)->toContain('artifact_hash_mismatch');
});
