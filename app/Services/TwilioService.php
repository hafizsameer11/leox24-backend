<?php

namespace App\Services;

use App\Models\Call;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

class TwilioService
{
    private ?Client $client;
    private string $phoneNumber;
    private string $whatsAppNumber;
    private ?string $verifiedSmsSender = null;

    public function __construct()
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $this->phoneNumber = (string) config('services.twilio.phone_number', '');
        $this->whatsAppNumber = (string) config('services.twilio.whatsapp_number', $this->phoneNumber);

        if (empty($accountSid) || empty($authToken)) {
            Log::warning('Twilio credentials not configured');
            $this->client = null;
        } else {
            $this->client = new Client($accountSid, $authToken);
        }
    }

    /**
     * Initiate a direct call to the customer.
     * This calls the customer directly (no agent in between).
     * 
     * @param Call $call The call record
     * @param string $customerPhoneNumber The customer's phone number to dial
     * @param string|null $fromPhoneNumber Optional from number (defaults to Twilio number)
     */
    public function initiateCall(Call $call, string $customerPhoneNumber, ?string $fromPhoneNumber = null): array
    {
        if (!$this->client) {
            throw new \RuntimeException('Twilio is not configured. Please set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_PHONE_NUMBER in your .env file.');
        }

        // Store customer phone in call record
        $call->update(['contact_phone' => $customerPhoneNumber]);

        $fromNumber = $fromPhoneNumber ?? $this->phoneNumber;
        
        // Get webhook URL - prefer TWILIO_WEBHOOK_URL, fallback to APP_URL
        $webhookBaseUrl = config('services.twilio.webhook_url') 
            ?: config('app.url', env('APP_URL', 'http://localhost'));
        
        // Validate URL is publicly accessible (not localhost for production)
        if (str_contains($webhookBaseUrl, 'localhost') || str_contains($webhookBaseUrl, '127.0.0.1')) {
            throw new \RuntimeException(
                'Twilio requires a publicly accessible URL. Localhost URLs are not allowed. ' .
                'For local development, please use ngrok or set TWILIO_WEBHOOK_URL in your .env file to a public URL. ' .
                'Example: TWILIO_WEBHOOK_URL=https://your-ngrok-url.ngrok.io'
            );
        }
        
        // Ensure URL uses HTTPS (required by Twilio)
        if (str_starts_with($webhookBaseUrl, 'http://') && !str_contains($webhookBaseUrl, 'localhost')) {
            $webhookBaseUrl = str_replace('http://', 'https://', $webhookBaseUrl);
        }
        
        // Remove trailing slash and trim any whitespace
        $webhookBaseUrl = trim(rtrim($webhookBaseUrl, '/'));

        // Webhook URL for call status updates
        $statusCallbackUrl = $webhookBaseUrl . '/api/calls/twilio/status';

        // Normalize customer phone number to E.164 format
        $customerPhone = preg_replace('/[^0-9+]/', '', $customerPhoneNumber);
        if (!str_starts_with($customerPhone, '+')) {
            $customerPhone = '+' . $customerPhone;
        }

        Log::info('Twilio direct call configuration', [
            'call_id' => $call->id,
            'customer_phone' => $customerPhone,
            'from_number' => $fromNumber,
            'status_callback_url' => $statusCallbackUrl,
        ]);

        try {
            // Verify Twilio number (Caller ID) is active before making call
            try {
                $phoneNumbers = $this->client->incomingPhoneNumbers->read([
                    'phoneNumber' => $fromNumber
                ], 1);
                
                if (empty($phoneNumbers)) {
                    Log::warning('Twilio number (Caller ID) not found or may be released', [
                        'phone_number' => $fromNumber,
                        'message' => 'This number cannot be used as Caller ID. Please purchase an active number.',
                    ]);
                    throw new \RuntimeException(
                        "Twilio number {$fromNumber} is not active or has been released. " .
                        "Please purchase an active number from Twilio Console and update TWILIO_PHONE_NUMBER in your .env file."
                    );
                } else {
                    $phoneNumber = $phoneNumbers[0];
                    // Capabilities is an object, not an array
                    $capabilities = $phoneNumber->capabilities;
                    Log::info('Twilio number (Caller ID) verified', [
                        'phone_number' => $fromNumber,
                        'status' => $phoneNumber->status,
                        'capabilities' => [
                            'voice' => $capabilities->voice ?? false,
                            'sms' => $capabilities->sms ?? false,
                        ],
                    ]);
                }
            } catch (TwilioException $e) {
                if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'released')) {
                    throw new \RuntimeException(
                        "Twilio number {$fromNumber} is not active or has been released. " .
                        "Please purchase an active number from Twilio Console and update TWILIO_PHONE_NUMBER in your .env file."
                    );
                }
                Log::warning('Could not verify Twilio number status', [
                    'phone_number' => $fromNumber,
                    'error' => $e->getMessage(),
                ]);
                // Continue anyway - Twilio will return an error if number is invalid
            }
            
            // DIRECT CALL: Call the customer directly
            // Twilio requires either 'url' or 'twiml' parameter for call instructions
            // For direct calls, we use a simple TwiML that connects immediately
            // The 'to' parameter is the customer's phone - they receive the call
            // The 'from' parameter is your Caller ID - what appears on customer's phone
            
            // Simple TwiML for direct connection (no agent, no dialing - just connect)
            // Using <Say> to announce, then <Hangup> - but actually we want to connect
            // For a direct call, we can use a minimal TwiML or point to a URL
            // Since we want direct connection, we'll use a simple TwiML endpoint URL
            $twimlUrl = $webhookBaseUrl . '/api/calls/twilio/twiml';
            
            Log::info('Creating direct call to customer', [
                'to' => $customerPhone,
                'from' => $fromNumber,
                'twiml_url' => $twimlUrl,
            ]);
            
            $twilioCall = $this->client->calls->create(
                $customerPhone,  // To: Customer's phone (they receive the call)
                $fromNumber,      // From: Your Twilio number (Caller ID)
                [
                    'url' => $twimlUrl,  // URL to TwiML endpoint - required by Twilio
                    'statusCallback' => $statusCallbackUrl,
                    'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
                    'statusCallbackMethod' => 'POST',
                    'record' => false, // Set to false to avoid recording issues
                ]
            );

            // Update call record with Twilio SID
            $call->update([
                'status' => 'in_progress',
                'started_at' => now(),
                'notes' => ($call->notes ?? '') . "\nTwilio Call SID: " . $twilioCall->sid,
            ]);

            return [
                'success' => true,
                'call_sid' => $twilioCall->sid,
                'status' => $twilioCall->status,
                'message' => 'Call initiated successfully',
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio call initiation failed', [
                'call_id' => $call->id,
                'customer_phone' => $customerPhone,
                'error' => $e->getMessage(),
            ]);

            // Update call status to failed
            $call->update([
                'status' => 'cancelled',
                'notes' => ($call->notes ?? '') . "\nCall failed: " . $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to initiate call: ' . $e->getMessage());
        }
    }

    /**
     * Get call status from Twilio.
     */
    public function getCallStatus(string $callSid): ?array
    {
        if (!$this->client) {
            return null;
        }

        try {
            $call = $this->client->calls($callSid)->fetch();
            
            return [
                'sid' => $call->sid,
                'status' => $call->status,
                'duration' => $call->duration,
                'start_time' => $call->startTime?->format('Y-m-d H:i:s'),
                'end_time' => $call->endTime?->format('Y-m-d H:i:s'),
            ];
        } catch (TwilioException $e) {
            Log::error('Failed to fetch Twilio call status', [
                'call_sid' => $callSid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send an SMS message.
     */
    public function sendSMS(string $to, string $message, ?array $mediaUrls = null): array
    {
        if (!$this->client) {
            throw new \RuntimeException('Twilio is not configured. Set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_PHONE_NUMBER.');
        }

        $to = $this->normalizeE164PhoneNumber($to, 'Recipient phone number');
        $from = $this->verifiedSmsSender();
        $statusCallbackUrl = $this->smsStatusCallbackUrl();

        try {
            $params = [
                'from' => $from,
                'body' => $message,
            ];

            if ($statusCallbackUrl) {
                $params['statusCallback'] = $statusCallbackUrl;
            }

            Log::info('Submitting SMS to Twilio', [
                'to' => $this->maskPhoneNumber($to),
                'from' => $this->maskPhoneNumber($from),
                'message_length' => mb_strlen($message),
                'delivery_tracking' => (bool) $statusCallbackUrl,
            ]);

            // Add media URLs if provided (for MMS)
            if (!empty($mediaUrls) && is_array($mediaUrls)) {
                // Filter and validate media URLs - Twilio requires publicly accessible URLs
                $validMediaUrls = [];
                $invalidUrls = [];
                
                foreach ($mediaUrls as $url) {
                    // Check if URL is localhost or not publicly accessible
                    if (str_contains($url, 'localhost') || 
                        str_contains($url, '127.0.0.1') || 
                        str_contains($url, '::1') ||
                        !filter_var($url, FILTER_VALIDATE_URL)) {
                        $invalidUrls[] = $url;
                        Log::warning('Skipping invalid media URL for SMS', [
                            'url' => $url,
                            'reason' => 'Not publicly accessible (localhost)',
                        ]);
                        continue;
                    }
                    
                    // Ensure URL uses HTTPS (preferred) or HTTP
                    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                        $invalidUrls[] = $url;
                        Log::warning('Skipping invalid media URL for SMS', [
                            'url' => $url,
                            'reason' => 'Invalid protocol',
                        ]);
                        continue;
                    }
                    
                    $validMediaUrls[] = $url;
                }
                
                if (!empty($validMediaUrls)) {
                    $params['mediaUrl'] = $validMediaUrls;
                }
                
                // If there were invalid URLs but no valid ones, throw an error
                if (!empty($invalidUrls) && empty($validMediaUrls)) {
                    throw new \RuntimeException(
                        'All media URLs are invalid. Twilio requires publicly accessible URLs (not localhost). ' .
                        'Please use URLs from a live server or skip media attachments for local development.'
                    );
                }
                
                // Log if some URLs were filtered out
                if (!empty($invalidUrls) && !empty($validMediaUrls)) {
                    Log::info('Some media URLs were filtered out for SMS', [
                        'valid_count' => count($validMediaUrls),
                        'invalid_count' => count($invalidUrls),
                        'invalid_urls' => $invalidUrls,
                    ]);
                }
            }

            $msg = $this->client->messages->create($to, $params);
            $status = strtolower((string) $msg->status);
            $acceptedStatuses = ['accepted', 'queued', 'scheduled', 'sending', 'sent'];

            if (!$msg->sid || !in_array($status, $acceptedStatuses, true)) {
                Log::error('Twilio did not accept SMS for delivery', [
                    'sid' => $msg->sid,
                    'status' => $status ?: 'unknown',
                    'to' => $this->maskPhoneNumber($to),
                    'from' => $this->maskPhoneNumber($from),
                    'error_code' => $msg->errorCode,
                    'error_message' => $msg->errorMessage,
                ]);

                throw new \RuntimeException(
                    'Twilio did not accept this SMS for delivery'.($msg->errorCode ? " (code {$msg->errorCode})" : '').'.'
                );
            }

            Log::info('Twilio accepted SMS for delivery', [
                'sid' => $msg->sid,
                'status' => $status,
                'to' => $this->maskPhoneNumber($to),
                'from' => $this->maskPhoneNumber($from),
                'delivery_tracking' => (bool) $statusCallbackUrl,
            ]);

            return [
                'success' => true,
                'sid' => $msg->sid,
                'status' => $status,
                'delivery_tracking' => (bool) $statusCallbackUrl,
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio SMS failed', [
                'to' => $this->maskPhoneNumber($to),
                'from' => $this->maskPhoneNumber($from),
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Twilio rejected the SMS request (code '.$e->getCode().'): '.$e->getMessage());
        }
    }

    /** Validate E.164 rather than silently guessing a country code. */
    private function normalizeE164PhoneNumber(string $phoneNumber, string $fieldName): string
    {
        $normalized = trim($phoneNumber);
        $normalized = preg_replace('/[\s().-]/', '', $normalized) ?? '';

        if (str_starts_with($normalized, '00')) {
            $normalized = '+'.substr($normalized, 2);
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $normalized) !== 1) {
            throw new \InvalidArgumentException($fieldName.' must be in international E.164 format, for example +393331234567.');
        }

        return $normalized;
    }

    /** Confirm the configured sender belongs to this account and can send SMS. */
    private function verifiedSmsSender(): string
    {
        if ($this->verifiedSmsSender) {
            return $this->verifiedSmsSender;
        }

        $from = $this->normalizeE164PhoneNumber($this->phoneNumber, 'TWILIO_PHONE_NUMBER');

        try {
            $phoneNumbers = $this->client->incomingPhoneNumbers->read(['phoneNumber' => $from], 1);
            if (empty($phoneNumbers)) {
                throw new \RuntimeException('TWILIO_PHONE_NUMBER is not an active phone number in this Twilio account.');
            }

            $phoneNumber = $phoneNumbers[0];
            $capabilities = $phoneNumber->capabilities;
            if (!($capabilities->sms ?? false)) {
                throw new \RuntimeException('TWILIO_PHONE_NUMBER is active but is not SMS-capable. Use an SMS-capable Twilio number.');
            }

            $this->verifiedSmsSender = $from;
            Log::info('Verified Twilio SMS sender', [
                'from' => $this->maskPhoneNumber($from),
                'status' => $phoneNumber->status,
                'sms_capable' => true,
            ]);

            return $this->verifiedSmsSender;
        } catch (TwilioException $e) {
            Log::error('Unable to verify Twilio SMS sender', [
                'from' => $this->maskPhoneNumber($from),
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Twilio could not verify TWILIO_PHONE_NUMBER (code '.$e->getCode().'): '.$e->getMessage());
        }
    }

    /** Configure delivery callbacks only when a public, valid callback URL exists. */
    private function smsStatusCallbackUrl(): ?string
    {
        $callbackUrl = trim((string) config('services.twilio.sms_status_callback_url', ''));
        if ($callbackUrl === '') {
            $baseUrl = trim((string) (config('services.twilio.webhook_url') ?: config('app.url', '')));
            if ($baseUrl !== '') {
                $callbackUrl = rtrim($baseUrl, '/').'/api/sms/twilio/status';
            }
        }

        if ($callbackUrl === '' || !filter_var($callbackUrl, FILTER_VALIDATE_URL)
            || str_contains($callbackUrl, 'localhost') || str_contains($callbackUrl, '127.0.0.1')) {
            Log::warning('Twilio SMS delivery callback is unavailable; accepted messages will not receive asynchronous delivery logs', [
                'callback_configured' => $callbackUrl !== '',
            ]);
            return null;
        }

        return $callbackUrl;
    }

    private function maskPhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/\D/', '', $phoneNumber) ?: '';
        return strlen($digits) > 4
            ? '+'.substr($digits, 0, 2).'••••'.substr($digits, -2)
            : '••••';
    }

    /**
     * Send a WhatsApp message.
     */
    public function sendWhatsAppMessage(
        string $to,
        string $message,
        ?array $mediaUrls = null,
        ?string $templateSid = null,
        ?array $templateVariables = null
    ): array
    {
        if (!$this->client) {
            throw new \RuntimeException('Twilio is not configured.');
        }

        // Ensure numbers are in E.164 format and prefixed with "whatsapp:"
        $to = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:' . $to;
        $from = str_starts_with($this->whatsAppNumber, 'whatsapp:') ? $this->whatsAppNumber : 'whatsapp:' . $this->whatsAppNumber;
        Log::info('WHATSAPP FROM NUMBER USED', [
            'raw_whatsapp_number' => $this->whatsAppNumber,
            'final_from' => $from
        ]);
        Log::info('TWILIO SID USED', [
            'sid' => config('services.twilio.account_sid')
        ]);

        try {
            $params = [
                'from' => $from,
            ];

            if (!empty($templateSid)) {
                $params['contentSid'] = $templateSid;

                if (!empty($templateVariables)) {
                    $params['contentVariables'] = json_encode($templateVariables, JSON_UNESCAPED_SLASHES);
                }
            } else {
                $params['body'] = $message;
            }

            // Add media URLs if provided
            if (!empty($mediaUrls) && is_array($mediaUrls)) {
                // Filter and validate media URLs - Twilio requires publicly accessible URLs
                $validMediaUrls = [];
                $invalidUrls = [];
                
                foreach ($mediaUrls as $url) {
                    // Check if URL is localhost or not publicly accessible
                    if (str_contains($url, 'localhost') || 
                        str_contains($url, '127.0.0.1') || 
                        str_contains($url, '::1') ||
                        !filter_var($url, FILTER_VALIDATE_URL)) {
                        $invalidUrls[] = $url;
                        Log::warning('Skipping invalid media URL for WhatsApp', [
                            'url' => $url,
                            'reason' => 'Not publicly accessible (localhost)',
                        ]);
                        continue;
                    }
                    
                    // Ensure URL uses HTTPS (preferred) or HTTP
                    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                        $invalidUrls[] = $url;
                        Log::warning('Skipping invalid media URL for WhatsApp', [
                            'url' => $url,
                            'reason' => 'Invalid protocol',
                        ]);
                        continue;
                    }
                    
                    $validMediaUrls[] = $url;
                }
                
                if (!empty($validMediaUrls)) {
                    $params['mediaUrl'] = $validMediaUrls;
                }
                
                // If there were invalid URLs but no valid ones, throw an error
                if (!empty($invalidUrls) && empty($validMediaUrls)) {
                    throw new \RuntimeException(
                        'All media URLs are invalid. Twilio requires publicly accessible URLs (not localhost). ' .
                        'Please use URLs from a live server or skip media attachments for local development.'
                    );
                }
                
                // Log if some URLs were filtered out
                if (!empty($invalidUrls) && !empty($validMediaUrls)) {
                    Log::info('Some media URLs were filtered out for WhatsApp', [
                        'valid_count' => count($validMediaUrls),
                        'invalid_count' => count($invalidUrls),
                        'invalid_urls' => $invalidUrls,
                    ]);
                }
            }

            $msg = $this->client->messages->create($to, $params);

            return [
                'success' => true,
                'sid' => $msg->sid,
                'status' => $msg->status,
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio WhatsApp message failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to send WhatsApp message: ' . $e->getMessage());
        }
    }
}
