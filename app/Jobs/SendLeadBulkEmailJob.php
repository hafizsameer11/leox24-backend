<?php

namespace App\Jobs;

use App\Mail\BulkEmailMail;
use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendLeadBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $leadId,
        public string $subject,
        public string $message,
        public array $attachments = [],
        public ?string $batchId = null,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $mailer = null
    ) {}

    public function handle(): void
    {
        $lead = Lead::withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            Log::error('Lead not found for bulk email', ['lead_id' => $this->leadId]);

            return;
        }

        $emailAddress = trim((string) $lead->email);

        if ($emailAddress === '' || ! filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            $lead->update([
                'bulk_email_status' => 'failed',
                'bulk_email_error' => 'Invalid or missing email address',
            ]);

            return;
        }

        try {
            $mailer = $this->mailer ?: config('mail.default', 'smtp');
            [$fromAddress, $fromName] = $this->alignedFromIdentity($mailer);

            Mail::mailer($mailer)->to($emailAddress)->send(
                new BulkEmailMail(
                    $this->subject,
                    $this->message,
                    $this->attachments,
                    $fromAddress,
                    $fromName
                )
            );

            $lead->update([
                'bulk_email_status' => 'sent',
                'bulk_email_sent_at' => now(),
                'bulk_email_error' => null,
            ]);
        } catch (\Exception $e) {
            $lead->update([
                'bulk_email_status' => 'failed',
                'bulk_email_error' => $e->getMessage(),
            ]);

            Log::error('Failed to send bulk email to lead', [
                'lead_id' => $this->leadId,
                'to' => $emailAddress,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->cleanupBatch();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function alignedFromIdentity(string $mailer): array
    {
        $fromAddress = trim((string) ($this->fromAddress ?: config('mail.from.address')));
        $fromName = trim((string) ($this->fromName ?: config('mail.from.name', $fromAddress)));
        $smtpUser = trim((string) config("mail.mailers.{$mailer}.username", ''));

        $forceAlign = str_starts_with($mailer, 'aruba_');
        if ($forceAlign && $smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)
            && strcasecmp($fromAddress, $smtpUser) !== 0) {
            $fromAddress = $smtpUser;
        }

        return [$fromAddress, $fromName !== '' ? $fromName : $fromAddress];
    }

    private function cleanupBatch(): void
    {
        if (! $this->batchId) {
            return;
        }

        $cacheKey = 'bulk_email:'.$this->batchId.':pending';
        $remaining = Cache::has($cacheKey) ? Cache::decrement($cacheKey) : null;

        if ($remaining !== null && $remaining <= 0) {
            Cache::forget($cacheKey);
            CleanupBulkEmailAttachmentsJob::dispatch($this->attachments, $this->batchId)
                ->delay(now()->addMinutes(15));
        }
    }
}
