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
        public ?string $fromName = null
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

            // Send email using Laravel Mailable
            Mail::to($emailAddress)->send(
                new BulkEmailMail(
                    $this->subject,
                    $this->message,
                    $this->attachments,
                    $this->fromAddress,
                    $this->fromName
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

    private function cleanupBatch(): void
    {
        if (!$this->batchId) {
            return;
        }

        $cacheKey = 'bulk_email:' . $this->batchId . ':pending';
        $remaining = Cache::has($cacheKey) ? Cache::decrement($cacheKey) : null;

        if ($remaining !== null && $remaining <= 0) {
            foreach ($this->attachments as $attachment) {
                if (!empty($attachment['path'])) {
                    Storage::disk('local')->delete($attachment['path']);
                }
            }
            Cache::forget($cacheKey);
        }
    }
}
