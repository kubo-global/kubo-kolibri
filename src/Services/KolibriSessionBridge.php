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
     * Build the data needed for the bridge page to auto-login and redirect.
     *
     * Returns an array with:
     * - kolibri_url: base URL of Kolibri server
     * - session_url: Kolibri's session API endpoint
     * - facility_id: the Kolibri facility ID
     * - username: the learner's Kolibri username
     * - password: the learner's deterministic password
     * - content_url: the final Kolibri content URL to redirect to
     */
    public function buildRedirectData(User $user, string $facilityId, string $contentNodeId): array
    {
        return [
            'kolibri_url' => $this->client->getBaseUrl(),
            'session_url' => $this->client->sessionApiUrl(),
            'facility_id' => $facilityId,
            'username' => $this->provisioner->kolibriUsername($user),
            'password' => $this->provisioner->kolibriPassword($user),
            'content_url' => $this->client->renderUrl($contentNodeId),
        ];
    }

    /**
     * Auto-provision a student and build proxy-based session data for the exercise page.
     *
     * Handles the full lifecycle: find Kolibri school, provision if needed,
     * return content URL and optional login credentials.
     */
    public function exerciseSessionData(User $user, string $contentNodeId): array
    {
        $school = School::whereNotNull('kolibri_facility_id')->first();
        $facilityId = config('kubo-kolibri.facility_id_override') ?: $school?->kolibri_facility_id;
        $kolibriReady = false;

        if ($facilityId) {
            if (!$user->kolibri_user_id) {
                $this->provisioner->provisionLearner($user, $facilityId);
                $user->refresh();
            }
            $kolibriReady = (bool) $user->kolibri_user_id;
            if (!$kolibriReady) {
                Log::warning('Kolibri learner provisioning failed', ['user_id' => $user->id, 'facility_id' => $facilityId]);
            }
        } else {
            Log::warning('Kolibri bridge skipped: no school has kolibri_facility_id. Run `php artisan kolibri:provision`.', ['user_id' => $user->id]);
        }

        return [
            'contentUrl' => $this->client->proxyRenderUrl($contentNodeId),
            'sessionUrl' => $kolibriReady ? $this->client->proxySessionApiUrl() : null,
            'facilityId' => $kolibriReady ? $facilityId : null,
            'kolibriUsername' => $kolibriReady ? $this->provisioner->kolibriUsername($user) : null,
            'kolibriPassword' => $kolibriReady ? $this->provisioner->kolibriPassword($user) : null,
        ];
    }
}
