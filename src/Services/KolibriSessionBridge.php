<?php

namespace KuboKolibri\Services;

use App\Models\User;
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
}
