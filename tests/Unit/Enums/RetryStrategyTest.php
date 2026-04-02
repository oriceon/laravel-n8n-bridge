<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\RetryStrategy;

covers(RetryStrategy::class);

describe('RetryStrategy', function() {
    describe('Exponential backoff', function() {
        it('increases delay with each attempt', function() {
            $s = RetryStrategy::Exponential;
            expect($s->delaySeconds(0))->toBeGreaterThanOrEqual(1)
                ->and($s->delaySeconds(3))->toBeGreaterThan($s->delaySeconds(0));
        });

        it('is capped at 300 seconds', function() {
            expect(RetryStrategy::Exponential->delaySeconds(100))->toBeLessThanOrEqual(300);
        });
    });

    describe('Linear backoff', function() {
        it('scales linearly', function() {
            expect(RetryStrategy::Linear->delaySeconds(0))->toBe(5)
                ->and(RetryStrategy::Linear->delaySeconds(1))->toBe(10)
                ->and(RetryStrategy::Linear->delaySeconds(2))->toBe(15);
        });

        it('is capped at 300 seconds', function() {
            expect(RetryStrategy::Linear->delaySeconds(100))->toBeLessThanOrEqual(300);
        });
    });

    describe('Fixed backoff', function() {
        it('always returns 10 seconds', function() {
            expect(RetryStrategy::Fixed->delaySeconds(0))->toBe(10)
                ->and(RetryStrategy::Fixed->delaySeconds(5))->toBe(10)
                ->and(RetryStrategy::Fixed->delaySeconds(50))->toBe(10);
        });
    });

    describe('Fibonacci backoff', function() {
        it('follows fibonacci sequence', function() {
            expect(RetryStrategy::Fibonacci->delaySeconds(0))->toBe(1)
                ->and(RetryStrategy::Fibonacci->delaySeconds(1))->toBe(1)
                ->and(RetryStrategy::Fibonacci->delaySeconds(2))->toBe(2)
                ->and(RetryStrategy::Fibonacci->delaySeconds(3))->toBe(3)
                ->and(RetryStrategy::Fibonacci->delaySeconds(4))->toBe(5)
                ->and(RetryStrategy::Fibonacci->delaySeconds(5))->toBe(8);
        });

        it('is capped at 300 seconds', function() {
            expect(RetryStrategy::Fibonacci->delaySeconds(100))->toBe(300);
        });
    });

    it('has a label for every case', function(RetryStrategy $strategy) {
        expect($strategy->label())->toBeString()->not->toBeEmpty();
    })->with(RetryStrategy::cases());

    it('can be created from value', function() {
        expect(RetryStrategy::from('exponential'))->toBe(RetryStrategy::Exponential)
            ->and(RetryStrategy::from('fibonacci'))->toBe(RetryStrategy::Fibonacci);
    });
});
