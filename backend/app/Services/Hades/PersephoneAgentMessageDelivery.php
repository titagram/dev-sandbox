<?php

namespace App\Services\Hades;

use App\Models\PersephoneAgentMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PersephoneAgentMessageDelivery
{
    public function __construct(private readonly PersephoneAgentMessageValidator $validator) {}

    /**
     * @return array{messages: Collection<int, PersephoneAgentMessage>, cursor: string|null}|JsonResponse
     */
    public function page(Request $request, object $agent): array|JsonResponse
    {
        $filters = $this->validator->inbox($request);

        $authorizationError = $this->authorize($agent, $filters);

        if ($authorizationError instanceof JsonResponse) {
            return $authorizationError;
        }

        $binding = null;

        if ($filters['target_workspace_binding_id'] !== null) {
            $binding = DB::table('hades_workspace_bindings')
                ->where('id', $filters['target_workspace_binding_id'])
                ->where('project_id', $agent->project_id)
                ->where('hades_agent_id', $agent->id)
                ->first();

            if (! $binding) {
                return $this->error(
                    'workspace_binding_not_found',
                    'Workspace binding was not found.',
                    Response::HTTP_NOT_FOUND,
                );
            }

            if ($binding->status !== 'linked' || $binding->unlinked_at !== null) {
                return $this->error(
                    'workspace_binding_inactive',
                    'Workspace binding is not active.',
                    Response::HTTP_FORBIDDEN,
                );
            }
        }

        if ($filters['cursor'] !== null && ! PersephoneAgentMessage::query()
            ->forTarget($filters['project_id'], $filters['target_agent_id'])
            ->whereKey($filters['cursor'])
            ->exists()) {
            return $this->error(
                'cursor_not_found',
                'The cursor does not belong to this project and target.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $query = PersephoneAgentMessage::query()
            ->forTarget($filters['project_id'], $filters['target_agent_id'])
            ->notExpired()
            ->where(function ($query): void {
                $query->whereNull('target_workspace_binding_id')
                    ->orWhereHas('targetWorkspaceBinding', function ($binding): void {
                        $binding->where('status', 'linked')->whereNull('unlinked_at');
                    });
            });

        if ($binding !== null) {
            $query->where(function ($query) use ($binding): void {
                $query->whereNull('target_workspace_binding_id')
                    ->orWhere('target_workspace_binding_id', $binding->id);
            });
        }

        if ($filters['cursor'] !== null) {
            $query->where('id', '>', $filters['cursor']);
        }

        $messages = $query
            ->orderBy('id')
            ->limit($filters['limit'])
            ->get();

        return [
            'messages' => $messages,
            'cursor' => $messages->last()?->id ?? $filters['cursor'],
        ];
    }

    /**
     * @param  array{project_id: string, target_agent_id: string}  $filters
     */
    private function authorize(object $agent, array $filters): ?JsonResponse
    {
        if ($agent->project_id !== $filters['project_id']) {
            return $this->error(
                'project_mismatch',
                'Hades agent token is scoped to a different project.',
                Response::HTTP_FORBIDDEN,
            );
        }

        if ($agent->external_agent_id !== $filters['target_agent_id']) {
            return $this->error(
                'target_agent_mismatch',
                'The inbox target must match the authenticated Hades agent.',
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
