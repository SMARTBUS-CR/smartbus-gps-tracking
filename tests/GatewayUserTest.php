<?php

use App\Support\Gateway\GatewayUser;

it('knows the roles of a gateway user', function () {
    $user = new GatewayUser('01a0c691-579f-731c-a034-03cc8fe8136f', ['passenger']);

    expect($user->hasRole('passenger'))->toBeTrue()
        ->and($user->hasRole('driver'))->toBeFalse()
        ->and($user->getAuthIdentifier())->toBe('01a0c691-579f-731c-a034-03cc8fe8136f');
});
