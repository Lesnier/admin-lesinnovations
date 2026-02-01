<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Revolution\Google\Sheets\Facades\Sheets;

class WizardSubmissionService
{
    protected $ghlService;

    public function __construct(GoHighLevelService $ghlService)
    {
        $this->ghlService = $ghlService;
    }

    /**
     * Process the full submission flow: Local DB -> GHL -> Sheets
     */
    public function process(array $data)
    {
        $contact = $data['contact'] ?? [];
        $objectives = $data['objectives'] ?? [];
        $requirements = $data['requirements'] ?? [];
        $totalEstimate = $data['totalEstimate'] ?? 0;
        $description = $data['description'] ?? '';

        try {
            // 1. Save Local Lead (Voyager / Database)
            Log::info("WizardSubmission: Saving to Local DB...");
            $lead = Lead::updateOrCreate(
                ['email' => $contact['email'] ?? null],
                [
                    'name' => $contact['fullName'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'company' => $contact['companyName'] ?? null,
                    'total_estimate' => $totalEstimate,
                    // Encoded JSON for the 'data' column (as string)
                    'data' => json_encode($data) 
                ]
            );

            // 2. Sync GoHighLevel (Using injected Service)
            Log::info("WizardSubmission: Syncing GHL...");
            $this->ghlService->sync($contact, $objectives, $requirements, $totalEstimate, $description);

            // 3. Sync Google Sheets
            Log::info("WizardSubmission: Syncing Sheets...");
            $this->syncGoogleSheets($contact, $objectives, $requirements, $totalEstimate, $description);

            return ['success' => true];

        } catch (\Exception $e) {
            Log::error("WizardSubmission Service Error: " . $e->getMessage());
            // Return failure but allow partial completion
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function syncGoogleSheets($contact, $objectives, $requirements, $totalEstimate, $description)
    {
        $sheetId = env('GOOGLE_SHEET_ID');
        if (!$sheetId) {
            Log::warning("GOOGLE_SHEET_ID missing. Skipping Sheets sync.");
            return;
        }

        try {
            $reqText = collect($requirements)
                ->map(fn($r) => "- {$r['text']} ($" . ($r['value']??0) . ")")
                ->implode("\n");

            $rowData = [
                'Date' => now()->toIso8601String(),
                'Name' => $contact['fullName'] ?? '',
                'Email' => $contact['email'] ?? '',
                'Phone' => $contact['phone'] ?? '',
                'Company' => $contact['companyName'] ?? '',
                'Estimate' => $totalEstimate,
                'Service' => implode(', ', $objectives['services'] ?? []),
                'Goals' => implode(', ', $objectives['goals'] ?? []),
                'Budget' => $objectives['budget'] ?? '',
                'Timeline' => $objectives['timeline'] ?? '',
                'Description' => $description,
                'Requirements' => $reqText
            ];

            Sheets::spreadsheet($sheetId)->sheetByIndex(0)->append([$rowData]);
            Log::info("WizardSubmission: Sheets Sync Successful");

        } catch (\Exception $e) {
            Log::error("WizardSubmission Sheets Error: " . $e->getMessage());
        }
    }
}
