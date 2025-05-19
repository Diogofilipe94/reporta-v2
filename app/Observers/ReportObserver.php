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

    /**
     * Handle the Report "created" event.
     */
    public function created(Report $report): void
    {
        // Opcionalmente, notifique administradores/curadores sobre novos relatórios
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

    /**
     * Handle the Report "updated" event.
     */
    public function updated(Report $report): void
    {
        // Verificar se o status foi alterado
        if ($report->isDirty('status_id')) {
            $oldStatusId = $report->getOriginal('status_id');
            $oldStatus = \App\Models\Status::find($oldStatusId);
            $newStatus = $report->status;

            // Se não conseguirmos obter os status, não enviamos notificação
            if (!$oldStatus || !$newStatus) {
                return;
            }

            $this->notificationService->sendToUser(
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
        }
    }
}
