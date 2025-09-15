<?php

namespace Modules\HRM\Services;

use App\Models\Advance;

class AdvanceStatusService
{
    /**
     * Update the status of an advance based on its repayments.
     *
     * @param Advance $advance
     * @return void
     */
    public function updateAdvanceStatus(Advance $advance): void
    {
        // Calculate the total amount paid from active repayments only.
        $totalPaid = $advance->repayments()->where('status', 'Active')->sum('amount_paid');

        if ($totalPaid >= $advance->advance_amount) {
            // If the total paid is enough, mark the advance as Paid Off.
            if ($advance->status !== 'Paid Off') {
                $advance->status = 'Paid Off';
                $advance->save();
            }
        } else {
            // Otherwise, ensure the status is Active.
            if ($advance->status !== 'Active') {
                $advance->status = 'Active';
                $advance->save();
            }
        }
    }
}
