<?php

namespace App\Jobs;

use App\Models\Email;
use App\Mail\BulkEmailMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SendBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $emailId,
        public string $subject,
        public string $message,
        public array $attachments = [],
        public ?string $batchId = null,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $mailer = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $email = Email::find($this->emailId);
        
        if (!$email) {
            Log::error('Email record not found', ['email_id' => $this->emailId]);
            return;
        }

        // Extract email address from row_data_json
        $emailAddress = $email->email_address;
        
        if (empty($emailAddress) || !filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            $email->update([
                'status' => 'failed',
                'error_message' => 'Invalid or missing email address',
            ]);
            Log::error('Invalid email address', [
                'email_id' => $this->emailId,
                'row_data' => $email->row_data_json
            ]);
            return;
        }

        try {
            Log::info('Bulk email attachments', [
                'email_id' => $this->emailId,
                'count' => count($this->attachments),
                'paths' => array_values(array_filter(array_map(
                    fn ($item) => $item['path'] ?? null,
                    $this->attachments
                ))),
            ]);

            // Send via the sender's SMTP mailer (Aruba brand mailbox or default Gmail).
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

            // Update status to sent
            $email->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
            ]);

            Log::info('Bulk email sent successfully', [
                'email_id' => $this->emailId,
                'to' => $emailAddress
            ]);

        } catch (\Exception $e) {
            // Update status to failed
            $email->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to send bulk email', [
                'email_id' => $this->emailId,
                'to' => $emailAddress,
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed
            throw $e;
        } finally {
            $this->cleanupBatch();
        }
    }

    /**
     * Keep From aligned with the authenticated SMTP mailbox to avoid
     * spoofing signals that push mail into spam.
     *
     * @return array{0: string, 1: string}
     */
    private function alignedFromIdentity(string $mailer): array
    {
        $fromAddress = trim((string) ($this->fromAddress ?: config('mail.from.address')));
        $fromName = trim((string) ($this->fromName ?: config('mail.from.name', $fromAddress)));
        $smtpUser = trim((string) config("mail.mailers.{$mailer}.username", ''));

        // Aruba brand mailboxes must authenticate as themselves.
        // Default Gmail smtp may use a configured From (e.g. noreply@optyshop.com)
        // if that alias is allowed in the Gmail account.
        $forceAlign = str_starts_with($mailer, 'aruba_');
        if ($forceAlign && $smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)
            && strcasecmp($fromAddress, $smtpUser) !== 0) {
            Log::warning('Bulk email From overridden to match SMTP username', [
                'mailer' => $mailer,
                'requested_from' => $fromAddress,
                'smtp_username' => $smtpUser,
            ]);
            $fromAddress = $smtpUser;
        }

        return [$fromAddress, $fromName !== '' ? $fromName : $fromAddress];
    }

    private function cleanupBatch(): void
    {
        if (!$this->batchId) {
            return;
        }

        $cacheKey = 'bulk_email:' . $this->batchId . ':pending';
        $remaining = Cache::has($cacheKey) ? Cache::decrement($cacheKey) : null;

        // Delay deletion so parallel workers / retries still find the files.
        // Immediate delete caused "Bulk email attachment missing" under load.
        if ($remaining !== null && $remaining <= 0) {
            Cache::forget($cacheKey);
            CleanupBulkEmailAttachmentsJob::dispatch($this->attachments, $this->batchId)
                ->delay(now()->addMinutes(15));
        }
    }
}
