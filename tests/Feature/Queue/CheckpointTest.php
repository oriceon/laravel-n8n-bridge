<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Enums\CheckpointStatus;
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;

covers(N8nQueueCheckpoint::class, CheckpointStatus::class);

describe('CheckpointStatus enum', function() {
    it('classifies terminal statuses correctly', function(CheckpointStatus $status, bool $expected) {
        expect($status->isTerminal())->toBe($expected);
    })->with([
        [CheckpointStatus::Completed, true],
        [CheckpointStatus::Failed,    true],
        [CheckpointStatus::Skipped,   true],
        [CheckpointStatus::Running,   false],
        [CheckpointStatus::Waiting,   false],
    ]);

    it('provides non-empty color, icon and label for every status', function(CheckpointStatus $status) {
        expect($status->color())->toBeString()->not->toBeEmpty()
            ->and($status->icon())->toBeString()->not->toBeEmpty()
            ->and($status->label())->toBeString()->not->toBeEmpty();
    })->with(CheckpointStatus::cases());
});

describe('N8nQueueCheckpoint::timelineForJob()', function() {
    it('returns checkpoints in sequence order', function() {
        $job = N8nQueueJobFactory::new()->create();

        N8nQueueCheckpoint::create(['job_id' => $job->id, 'node_name' => 'fetch_invoice', 'status' => CheckpointStatus::Completed, 'sequence' => 1]);
        N8nQueueCheckpoint::create(['job_id' => $job->id, 'node_name' => 'enrich_data',   'status' => CheckpointStatus::Completed, 'sequence' => 2]);
        N8nQueueCheckpoint::create(['job_id' => $job->id, 'node_name' => 'send_email',    'status' => CheckpointStatus::Failed,    'sequence' => 3]);

        $timeline = N8nQueueCheckpoint::timelineForJob($job->id);

        expect($timeline)->toHaveCount(3)
            ->and($timeline[0]['node'])->toBe('fetch_invoice')
            ->and($timeline[1]['node'])->toBe('enrich_data')
            ->and($timeline[2]['node'])->toBe('send_email')
            ->and($timeline[2]['status'])->toBe('failed');
    });

    it('timeline entries include id, color, icon, at and sequence fields', function() {
        $job = N8nQueueJobFactory::new()->create();
        N8nQueueCheckpoint::create([
            'job_id' => $job->id, 'node_name' => 'my_node',
            'status' => CheckpointStatus::Completed, 'sequence' => 1,
        ]);

        expect(N8nQueueCheckpoint::timelineForJob($job->id)[0])
            ->toHaveKeys(['id', 'node', 'label', 'status', 'color', 'icon', 'at', 'sequence']);
    });

    it('falls back to node_name when node_label is null', function() {
        $job = N8nQueueJobFactory::new()->create();
        N8nQueueCheckpoint::create([
            'job_id' => $job->id, 'node_name' => 'my_node', 'node_label' => null,
            'status' => CheckpointStatus::Running, 'sequence' => 1,
        ]);

        expect(N8nQueueCheckpoint::timelineForJob($job->id)[0]['label'])->toBe('my_node');
    });

    it('uses node_label when provided', function() {
        $job = N8nQueueJobFactory::new()->create();
        N8nQueueCheckpoint::create([
            'job_id'     => $job->id, 'node_name' => 'send_invoice_email',
            'node_label' => 'Send Invoice Email',
            'status'     => CheckpointStatus::Completed, 'sequence' => 1,
        ]);

        expect(N8nQueueCheckpoint::timelineForJob($job->id)[0]['label'])->toBe('Send Invoice Email');
    });

    it('returns empty array for job with no checkpoints', function() {
        $job = N8nQueueJobFactory::new()->create();
        expect(N8nQueueCheckpoint::timelineForJob($job->id))->toBe([]);
    });
});

it('forJob scope orders by sequence regardless of insert order', function() {
    $job = N8nQueueJobFactory::new()->create();

    N8nQueueCheckpoint::create(['job_id' => $job->id, 'node_name' => 'c', 'status' => CheckpointStatus::Completed, 'sequence' => 3]);
    N8nQueueCheckpoint::create(['job_id' => $job->id, 'node_name' => 'a', 'status' => CheckpointStatus::Completed, 'sequence' => 1]);
    N8nQueueCheckpoint::create(['job_id' => $job->id, 'node_name' => 'b', 'status' => CheckpointStatus::Completed, 'sequence' => 2]);

    expect(
        N8nQueueCheckpoint::query()->forJob($job->id)->pluck('node_name')->toArray()
    )->toBe(['a', 'b', 'c']);
});
