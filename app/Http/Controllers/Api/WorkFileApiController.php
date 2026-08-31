<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkFileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * What this customer has been charged for each work before.
     *
     * Answers the other question the receive screen needs answered before a
     * price is typed: what did this customer pay for a transfer last time.
     *
     * Fetched rather than sent with the screen, because the customer is chosen
     * after it loads and the answer is theirs alone.
     */
    public function customerRates(Request $req): JsonResponse
    {
        $req->validate([
            'customer_id' => ['required', 'integer', Rule::exists('party', 'id')->where('party_type', 'customer')],
        ]);

        $rates = WorkFileModel::recentCustomerRates((int) $req->query('customer_id'));

        return response()->json([
            'customer_id' => (int) $req->query('customer_id'),
            'works' => collect($rates)->map(fn ($one) => [
                'work_type_id' => $one['work_type_id'],
                // The office these were charged at: the first four characters
                // of the registration, and the reason two of them differ.
                'rto' => $one['rto'],
                'rates' => collect($one['rates'])->map(fn ($rate) => [
                    'file_no' => $rate->file_no,
                    'received_date' => date('d-m-Y', strtotime($rate->received_date)),
                    'registration_no' => $rate->registration_no,
                    'work_type' => $rate->work_type,
                    'status' => WorkFileModel::STATUSES[$rate->status] ?? $rate->status,
                    'amount' => (float) $rate->amount,
                ])->values(),
            ])->values(),
        ]);
    }

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
