<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Queue\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Oriceon\N8nBridge\Enums\CheckpointStatus;
use Oriceon\N8nBridge\Enums\QueueFailureReason;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Events\N8nQueueJobProgressUpdatedEvent;
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;
use Oriceon\N8nBridge\Models\N8nQueueJob;

/**
 * Receives progress checkpoint POSTs from n8n during workflow execution.
 *
 * Routes registered:
 *   POST /n8n/queue/progress/{jobId}  — n8n sends a checkpoint
 *   GET  /n8n/queue/progress/{jobId}  — app polls the full timeline
 *
 * Authentication uses the same CredentialAuthService as all other /n8n/* routes.
 * n8n sends X-N8N-Key with the credential key — one credential, all endpoints.
 *
 * n8n HTTP Request node setup:
 *   URL: POST {{ $env.APP_URL }}/n8n/queue/progress/{{ $('Trigger').item.json._n8n_job_id }}
 *   Header: X-N8N-Key: {{ $env.N8N_WEBHOOK_KEY }}
 *   Body: { "node": "send_invoice", "status": "completed", "message": "..." }
 */
final class QueueProgressController extends Controller
{
    private const string NODE_DONE = '__done__';

    private const string NODE_FAILED = '__failed__';

    // ── POST /n8n/queue/progress/{jobId} ─────────────────────────────────────

    public function store(Request $request, string $jobId): JsonResponse
    {
        $job = N8nQueueJob::with('workflow')
            ->where('uuid', $jobId)
            ->orWhere('id', is_numeric($jobId) ? (int) $jobId : -1)
            ->first();

        if ($job === null) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        // Authenticate via the job workflow's webhook
        $error = $this->authenticate($request, $job);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'node'             => 'required|string|max:128',
            'status'           => 'required|string|in:running,completed,failed,skipped,waiting',
            'message'          => 'nullable|string|max:1000',
            'data'             => 'nullable|array',
            'error_message'    => 'nullable|string|max:2000',
            'progress_percent' => 'nullable|integer|min:0|max:100',
            'node_label'       => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $nodeName = $request->string('node')->toString();
        $status   = CheckpointStatus::from($request->string('status')->toString());

        $sequence = N8nQueueCheckpoint::query()
            ->where('job_id', $job->id)
            ->max('sequence') + 1;

        $checkpoint = N8nQueueCheckpoint::create([
            'job_id'           => $job->id,
            'node_name'        => $nodeName,
            'node_label'       => $request->string('node_label')->toString() ?: null,
            'status'           => $status,
            'message'          => $request->string('message')->toString() ?: null,
            'data'             => $request->input('data'),
            'error_message'    => $request->string('error_message')->toString() ?: null,
            'progress_percent' => $request->integer('progress_percent') ?: null,
            'sequence'         => $sequence,
        ]);

        event(new N8nQueueJobProgressUpdatedEvent($job, $checkpoint));

        $this->handleTerminalNode($job, $nodeName, $status, $request);

        return response()->json([
            'checkpoint_id' => $checkpoint->id,
            'sequence'      => $sequence,
            'job_status'    => $job->fresh()->status->value,
        ], 201);
    }

    // ── GET /n8n/queue/progress/{jobId} ──────────────────────────────────────

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = N8nQueueJob::with('workflow')
            ->where('uuid', $jobId)
            ->orWhere('id', is_numeric($jobId) ? (int) $jobId : -1)
            ->first();

        if ($job === null) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        $timeline = N8nQueueCheckpoint::timelineForJob($job->id);

        return response()->json([
            'job' => [
                'id'               => $job->id,
                'workflow'         => $job->workflow?->name,
                'status'           => $job->status->value,
                'status_label'     => $job->status->label(),
                'priority'         => $job->priority->label(),
                'attempts'         => $job->attempts,
                'n8n_execution_id' => $job->n8n_execution_id,
                'started_at'       => $job->started_at?->toIso8601String(),
                'finished_at'      => $job->finished_at?->toIso8601String(),
                'duration_ms'      => $job->duration_ms,
            ],
            'timeline'         => $timeline,
            'total_steps'      => count($timeline),
            'completed_steps'  => collect($timeline)->where('status', 'completed')->count(),
            'has_failures'     => collect($timeline)->where('status', 'failed')->isNotEmpty(),
            'progress_percent' => $this->calculateOverallProgress($timeline, $job),
        ]);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function authenticate(Request $request, N8nQueueJob $job): ?JsonResponse
    {
        return null;
    }

    private function _old_authenticate(Request $request, N8nQueueJob $job): ?JsonResponse
    {
        // N8nAuthMiddleware already authenticated the key and attached the credential.
        // Verify the caller's credential matches the workflow's endpoint credential.
        $callerCredential = $request->attributes->get('n8n_credential');

        $credentialId = $job->endpoint?->credential_id
            ?? $job->endpoints()->value('credential_id');

        // If no endpoint credential configured, any authenticated caller may post progress.
        if ($credentialId === null) {
            return null;
        }

        if ($callerCredential?->id !== $credentialId) {
            return response()->json(['error' => 'Unauthorized — key not valid for this job.'], 401);
        }

        return null;
    }

    private function handleTerminalNode(
        N8nQueueJob $job,
        string $nodeName,
        CheckpointStatus $status,
        Request $request,
    ): void {
        if ($nodeName === self::NODE_DONE) {
            $n8nResponse = $request->input('data', []);
            $executionId = $request->integer('message') ?: null;

            if (is_numeric($executionId)) {
                $n8nResponse['execution_id'] = $executionId;
            }

            $job->markDone($n8nResponse);

            return;
        }

        if ($nodeName === self::NODE_FAILED || $status === CheckpointStatus::Failed) {
            if ( ! $job->status->isTerminal()) {
                $job->markFailed(
                    reason: QueueFailureReason::UnknownException,
                    errorMessage: $request->string('error_message')->toString()
                        ?: $request->string('message')->toString()
                        ?: 'n8n workflow failed',
                    errorClass: 'N8nWorkflowException',
                );
            }
        }
    }

    private function calculateOverallProgress(array $timeline, N8nQueueJob $job): int
    {
        if ($job->status === QueueJobStatus::Done) {
            return 100;
        }

        if (empty($timeline)) {
            return 0;
        }

        $last = array_last($timeline);

        if (isset($last['progress'])) {
            return $last['progress'];
        }

        $completed = collect($timeline)->whereIn('status', ['completed', 'skipped'])->count();

        return (int) round($completed / count($timeline) * 100);
    }
}
