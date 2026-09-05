<?php

/*
|--------------------------------------------------------------------------
| ApiExceptionHandler — DomainRuleException → 422 merkezi mapleme (Task 9)
|--------------------------------------------------------------------------
|
| FileManagerController'daki 12 `try/catch (LogicException) → ApiException::
| unprocessable()` bloğu kaldırıldı; davranış merkeze (ApiExceptionHandler)
| taşındı. Bu test öncesi/sonrası eşdeğerliği ve regresyon guard'ını kanıtlar:
|
|   A) DomainRuleException → [422, mesaj] (yeni merkezi mapleme)
|   B) ApiException::unprocessable(mesaj) → [422, mesaj] (eski davranış — aynı)
|   C) DomainRuleException, LogicException'ın alt sınıfıdır (eski
|      `catch (LogicException)` / `@throws LogicException` sözleşmeleri kırılmaz)
|   D) Regresyon guard: framework-içi LogicException türleri
|      (BadMethodCallException, InvalidArgumentException) HÂLÂ [500, ...]
|      döner — mesaj sızdırılmaz.
|
*/

use Lvntr\StarterKit\Exceptions\ApiException;
use Lvntr\StarterKit\Exceptions\ApiExceptionHandler;
use Lvntr\StarterKit\Exceptions\DomainRuleException;

/**
 * Private `resolve()` maplemesini reflection ile çağırır — saf [status, message]
 * eşlemesini test eder (Request / app boot gerektirmez).
 *
 * @return array{0: int, 1: string}
 */
function resolveException(Throwable $e): array
{
    $method = new ReflectionMethod(ApiExceptionHandler::class, 'resolve');

    /** @var array{0: int, 1: string} $result */
    $result = $method->invoke(null, $e);

    return $result;
}

it('maps DomainRuleException to 422 with its curated message', function (): void {
    [$status, $message] = resolveException(new DomainRuleException('Folder already exists.'));

    expect($status)->toBe(422);
    expect($message)->toBe('Folder already exists.');
});

it('produces the same [422, message] as the old ApiException::unprocessable path', function (): void {
    $message = 'This item is out of context.';

    $before = resolveException(ApiException::unprocessable($message));
    $after = resolveException(new DomainRuleException($message));

    // Öncesi (controller try/catch → ApiException::unprocessable) ile
    // sonrası (merkezi DomainRuleException mapleme) birebir aynı.
    expect($after)->toBe($before);
    expect($after)->toBe([422, $message]);
});

it('DomainRuleException remains a LogicException subclass', function (): void {
    // Eski `catch (LogicException)` yakalama noktaları ve `@throws LogicException`
    // sözleşmeleri kırılmamalı.
    expect(new DomainRuleException('x'))->toBeInstanceOf(LogicException::class);
});

it('does not demote framework LogicExceptions to 422 (regression guard)', function (): void {
    // BadMethodCallException ve InvalidArgumentException LogicException'dan türer;
    // gerçek bug sinyalidir. 500 kalmalı ve ham mesaj sızmamalı.
    [$badMethodStatus, $badMethodMessage] = resolveException(
        new BadMethodCallException('Method Foo::bar() does not exist.')
    );
    expect($badMethodStatus)->toBe(500);
    expect($badMethodMessage)->toBe('A server error occurred.');

    [$invalidArgStatus, $invalidArgMessage] = resolveException(
        new InvalidArgumentException('Leaked internal detail.')
    );
    expect($invalidArgStatus)->toBe(500);
    expect($invalidArgMessage)->toBe('A server error occurred.');

    // Çıplak LogicException de 500 kalır.
    [$logicStatus] = resolveException(new LogicException('bare'));
    expect($logicStatus)->toBe(500);
});
