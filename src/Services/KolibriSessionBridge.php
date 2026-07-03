<?php

namespace KuboKolibri\Services;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use KuboKolibri\Client\KolibriClient;

/**
 * Handles cross-domain SSO between KUBO and Kolibri.
 *
 * Since KUBO and Kolibri run on separate servers, we can't share session
 * cookies directly. Instead, this service builds the data needed for a
 * client-side bridge page that authenticates the student into Kolibri
 * via its API, then redirects to the content.
 */
class KolibriSessionBridge
{
    private KolibriClient $client;
    private KolibriProvisioner $provisioner;

    public function __construct(KolibriClient $client, KolibriProvisioner $provisioner)
    {
        $this->client = $client;
        $this->provisioner = $provisioner;
    }

    /**
     * Auto-provision a student and establish their Kolibri session server-side
     * for the exercise page.
     *
     * KUBO logs the learner into Kolibri here and returns the resulting session
     * as ready-to-append Set-Cookie headers (scoped to the proxy path). The
     * learner's password never reaches the browser; the page just loads the
     * proxied content and the cookie authenticates it. If Kolibri isn't ready or
     * the login fails, `ssoReady` is false and the iframe falls back to Kolibri's
     * own name-picker.
     *
     * @return array{contentUrl: string, ssoReady: bool, cookieHeaders: string[]}
     */
    public function exerciseSessionData(User $user, string $contentNodeId): array
    {
        $school = School::whereNotNull('kolibri_facility_id')->first();
        $facilityId = config('kubo-kolibri.facility_id_override') ?: $school?->kolibri_facility_id;
        $session = null;

        if ($facilityId) {
            if (!$user->kolibri_user_id) {
                $this->provisioner->provisionLearner($user, $facilityId);
                $user->refresh();
            }

            if ($user->kolibri_user_id) {
                $session = $this->client->openSession(
                    $this->provisioner->kolibriUsername($user),
                    $this->provisioner->kolibriPassword($user),
                    $facilityId,
                );
                if (!$session) {
                    Log::warning('Kolibri learner session could not be established server-side', ['user_id' => $user->id]);
                }
            } else {
                Log::warning('Kolibri learner provisioning failed', ['user_id' => $user->id, 'facility_id' => $facilityId]);
            }
        } else {
            Log::warning('Kolibri bridge skipped: no school has kolibri_facility_id. Run `php artisan kolibri:provision`.', ['user_id' => $user->id]);
        }

        return [
            'contentUrl' => $this->client->proxyRenderUrl($contentNodeId),
            'ssoReady' => (bool) $session,
            'cookieHeaders' => $session ? $this->sessionCookieHeaders($session) : [],
        ];
    }

    /**
     * Raw Set-Cookie header strings that place Kolibri's session on the browser,
     * scoped to the proxy path so the browser only sends them on proxied requests.
     *
     * Deliberately raw strings rather than Illuminate cookies: the proxy forwards
     * these values verbatim to Kolibri, so they must not pass through Laravel's
     * cookie encryption. The session cookie is HttpOnly (only the proxy reads it,
     * server-side); the CSRF token mirrors Django's own non-HttpOnly cookie.
     *
     * @param  array{kolibri: string, kolibri_csrftoken: ?string}  $session
     * @return string[]
     */
    private function sessionCookieHeaders(array $session): array
    {
        $path = '/kolibri-proxy/';
        $headers = ["kolibri={$session['kolibri']}; Path={$path}; HttpOnly; SameSite=Lax"];

        if (!empty($session['kolibri_csrftoken'])) {
            $headers[] = "kolibri_csrftoken={$session['kolibri_csrftoken']}; Path={$path}; SameSite=Lax";
        }

        return $headers;
    }
}
