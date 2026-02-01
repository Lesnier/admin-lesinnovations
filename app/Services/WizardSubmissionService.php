<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Revolution\Google\Sheets\Facades\Sheets;

class WizardSubmissionService
{
    protected $apiKey;
    protected $locationId;
    protected $pipelineId;
    protected $baseUrl = 'https://services.leadconnectorhq.com';

    public function __construct()
    {
        $this->apiKey = trim(env('GHL_API_KEY'));
        $this->locationId = trim(env('GHL_LOCATION_ID'));
        $this->pipelineId = trim(env('GHL_PIPELINE_ID'));
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

            // 2. Sync GoHighLevel (Inline Implementation)
            Log::info("WizardSubmission: Syncing GHL...");
            $this->syncGoHighLevel($contact, $objectives, $requirements, $totalEstimate, $description);

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

    protected function syncGoHighLevel($contact, $objectives, $requirements, $totalEstimate, $description)
    {
        // Lazy load config if constructor missed it
        if (!$this->apiKey) {
            $this->apiKey = trim(env('GHL_API_KEY'));
            $this->locationId = trim(env('GHL_LOCATION_ID'));
            $this->pipelineId = trim(env('GHL_PIPELINE_ID'));
        }

        if (!$this->apiKey) {
            Log::warning("GHL_API_KEY is missing (even after retry). Skipping GHL Sync.");
            return;
        }

        try {
            // 1. Get or Create Contact (Now ensures Tags are updated)
            $contactId = $this->getOrCreateContact($contact);
            
            if (!$contactId) {
                Log::error("GHL: Failed to get proper Contact ID.");
                return;
            }

            // 2. Create Opportunity
            if ($this->pipelineId) {
                $this->createOpportunity($contactId, $contact, $totalEstimate);
            } else {
                Log::warning("GHL_PIPELINE_ID missing. Skipping Opportunity creation.");
            }

            // 3. Create Note
            $this->createNote($contactId, $objectives, $requirements, $totalEstimate, $description);

        } catch (\Exception $e) {
            Log::error("GHL Sync Critical Error: " . $e->getMessage());
        }
    }

    protected function getOrCreateContact($contact)
    {
        // Common payload for Create and Update
        $dataPayload = [
            'email' => $contact['email'],
            'phone' => $contact['phone'] ?? null,
            'name' => $contact['fullName'],
            'companyName' => $contact['companyName'] ?? null,
            'locationId' => $this->locationId,
            'tags' => ["wizard-est", "website-lead"]
        ];
        
        $parts = explode(' ', $contact['fullName']);
        $dataPayload['firstName'] = $parts[0] ?? '';
        $dataPayload['lastName'] = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        // 1. Search
        $response = \Illuminate\Support\Facades\Http::withHeaders($this->getHeaders())->get($this->baseUrl . '/contacts/', [
            'locationId' => $this->locationId,
            'query' => $contact['email']
        ]);

        if ($response->successful() && count($response->json()['contacts'] ?? []) > 0) {
            $id = $response->json()['contacts'][0]['id'];
            Log::info("GHL: Contact Found (ID: $id). Updating tags/info...");
            
            // UPDATE existing contact to ensure Tags are applied
            $updateResp = \Illuminate\Support\Facades\Http::withHeaders($this->getHeaders())->put($this->baseUrl . "/contacts/{$id}", $dataPayload);
            
            if (!$updateResp->successful()) {
                Log::warning("GHL: Failed to update existing contact: " . $updateResp->body());
            }
            
            return $id;
        }

        // 2. Create
        Log::info("GHL: Creating New Contact...");
        $createResp = \Illuminate\Support\Facades\Http::withHeaders($this->getHeaders())->post($this->baseUrl . '/contacts/', $dataPayload);

        if ($createResp->successful()) {
            $id = $createResp->json()['contact']['id'];
            Log::info("GHL: Contact Created", ['id' => $id]);
            return $id;
        }

        Log::error("GHL: Contact Creation Failed", ['body' => $createResp->body()]);
        return null;
    }

    protected function createOpportunity($contactId, $contact, $totalEstimate)
    {
        $stageId = $this->findStageId("New Lead");

        if (!$stageId) {
            Log::error("GHL: Could not find 'New Lead' stage in pipeline {$this->pipelineId}");
            return;
        }

        $payload = [
            'pipelineId' => $this->pipelineId,
            'locationId' => $this->locationId,
            'contactId' => $contactId,
            'name' => ($contact['fullName'] ?? 'Lead') . ' - App Estimate',
            'pipelineStageId' => $stageId,
            'status' => 'open',
            'monetaryValue' => (float) $totalEstimate
        ];

        $response = \Illuminate\Support\Facades\Http::withHeaders($this->getHeaders())->post($this->baseUrl . '/opportunities/', $payload);

        if ($response->successful()) {
            Log::info("GHL: Opportunity Created");
        } else {
            Log::error("GHL: Opportunity Failed", ['body' => $response->body()]);
        }
    }

    protected function findStageId($stageName)
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders($this->getHeaders())->get($this->baseUrl . '/opportunities/pipelines/', [
            'locationId' => $this->locationId
        ]);

        if (!$response->successful()) {
            Log::error("GHL: Failed to fetch pipelines");
            return null;
        }

        $pipelines = $response->json()['pipelines'] ?? [];
        $pipeline = collect($pipelines)->firstWhere('id', $this->pipelineId);

        if (!$pipeline) {
             Log::error("GHL: Pipeline {$this->pipelineId} not found in account.");
             return null;
        }

        $stages = $pipeline['stages'] ?? [];
        $stage = collect($stages)->first(function($s) use ($stageName) {
            return stripos($s['name'], $stageName) !== false;
        });

        if (!$stage && count($stages) > 0) {
            $stage = $stages[0];
            Log::info("GHL: '{$stageName}' stage not found. Defaulting to first stage: " . $stage['name']);
        }

        return $stage['id'] ?? null;
    }

    protected function createNote($contactId, $objectives, $requirements, $totalEstimate, $description)
    {
        $reqList = collect($requirements)->map(function($r) {
            $val = $r['value'] ?? 0;
            return "- {$r['text']}: \${$val}";
        })->implode("\n");

        $services = implode(', ', $objectives['services'] ?? []);
        $goals = implode(', ', $objectives['goals'] ?? []);
        $budget = $objectives['budget'] ?? 'N/A';
        $timeline = $objectives['timeline'] ?? 'N/A';

        $body = "Resultados del Wizard:\n---------------------\n"
            . "Servicios: {$services}\n"
            . "Objetivos: {$goals}\n"
            . "Presupuesto: {$budget}\n"
            . "Tiempo: {$timeline}\n\n"
            . "Descripción:\n{$description}\n\n"
            . "Requerimientos:\n{$reqList}\n\n"
            . "Total Estimado: \${$totalEstimate}";

        $response = \Illuminate\Support\Facades\Http::withHeaders($this->getHeaders())->post($this->baseUrl . "/contacts/{$contactId}/notes", [
            'body' => $body
        ]);

        if ($response->successful()) {
            Log::info("GHL: Note Created");
        } else {
            Log::error("GHL: Note Creation Failed", ['body' => $response->body()]);
        }
    }

    protected function getHeaders()
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Version' => '2021-07-28',
            'Content-Type' => 'application/json'
        ];
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
