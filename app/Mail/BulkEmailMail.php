<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BulkEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    private string $emailSubject;

    private string $emailMessage;

    private array $attachmentMeta;

    private ?string $fromAddress;

    private ?string $fromName;

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

    public function envelope(): Envelope
    {
        $fromAddress = $this->resolvedFromAddress();
        $fromName = $this->fromName ?: $fromAddress;

        return new Envelope(
            subject: $this->emailSubject,
            from: new Address($fromAddress, $fromName),
            replyTo: [
                new Address($fromAddress, $fromName),
            ],
        );
    }

    public function headers(): Headers
    {
        // Keep headers minimal / transactional — no marketing List-Unsubscribe wording.
        return new Headers(
            text: [
                'X-Mailer' => 'LEO24 CRM',
                'Auto-Submitted' => 'auto-generated',
            ],
        );
    }

    public function content(): Content
    {
        $fromAddress = $this->resolvedFromAddress();
        $fromName = $this->fromName ?: $fromAddress;
        $bodyText = $this->plainBody();
        $bodyHtml = $this->safeBodyHtml();

        return new Content(
            html: 'emails.bulk-email',
            text: 'emails.bulk-email-text',
            with: [
                'subject' => $this->emailSubject,
                'bodyText' => $bodyText,
                'bodyHtml' => $bodyHtml,
                'fromAddress' => $fromAddress,
                'fromName' => $fromName,
            ],
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
            if (! is_file($absolutePath)) {
                Log::warning('Bulk email attachment missing', [
                    'path' => $attachment['path'],
                ]);
                continue;
            }

            $item = Attachment::fromPath($absolutePath);

            if (! empty($attachment['name'])) {
                $item = $item->as($attachment['name']);
            }

            if (! empty($attachment['mime'])) {
                $item = $item->withMime($attachment['mime']);
            }

            $mailAttachments[] = $item;
        }

        return $mailAttachments;
    }

    private function resolvedFromAddress(): string
    {
        if ($this->fromAddress && filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            return $this->fromAddress;
        }

        return (string) config('mail.from.address');
    }

    private function plainBody(): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $this->emailMessage);
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*p\s*>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*div\s*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*li\s*>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * Allow a small HTML subset from the CRM rich editor (links + basic formatting).
     * Plain text is escaped and converted with nl2br.
     */
    private function safeBodyHtml(): string
    {
        $raw = trim($this->emailMessage);
        if ($raw === '') {
            return '';
        }

        if (! preg_match('/<\s*(?:p|br|b|strong|i|em|u|a|ul|ol|li|font|span|div)\b/i', $raw)) {
            return nl2br(e($raw));
        }

        $allowed = '<p><br><b><strong><i><em><u><a><ul><ol><li><font><span><div>';
        $html = strip_tags($raw, $allowed);

        // Drop event handlers / javascript: URLs from remaining tags.
        $html = preg_replace('/\s+on\w+\s*=\s*(["\']).*?\1/iu', '', $html) ?? $html;
        $html = preg_replace_callback(
            '/<\s*a\b([^>]*)>/iu',
            static function (array $m): string {
                $attrs = $m[1];
                $href = '';
                if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/iu', $attrs, $hm)) {
                    $candidate = trim(html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (preg_match('#^(https?://|mailto:)#i', $candidate)) {
                        $href = htmlspecialchars($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                if ($href === '') {
                    return '<a>';
                }

                return '<a href="'.$href.'" target="_blank" rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;

        // Keep only size/face/color on <font>, style size/color/weight on <span>/<div>/<p>
        $html = preg_replace_callback(
            '/<\s*(font|span|div|p)\b([^>]*)>/iu',
            static function (array $m): string {
                $tag = strtolower($m[1]);
                $attrs = $m[2];
                $kept = [];

                if ($tag === 'font') {
                    foreach (['size', 'face', 'color'] as $name) {
                        if (preg_match('/\b'.$name.'\s*=\s*(["\'])(.*?)\1/iu', $attrs, $am)) {
                            $kept[] = $name.'="'.htmlspecialchars($am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8').'"';
                        }
                    }
                } else {
                    if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/iu', $attrs, $sm)) {
                        $style = $sm[2];
                        $safeParts = [];
                        if (preg_match('/font-size\s*:\s*[^;]+/i', $style, $fm)) {
                            $safeParts[] = trim($fm[0]);
                        }
                        if (preg_match('/color\s*:\s*[^;]+/i', $style, $cm)) {
                            $safeParts[] = trim($cm[0]);
                        }
                        if (preg_match('/font-weight\s*:\s*[^;]+/i', $style, $wm)) {
                            $safeParts[] = trim($wm[0]);
                        }
                        if (preg_match('/font-style\s*:\s*[^;]+/i', $style, $im)) {
                            $safeParts[] = trim($im[0]);
                        }
                        if (preg_match('/text-decoration\s*:\s*[^;]+/i', $style, $tm)) {
                            $safeParts[] = trim($tm[0]);
                        }
                        if ($safeParts !== []) {
                            $kept[] = 'style="'.htmlspecialchars(implode('; ', $safeParts), ENT_QUOTES | ENT_HTML5, 'UTF-8').'"';
                        }
                    }
                }

                return $kept === [] ? '<'.$tag.'>' : '<'.$tag.' '.implode(' ', $kept).'>';
            },
            $html
        ) ?? $html;

        return $html;
    }
}
