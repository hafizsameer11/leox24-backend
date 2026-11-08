<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Repair scraper→CRM field mixups for an imported Piro CSV batch.
 * Mirrors /var/www/piroscrapper/src/crmExportSanitize.js rules.
 */
class SanitizePiroLeadImport extends Command
{
    protected $signature = 'leads:sanitize-piro-import
        {--import-id= : lead_imports.id to repair}
        {--file= : Match leads.file_name (LIKE)}
        {--dry-run : Show counts without writing}';

    protected $description = 'Sanitize Address/Source/Website/City/Category on imported Piro scraper leads';

    private const IT_REGIONS = [
        'abruzzo', 'basilicata', 'calabria', 'campania', 'emilia-romagna', 'emilia romagna',
        'friuli-venezia giulia', 'friuli venezia giulia', 'lazio', 'liguria', 'lombardia',
        'marche', 'molise', 'piemonte', 'puglia', 'sardegna', 'sicilia', 'toscana',
        'trentino-alto adige', 'trentino alto adige', 'umbria', "valle d'aosta", 'valle daosta', 'veneto',
    ];

    public function handle(): int
    {
        $importId = $this->option('import-id');
        $file = $this->option('file');
        $dry = (bool) $this->option('dry-run');

        if (!$importId && !$file) {
            $importId = 6;
            $this->warn('No --import-id/--file given; defaulting to import-id=6 (piro-leads-job-60-crm.csv).');
        }

        $query = Lead::withoutGlobalScopes()->newQuery();
        if ($importId) {
            $query->where('lead_import_id', (int) $importId);
        }
        if ($file) {
            $query->where('file_name', 'like', '%'.$file.'%');
        }

        $total = (clone $query)->count();
        $this->info("Matching leads: {$total}");
        if ($total === 0) {
            return self::SUCCESS;
        }

        $updated = 0;
        $cityFilled = 0;
        $swapped = 0;

        $query->orderBy('id')->chunkById(200, function ($leads) use ($dry, &$updated, &$cityFilled, &$swapped) {
            foreach ($leads as $lead) {
                $raw = is_array($lead->raw_attributes) ? $lead->raw_attributes : [];
                if ($raw === []) {
                    continue;
                }

                $beforeAddr = (string) ($raw['Address'] ?? '');
                $beforeSrc = (string) ($raw['Source'] ?? '');
                $result = $this->sanitizeRaw($raw);
                $next = $result['raw_attributes'];

                $changed = json_encode($raw) !== json_encode($next)
                    || (($result['city'] && $result['city'] !== $lead->city)
                        || ($result['country'] && $result['country'] !== $lead->country));

                if (
                    (preg_match('#^https?://#i', $beforeAddr) && preg_match('/\b(via|viale|piazza|corso)\b/i', $beforeSrc))
                    || ($beforeSrc && preg_match('/\b(via|viale|piazza|corso)\b/i', $beforeSrc) && preg_match('#^https?://#i', $beforeAddr))
                ) {
                    $swapped++;
                }

                if (!$changed) {
                    continue;
                }

                $cityWasEmpty = !trim((string) $lead->city);
                if (!$dry) {
                    $lead->raw_attributes = $next;
                    if ($result['city']) {
                        $lead->city = mb_substr($result['city'], 0, 120);
                    }
                    if ($result['country']) {
                        $lead->country = mb_substr($result['country'], 0, 100);
                    }
                    $lead->save();
                }
                $updated++;
                if ($cityWasEmpty && $result['city']) {
                    $cityFilled++;
                }
            }
        });

        $this->info(($dry ? '[dry-run] would update' : 'updated').": {$updated}");
        $this->info("city filled: {$cityFilled}");
        $this->info("swap-like rows seen: {$swapped}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array{raw_attributes: array<string, string>, city: ?string, country: ?string}
     */
    private function sanitizeRaw(array $attrs): array
    {
        $pick = function (array $keys) use ($attrs): string {
            foreach ($keys as $key) {
                if (isset($attrs[$key]) && trim((string) $attrs[$key]) !== '') {
                    return trim((string) $attrs[$key]);
                }
            }
            $lower = [];
            foreach ($attrs as $k => $v) {
                $lower[mb_strtolower((string) $k)] = $v;
            }
            foreach ($keys as $key) {
                $lk = mb_strtolower($key);
                if (isset($lower[$lk]) && trim((string) $lower[$lk]) !== '') {
                    return trim((string) $lower[$lk]);
                }
            }

            return '';
        };

        $rawSource = $pick(['Source', 'source']);
        $rawSourceUrl = $pick(['Source URL', 'Source Url', 'SourceURL']);
        $fields = $this->sanitizeFields([
            'website' => $pick(['Website', 'website']),
            'address' => $pick(['Address', 'address']),
            'city' => $pick(['City', 'city']),
            'source_url' => $rawSourceUrl !== '' ? $rawSourceUrl : ($this->looksLikeUrl($rawSource) ? $rawSource : ''),
            'source_channel' => $this->looksLikeUrl($rawSource) ? '' : $rawSource,
            'category_label' => $pick(['Category', 'category']),
            'country_code' => $pick(['Country', 'country']),
        ]);

        $next = $attrs;
        if ($pick(['Name', 'name']) !== '') {
            $next['Name'] = $pick(['Name', 'name']);
        }
        if ($pick(['Email', 'email']) !== '') {
            $next['Email'] = $pick(['Email', 'email']);
        }
        if ($pick(['Phone', 'phone']) !== '') {
            $next['Phone'] = $pick(['Phone', 'phone']);
        }

        $next['City'] = $fields['city'] !== '' ? $fields['city'] : $pick(['City', 'city']);
        $next['Country'] = $fields['country'] !== '' ? $fields['country'] : ($pick(['Country', 'country']) ?: 'IT');
        $next['Category'] = $fields['category'];
        if ($fields['website'] !== '') {
            $next['Website'] = $fields['website'];
        } else {
            unset($next['Website'], $next['website']);
        }
        $next['Address'] = $fields['address'];
        $next['Source'] = $fields['source'];
        if ($fields['sourceUrl'] !== '') {
            $next['Source URL'] = $fields['sourceUrl'];
        } else {
            unset($next['Source URL'], $next['Source Url'], $next['SourceURL']);
        }

        foreach ($next as $k => $v) {
            if ($v === null || trim((string) $v) === '') {
                unset($next[$k]);
            } else {
                $next[$k] = is_string($v) ? $v : (string) $v;
            }
        }

        return [
            'raw_attributes' => $next,
            'city' => $fields['city'] !== '' ? $fields['city'] : null,
            'country' => $fields['country'] !== '' ? $fields['country'] : null,
        ];
    }

    /**
     * @param  array<string, string>  $lead
     * @return array{city: string, country: string, category: string, website: string, address: string, source: string, sourceUrl: string}
     */
    private function sanitizeFields(array $lead): array
    {
        $website = trim((string) ($lead['website'] ?? ''));
        $address = trim((string) ($lead['address'] ?? ''));
        $sourceUrl = trim((string) ($lead['source_url'] ?? ''));
        $city = trim((string) ($lead['city'] ?? ''));
        $category = $this->primaryCategory($lead['category_label'] ?? '');
        $mislabeledStreet = trim((string) ($lead['source_channel'] ?? $lead['source'] ?? ''));
        $sourceIsListing = $this->isListingUrl($sourceUrl);

        // Source column sometimes holds the street while Address holds the listing URL.
        if ($this->looksLikeUrl($address) && $this->looksLikeStreet($mislabeledStreet) && !$this->looksLikeStreet($sourceUrl)) {
            $sourceUrl = $address;
            $address = $mislabeledStreet;
        }

        if ($this->looksLikeUrl($address) && $this->looksLikeStreet($sourceUrl)) {
            [$address, $sourceUrl] = [$sourceUrl, $address];
        }
        if ($this->looksLikeUrl($address) && $this->looksLikeStreet($website)) {
            [$address, $website] = [$website, $address];
        }
        if ($this->looksLikeStreet($website) && $this->looksLikeUrl($sourceUrl) && !$this->looksLikeStreet($address)) {
            if ($address === '') {
                $address = $website;
            }
            $website = '';
        }
        if ($this->looksLikeUrl($address) && !$this->looksLikeStreet($address)) {
            if ($sourceUrl === '') {
                $sourceUrl = $address;
            } elseif ($website === '') {
                $website = $address;
            }
            $address = '';
        }
        if ($this->looksLikeStreet($sourceUrl) && !$this->looksLikeUrl($sourceUrl)) {
            if ($address === '') {
                $address = $sourceUrl;
            }
            $sourceUrl = '';
        }

        if ($this->isListingUrl($website)) {
            if ($sourceUrl === '' || !$this->looksLikeUrl($sourceUrl)) {
                $sourceUrl = $website;
            }
            $website = '';
        }
        if ($website !== '' && $sourceUrl !== '' && $this->normalizeUrl($website) === $this->normalizeUrl($sourceUrl) && $this->isListingUrl($sourceUrl)) {
            $website = '';
        }
        if ($website !== '' && !$this->looksLikeUrl($website) && mb_strlen($website) > 80) {
            if ($category === '' || mb_strlen($category) < 20) {
                $category = $this->primaryCategory($website);
            }
            $website = '';
        }
        if (preg_match('/^[A-Z]{2}$/', $category) && $website !== '' && !$this->looksLikeUrl($website) && mb_strlen($website) > 10) {
            $category = $this->primaryCategory($website);
            $website = '';
        }

        if ($website !== '' && $this->looksLikeUrl($website) && !$this->isListingUrl($website)) {
            $website = $this->normalizeUrl($website);
        } else {
            if ($website !== '' && $this->looksLikeUrl($website) && $sourceUrl === '') {
                $sourceUrl = $website;
            }
            $website = '';
        }

        if ($sourceUrl !== '' && $this->looksLikeUrl($sourceUrl)) {
            $sourceUrl = $this->normalizeUrl($sourceUrl);
        } elseif ($sourceIsListing) {
            $sourceUrl = $this->normalizeUrl(trim((string) ($lead['source_url'] ?? '')) ?: trim((string) ($lead['website'] ?? '')));
        } else {
            $sourceUrl = '';
        }

        if ($city === '') {
            $city = $this->parseCityFromAddress($address);
        }

        $existingChannel = strtolower(trim((string) ($lead['source_channel'] ?? $lead['source'] ?? '')));
        $sourceChannel = $this->channelFromUrl($sourceUrl)
            ?: $this->channelFromUrl(trim((string) ($lead['source_url'] ?? '')))
            ?: $this->channelFromUrl(trim((string) ($lead['website'] ?? '')))
            ?: (preg_match('/^(paginegialle|osm|google|web)$/', $existingChannel) ? $existingChannel : '')
            ?: ($sourceUrl !== '' ? 'web' : '');

        return [
            'city' => $city,
            'country' => trim((string) ($lead['country_code'] ?? '')) ?: 'IT',
            'category' => $category,
            'website' => $website,
            'address' => $address,
            'source' => $sourceChannel,
            'sourceUrl' => $sourceUrl,
        ];
    }

    private function looksLikeUrl(string $value): bool
    {
        $s = trim($value);
        if ($s === '') {
            return false;
        }
        if (preg_match('#^(https?://|www\.)#i', $s)) {
            return true;
        }

        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|\?|$)#i', $s)
            && !preg_match('/\b(via|viale|piazza|corso)\b/iu', $s)) {
            return true;
        }

        return false;
    }

    private function looksLikeStreet(string $value): bool
    {
        $s = trim($value);
        if ($s === '' || preg_match('#^(https?://|www\.)#i', $s)) {
            return false;
        }
        if (preg_match('/\b(via|viale|piazza|piazzale|corso|largo|vicolo|strada|contrada|localit[aà])\b/iu', $s)) {
            return true;
        }

        return (bool) (preg_match('/\b\d{5}\b/', $s) && str_contains($s, ','));
    }

    private function isListingUrl(string $value): bool
    {
        if (!$this->looksLikeUrl($value)) {
            return false;
        }
        $s = strtolower($value);

        return (bool) preg_match('/paginegialle\.it|paginebianche\.it|openstreetmap\.org|google\.[a-z.]+\/maps|maps\.google\./i', $s);
    }

    private function channelFromUrl(string $url): string
    {
        $s = strtolower(trim($url));
        if ($s === '') {
            return '';
        }
        if (preg_match('/paginegialle|paginebianche/', $s)) {
            return 'paginegialle';
        }
        if (str_contains($s, 'openstreetmap')) {
            return 'osm';
        }
        if (preg_match('/google\.|maps\.google/', $s)) {
            return 'google';
        }

        return 'web';
    }

    private function normalizeUrl(string $value): string
    {
        $s = trim($value);
        if ($s === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $s)) {
            return $s;
        }
        if (preg_match('#^www\.#i', $s)) {
            return 'https://'.$s;
        }
        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}#i', $s)) {
            return 'https://'.$s;
        }

        return $s;
    }

    private function parseCityFromAddress(string $address): string
    {
        $s = trim($address);
        if ($s === '' || $this->looksLikeUrl($s)) {
            return '';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $s)), fn ($p) => $p !== ''));
        $capIdx = null;
        foreach ($parts as $i => $p) {
            if (preg_match('/^\d{5}$/', $p)) {
                $capIdx = $i;
                break;
            }
        }
        if ($capIdx !== null) {
            for ($i = $capIdx + 1; $i < count($parts); $i++) {
                if (!in_array(mb_strtolower($parts[$i]), self::IT_REGIONS, true)) {
                    return preg_replace('/\s+[A-Z]{2}$/u', '', $parts[$i]) ?? $parts[$i];
                }
            }
        }
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $p = $parts[$i];
            if (in_array(mb_strtolower($p), self::IT_REGIONS, true)) {
                continue;
            }
            if (preg_match('/^\d{5}$/', $p) || preg_match('/^\d+[A-Za-z\/]*$/', $p)) {
                continue;
            }
            if (preg_match('/\b(via|viale|piazza|corso)\b/iu', $p)) {
                continue;
            }
            if (mb_strlen($p) >= 2) {
                return preg_replace('/\s+[A-Z]{2}$/u', '', $p) ?? $p;
            }
        }

        return '';
    }

    private function primaryCategory(string $label): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        if ($s === '') {
            return '';
        }
        $sep = preg_split('/\s*[|•·;]\s*/u', $s) ?: [];
        $sep = array_values(array_filter(array_map('trim', $sep)));
        if (count($sep) > 1 && mb_strlen($sep[0]) <= 80) {
            $s = $sep[0];
        }
        if (mb_strlen($s) <= 60) {
            return $s;
        }
        $cut = mb_substr($s, 0, 60);
        $sp = mb_strrpos($cut, ' ');

        return trim($sp !== false && $sp > 20 ? mb_substr($cut, 0, $sp) : $cut);
    }
}
