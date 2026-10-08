<?php

namespace App\Http\Controllers;

use App\Models\TelegramPayment;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $payments = TelegramPayment::query()
            ->with(['student.grade'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        $payments->getCollection()->makeHidden('receipt_file_id');

        return response()->json($payments);
    }

    public function receipt(int $id, TelegramClient $telegram): Response
    {
        $payment = TelegramPayment::findOrFail($id);
        $file = $telegram->downloadFile($payment->receipt_file_id);
        abort_if($file === null, 404, 'Receipt preview is unavailable while Telegram is in mock mode.');

        abort_unless(str_starts_with(strtolower($file['mime']), 'image/'), 415, 'The receipt file is not an image.');

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(int $id, TelegramClient $telegram): JsonResponse
    {
        $payment = TelegramPayment::with('student')->findOrFail($id);
        abort_if($payment->status !== 'pending', 409, 'This receipt has already been reviewed.');

        [$payment, $validUntil] = DB::transaction(function () use ($id) {
                $payment = TelegramPayment::with('student')->lockForUpdate()->findOrFail($id);
                abort_if($payment->status !== 'pending', 409, 'This receipt has already been reviewed.');
                $student = $payment->student;
                $startsAt = $student->activated_until?->isFuture() ? $student->activated_until : now();
                $validUntil = $startsAt->copy()->addDays(30);

                $payment->update([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                    'valid_until' => $validUntil,
                    'review_reason' => null,
                ]);
                $student->update(['activated_until' => $validUntil]);

                return [$payment->fresh('student.grade'), $validUntil];
        });

        $telegram->sendMessage(
            (int) $payment->student->telegram_id,
            '✅ Your payment was approved. Your account is active until <b>'.e($validUntil->timezone('Africa/Addis_Ababa')->format('M j, Y')).'</b>.'
        );

        $payment->makeHidden('receipt_file_id');

        return response()->json(['payment' => $payment, 'activated_until' => $validUntil]);
    }

    public function reject(Request $request, int $id, TelegramClient $telegram): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);

        $payment = DB::transaction(function () use ($id, $data) {
            $payment = TelegramPayment::with('student')->lockForUpdate()->findOrFail($id);
            abort_if($payment->status !== 'pending', 409, 'This receipt has already been reviewed.');
            $payment->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
                'review_reason' => $data['reason'],
            ]);

            return $payment->fresh('student.grade');
        });

        $telegram->sendMessage(
            (int) $payment->student->telegram_id,
            '❌ We could not approve your receipt. '.e($payment->review_reason).' Please send a corrected receipt or contact support.'
        );

        $payment->makeHidden('receipt_file_id');

        return response()->json(['payment' => $payment]);
    }
}
