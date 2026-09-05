<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BulkEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Email subject
     */
    private string $emailSubject;

    /**
     * Email message content
     */
    private string $emailMessage;

    /**
     * Attachment metadata
     */
    private array $attachmentMeta;

    /**
     * Sender address
     */
    private ?string $fromAddress;

    /**
     * Sender display name
     */
    private ?string $fromName;

    /**
     * Create a new message instance.
     */
    public function __construct(
        string $subject,
        string $message,
        array $attachments = [],
        ?string $fromAddress = null,
        ?string $fromName = null
    ) {
        $this->emailSubject = $subject;
        $this->emailMessage = $message;
        $this->attachmentMeta = $attachments;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        if ($this->fromAddress) {
            return new Envelope(
                subject: $this->emailSubject,
                from: new Address(
                    $this->fromAddress,
                    $this->fromName ?? $this->fromAddress
                ),
            );
        }

        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->emailMessage)),
        );
    }

    public function attachments(): array
    {
        $mailAttachments = [];

        foreach ($this->attachmentMeta as $attachment) {
            if (empty($attachment['path'])) {
                continue;
            }

            $absolutePath = Storage::disk('local')->path($attachment['path']);
            if (!is_file($absolutePath)) {
                Log::warning('Bulk email attachment missing', [
                    'path' => $attachment['path'],
                ]);
                continue;
            }

            $item = Attachment::fromPath($absolutePath);

            if (!empty($attachment['name'])) {
                $item = $item->as($attachment['name']);
            }

            if (!empty($attachment['mime'])) {
                $item = $item->withMime($attachment['mime']);
            }

            $mailAttachments[] = $item;
        }

        return $mailAttachments;
    }
}
