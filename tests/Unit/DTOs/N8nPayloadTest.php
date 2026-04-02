<?php

declare(strict_types=1);

use Oriceon\N8nBridge\DTOs\N8nPayload;

covers(N8nPayload::class);

describe('N8nPayload', function() {

    describe('typed getters', function() {

        it('retrieves scalar values with correct types', function() {
            $payload = new N8nPayload([
                'name'         => 'Test User',
                'count'        => 42,
                'float_val'    => 19.99,
                'float_as_int' => 3.7,
                'active'       => true,
                'disabled'     => false,
                'string_true'  => 'true',
                'int_one'      => 1,
            ]);

            expect($payload->getString('name'))->toBe('Test User')
                ->and($payload->getString('missing', 'default'))->toBe('default')
                ->and($payload->getInt('count'))->toBe(42)
                ->and($payload->getInt('float_as_int'))->toBe(3)
                ->and($payload->getInt('missing', 0))->toBe(0)
                ->and($payload->getFloat('float_val'))->toBe(19.99)
                ->and($payload->getFloat('missing', 0.0))->toBe(0.0)
                ->and($payload->getBool('active'))->toBeTrue()
                ->and($payload->getBool('disabled'))->toBeFalse()
                ->and($payload->getBool('string_true'))->toBeTrue()
                ->and($payload->getBool('missing', true))->toBeTrue();
        });

        it('retrieves array values', function() {
            $payload = new N8nPayload(['tags' => ['a', 'b', 'c'], 'name' => 'not-array']);

            expect($payload->getArray('tags'))->toBe(['a', 'b', 'c'])
                ->and($payload->getArray('name', []))->toBe([])
                ->and($payload->getArray('missing'))->toBe([]);
        });
    });

    describe('dot notation access', function() {

        it('retrieves nested values with dot notation', function() {
            $payload = new N8nPayload([
                'user' => ['profile' => ['email' => 'user@example.com']],
            ]);

            expect($payload->getString('user.profile.email'))->toBe('user@example.com')
                ->and($payload->get('user.profile'))->toBe(['email' => 'user@example.com']);
        });
    });

    describe('required() validation', function() {

        it('throws InvalidArgumentException for missing required fields', function() {
            $payload = new N8nPayload(['name' => 'test']);

            expect(fn() => $payload->required('missing_field'))
                ->toThrow(InvalidArgumentException::class, 'missing_field');
        });

        it('returns value when required field exists', function() {
            $payload = new N8nPayload(['id' => 42]);
            expect($payload->required('id'))->toBe(42);
        });
    });

    describe('has() check', function() {

        it('correctly identifies present and absent fields', function() {
            $payload = new N8nPayload(['name' => 'test', 'null_val' => null]);

            expect($payload->has('name'))->toBeTrue()
                ->and($payload->has('null_val'))->toBeFalse()
                ->and($payload->has('missing'))->toBeFalse();
        });
    });

    describe('Carbon date parsing', function() {

        it('parses valid ISO date string', function() {
            $payload = new N8nPayload(['paid_at' => '2026-03-22T10:00:00Z']);

            $carbon = $payload->getCarbon('paid_at');
            expect($carbon)->not->toBeNull()
                ->and($carbon->year)->toBe(2026)
                ->and($carbon->month)->toBe(3);
        });

        it('returns null for invalid or missing date', function() {
            $payload = new N8nPayload(['bad_date' => 'not-a-date']);

            expect($payload->getCarbon('bad_date'))->toBeNull()
                ->and($payload->getCarbon('missing'))->toBeNull();
        });
    });

    describe('mapTo()', function() {

        it('maps nested fields to flat keys, skipping missing', function() {
            $payload = new N8nPayload([
                'payload' => [
                    'email'   => 'user@example.com',
                    'name'    => 'John Doe',
                    'company' => 'Acme Inc',
                ],
            ]);

            expect($payload->mapTo([
                'payload.email'   => 'email_address',
                'payload.name'    => 'full_name',
                'payload.company' => 'company_name',
                'payload.missing' => 'will_be_skipped',
            ]))->toBe([
                'email_address' => 'user@example.com',
                'full_name'     => 'John Doe',
                'company_name'  => 'Acme Inc',
            ]);
        });
    });

    describe('fromRequest() factory', function() {

        it('extracts execution id from headers (case-insensitive)', function() {
            $payloadUpper = N8nPayload::fromRequest(
                body: ['event' => 'test'],
                headers: ['X-N8N-Execution-Id' => [123]],
            );

            $payloadLower = N8nPayload::fromRequest(
                body: [],
                headers: ['x-n8n-execution-id' => [456]],
            );

            expect($payloadUpper->idempotencyKey())->toBe(123)
                ->and($payloadUpper->executionId())->toBe(123)
                ->and($payloadLower->idempotencyKey())->toBe(456);
        });
    });

    it('returns all data with all()', function() {
        $data    = ['a' => 1, 'b' => 2];
        $payload = new N8nPayload($data);

        expect($payload->all())->toBe($data);
    });
});
