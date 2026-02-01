<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoHighLevelService
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

    public function sync($contact, $objectives, $requirements, $totalEstimate, $description)
    {
        if (!$this->apiKey) {
            Log::warning("GHL_API_KEY is missing. Skipping GHL Sync.");
            return;
        }

        Log::info("GHL Sync Starting...", ['email' => $contact['email']]);

        try {
            // 1. Get or Create Contact
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
        // 1. Search
        $response = Http::withHeaders($this->getHeaders())->get($this->baseUrl . '/contacts/', [
            'locationId' => $this->locationId,
            'query' => $contact['email']
        ]);

        if ($response->successful() && count($response->json()['contacts'] ?? []) > 0) {
            $id = $response->json()['contacts'][0]['id'];
            Log::info("GHL: Contact Found", ['id' => $id]);
            return $id;
        }

        // 2. Create
        Log::info("GHL: Creating New Contact...");
        $payload = [
            'email' => $contact['email'],
            'phone' => $contact['phone'] ?? null,
            'name' => $contact['fullName'],
            'companyName' => $contact['companyName'] ?? null,
            'locationId' => $this->locationId,
            'tags' => ["wizard-est", "website-lead"]
        ];
        
        // Handle name split if needed, but 'name' usually works. GHL uses firstName/lastName too.
        $parts = explode(' ', $contact['fullName']);
        $payload['firstName'] = $parts[0] ?? '';
        $payload['lastName'] = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        $createResp = Http::withHeaders($this->getHeaders())->post($this->baseUrl . '/contacts/', $payload);

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
        // Fetch Pipeline to find "New Lead" stage
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

        $response = Http::withHeaders($this->getHeaders())->post($this->baseUrl . '/opportunities/', $payload);

        if ($response->successful()) {
            Log::info("GHL: Opportunity Created");
        } else {
            Log::error("GHL: Opportunity Failed", ['body' => $response->body()]);
        }
    }

    protected function findStageId($stageName)
    {
        // Get Pipeline Details
        $response = Http::withHeaders($this->getHeaders())->get($this->baseUrl . '/opportunities/pipelines/', [
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
        
        // Find by name or default to first
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

        $response = Http::withHeaders($this->getHeaders())->post($this->baseUrl . "/contacts/{$contactId}/notes", [
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
}
