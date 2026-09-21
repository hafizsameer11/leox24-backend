<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class SmsController extends Controller
{
    /**
     * Send a direct SMS message (super admin only).
     */
    public function send(Request $request)
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Only super administrators can send SMS'], 403);
        }

        $validated = $request->validate([
            'phone_number' => 'required|string|max:30',
            'message' => 'required|string|max:1600',
        ]);

        try {
            $twilioService = app(TwilioService::class);
            $result = $twilioService->sendSMS(
                $validated['phone_number'],
                $validated['message']
            );

            return response()->json([
                'message' => 'SMS accepted by Twilio for delivery',
                'accepted' => true,
                'sid' => $result['sid'],
                'status' => $result['status'],
                'delivery_tracking' => $result['delivery_tracking'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Direct SMS request failed', [
                'user_id' => $user->id,
                'to' => $this->maskPhoneNumber((string) $request->input('phone_number')),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], $e instanceof \InvalidArgumentException ? 422 : 502);
        }
    }

    /** Record Twilio's asynchronous final delivery status for support diagnostics. */
    public function statusCallback(Request $request)
    {
        if (!$this->hasValidTwilioSignature($request)) {
            Log::warning('Rejected an SMS status callback with an invalid Twilio signature', [
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
            Log::error('Twilio SMS delivery failed', $context);
        } else {
            Log::info('Twilio SMS delivery status received', $context);
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
        $normalized = preg_replace('/\D/', '', $phoneNumber) ?: '';
        return strlen($normalized) > 4
            ? '+'.substr($normalized, 0, 2).'••••'.substr($normalized, -2)
            : '••••';
    }
}
