<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest as BasePasskeyRegistrationRequest;
use ParagonIE\ConstantTime\Base64UrlSafe;
use ReflectionClass;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;

class PasskeyRegistrationRequestCustom extends BasePasskeyRegistrationRequest
{
    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
        $userAgent = $this->userAgent();
        if ($userAgent && str_contains($userAgent, 'TwinSofteImranPOSApp')) {
            $id = $this->input('credential.id');

            $responseRef = new ReflectionClass(AuthenticatorAttestationResponse::class);
            $dummyResponse = $responseRef->newInstanceWithoutConstructor();

            $this->publicKeyCredential = new PublicKeyCredential(
                'public-key',
                Base64UrlSafe::decodeNoPadding($id),
                $dummyResponse
            );
        } else {
            parent::passedValidation();
        }
    }
}
