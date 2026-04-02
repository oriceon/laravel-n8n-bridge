<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nStat;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(\Oriceon\N8nBridge\Concerns\HasDynamicTable::class);

it('uses the configured table prefix for N8nWorkflow', function() {
    config(['n8n-bridge.table_prefix' => 'n8n']);

    $model = new N8nWorkflow();
    expect($model->getTable())->toBe('n8n__workflows__lists');
});

it('uses the configured table prefix for N8nQueueJob', function() {
    config(['n8n-bridge.table_prefix' => 'n8n']);

    $model = new N8nQueueJob();
    expect($model->getTable())->toBe('n8n__queue__jobs');
});

it('uses the configured table prefix for N8nDelivery', function() {
    config(['n8n-bridge.table_prefix' => 'n8n']);

    $model = new N8nDelivery();
    expect($model->getTable())->toContain('n8n__');
});

it('reflects a custom table prefix', function() {
    config(['n8n-bridge.table_prefix' => 'myapp']);

    $model = new N8nWorkflow();
    expect($model->getTable())->toStartWith('myapp__');
});

it('N8nEndpoint table uses endpoints__lists base name', function() {
    config(['n8n-bridge.table_prefix' => 'n8n']);

    $model = new N8nEndpoint();
    expect($model->getTable())->toBe('n8n__endpoints__lists');
});

it('N8nStat table uses stats__lists base name', function() {
    config(['n8n-bridge.table_prefix' => 'n8n']);

    $model = new N8nStat();
    expect($model->getTable())->toBe('n8n__stats__lists');
});

it('N8nCredential table uses credentials__lists base name', function() {
    config(['n8n-bridge.table_prefix' => 'n8n']);

    $model = new N8nCredential();
    expect($model->getTable())->toBe('n8n__credentials__lists');
});
