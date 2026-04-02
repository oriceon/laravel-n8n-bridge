<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Queue\Workers;

use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Updates the rolling EMA of estimated_duration_ms on a workflow
 * after every successful queue job completion.
 *
 * PHP 8.5: Uses clone($workflow, [...]) for the internal update pattern,
 * and the pipe operator for the EMA calculation chain.
 */
final class WorkflowDurationUpdater
{
    private int $sampleSize;

    public function __construct()
    {
        $this->sampleSize = (int) config('n8n-bridge.queue.duration_sample_size', 50);
    }

    /**
     * @param N8nQueueJob $job
     * @return void
     */
    public function record(N8nQueueJob $job): void
    {
        if ($job->duration_ms === null || $job->duration_ms <= 0) {
            return;
        }

        $workflow = $job->workflow;

        if ($workflow === null) {
            return;
        }

        $newDuration    = $job->duration_ms;
        $currentEma     = $workflow->estimated_duration_ms;
        $currentSamples = $workflow->estimated_sample_count ?? 0;

        [$updatedEma, $updatedSamples] = $currentEma === null || $currentSamples === 0
            ? [$newDuration, 1]
            : $this->computeEma($newDuration, $currentEma, $currentSamples);

        N8nWorkflow::query()
            ->where('id', $workflow->id)
            ->update([
                'estimated_duration_ms'  => $updatedEma,
                'estimated_sample_count' => $updatedSamples,
                'estimated_updated_at'   => now(),
            ]);
    }

    /**
     * PHP 8.5 pipe operator: compute EMA as a readable left-to-right pipeline.
     *
     * @param int $newValue
     * @param int $currentEma
     * @param int $currentSamples
     * @return array{int, int}
     */
    private function computeEma(int $newValue, int $currentEma, int $currentSamples): array
    {
        $cappedSamples = min($currentSamples, $this->sampleSize);

        $alpha = $cappedSamples
            |> (static fn(int $n): float => 2.0 / ($n + 1));

        $updatedEma = (int) round($alpha * $newValue + (1 - $alpha) * $currentEma);

        return [$updatedEma, min($currentSamples + 1, $this->sampleSize)];
    }

    /**
     * @param N8nWorkflow $workflow
     * @return void
     */
    public function reset(N8nWorkflow $workflow): void
    {
        $workflow->update([
            'estimated_duration_ms'  => null,
            'estimated_sample_count' => 0,
            'estimated_updated_at'   => null,
        ]);
    }

    /**
     * @param N8nWorkflow $workflow
     * @return void
     */
    public function recalculate(N8nWorkflow $workflow): void
    {
        $durations = N8nQueueJob::query()
            ->forWorkflow($workflow->id)
            ->where('status', QueueJobStatus::Done->value)
            ->whereNotNull('duration_ms')
            ->where('duration_ms', '>', 0)
            ->orderByDesc('finished_at')
            ->limit($this->sampleSize)
            ->pluck('duration_ms');

        if ($durations->isEmpty()) {
            return;
        }

        $avg     = (int) round($durations->average());
        $samples = $durations->count();

        $workflow->update([
            'estimated_duration_ms'  => $avg,
            'estimated_sample_count' => $samples,
            'estimated_updated_at'   => now(),
        ]);
    }
}
