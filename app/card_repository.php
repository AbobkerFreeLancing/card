<?php
/**
 * طبقة مستودع البيانات (Repository) لنظام البطاقات الجامعية
 * تدير قوالب البيانات الثابتة، وبيانات حاملي البطاقات الديناميكية، وسجل الطباعة وإعادة الطباعة.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * التحقق من مسار صورة صالح (Data URI أو مسار نسبي آمن)
 */
function cerd_sanitize_media_src(?string $src): ?string
{
    $val = trim((string) $src);
    if ($val === '' || str_starts_with($val, 'data:image/svg+xml')) {
        return null;
    }
    return $val;
}

/**
 * تحويل سجل جدول card_templates إلى بنية موحدة للواجهة والطباعة.
 */
function cerd_normalize_template_row(array $row): array
{
    $sigTransform = json_decode((string) ($row['sig_transform_json'] ?? ''), true);
    if (!is_array($sigTransform)) {
        $sigTransform = ['scale' => 108, 'x' => 0, 'y' => 2, 'rot' => 0, 'opacity' => 96];
    }

    $backRules = json_decode((string) ($row['back_rules_json'] ?? ''), true);
    if (!is_array($backRules)) {
        $backRules = [
            'يجب إبلاغ الجامعة فوراً في حالة فقدان البطاقة',
            'يُسمح لحامل البطاقة بدخول كافة المرافق الحكومية والمستشفيات',
            "إذا عثرت على هذه البطاقة يرجى تسليمها إلى إدارة الجامعة\nأو الاتصال بالرقم: ٠١٢٣٤٥٦٧٨٩",
        ];
    }

    $layoutSettings = json_decode((string) ($row['layout_settings_json'] ?? ''), true);
    if (!is_array($layoutSettings)) {
        $layoutSettings = [];
    }

    return [
        'id'             => (int) $row['id'],
        'templateCode'   => (string) $row['template_code'],
        'templateName'   => (string) $row['template_name'],
        'uniName'        => (string) $row['institution_name'],
        'logoSrc'        => $row['logo_src'] ?: null,
        'sigCaption'     => (string) $row['sig_caption'],
        'sigStyle'       => (string) $row['sig_style'],
        'sigImageSrc'    => $row['sig_image_src'] ?: null,
        'sigTransform'   => [
            'scale'   => (int) ($sigTransform['scale'] ?? 108),
            'x'       => (int) ($sigTransform['x'] ?? 0),
            'y'       => (int) ($sigTransform['y'] ?? 2),
            'rot'     => (int) ($sigTransform['rot'] ?? 0),
            'opacity' => (int) ($sigTransform['opacity'] ?? 96),
        ],
        'backHeading'    => (string) $row['back_heading'],
        'backRule1'      => (string) ($backRules[0] ?? ''),
        'backRule2'      => (string) ($backRules[1] ?? ''),
        'backRule3'      => (string) ($backRules[2] ?? ''),
        'backRules'      => array_values($backRules),
        'layoutSettings' => $layoutSettings,
    ];
}

/**
 * تحويل سجل جدول cards إلى بنية موحدة للواجهة والطباعة.
 */
function cerd_normalize_card_row(array $row): array
{
    $dyn = json_decode((string) ($row['dynamic_fields'] ?? ''), true);
    $ovr = json_decode((string) ($row['card_overrides_json'] ?? ''), true);

    return [
        'id'             => (int) $row['id'],
        'templateId'     => (int) $row['template_id'],
        'departmentId'   => $row['department_id'] !== null ? (int) $row['department_id'] : null,
        'serial'         => (string) $row['serial_number'],
        'isSerialCustom' => ((int) ($row['is_serial_custom'] ?? 0)) === 1,
        'name'           => (string) $row['holder_name'],
        'dept'           => (string) $row['department_name'],
        'role'           => (string) $row['job_title'],
        'phone'          => (string) ($row['phone_number'] ?? ''),
        'date'           => (string) $row['issue_date_label'],
        'photoSrc'       => $row['photo_src'] ?: '',
        'qrPayload'      => (string) ($row['qr_payload'] ?? ''),
        'dynamicFields'  => is_array($dyn) ? $dyn : [],
        'overrides'      => is_array($ovr) ? $ovr : [],
        'status'         => (string) ($row['status'] ?? 'active'),
        'printedCount'   => (int) ($row['printed_count'] ?? 0),
        'lastPrintedAt'  => $row['last_printed_at'] ?: null,
        'createdAt'      => $row['created_at'] ?: null,
        'updatedAt'      => $row['updated_at'] ?: null,
    ];
}

/**
 * جلب القالب الثابت الافتراضي من قاعدة البيانات.
 */
function cerd_get_default_template(?PDO $pdo = null): array
{
    $pdo = $pdo ?? cerd_get_pdo();
    $stmt = $pdo->query(
        'SELECT * FROM card_templates WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1'
    );
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('لم يتم العثور على قالب افتراضي في جدول card_templates.');
    }

    return cerd_normalize_template_row($row);
}

/**
 * جلب قائمة الإدارات النشطة.
 */
function cerd_get_departments(?PDO $pdo = null): array
{
    $pdo = $pdo ?? cerd_get_pdo();
    $stmt = $pdo->query(
        'SELECT id, dept_code, dept_name FROM departments WHERE is_active = 1 ORDER BY id ASC'
    );
    return $stmt->fetchAll();
}

/**
 * العثور على إدارة بالاسم أو إنشاؤها تلقائياً عند الطباعة/الحفظ.
 */
function cerd_find_or_create_department(PDO $pdo, string $deptName): ?int
{
    $clean = trim($deptName);
    if ($clean === '') {
        return null;
    }

    $sel = $pdo->prepare('SELECT id FROM departments WHERE dept_name = ? LIMIT 1');
    $sel->execute([$clean]);
    $existingId = $sel->fetchColumn();
    if ($existingId !== false) {
        return (int) $existingId;
    }

    $ins = $pdo->prepare('INSERT INTO departments (dept_name, is_active) VALUES (?, 1)');
    $ins->execute([$clean]);
    return (int) $pdo->lastInsertId();
}

/**
 * جلب البطاقات المحفوظة من قاعدة البيانات مع دعم البحث.
 */
function cerd_list_cards(string $search = '', int $limit = 150, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? cerd_get_pdo();
    $limit = max(1, min(500, $limit));
    $q = trim($search);

    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql = 'SELECT * FROM cards
                WHERE deleted_at IS NULL
                  AND (holder_name LIKE ? OR serial_number LIKE ? OR department_name LIKE ? OR job_title LIKE ? OR phone_number LIKE ?)
                ORDER BY COALESCE(last_printed_at, updated_at) DESC, id DESC
                LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like, $like, $like, $like, $like]);
    } else {
        $sql = 'SELECT * FROM cards
                WHERE deleted_at IS NULL
                ORDER BY COALESCE(last_printed_at, updated_at) DESC, id DESC
                LIMIT ' . $limit;
        $stmt = $pdo->query($sql);
    }

    return array_map('cerd_normalize_card_row', $stmt->fetchAll());
}

/**
 * جلب بطاقات محددة وفق مصفوفة أرقام المعرفات بالترتيب نفسه.
 */
function cerd_get_cards_by_ids(array $ids, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? cerd_get_pdo();
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (count($cleanIds) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM cards WHERE deleted_at IS NULL AND id IN ({$placeholders})");
    $stmt->execute($cleanIds);

    $byId = [];
    foreach ($stmt->fetchAll() as $row) {
        $norm = cerd_normalize_card_row($row);
        $byId[$norm['id']] = $norm;
    }

    $ordered = [];
    foreach ($cleanIds as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }
    return $ordered;
}

/**
 * جلب سجل دفعات الطباعة الأخيرة مع ملخص البطاقات في كل دفعة لإتاحة إعادة طباعة الدفعة كاملة.
 */
function cerd_list_print_batches(int $limit = 25, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? cerd_get_pdo();
    $limit = max(1, min(100, $limit));

    $batches = $pdo->query(
        'SELECT id, batch_code, template_id, paper_size, orientation, cards_per_page, mirror_mode, total_cards, created_at
         FROM print_batches
         ORDER BY id DESC
         LIMIT ' . $limit
    )->fetchAll();

    if (count($batches) === 0) {
        return [];
    }

    $batchIds = array_map(static fn(array $b): int => (int) $b['id'], $batches);
    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
    $itemStmt = $pdo->prepare(
        "SELECT batch_id, card_id, page_number, slot_index, snapshot_json
         FROM print_batch_items
         WHERE batch_id IN ({$placeholders})
         ORDER BY page_number ASC, slot_index ASC"
    );
    $itemStmt->execute($batchIds);

    $itemsByBatch = [];
    foreach ($itemStmt->fetchAll() as $item) {
        $snap = json_decode((string) $item['snapshot_json'], true);
        $itemsByBatch[(int) $item['batch_id']][] = [
            'cardId'     => $item['card_id'] !== null ? (int) $item['card_id'] : null,
            'pageNumber' => (int) $item['page_number'],
            'slotIndex'  => (int) $item['slot_index'],
            'card'       => is_array($snap) ? $snap : [],
        ];
    }

    foreach ($batches as &$b) {
        $bid = (int) $b['id'];
        $b['id'] = $bid;
        $b['template_id'] = (int) $b['template_id'];
        $b['cards_per_page'] = (int) $b['cards_per_page'];
        $b['total_cards'] = (int) $b['total_cards'];
        $b['items'] = $itemsByBatch[$bid] ?? [];
    }
    unset($b);

    return $batches;
}

/**
 * جلب بطاقات دفعة طباعة محددة (لإعادة طباعة الدفعة أو عرضها في print4.php).
 */
function cerd_get_batch_cards(int $batchId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? cerd_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT pbi.card_id, pbi.snapshot_json, c.id AS live_id, c.template_id, c.department_id,
                c.serial_number, c.is_serial_custom, c.holder_name, c.department_name,
                c.job_title, c.phone_number, c.issue_date_label, c.photo_src, c.qr_payload,
                c.dynamic_fields, c.card_overrides_json, c.status, c.printed_count,
                c.last_printed_at, c.created_at, c.updated_at
         FROM print_batch_items pbi
         LEFT JOIN cards c ON c.id = pbi.card_id AND c.deleted_at IS NULL
         WHERE pbi.batch_id = ?
         ORDER BY pbi.page_number ASC, pbi.slot_index ASC'
    );
    $stmt->execute([$batchId]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['live_id'] !== null) {
            $row['id'] = $row['live_id'];
            $result[] = cerd_normalize_card_row($row);
        } else {
            $snap = json_decode((string) $row['snapshot_json'], true);
            if (is_array($snap)) {
                $result[] = $snap;
            }
        }
    }
    return $result;
}

/**
 * توليد سيريال متوافق مع الواجهة إذا لم يُمرّر جاهزاً.
 */
function cerd_generate_serial(array $data): string
{
    $rawDate = trim((string) ($data['date'] ?? '2026'));
    $map = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $asciiDate = strtr($rawDate, $map);
    $yearMatch = preg_match('/\d{4}/', $asciiDate, $m) ? $m[0] : (preg_replace('/\D+/', '', $asciiDate) ?: '2026');
    $seed = implode('|', [
        trim((string) ($data['uniName'] ?? '')),
        trim((string) ($data['name'] ?? '')),
        trim((string) ($data['dept'] ?? '')),
        trim((string) ($data['role'] ?? '')),
        trim((string) ($data['phone'] ?? '')),
        $rawDate,
    ]);
    $hash = 5381;
    $chars = preg_split('//u', $seed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $ch) {
        $code = mb_ord($ch, 'UTF-8');
        $hash = (($hash * 33) ^ $code) & 0xFFFFFFFF;
    }
    $num = str_pad((string) ($hash % 1000000), 6, '0', STR_PAD_LEFT);
    return "UHS-{$yearMatch}-{$num}";
}

/**
 * بناء نص حمولة QR بالصيغة القياسية للبطاقة.
 */
function cerd_build_qr_payload(array $data, string $serial, string $defaultUniName = ''): string
{
    $lines = [
        'الرقم: ' . $serial,
        'الجهة: ' . trim((string) ($data['uniName'] ?? $defaultUniName)),
        'الاسم: ' . trim((string) ($data['name'] ?? '')),
        'الإدارة: ' . trim((string) ($data['dept'] ?? '')),
        'الوظيفة: ' . trim((string) ($data['role'] ?? '')),
        'الهاتف: ' . trim((string) ($data['phone'] ?? '')),
        'الإصدار: ' . trim((string) ($data['date'] ?? '')),
    ];
    return implode("\n", $lines);
}

/**
 * حفظ بطاقة جديدة أو تحديث بطاقة قائمة في جدول cards.
 */
function cerd_upsert_card(PDO $pdo, array $input, array $template, bool $incrementPrint = false): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('يرجى إدخال اسم حامل البطاقة قبل الحفظ أو الطباعة.');
    }

    $dept = trim((string) ($input['dept'] ?? ''));
    $role = trim((string) ($input['role'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $dateLabel = trim((string) ($input['date'] ?? ''));
    if ($dateLabel === '') {
        $dateLabel = '٢٠٢٦';
    }

    $customSerial = trim((string) ($input['serial'] ?? ''));
    $isSerialCustom = !empty($input['isSerialCustom']) && $customSerial !== '' ? 1 : 0;
    $serial = $customSerial !== '' ? $customSerial : cerd_generate_serial([
        'uniName' => $input['uniName'] ?? $template['uniName'],
        'name'    => $name,
        'dept'    => $dept,
        'role'    => $role,
        'phone'   => $phone,
        'date'    => $dateLabel,
    ]);

    $photoSrc = cerd_sanitize_media_src($input['photoSrc'] ?? null);
    $qrPayload = trim((string) ($input['qrPayload'] ?? ''));
    if ($qrPayload === '') {
        $qrPayload = cerd_build_qr_payload($input, $serial, $template['uniName']);
    }

    $deptId = $dept !== '' ? cerd_find_or_create_department($pdo, $dept) : null;
    $dynamicFields = isset($input['dynamicFields']) && is_array($input['dynamicFields'])
        ? json_encode($input['dynamicFields'], JSON_UNESCAPED_UNICODE)
        : null;
    $overrides = isset($input['overrides']) && is_array($input['overrides'])
        ? json_encode($input['overrides'], JSON_UNESCAPED_UNICODE)
        : null;

    $cardId = (int) ($input['id'] ?? 0);
    $existing = null;

    if ($cardId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM cards WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$cardId]);
        $existing = $stmt->fetch() ?: null;
    }

    if (!$existing) {
        $stmt = $pdo->prepare('SELECT * FROM cards WHERE serial_number = ? LIMIT 1');
        $stmt->execute([$serial]);
        $existing = $stmt->fetch() ?: null;
    }

    if ($existing) {
        // ضمان عدم تعارض السيريال مع سجل آخر مختلف عند التعديل
        if ($existing['serial_number'] !== $serial) {
            $dup = $pdo->prepare('SELECT id FROM cards WHERE serial_number = ? AND id <> ? LIMIT 1');
            $dup->execute([$serial, (int) $existing['id']]);
            if ($dup->fetchColumn() !== false) {
                $serial = $serial . '-' . (int) $existing['id'];
            }
        }

        $prevPrints = (int) $existing['printed_count'];
        $nextPrints = $incrementPrint ? $prevPrints + 1 : $prevPrints;
        $nextStatus = $incrementPrint
            ? ($prevPrints >= 1 ? 'reprinted' : 'active')
            : (string) $existing['status'];

        $upd = $pdo->prepare(
            'UPDATE cards SET
                template_id = ?,
                department_id = ?,
                serial_number = ?,
                is_serial_custom = ?,
                holder_name = ?,
                department_name = ?,
                job_title = ?,
                phone_number = ?,
                issue_date_label = ?,
                photo_src = COALESCE(?, photo_src),
                qr_payload = ?,
                dynamic_fields = COALESCE(?, dynamic_fields),
                card_overrides_json = COALESCE(?, card_overrides_json),
                status = ?,
                printed_count = ?,
                last_printed_at = ' . ($incrementPrint ? 'NOW()' : 'last_printed_at') . ',
                deleted_at = NULL
             WHERE id = ?'
        );
        $upd->execute([
            $template['id'],
            $deptId,
            $serial,
            $isSerialCustom,
            $name,
            $dept,
            $role,
            $phone,
            $dateLabel,
            $photoSrc,
            $qrPayload,
            $dynamicFields,
            $overrides,
            $nextStatus,
            $nextPrints,
            (int) $existing['id'],
        ]);
        $savedId = (int) $existing['id'];
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO cards (
                template_id, department_id, serial_number, is_serial_custom,
                holder_name, department_name, job_title, phone_number,
                issue_date_label, photo_src, qr_payload, dynamic_fields,
                card_overrides_json, status, printed_count, last_printed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($incrementPrint ? 'NOW()' : 'NULL') . ')'
        );
        $ins->execute([
            $template['id'],
            $deptId,
            $serial,
            $isSerialCustom,
            $name,
            $dept,
            $role,
            $phone,
            $dateLabel,
            $photoSrc,
            $qrPayload,
            $dynamicFields,
            $overrides,
            'active',
            $incrementPrint ? 1 : 0,
        ]);
        $savedId = (int) $pdo->lastInsertId();
    }

    $sel = $pdo->prepare('SELECT * FROM cards WHERE id = ? LIMIT 1');
    $sel->execute([$savedId]);
    $row = $sel->fetch();
    return cerd_normalize_card_row($row);
}

/**
 * حفظ مجموعة البطاقات المطبوعة + إنشاء سجل دفعة طباعة ولقطاتها في معاملة واحدة.
 */
function cerd_record_print_batch(array $cardsInput, string $mirrorMode = 'text'): array
{
    if (count($cardsInput) === 0) {
        throw new InvalidArgumentException('لا توجد بطاقات لتسجيل الطباعة.');
    }
    if (!in_array($mirrorMode, ['normal', 'text', 'full'], true)) {
        $mirrorMode = 'text';
    }

    $pdo = cerd_get_pdo();
    $template = cerd_get_default_template($pdo);

    $pdo->beginTransaction();
    try {
        $savedCards = [];
        foreach ($cardsInput as $cardInput) {
            $savedCards[] = cerd_upsert_card($pdo, is_array($cardInput) ? $cardInput : [], $template, true);
        }

        $batchCode = 'BATCH-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $batchIns = $pdo->prepare(
            'INSERT INTO print_batches (batch_code, template_id, paper_size, orientation, cards_per_page, mirror_mode, total_cards)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $batchIns->execute([
            $batchCode,
            $template['id'],
            'A4',
            'portrait',
            4,
            $mirrorMode,
            count($savedCards),
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $itemIns = $pdo->prepare(
            'INSERT INTO print_batch_items (batch_id, card_id, page_number, slot_index, snapshot_json)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($savedCards as $idx => $saved) {
            $pageNumber = intdiv($idx, 4) + 1;
            $slotIndex = ($idx % 4) + 1;
            $snapshot = [
                'id'       => $saved['id'],
                'serial'   => $saved['serial'],
                'name'     => $saved['name'],
                'dept'     => $saved['dept'],
                'role'     => $saved['role'],
                'phone'    => $saved['phone'],
                'date'     => $saved['date'],
                'uniName'  => $template['uniName'],
                'photoSrc' => $saved['photoSrc'],
            ];
            $itemIns->execute([
                $batchId,
                $saved['id'],
                $pageNumber,
                $slotIndex,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pdo->commit();

        return [
            'batchId'    => $batchId,
            'batchCode'  => $batchCode,
            'savedCards' => $savedCards,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * تحديث البيانات الثابتة للقالب الافتراضي.
 */
function cerd_save_template(array $input): array
{
    $pdo = cerd_get_pdo();
    $current = cerd_get_default_template($pdo);

    $uniName = trim((string) ($input['uniName'] ?? $current['uniName']));
    $sigCaption = trim((string) ($input['sigCaption'] ?? $current['sigCaption']));
    $sigStyle = (string) ($input['sigStyle'] ?? $current['sigStyle']);
    if (!in_array($sigStyle, ['silver', 'gold', 'cyan', 'dark', 'custom'], true)) {
        $sigStyle = $current['sigStyle'];
    }

    $backHeading = trim((string) ($input['backHeading'] ?? $current['backHeading']));
    $backRules = [
        trim((string) ($input['backRule1'] ?? $current['backRule1'])),
        trim((string) ($input['backRule2'] ?? $current['backRule2'])),
        trim((string) ($input['backRule3'] ?? $current['backRule3'])),
    ];

    $sigTransform = $input['sigTransform'] ?? $current['sigTransform'];
    $logoSrc = array_key_exists('logoSrc', $input) ? cerd_sanitize_media_src($input['logoSrc']) : $current['logoSrc'];
    $sigImageSrc = array_key_exists('sigImageSrc', $input) ? cerd_sanitize_media_src($input['sigImageSrc']) : $current['sigImageSrc'];

    $upd = $pdo->prepare(
        'UPDATE card_templates SET
            institution_name = ?,
            logo_src = COALESCE(?, logo_src),
            sig_caption = ?,
            sig_style = ?,
            sig_image_src = COALESCE(?, sig_image_src),
            sig_transform_json = ?,
            back_heading = ?,
            back_rules_json = ?
         WHERE id = ?'
    );
    $upd->execute([
        $uniName !== '' ? $uniName : $current['uniName'],
        $logoSrc,
        $sigCaption !== '' ? $sigCaption : $current['sigCaption'],
        $sigStyle,
        $sigImageSrc,
        json_encode($sigTransform, JSON_UNESCAPED_UNICODE),
        $backHeading !== '' ? $backHeading : $current['backHeading'],
        json_encode($backRules, JSON_UNESCAPED_UNICODE),
        $current['id'],
    ]);

    return cerd_get_default_template($pdo);
}

/**
 * حذف ناعم للبطاقة من السجل.
 */
function cerd_delete_card(int $cardId): bool
{
    if ($cardId <= 0) {
        return false;
    }
    $pdo = cerd_get_pdo();
    $stmt = $pdo->prepare('UPDATE cards SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$cardId]);
    return $stmt->rowCount() > 0;
}

