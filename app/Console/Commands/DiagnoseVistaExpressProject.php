<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Shows how each catalog project is configured for the read-only CRM
 * integration, without printing any secret material.
 *
 * Useful when the Vista Express Leads page reports that a project is not
 * configured: it reveals which project carries the API Base URL / API Key and
 * which look-alike project is being resolved instead.
 */
class DiagnoseVistaExpressProject extends Command
{
    protected $signature = 'crm:diagnose-vista-express';
    protected $description = 'Report Vista Express project configuration state (read-only, prints no secrets).';

    public function handle(): int
    {
        $target = 'vista-express';

        $this->line('');
        $this->line('  <options=bold>Vista Express CRM project diagnostics</>');
        $this->line('');

        $rows = $this->readProjects();

        if ($rows === null) {
            $this->error('  The projects table does not exist in the configured database.');
            $this->line('  Run: php artisan migrate');
            $this->line('');
            $this->line('  This command reads the same database the API uses, so run it on the');
            $this->line('  server that serves the CRM if the local database is not the live one.');
            $this->line('');

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->error('  The projects table is empty. Run: php artisan migrate');
            $this->line('');

            return self::FAILURE;
        }

        $this->line(sprintf('  %-4s %-16s %-26s %-10s %-10s %s', 'ID', 'SLUG', 'NAME', 'BASE URL', 'API KEY', 'USED BY PAGE'));
        $this->line('  ' . str_repeat('-', 96));

        $match = null;

        foreach ($rows as $project) {
            $hasBase = filled($project->api_base_url);
            $hasKey = filled($project->api_key);

            if ($project->slug === $target) {
                $match = $project;
            }

            $this->line(sprintf(
                '  %-4s %-16s %-26s %-10s %-10s %s',
                $project->id,
                $project->slug,
                \Illuminate\Support\Str::limit((string) $project->name, 25),
                $hasBase ? 'yes' : '<fg=yellow>NO</>',
                $hasKey ? 'yes' : '<fg=yellow>NO</>',
                $project->slug === $target ? '<fg=green>yes</>' : '-',
            ));
        }

        $this->line('');

        if (! $match) {
            $this->error(sprintf('  No project with slug "%s" exists.', $target));
            $this->line('  Run: php artisan migrate');
            $this->line('');

            return self::FAILURE;
        }

        $missing = [];

        if (!filled($match->api_base_url)) {
            $missing[] = 'API Base URL';
        }

        if (!filled($match->api_key)) {
            $missing[] = 'API Key';
        }

        if ($missing === []) {
            // The key is stored encrypted; verify it round-trips before blaming
            // the upstream server for a 401.
            try {
                \Illuminate\Support\Facades\Crypt::decryptString($match->api_key);
                $decrypts = true;
            } catch (\Throwable $e) {
                $decrypts = false;
            }

            $this->line('  <fg=green>Configuration looks complete.</>');
            $this->line(sprintf('    API Base URL : %s', $match->api_base_url));
            $this->line(sprintf('    API Key      : stored, encrypted, %s', $decrypts ? 'decrypts OK' : '<fg=red>DOES NOT DECRYPT</>'));
            $this->line('');
            $this->line('    If the page still fails, the next step is the Vista Express server rejecting');
            $this->line('    the key. Check storage/logs on that server, or call the endpoint directly:');
            $this->line('');
            $this->line(sprintf('      curl -H "X-CRM-API-Key: <key>" %s/api/crm/overview', rtrim($match->api_base_url, '/')));
            $this->line('');

            return self::SUCCESS;
        }

        $this->error(sprintf('  Project id %d ("%s") is missing: %s', $match->id, $match->name, implode(', ', $missing)));
        $this->line('');

        $others = $rows->where('slug', '!=', $target)
            ->filter(fn ($p) => filled($p->api_base_url) || filled($p->api_key));

        if ($others->isNotEmpty()) {
            $this->line('  <options=bold>Heads up — these other projects already have credentials set:</>');
            foreach ($others as $other) {
                $this->line(sprintf('    id %d  slug %-16s base=%s key=%s', $other->id, $other->slug, filled($other->api_base_url) ? 'yes' : 'no', filled($other->api_key) ? 'yes' : 'no'));
            }
            $this->line('');
            $this->line('  The Vista Express Leads page only ever uses the project with slug "'.$target.'".');
            $this->line('  If the key was entered on one of the projects above, it needs to be on');
            $this->line(sprintf('  id %d ("%s") instead.', $match->id, $match->name));
            $this->line('');
        }

        $this->line('  <options=bold>To fix it:</>');
        $this->line('    1. Open Project Management → Projects in the CRM');
        $this->line(sprintf('    2. Edit the project named "%s"', $match->name));
        $this->line('    3. Set the API Base URL and paste the API Key, then Save');
        $this->line('');

        return self::FAILURE;
    }

    /**
     * Read the catalog, or null when the table is absent so the command can
     * explain itself instead of surfacing a raw PDO exception.
     */
    private function readProjects(): ?\Illuminate\Support\Collection
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('projects')) {
            return null;
        }

        return Project::orderBy('id')->get();
    }
}
