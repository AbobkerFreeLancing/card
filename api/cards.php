<?php
/**
 * واجهة API بصيغة JSON لإدارة البطاقات وإعادة الطباعة وحفظ القالب في MySQL
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/card_repository.php';

function cerd_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'bootstrap');
        if ($action === 'list') {
            $q = (string) ($_GET['q'] ?? '');
            cerd_json_response([
                'ok'    => true,
                'cards' => cerd_list_cards($q),
            ]);
        }

        if ($action === 'batches') {
            cerd_json_response([
                'ok'      => true,
                'batches' => cerd_list_print_batches(),
            ]);
        }

        if ($action === 'batch_cards') {
            $batchId = (int) ($_GET['batch_id'] ?? 0);
            if ($batchId <= 0) {
                cerd_json_response(['ok' => false, 'error' => 'معرف الدفعة غير صالح.'], 422);
            }
            $pdo = cerd_get_pdo();
            $stmt = $pdo->prepare('SELECT id, batch_code, mirror_mode, total_cards, created_at FROM print_batches WHERE id = ? LIMIT 1');
            $stmt->execute([$batchId]);
            $batch = $stmt->fetch() ?: null;
            $cards = cerd_get_batch_cards($batchId, $pdo);
            cerd_json_response([
                'ok'    => true,
                'batch' => $batch,
                'cards' => $cards,
            ]);
        }

        $pdo = cerd_get_pdo();
        cerd_json_response([
            'ok'          => true,
            'template'    => cerd_get_default_template($pdo),
            'departments' => cerd_get_departments($pdo),
            'cards'       => cerd_list_cards('', 150, $pdo),
            'batches'     => cerd_list_print_batches(25, $pdo),
        ]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '', true);
        if (!is_array($body)) {
            cerd_json_response(['ok' => false, 'error' => 'صيغة الطلب غير صالحة (JSON مطلوب).'], 400);
        }

        $action = (string) ($body['action'] ?? '');

        if ($action === 'save_card') {
            $pdo = cerd_get_pdo();
            $template = cerd_get_default_template($pdo);
            $cardInput = isset($body['card']) && is_array($body['card']) ? $body['card'] : [];
            $saved = cerd_upsert_card($pdo, $cardInput, $template, false);
            cerd_json_response([
                'ok'    => true,
                'card'  => $saved,
                'cards' => cerd_list_cards('', 150, $pdo),
            ]);
        }

        if ($action === 'record_print') {
            $cardsInput = isset($body['cards']) && is_array($body['cards']) ? $body['cards'] : [];
            $mirrorMode = (string) ($body['mirrorMode'] ?? 'text');
            $res = cerd_record_print_batch($cardsInput, $mirrorMode);
            cerd_json_response([
                'ok'         => true,
                'batchId'    => $res['batchId'],
                'batchCode'  => $res['batchCode'],
                'savedCards' => $res['savedCards'],
                'cards'      => cerd_list_cards(),
                'batches'    => cerd_list_print_batches(),
            ]);
        }

        if ($action === 'delete_card') {
            $cardId = (int) ($body['id'] ?? 0);
            $deleted = cerd_delete_card($cardId);
            cerd_json_response([
                'ok'      => $deleted,
                'deleted' => $deleted,
                'cards'   => cerd_list_cards(),
            ], $deleted ? 200 : 404);
        }

        if ($action === 'save_template') {
            $tplInput = isset($body['template']) && is_array($body['template']) ? $body['template'] : [];
            $template = cerd_save_template($tplInput);
            cerd_json_response([
                'ok'       => true,
                'template' => $template,
            ]);
        }

        cerd_json_response(['ok' => false, 'error' => 'إجراء غير معروف.'], 400);
    }

    cerd_json_response(['ok' => false, 'error' => 'طريقة الطلب غير مدعومة.'], 405);
} catch (InvalidArgumentException $e) {
    cerd_json_response(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    cerd_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
