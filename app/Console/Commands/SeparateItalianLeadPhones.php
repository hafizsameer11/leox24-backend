<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Support\ItalianPhoneNumber;
use Illuminate\Console\Command;

class SeparateItalianLeadPhones extends Command
{
    protected $signature = 'leads:separate-italian-phones
        {--import-id= : Limit to a lead_imports.id}
        {--file= : Match leads.file_name (LIKE)}
        {--dry-run : Count changes without writing}';

    protected $description = 'Move Italian landlines to phone and mobiles to mobile on leads';

    public function handle(): int
    {
        $importId = $this->option('import-id');
        $file = $this->option('file');
        $dry = (bool) $this->option('dry-run');

        $query = Lead::withoutGlobalScopes()->newQuery();
        if ($importId) {
            $query->where('lead_import_id', (int) $importId);
        }
        if ($file) {
            $query->where('file_name', 'like', '%'.$file.'%');
        }

        $total = (clone $query)->count();
        $this->info("Matching leads: {$total}");

        $updated = 0;
        $toMobile = 0;
        $toLandline = 0;

        $query->orderBy('id')->chunkById(250, function ($leads) use ($dry, &$updated, &$toMobile, &$toLandline) {
            foreach ($leads as $lead) {
                $separated = ItalianPhoneNumber::separate($lead->phone, $lead->mobile);
                $newPhone = $separated['phone'];
                $newMobile = $separated['mobile'];

                if ((string) ($lead->phone ?? '') === (string) ($newPhone ?? '')
                    && (string) ($lead->mobile ?? '') === (string) ($newMobile ?? '')) {
                    continue;
                }

                if ($newMobile && ItalianPhoneNumber::isMobile($newMobile) && ! ItalianPhoneNumber::isMobile((string) $lead->mobile)) {
                    $toMobile++;
                }
                if ($newPhone && ItalianPhoneNumber::isLandline($newPhone) && ItalianPhoneNumber::isMobile((string) $lead->phone)) {
                    $toLandline++;
                }

                if (! $dry) {
                    $lead->phone = $newPhone;
                    $lead->mobile = $newMobile;
                    $lead->save();
                }
                $updated++;
            }
        });

        $this->info(($dry ? '[dry-run] would update' : 'updated').": {$updated}");
        $this->info("mobiles placed: {$toMobile}");

        return self::SUCCESS;
    }
}
