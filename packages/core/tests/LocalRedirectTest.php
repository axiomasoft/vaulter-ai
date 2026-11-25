<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Ai\Core\Http\LocalRedirect;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

test('workspace form posts redirect only to same-host absolute paths', function (string $candidate): void {
    expect(LocalRedirect::isLocalPath($candidate))->toBeTrue();

    $request = Request::create('/office/proposals/p-1/decision', 'POST', ['redirect_to' => $candidate]);

    $response = LocalRedirect::after($request);

    expect($response->getStatusCode())->toBe(303)
        ->and($response->getTargetUrl())->toBe($candidate);
})->with([
    'list' => '/office/proposals',
    'query string' => '/documents/42?tab=review#p-1',
]);

test('workspace form posts refuse open-redirect targets', function (mixed $candidate): void {
    expect(LocalRedirect::isLocalPath($candidate))->toBeFalse();

    $request = Request::create('/office/proposals/p-1/decision', 'POST', ['redirect_to' => $candidate]);

    expect(LocalRedirect::after($request)->getTargetUrl())
        ->toBe(route('vaulter-ai.workspace.proposals.index'));
})->with([
    'network path' => '//evil.example/phish',
    'backslash network path' => '/\\evil.example/phish',
    'absolute URL' => 'https://evil.example/phish',
    'scheme' => 'javascript:alert(1)',
    'relative path' => 'office/proposals',
    'header injection' => "/office\r\nLocation: https://evil.example",
    'tab smuggling' => "/\t/evil.example",
    'empty' => '',
    'array' => [['/office/proposals']],
]);
