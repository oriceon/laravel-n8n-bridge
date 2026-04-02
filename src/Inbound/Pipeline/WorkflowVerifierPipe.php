<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Http\Request;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class WorkflowVerifierPipe
{
    public function handle(array $passable, \Closure $next): mixed
    {
        /** @var Request $request */
        /** @var N8nEndpoint $endpoint */
        [$request, $endpoint] = $passable;

        $workflowId = $request->header('X-N8N-Workflow-Id');

        if (empty($workflowId)) {
            throw new AccessDeniedHttpException('Missing X-N8N-Workflow-Id header.');
        }

        $workflow = N8nWorkflow::where('n8n_id', $workflowId)->first();

        if ($workflow === null) {
            throw new AccessDeniedHttpException('Invalid X-N8N-Workflow-Id.');
        }

        $passable[] = $workflow;

        return $next($passable);
    }
}
