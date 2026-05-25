<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Actions\VerifyPasskey as BaseVerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class VerifyPasskeyCustom extends BaseVerifyPasskey
{
    /**
     * Validate the passkey credential and return the passkey.
     *
     * @throws InvalidPasskeyException
     */
    public function __invoke(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
        ?PasskeyUser $user = null
    ): Passkey {
        $userAgent = request()->userAgent();
        if ($userAgent && str_contains($userAgent, 'TwinSofteImranPOSApp')) {
            // Request is from our APK App!
            // Bypass standard assertion check, directly load passkey from database.
            return DB::transaction(function () use ($credential, $user) {
                $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);
                Log::info('VerifyPasskeyCustom Debug', [
                    'requested_credential_id' => $credentialId,
                    'all_credential_ids_in_db' => Passkey::pluck('credential_id')->toArray(),
                ]);

                $passkey = $this->getPasskey($credential, lock: true);

                $this->ensurePasskeyBelongsToUser($passkey, $user);

                // Update last used time and counter (no need for assertion checks)
                $passkey->forceFill([
                    'last_used_at' => now(),
                ])->save();

                PasskeyVerified::dispatch($passkey->user, $passkey);

                return $passkey;
            });
        }

        return parent::__invoke($credential, $options, $user);
    }
}
