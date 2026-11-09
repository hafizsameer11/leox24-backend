<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class MyDoctorPlusLeadService
{
    /**
     * Fetch the My Doctor Plus CRM feed through the configured Project. The
     * browser never receives this API key; only the CRM backend talks to the
     * external medical platform.
     */
    public function fetch(Project $project, array $filters): array
    {
        if (!$project->api_base_url || !$project->api_key) {
            throw new \RuntimeException(
                'My Doctor Plus API Base URL and API Key must be configured in the Projects page before analytics can be loaded.'
            );
        }

        try {
            $apiKey = Crypt::decryptString($project->api_key);
        } catch (\Exception $exception) {
            throw new \RuntimeException('The configured My Doctor Plus API Key could not be decrypted.', 0, $exception);
        }

        $client = Http::timeout(30)->acceptJson();
        $client = ($project->api_auth_type ?? 'custom') === 'custom'
            ? $client->withHeader('X-CRM-API-Key', $apiKey)
            : $client->withToken($apiKey);

        try {
            $response = $client->get(rtrim($project->api_base_url, '/') . '/crm/leads', $filters);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Unable to reach the My Doctor Plus CRM service.', 0, $exception);
        }

        if (!$response->successful()) {
            $message = $response->json('message') ?: 'My Doctor Plus rejected the CRM request.';
            throw new \RuntimeException($message);
        }

        $payload = $response->json();
        if (!is_array($payload) || !($payload['success'] ?? false) || !is_array($payload['data'] ?? null)) {
            throw new \RuntimeException('My Doctor Plus returned an invalid CRM response.');
        }

        return $payload['data'];
    }
}
