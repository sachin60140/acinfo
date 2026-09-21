<?php

namespace App\Support;

/**
 * What a refused Update Status save held, for the dialog it came from.
 *
 * The Work Report and In-house Work post their Update dialog to the status
 * screen's controller. When it refuses — a missing screenshot, a work changed
 * since — it goes back with the input, and the page then drew nothing of it:
 * the dialog closed and the remark was gone. This hands the page what was
 * typed so the dialog can open again with it; WorkUpdateDialog decides what is
 * safe to put back.
 */
class UpdateDialog
{
    /** @return array{statuses: array, was: array, remarks: array, approved_on: array}|null */
    public static function restore(): ?array
    {
        $statuses = old('statuses');

        if (! is_array($statuses) || ! $statuses) {
            return null;
        }

        return [
            'statuses' => $statuses,
            'was' => (array) old('was', []),
            'remarks' => (array) old('remarks', []),
            'approved_on' => (array) old('approved_on', []),
        ];
    }
}
