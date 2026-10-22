<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\MidtransWebhookReconciliationService;
use JsonException;
use RuntimeException;
use Throwable;

final class MidtransWebhookController
{
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $raw = file_get_contents('php://input');

        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request body']);
            return;
        }

        try {
            $notification = json_decode(
                $raw,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        if (!is_array($notification)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid notification']);
            return;
        }

        try {
            $service = new MidtransWebhookReconciliationService();
            $service->process($notification);

            http_response_code(200);
            echo json_encode(['message' => 'Webhook processed']);
        } catch (RuntimeException $e) {
            // Error validasi notifikasi yang diketahui.
            // Error database/API juga bisa berupa RuntimeException,
            // jadi jangan menganggap semuanya sebagai notifikasi palsu.
            error_log('Midtrans webhook processing failed: ' . $e->getMessage());

            http_response_code(500);
            echo json_encode(['error' => 'Webhook processing failed']);
        } catch (Throwable $e) {
            error_log('Midtrans webhook internal error: ' . $e->getMessage());

            http_response_code(500);
            echo json_encode(['error' => 'Internal processing error']);
        }
    }
}