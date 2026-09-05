<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TwilioService;
use Illuminate\Http\Request;

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
                'message' => 'SMS sent successfully',
                'sid' => $result['sid'],
                'status' => $result['status'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send SMS: ' . $e->getMessage(),
            ], 500);
        }
    }
}
