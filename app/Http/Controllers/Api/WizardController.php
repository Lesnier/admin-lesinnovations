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
        $hourlyRate = $data['location']['hourlyRate'] ?? 50;

        try {
            $prompt = "
            Actúa como un Arquitecto de Software Senior y Experto en Estimación de Proyectos.
      
            CONTEXTO DEL PROYECTO:
            -----------------------
            Descripción: \"{$description}\"
            Servicios Solicitados: " . ($data['services'] ? implode(', ', $data['services']) : 'N/A') . "
            Objetivos de Negocio: " . ($data['goals'] ? implode(', ', $data['goals']) : 'N/A') . "
            Presupuesto Indicativo: " . ($data['budget'] ?? 'N/A') . "
            Tiempo Objetivo: " . ($data['timeline'] ?? 'N/A') . "
            
            TAREA:
            Desglosa esta solicitud en una lista de 15 a 20 requerimientos técnicos funcionales de alto nivel necesarios para cumplir con los objetivos.
            
            METODOLOGÍA:
            Utiliza un desglose arquitectónico (ej: Frontend, Backend, Integraciones, Infraestructura, Marketing Digital si aplica).
            
            REGLAS DE FORMATO:
            1. El \"text\" de cada requerimiento debe ser un BENEFICIO o ENTREGABLE TANGIBLE para el cliente.
               - Malo: \"Base de datos SQL\"
               - Bueno: \"Almacenamiento Seguro de Datos de Clientes\"
            
            2. PRECIOS:
               - Estima utilizando TARIFAS ESTÁNDAR INTERNACIONALES (Base EE.UU/Global: $30-$60/hora).
               - NO ajustes los precios a la baja por región (el sistema aplicará un multiplicador regional después).
               - Rango típico por módulo: $100 - $1500 USD según complejidad.
            
            3. Importante: Si el usuario seleccionó servicios de Marketing o Diseño, incluye items relacionados (ej: \"Estrategia de Contenidos\", \"Diseño UI/UX Premium\").
            
            Responde SOLO con un JSON array válido. Formato exacto:
            [
              { \"text\": \"Título del Beneficio/Entregable\", \"value\": 250, \"included\": true },
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
            
            return response()->json(json_decode($jsonString));

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
