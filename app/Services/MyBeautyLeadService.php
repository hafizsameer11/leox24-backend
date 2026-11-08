<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class MyBeautyLeadService
{
    /**
     * Fetch the filtered registration feed through the project's existing API
     * configuration. The CRM browser never receives the My Beauty API key.
     */
    public function fetch(Project $project, array $filters): array
    {
        if (!$project->api_base_url || !$project->api_key) {
            throw new \RuntimeException(
                'My Beauty API Base URL and API Key must be configured in the Projects page before leads can be loaded.'
            );
        }

        try {
            $apiKey = Crypt::decryptString($project->api_key);
        } catch (\Exception $exception) {
            throw new \RuntimeException('The configured My Beauty API Key could not be decrypted.', 0, $exception);
        }

        $client = Http::timeout(30)->acceptJson();
        if (($project->api_auth_type ?? 'bearer') === 'custom') {
            $client = $client->withHeader('X-CRM-API-Key', $apiKey);
        } else {
            $client = $client->withToken($apiKey);
        }

        try {
            $response = $client->get(rtrim($project->api_base_url, '/') . '/crm/leads', $filters);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Unable to reach the My Beauty leads service.', 0, $exception);
        }

        if (!$response->successful()) {
            $message = $response->json('message') ?: 'My Beauty rejected the leads request.';
            throw new \RuntimeException($message);
        }

        $payload = $response->json();
        if (!is_array($payload) || !($payload['success'] ?? false) || !is_array($payload['data'] ?? null)) {
            throw new \RuntimeException('My Beauty returned an invalid leads response.');
        }

        return $payload['data'];
    }
}
