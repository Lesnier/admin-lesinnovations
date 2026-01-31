<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Revolution\Google\Sheets\Facades\Sheets;
use Illuminate\Support\Facades\Log;

class WizardController extends Controller
{
    /**
     * Analyze project requirements using OpenAI.
     */
    public function analyze(Request $request)
    {
        $data = $request->validate([
            'description' => 'required|string',
            'services' => 'nullable|array',
            'goals' => 'nullable|array',
            'budget' => 'nullable|string',
            'timeline' => 'nullable|string',
            'location' => 'nullable|array',
        ]);

        $description = $data['description'];
        
        // 1. Resolve Settings from DB (Single Source of Truth)
        $countryCode = $data['location']['countryCode'] ?? 'DEFAULT';
        
        Log::info("Wizard Analysis Request", [
            'country_provided' => $countryCode,
            'description_length' => strlen($description)
        ]);

        $setting = \App\Models\RegionalSetting::where('country_code', $countryCode)->first();
        
        if (!$setting) {
             Log::info("Country code {$countryCode} not found in DB. Falling back to DEFAULT.");
             $setting = \App\Models\RegionalSetting::where('country_code', 'DEFAULT')->first();
        }

        // Fallback hardcoded if DB is empty
        $hourlyRate = $setting ? $setting->hourly_rate : 50;
        $multiplier = $setting ? $setting->multiplier : 1.1;

        Log::info("Regional Setting Resolved", [
            'country' => $setting ? $setting->country_name : 'Unknown',
            'hourly_rate' => $hourlyRate,
            'multiplier' => $multiplier
        ]);

        try {
            $prompt = "
            ROL:
            Actúa como un Arquitecto de Soluciones de Software Senior y Project Manager con experiencia en estimación de costos basada en esfuerzo.
            
            CONTEXTO DEL PROYECTO:
            -----------------------
            Descripción: \"{$description}\"
            Servicios Solicitados: " . ($data['services'] ? implode(', ', $data['services']) : 'N/A') . "
            Objetivos de Negocio: " . ($data['goals'] ? implode(', ', $data['goals']) : 'N/A') . "
            Presupuesto Indicativo: " . ($data['budget'] ?? 'N/A') . "
            Tiempo Objetivo: " . ($data['timeline'] ?? 'N/A') . "
            
            TAREA:
            Desglosa la solicitud del usuario en una lista de 12 a 40 requerimientos funcionales de alto nivel para cumplir los objetivos. Debes estimar el esfuerzo en HORAS HOMBRE para cada ítem.
            
            METODOLOGÍA DE ESTIMACIÓN:
            1. Analiza la complejidad técnica de cada requerimiento.
            2. Asigna una cantidad de horas realista (Desarrollo + Pruebas unitarias).
            3. Calcula el precio multiplicando las horas por la TASA BASE.
            4. TASA BASE A UTILIZAR: {$hourlyRate} USD/hora. (Si no se especifica, usa $50 USD/h por defecto).
            
            REGLAS DE FORMATO Y REDACCIÓN:
            1. El \"text\" debe ser un BENEFICIO o ENTREGABLE TANGIBLE para el cliente (No uses jerga técnica oscura).
               - Malo: \"Creación de endpoints API REST\"
               - Bueno: \"Conexión segura para intercambio de datos\"
               
            2. ESTRUCTURA DEL DESGLOSE (Categorías sugeridas):
               - Frontend / Experiencia de Usuario
               - Backend / Lógica de Negocio
               - Integraciones (APIs, Pagos, Terceros)
               - Marketing / Contenidos (Solo si el usuario lo pidió)
            
            3. ÍTEMS OBLIGATORIOS (Deben ir AL FINAL de la lista):
               Debes estimar las horas para estos ítems transversales basándote en el tamaño del proyecto (usualmente un % del total de desarrollo):
               - \"QA - Aseguramiento de la Calidad y Pruebas\" (Aprox 15-20% del tiempo de dev)
               - \"Infraestructura y Configuración DevOps\" (Configuración de servidores, CI/CD)
               - \"Gestión de Proyecto y Comunicación\" (Reuniones, demos, seguimiento)
               - \"Análisis Funcional y Documentación Técnica\"
                  
            SALIDA (JSON ARRAY):
            Responde ÚNICAMENTE con un JSON array válido. No incluyas texto antes ni después.
            Formato:
            [
            { 
                \"text\": \"Título del Entregable\", 
                \"hours\": 10, 
                \"rate\": 50, 
                \"value\": 500, 
                \"category\": \"Backend\",
                \"included\": true 
            },
            ...
            ]
            ";

            $result = OpenAI::chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un arquitecto de software experto en cotizaciones.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            $content = $result->choices[0]->message->content;
            
            // Extract JSON from response
            $jsonStart = strpos($content, '[');
            $jsonEnd = strrpos($content, ']');
            if ($jsonStart === false || $jsonEnd === false) {
                throw new \Exception("Invalid JSON response from AI");
            }
            
            $jsonString = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
            $items = json_decode($jsonString, true);

            Log::info("OpenAI Response Received", ['item_count' => count($items)]);

            // 2. Apply Regional Multiplier logic (Backend side)
            $finalItems = collect($items)->map(function($item) use ($multiplier) {
                // Ensure base properties exist
                $baseValue = $item['value'] ?? 0;
                // Apply multiplier to value
                $originalValue = $baseValue;
                $item['value'] = round($baseValue * $multiplier, 2);
                
                // Attach metadata so frontend knows what happened (optional)
                $item['_multiplier_applied'] = $multiplier;

                // Log sample (only first item to avoid spam)
                // Log::debug("Applying Multiplier", ['txt'=>$item['text'], 'base'=>$originalValue, 'final'=>$item['value']]);
                
                return $item;
            });

            Log::info("Analysis Completed Successfully", [
                'final_total_base' => collect($items)->sum('value'),
                'final_total_adjusted' => $finalItems->sum('value') 
            ]);
            
            return response()->json($finalItems);

        } catch (\Exception $e) {
            Log::error("OpenAI Error: " . $e->getMessage());
            return response()->json([
                ['text' => "Error al generar estimados con IA", 'value' => 0, 'included' => false]
            ], 500);
        }
    }

    /**
     * Process Wizard Submission (Save Lead, Sync GHL, Sync Sheets)
     */
    public function submit(Request $request) 
    {
        $data = $request->all();
        $contact = $data['contact'] ?? [];
        $objectives = $data['objectives'] ?? [];
        $requirements = $data['requirements'] ?? [];
        $totalEstimate = $data['totalEstimate'] ?? 0;

        try {
            // 1. Save Local Lead
            $lead = Lead::updateOrCreate(
                ['email' => $contact['email'] ?? null],
                [
                    'name' => $contact['fullName'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'company' => $contact['companyName'] ?? null,
                    'total_estimate' => $totalEstimate,
                    'data' => $data // Save full payload
                ]
            );

            // 2. Sync GoHighLevel
            $this->syncGoHighLevel($contact, $objectives, $requirements, $totalEstimate, $data['description'] ?? '');

            // 3. Sync Google Sheets
            $this->syncGoogleSheets($contact, $objectives, $requirements, $totalEstimate, $data['description'] ?? '');

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error("Submission Error: " . $e->getMessage());
            return response()->json(['success' => true, 'warning' => 'Partial failure'], 200);
        }
    }

    private function syncGoHighLevel($contact, $objectives, $requirements, $totalEstimate, $description)
    {
        $apiKey = env('GHL_API_KEY');
        if (!$apiKey) return;

        $locationId = env('GHL_LOCATION_ID');

        try {
            // Search Contact
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Version' => '2021-07-28'
            ])->get('https://services.leadconnectorhq.com/contacts/', [
                'locationId' => $locationId,
                'query' => $contact['email']
            ]);

            $contactId = null;

            if ($response->successful() && count($response->json()['contacts'] ?? []) > 0) {
                $contactId = $response->json()['contacts'][0]['id'];
            } else {
                // Create Contact
                $createResp = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Version' => '2021-07-28'
                ])->post('https://services.leadconnectorhq.com/contacts/', [
                    'email' => $contact['email'],
                    'phone' => $contact['phone'],
                    'firstName' => explode(' ', $contact['fullName'])[0],
                    'lastName' => implode(' ', array_slice(explode(' ', $contact['fullName']), 1)),
                    'name' => $contact['fullName'],
                    'companyName' => $contact['companyName'],
                    'locationId' => $locationId,
                    'tags' => ["wizard-est", "website-lead"]
                ]);
                
                if ($createResp->successful()) {
                    $contactId = $createResp->json()['contact']['id'];
                }
            }

            if ($contactId) {
                // Create Opportunity
                $pipelineId = env('GHL_PIPELINE_ID');
                if ($pipelineId) {
                    // Logic to find stage simplified for MVP - hardcoded or first stage
                    // Ideally fetch stages like in original code
                    
                    Http::withHeaders([
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Version' => '2021-07-28'
                    ])->post('https://services.leadconnectorhq.com/opportunities/', [
                        'pipelineId' => $pipelineId,
                        'locationId' => $locationId,
                        'contactId' => $contactId,
                        'name' => $contact['fullName'] . ' - Lead',
                        'status' => 'open',
                        'monetaryValue' => $totalEstimate
                    ]);
                }

                // Create Note
                $noteBody = "Resultados del Wizard:\n---------------------\n";
                $noteBody .= "Servicios: " . implode(', ', $objectives['services'] ?? []) . "\n";
                $noteBody .= "Objetivos: " . implode(', ', $objectives['goals'] ?? []) . "\n";
                $noteBody .= "Presupuesto: " . ($objectives['budget'] ?? 'N/A') . "\n";
                $noteBody .= "Tiempo: " . ($objectives['timeline'] ?? 'N/A') . "\n\n";
                $noteBody .= "Descripción:\n" . ($description ?? 'N/A') . "\n\n";
                $noteBody .= "Requerimientos:\n";
                foreach (($requirements ?? []) as $req) {
                    $noteBody .= "- {$req['text']}: \${$req['value']}\n";
                }
                $noteBody .= "\nTotal Estimado: \${$totalEstimate}";

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Version' => '2021-07-28'
                ])->post("https://services.leadconnectorhq.com/contacts/{$contactId}/notes", [
                    'body' => $noteBody
                ]);
            }

        } catch (\Exception $e) {
            Log::error("GHL Sync Error: " . $e->getMessage());
        }
    }

    private function syncGoogleSheets($contact, $objectives, $requirements, $totalEstimate, $description)
    {
        $sheetId = env('GOOGLE_SHEET_ID');
        if (!$sheetId) return;

        try {
            $rowData = [
                'Date' => now()->toIso8601String(),
                'Name' => $contact['fullName'],
                'Email' => $contact['email'],
                'Phone' => $contact['phone'],
                'Company' => $contact['companyName'],
                'Estimate' => $totalEstimate,
                'Service' => implode(', ', $objectives['services'] ?? []),
                'Goals' => implode(', ', $objectives['goals'] ?? []),
                'Budget' => $objectives['budget'] ?? '',
                'Timeline' => $objectives['timeline'] ?? '',
                'Description' => $description,
                'Requirements' => collect($requirements)->map(fn($r) => "{$r['text']} (\${$r['value']})")->implode("\n")
            ];

            Sheets::spreadsheet($sheetId)->sheetByIndex(0)->append([$rowData]);

        } catch (\Exception $e) {
            Log::error("Sheets Sync Error: " . $e->getMessage());
        }
    }
}
