<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Actions\StorePasskey as BaseStorePasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class StorePasskeyCustom extends BaseStorePasskey
{
    /**
     * Validate and store a passkey for the user.
     *
     * @throws InvalidPasskeyException
     */
    public function __invoke(
        Authenticatable $user,
        string $name,
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options
    ): Passkey {
        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        $userAgent = request()->userAgent();
        if ($userAgent && str_contains($userAgent, 'TwinSofteImranPOSApp')) {
            // Request is from our APK App!
            // Bypass normal FIDO2 WebAuthn attestation validation.
            $rawId = $credential->rawId;
            $credentialId = Base64UrlSafe::encodeUnpadded($rawId);

            // Ensure the credential ID is unique
            $exists = $user->passkeys()->where('credential_id', $credentialId)->exists();
            if ($exists) {
                throw InvalidPasskeyException::make('Unable to register this passkey.');
            }

            // Create a fake/simplified credential structure that will satisfy simplewebauthn deserialization when logging in
            $fakeCredential = [
                'publicKeyCredentialId' => $credentialId,
                'type' => 'public-key',
                'transports' => [],
                'attestationType' => 'none',
                'trustPath' => ['type' => 'empty'],
                'aaguid' => '00000000-0000-0000-0000-000000000000',
                'credentialPublicKey' => 'fake_public_key',
                'userDescriptor' => [
                    'id' => Base64UrlSafe::encodeUnpadded((string) $user->getKey()),
                    'type' => 'public-key',
                ],
                'counter' => 0,
                'other' => null,
            ];

            return $user->passkeys()->create([
                'name' => $name,
                'credential_id' => $credentialId,
                'credential' => $fakeCredential,
            ]);
        }

        return parent::__invoke($user, $name, $credential, $options);
    }
}
