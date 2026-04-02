<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Oriceon\N8nBridge\DTOs\N8nToolResponse;

covers(N8nToolResponse::class);

describe('N8nToolResponse', function() {

    describe('success factories', function() {

        it('success() creates a successful 200 response', function() {
            $response = N8nToolResponse::success(['id' => 1, 'name' => 'Test']);

            expect($response->isSuccess())->toBeTrue()
                ->and($response->isError())->toBeFalse()
                ->and($response->getStatus())->toBe(200)
                ->and($response->getData())->toBe(['id' => 1, 'name' => 'Test']);
        });

        it('success() prepends message when provided', function() {
            $data = N8nToolResponse::success(['key' => 'value'], 'Done')->getData();

            expect($data['message'])->toBe('Done')
                ->and($data['key'])->toBe('value');
        });

        it('item() wraps a plain array', function() {
            $response = N8nToolResponse::item(['id' => 42, 'name' => 'John']);

            expect($response->isSuccess())->toBeTrue()
                ->and($response->getData())->toBe(['id' => 42, 'name' => 'John']);
        });

        it('item() applies transform closure, hiding unwanted fields', function() {
            $model    = (object) ['id' => 1, 'secret' => 'hidden'];
            $response = N8nToolResponse::item($model, static fn($m) => ['id' => $m->id]);

            expect($response->getData())->toBe(['id' => 1])
                ->and(array_key_exists('secret', $response->getData()))->toBeFalse();
        });

        it('collection() wraps list of items', function() {
            $response = N8nToolResponse::collection([['id' => 1], ['id' => 2]]);

            expect($response->getData())->toHaveCount(2)
                ->and($response->getData()[0])->toBe(['id' => 1]);
        });

        it('empty() returns empty data array', function() {
            $response = N8nToolResponse::empty();

            expect($response->getData())->toBe([])
                ->and($response->isSuccess())->toBeTrue();
        });
    });

    describe('paginated()', function() {

        it('includes full pagination metadata', function() {
            $paginator = new LengthAwarePaginator(
                items:       [['id' => 1]],
                total:       50,
                perPage:     15,
                currentPage: 1,
            );

            $response = N8nToolResponse::paginated($paginator);

            expect($response->getMeta()['total'])->toBe(50)
                ->and($response->getMeta()['per_page'])->toBe(15)
                ->and($response->getMeta()['current_page'])->toBe(1)
                ->and($response->getMeta()['has_more'])->toBeTrue()
                ->and($response->getData())->toHaveCount(1);
        });
    });

    describe('error factories', function() {

        it('error() creates error response with correct status', function() {
            $response = N8nToolResponse::error('Something went wrong', 400);

            expect($response->isError())->toBeTrue()
                ->and($response->isSuccess())->toBeFalse()
                ->and($response->getStatus())->toBe(400);
        });

        it('notFound() returns 404', function() {
            expect(N8nToolResponse::notFound('Contact not found')->getStatus())->toBe(404)
                ->and(N8nToolResponse::notFound()->getStatus())->toBe(404);
        });

        it('unauthorized() returns 401', function() {
            expect(N8nToolResponse::unauthorized()->getStatus())->toBe(401);
        });
    });

    describe('withMeta() — PHP 8.5 clone with', function() {

        it('returns new immutable instance with merged metadata', function() {
            $original = N8nToolResponse::collection([['id' => 1]]);
            $withMeta = $original->withMeta(['currency' => 'RON']);

            expect($original->getMeta())->toBe([])
                ->and($withMeta->getMeta())->toBe(['currency' => 'RON']);
        });

        it('merges with existing pagination meta', function() {
            $paginator = new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: 15,
                currentPage: 1,
            );
            $response = N8nToolResponse::paginated($paginator)->withMeta(['source' => 'crm']);

            expect($response->getMeta())
                ->toHaveKey('total')
                ->toHaveKey('source');
        });
    });

    describe('JSON rendering', function() {

        it('toJsonResponse() wraps data in data key', function() {
            $json = N8nToolResponse::item(['id' => 1])->toJsonResponse()->getData(true);

            expect($json)->toHaveKey('data')
                ->and($json['data'])->toBe(['id' => 1]);
        });

        it('toJsonResponse() error returns error key', function() {
            $json = N8nToolResponse::error('Not valid', 422)->toJsonResponse()->getData(true);
            expect($json)->toHaveKey('error');
        });
    });

    describe('toArray() legacy compatibility', function() {

        it('returns status/data for success', function() {
            $array = N8nToolResponse::success(['key' => 'value'])->toArray();

            expect($array['status'])->toBe('success')
                ->and($array['data'])->toHaveKey('key');
        });

        it('returns status/error for failure', function() {
            expect(N8nToolResponse::error('Oops')->toArray()['status'])->toBe('error');
        });
    });
});
