<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class CommunicationController extends Controller
{

    public function __construct(
        private TwilioService $twilioService
    ) {}

    /**
     * Send a WhatsApp message.
     */
    public function sendWhatsApp(Request $request)
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Only super administrators can send WhatsApp messages'], 403);
        }

        $validated = $request->validate([
            'to' => 'required|string|max:30',
            'message' => 'required_without:template_sid|nullable|string|max:4096',
            'template_sid' => 'required_without:message|nullable|string',
            'template_variables' => 'nullable|array',
            'media_urls' => 'nullable|array',
        ]);

        try {
            $result = $this->twilioService->sendWhatsAppMessage(
                $validated['to'],
                $validated['message'] ?? '',
                $validated['media_urls'] ?? null,
                $validated['template_sid'] ?? null,
                $validated['template_variables'] ?? null
            );

            return response()->json([
                'message' => 'WhatsApp message accepted by Twilio for delivery',
                'accepted' => true,
                'sid' => $result['sid'],
                'status' => $result['status'],
                'delivery_tracking' => $result['delivery_tracking'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Direct WhatsApp request failed', [
                'user_id' => $user->id,
                'to' => $this->maskPhoneNumber((string) $request->input('to')),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], $e instanceof \InvalidArgumentException ? 422 : 502);
        }
    }

    /** Record Twilio's final WhatsApp delivery status for support diagnostics. */
    public function statusCallback(Request $request)
    {
        if (! $this->hasValidTwilioSignature($request)) {
            Log::warning('Rejected a WhatsApp status callback with an invalid Twilio signature', [
                'ip' => $request->ip(),
                'message_sid' => $request->input('MessageSid'),
            ]);

            return response()->json(['message' => 'Invalid webhook signature'], 403);
        }

        $status = strtolower((string) $request->input('MessageStatus', 'unknown'));
        $context = [
            'message_sid' => $request->input('MessageSid'),
            'status' => $status,
            'to' => $this->maskPhoneNumber((string) $request->input('To')),
            'from' => $this->maskPhoneNumber((string) $request->input('From')),
            'error_code' => $request->input('ErrorCode'),
            'error_message' => $request->input('ErrorMessage'),
        ];

        if (in_array($status, ['failed', 'undelivered'], true)) {
            Log::error('Twilio WhatsApp delivery failed', $context);
        } else {
            Log::info('Twilio WhatsApp delivery status received', $context);
        }

        return response()->noContent();
    }

    private function hasValidTwilioSignature(Request $request): bool
    {
        $authToken = (string) config('services.twilio.auth_token', '');
        $signature = (string) $request->header('X-Twilio-Signature', '');

        if ($authToken === '' || $signature === '') {
            return false;
        }

        return (new RequestValidator($authToken))->validate(
            $request->fullUrl(),
            $request->all(),
            $signature,
        );
    }

    private function maskPhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/\D/', '', $phoneNumber) ?: '';

        return strlen($digits) > 4
            ? '+'.substr($digits, 0, 2).'••••'.substr($digits, -2)
            : '••••';
    }
}
