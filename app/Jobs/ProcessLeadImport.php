<?php

namespace App\Jobs;

use App\Http\Controllers\Api\LeadController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessLeadImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    // A timed-out import is cleaned up and restarted by the worker instead of
    // inserting the same spreadsheet rows more than once.
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $leadImportId)
    {
        $this->onQueue('lead-import');
    }

    public function handle(LeadController $leadController): void
    {
        $leadController->processQueuedImport($this->leadImportId);
    }

    public function failed(Throwable $exception): void
    {
        app(LeadController::class)->markQueuedImportFailed($this->leadImportId, $exception);
    }
}
