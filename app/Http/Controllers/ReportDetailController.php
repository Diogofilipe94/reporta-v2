<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportDetail;
use Illuminate\Http\Request;

class ReportDetailController extends Controller
{
    public function store(Request $request, $id)
    {
        $report = Report::where('id', $id)->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report not found'
            ], 404);
        }

        $user = auth()->user();
        if ($user->role->role !== 'admin' && $user->role->role !== 'curator') {
            return response()->json([
                'error' => 'Unauthorized. Only admin or curator can add details.'
            ], 403);
        }

        if ($report->detail) {
            return response()->json([
                'error' => 'Report details already exist'
            ], 400);
        }

        $detail = new ReportDetail();
        $detail->report_id = $report->id;
        $detail->technical_description = $request->technical_description;
        $detail->priority = $request->priority;
        $detail->resolution_notes = $request->resolution_notes;
        $detail->estimated_cost = $request->estimated_cost;
        $detail->save();

        return response()->json([
            'message' => 'Report details created successfully',
            'detail' => $detail
        ], 201);
    }

    public function show($id)
    {
        $report = Report::where('id', $id)->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report not found'
            ], 404);
        }

        $detail = ReportDetail::where('report_id', $report->id)->first();

        if (!$detail) {
            return response()->json([
                'error' => 'Report detail not found'
            ], 404);
        }

        return response()->json($detail);
    }

    public function update(Request $request, $id)
    {
        $report = Report::where('id', $id)->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report not found'
            ], 404);
        }

        $user = auth()->user();
        if ($user->role->role !== 'admin' && $user->role->role !== 'curator') {
            return response()->json([
                'error' => 'Unauthorized. Only admin or curator can update details.'
            ], 403);
        }

        $detail = ReportDetail::where('report_id', $report->id)->first();

        if (!$detail) {
            return response()->json([
                'error' => 'Report detail not found'
            ], 404);
        }

        if ($request->has('technical_description')) {
            $detail->technical_description = $request->technical_description;
        }
        if ($request->has('priority')) {
            $detail->priority = $request->priority;
        }
        if ($request->has('resolution_notes')) {
            $detail->resolution_notes = $request->resolution_notes;
        }
        if ($request->has('estimated_cost')) {
            $detail->estimated_cost = $request->estimated_cost;
        }

        $detail->save();

        return response()->json([
            'message' => 'Report details updated successfully',
            'detail' => $detail
        ]);
    }
}
