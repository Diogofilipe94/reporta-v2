<?php

namespace App\Observers;

use App\Models\Report;
use App\Services\NotificationService;

class ReportObserver
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function created(Report $report): void
    {
        $status = $report->status->status ?? 'pendente';
        $categoryNames = $report->categories->pluck('name')->implode(', ');

        $this->notificationService->sendToAdminsAndCurators(
            'Novo Relatório Registrado',
            "Novo relatório em '{$report->location}' registrado na(s) categoria(s): {$categoryNames}",
            [
                'type' => 'new_report',
                'report_id' => $report->id,
                'status' => $status
            ]
        );
    }

    public function updated(Report $report): void
    {
        \Log::info('Report updated observer triggered', ['report_id' => $report->id]);

        // Verificar se o status foi alterado
        if ($report->isDirty('status_id')) {
            \Log::info('Status was changed', [
                'old_status_id' => $report->getOriginal('status_id'),
                'new_status_id' => $report->status_id
            ]);

            $oldStatusId = $report->getOriginal('status_id');
            $oldStatus = \App\Models\Status::find($oldStatusId);
            $newStatus = $report->status;

            // Se não conseguirmos obter os status, não enviamos notificação
            if (!$oldStatus || !$newStatus) {
                \Log::warning('Could not find status objects', [
                    'old_status_id' => $oldStatusId,
                    'new_status_id' => $report->status_id
                ]);
                return;
            }

            \Log::info('Attempting to send notification', [
                'user_id' => $report->user_id,
                'old_status' => $oldStatus->status,
                'new_status' => $newStatus->status
            ]);

            $result = $this->notificationService->sendToUser(
                $report->user,
                'Atualização de Status',
                "Seu relatório em '{$report->location}' foi atualizado de '{$oldStatus->status}' para '{$newStatus->status}'",
                [
                    'type' => 'status_update',
                    'report_id' => $report->id,
                    'old_status' => $oldStatus->status,
                    'new_status' => $newStatus->status
                ]
            );

            \Log::info('Notification send result', $result);
        }
    }
}
