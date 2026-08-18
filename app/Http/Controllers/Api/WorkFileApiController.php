<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkFileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints for the browser-side screens.
 *
 * Sits behind the same admin middleware as everything else — a lookup that
 * reveals a customer's history and prices is not public just because it returns
 * JSON.
 */
class WorkFileApiController extends Controller
{
    /**
     * What has already been booked against a vehicle.
     *
     * Answers the question the receive screen needs answered before a file is
     * charged: has this vehicle been here before, for what work, at what price.
     */
    public function history(Request $req): JsonResponse
    {
        $req->validate([
            'registration_no' => 'required|string|max:20',
            'exclude' => 'nullable|integer',
        ]);

        $normalised = WorkFileModel::normaliseRegistration($req->query('registration_no'));

        $files = WorkFileModel::historyFor($normalised, $req->query('exclude'));

        return response()->json([
            'registration_no' => $normalised,
            'count' => $files->count(),
            'files' => $files->map(function ($file) {
                $totals = WorkFileModel::rowTotals((object) [
                    'status' => $file->status,
                    'customer_amount' => $file->customer_amount,
                    'returned_amount' => $file->returned_amount,
                    // This lookup is about what a vehicle was charged before, so
                    // the vendor side is deliberately left out of the figures.
                    'vendor_id' => null,
                    'vendor_amount' => null,
                    'vendor_returned_on' => null,
                    'vendor_returned_amount' => null,
                ]);

                return [
                    'id' => $file->id,
                    'file_no' => $file->file_no,
                    'received_date' => date('d-m-Y', strtotime($file->received_date)),
                    'work_type' => $file->work_type,
                    'work_type_id' => $file->work_type_id,
                    'customer' => $file->customer_name,
                    'vendor' => $file->vendor_name,
                    'status' => $file->status,
                    'status_label' => WorkFileModel::STATUSES[$file->status] ?? $file->status,
                    'status_badge' => WorkFileModel::STATUS_BADGES[$file->status] ?? 'bg-secondary',
                    // What was charged, and what it actually came to once any
                    // return is taken into account — the second is the one to
                    // compare a new quote against.
                    'charged' => number_format((float) $file->customer_amount, 2, '.', ','),
                    'net' => number_format($totals['billed'], 2, '.', ','),
                    'was_returned' => abs($totals['billed'] - (float) $file->customer_amount) > 0.005,
                ];
            })->all(),
        ]);
    }
}
