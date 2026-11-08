<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupBulkEmailAttachmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public array $attachments = [],
        public ?string $batchId = null
    ) {}

    public function handle(): void
    {
        foreach ($this->attachments as $attachment) {
            if (empty($attachment['path'])) {
                continue;
            }

            try {
                Storage::disk('local')->delete($attachment['path']);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete bulk email attachment', [
                    'path' => $attachment['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Remove empty batch directory when possible.
        if ($this->batchId) {
            $dir = 'bulk-email/'.$this->batchId;
            try {
                if (Storage::disk('local')->exists($dir)) {
                    Storage::disk('local')->deleteDirectory($dir);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete bulk email batch directory', [
                    'dir' => $dir,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
