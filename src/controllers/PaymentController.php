<?php
/**
 * UniGo - passenger payment history.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Models\PaymentModel;

final class PaymentController extends Controller
{
    /** /payments - the signed-in user's ledger. */
    public function index(): void
    {
        $this->requireLogin();

        $page = max(1, $this->request->int('page', 1));
        $result = $this->safe(
            static fn () => (new PaymentModel())->paginatePayments(
                ['user_id' => (int) Auth::id()],
                $page,
                15
            ),
            ['items' => [], 'paginator' => null]
        );

        $this->view('payments/index', [
            'title'     => 'Payments - ' . app_name(),
            'pageTitle' => 'Payments',
            'pageSub'   => 'Your transaction history',
            'payments'  => $result['items'] ?? [],
            'paginator' => $result['paginator'] ?? null,
        ], 'layouts/app');
    }

    /** /payments/{id} - a single receipt. */
    public function show(string $id): void
    {
        $this->requireLogin();
        $paymentId = (int) $id;

        $payment = $this->safe(static fn () => (new PaymentModel())->findDetailed($paymentId), null);
        if (!$payment) {
            $this->notFound();
        }
        if ((int) ($payment['user_id'] ?? 0) !== (int) Auth::id() && !Auth::isAdmin()) {
            Auth::deny('That receipt does not belong to you.');
        }

        $this->view('payments/show', [
            'title'     => 'Receipt ' . ($payment['reference'] ?? '') . ' - ' . app_name(),
            'pageTitle' => 'Receipt',
            'pageSub'   => (string) ($payment['reference'] ?? ''),
            'payment'   => $payment,
        ], 'layouts/app');
    }

    private function notFound(): void
    {
        \App\Core\Http::status(404);
        $this->view('errors/404', [
            'title'   => 'Receipt not found',
            'heading' => 'Receipt not found',
            'message' => 'That payment does not exist, or it is not yours.',
        ], 'layouts/app');
        exit;
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Payment data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
