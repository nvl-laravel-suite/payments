<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Payments\Tests\MismappedOwnerScopeTestCase;

uses(MismappedOwnerScopeTestCase::class);

it('rejects a reviewed provider receipt mapping that differs from its canonical source payment owner', function (): void {
    $verification = app(OwnershipAdoptionCoordinator::class)->verify($this->adoptionPlan);
    expect($verification->passed())->toBeFalse();
});
