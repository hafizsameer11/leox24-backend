<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Stores the Vista Express read-only key on the correct project and, optionally,
 * verifies the whole chain end to end.
 *
 * This exists because the Projects screen can silently fail to persist
 * api_key: the form deliberately blanks the key on every edit, so a save that
 * only carries the base URL leaves the key untouched, and a paste that lands in
 * the neighbouring "API Secret" field saves nothing at all. Setting the value
 * here is unambiguous and self-verifying.
 */
class SetVistaExpressProjectKey extends Command
{
    protected $signature = 'crm:set-vista-express-key
                            {key? : The read-only key from "php artisan crm:client" on the Vista Express server}
                            {--base-url= : Optionally also set the API Base URL, e.g. https://api.vistaexpress.it}
                            {--verify : Call the Vista Express API to confirm the whole chain works}';

    protected $description = 'Store the Vista Express read-only API key on the vista-express project and optionally verify it.';

    public function handle(): int
    {
        $slug = 'vista-express';

        if (! \Illuminate\Support\Facades\Schema::hasTable('projects')) {
            $this->error('The projects table does not exist. Run: php artisan migrate');

            return self::FAILURE;
        }

        $project = Project::where('slug', $slug)->first();

        if (! $project) {
            $this->error(sprintf('No project with slug "%s" exists. Run: php artisan migrate', $slug));

            return self::FAILURE;
        }

        $this->line('');
        $this->line(sprintf('  Target project: id %d  "%s"  (slug: %s)', $project->id, $project->name, $project->slug));
        $this->line('');

        $key = trim((string) ($this->argument('key') ?? ''));

        if ($key !== '') {
            // Guard against pasting the wrong credential: this integration only
            // ever sends the read-only key produced by crm:client.
            if (! str_starts_with($key, 'crm_')) {
                $this->error('  That does not look like a CRM key. Expected it to start with "crm_".');
                $this->line('  Generate one on the Vista Express server with: php artisan crm:client');
                $this->line('');

                return self::FAILURE;
            }

            $project->api_key = Crypt::encryptString($key);
            $this->line('  API Key : stored (encrypted)');
        } elseif (filled($project->api_key)) {
            $this->line('  API Key : left as-is (no key argument given)');
        } else {
            $this->error('  No key supplied and none stored. Nothing to do.');
            $this->line('');

            return self::FAILURE;
        }

        $baseUrl = trim((string) ($this->option('base-url') ?? ''));

        if ($baseUrl !== '') {
            $project->api_base_url = rtrim($baseUrl, '/');
            $this->line(sprintf('  Base URL: %s', $project->api_base_url));
        } elseif (filled($project->api_base_url)) {
            $this->line(sprintf('  Base URL: %s (unchanged)', $project->api_base_url));
        }

        // The read-only integration never uses an API secret; clearing it avoids
        // a stale placeholder being mistaken for a required credential.
        $project->api_secret = null;
        $project->api_auth_type = 'bearer';
        $project->is_active = true;

        $project->save();

        $this->line('  Saved.');
        $this->line('');

        // Re-read from the database so we prove what was actually persisted
        // rather than trusting the in-memory model.
        $fresh = Project::find($project->id);
        $this->reportPersisted($fresh);

        if ($this->option('verify')) {
            return $this->verify($fresh);
        }

        $this->line('  Run again with --verify to call the Vista Express API and confirm end to end.');
        $this->line('');

        return self::SUCCESS;
    }

    private function reportPersisted(?Project $project): void
    {
        if (! $project) {
            $this->error('  Could not re-read the project after saving.');

            return;
        }

        $hasBase = filled($project->api_base_url);
        $hasKey = filled($project->api_key);

        $this->line('  Persisted state:');
        $this->line(sprintf('    API Base URL : %s', $hasBase ? $project->api_base_url : '<fg=yellow>MISSING</>'));
        $this->line(sprintf('    API Key      : %s', $hasKey ? 'stored' : '<fg=yellow>MISSING</>'));

        if (! $hasBase || ! $hasKey) {
            $this->line('');
            $this->error('  Still incomplete — the page will keep reporting a configuration error.');

            return;
        }

        try {
            $decrypted = Crypt::decryptString($project->api_key);
            $ok = str_starts_with($decrypted, 'crm_');
            $this->line(sprintf('    Decrypt check: %s', $ok ? '<fg=green>OK</>' : '<fg=red>FAILED</>'));
        } catch (\Throwable $e) {
            $this->line('    Decrypt check: <fg=red>FAILED — re-enter the key</>');
        }
    }

    private function verify(Project $project): int
    {
        // Use the same URL builder as the service so this check can never
        // disagree with what the page actually requests.
        $url = \App\Services\VistaExpressService::apiRoot($project->api_base_url) . '/crm/overview';

        $this->line('');
        $this->line('  Live check');
        $this->line(sprintf('    GET %s', $url));
        $this->line('');

        try {
            $key = Crypt::decryptString($project->api_key);
        } catch (\Throwable $e) {
            $this->error('  Stored key cannot be decrypted. Re-run with the key to replace it.');

            return self::FAILURE;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withHeader('X-CRM-API-Key', $key)
                ->get($url);
        } catch (\Throwable $e) {
            $this->error('  Could not reach the Vista Express server: '.$e->getMessage());
            $this->line('    Check the base URL, DNS and any firewall between the two servers.');
            $this->line('');

            return self::FAILURE;
        }

        if ($response->successful()) {
            $payload = $response->json();
            $totals = $payload['data']['totals'] ?? [];

            $this->line('  <fg=green>Success — the API key is valid and the endpoint is live.</>');
            $this->line('');
            $this->line('    Total users    : '.($totals['total_users'] ?? 'n/a'));
            $this->line('    Sellers        : '.($totals['total_sellers'] ?? 'n/a'));
            $this->line('    Buyers         : '.($totals['total_buyers'] ?? 'n/a'));
            $this->line('    Products       : '.($totals['total_products'] ?? 'n/a'));
            $this->line('    Orders         : '.($totals['total_orders'] ?? 'n/a'));
            $this->line('    Total revenue  : '.($totals['total_revenue'] ?? 'n/a'));
            $this->line('');
            $this->line('  The Vista Express Leads page will now populate.');

            return self::SUCCESS;
        }

        $this->error(sprintf('  Vista Express responded %d.', $response->status()));
        $this->line('    Message: '.Str::limit((string) $response->json('message'), 300));
        $this->line('');

        return match ($response->status()) {
            401 => $this->explain401(),
            403 => $this->explain403(),
            404 => $this->explain404(),
            429 => $this->explain429(),
            default => self::FAILURE,
        };
    }

    private function explain401(): int
    {
        $this->line('  <options=bold>Likely causes</>');
        $this->line('    - the key was pasted with extra characters or whitespace');
        $this->line('    - the client was revoked or the row is not active on the Vista Express server');
        $this->line('    - api_clients has a different row than the one that was created');
        $this->line('');
        $this->line('  Re-run the command with the key copied carefully, or create a fresh one:');
        $this->line('    (on Vista Express) php artisan crm:client "LEO24 CRM (production)"');
        $this->line('');

        return self::FAILURE;
    }

    private function explain403(): int
    {
        $this->line('  The key is valid but not permitted to read "overview".');
        $this->line('  Create a key with all scopes: php artisan crm:client "LEO24 CRM" ');
        $this->line('');

        return self::FAILURE;
    }

    private function explain404(): int
    {
        $this->line('  The server answered, but that path is not there.');
        $this->line('  The read-only namespace is mounted from routes/api.php, so it must be');
        $this->line('  reachable under /api. Confirm on the Vista Express server:');
        $this->line('');
        $this->line('    php artisan route:list --path=crm');
        $this->line('');
        $this->line('  If that lists api/crm/... routes, the path is right and the deployment is');
        $this->line('  stale — pull and restart. Also make sure the base URL points at the API');
        $this->line('  host, not at a front-end domain.');
        $this->line('');

        return self::FAILURE;
    }

    private function explain429(): int
    {
        $this->line('  The client exceeded its per-minute rate limit.');
        $this->line('  Wait a minute and re-run with --verify.');
        $this->line('');

        return self::FAILURE;
    }
}
