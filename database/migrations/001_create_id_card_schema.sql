-- ============================================================================
-- Migration: 001_create_id_card_schema.sql
-- Description: هيكل قاعدة بيانات مرن (لين) لنظام البطاقات الجامعية (cerd)
--              يفصل بين البيانات الثابتة للقوالب والبيانات الديناميكية لحاملي البطاقات
-- Target DBMS: MySQL 8.0+ / 9.x (Laragon) — Charset: utf8mb4_unicode_ci
-- Created: 2026-11-11
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `cerd_cards`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `cerd_cards`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ----------------------------------------------------------------------------
-- 0. جدول تتبع الترحيلات (Schema Migrations)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version`     VARCHAR(64)  NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `applied_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1. جدول قوالب البطاقات (البيانات الثابتة المشتركة وإعدادات التصميم)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_templates` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_code`        VARCHAR(64)     NOT NULL COMMENT 'معرف فريد للقالب مثل uhs-default',
    `template_name`        VARCHAR(150)    NOT NULL COMMENT 'اسم وصفي للقالب',

    -- الهوية والنصوص الثابتة للجهة
    `institution_name`     VARCHAR(191)    NOT NULL DEFAULT 'جامعة العلوم الصحية',
    `logo_src`             MEDIUMTEXT      NULL     COMMENT 'مسار ملف الشعار أو Data URI',
    `sig_caption`          VARCHAR(150)    NOT NULL DEFAULT 'توقيع المدير الإداري',
    `sig_style`            ENUM('silver', 'gold', 'cyan', 'dark', 'custom') NOT NULL DEFAULT 'silver',
    `sig_image_src`        MEDIUMTEXT      NULL     COMMENT 'مسار صورة التوقيع المخصص أو الافتراضي',

    -- إعدادات تموضع التوقيع (scale, x, y, rot, opacity) بصيغة JSON مرنة
    `sig_transform_json`   JSON            NULL     COMMENT '{"scale":108,"x":0,"y":2,"rot":0,"opacity":96}',

    -- البيانات الثابتة للوجه الخلفي
    `back_heading`         VARCHAR(150)    NOT NULL DEFAULT 'إرشادات هامة',
    `back_rules_json`      JSON            NULL     COMMENT 'مصفوفة بنود الإرشادات بالترتيب',

    -- إعدادات التخطيط المرنة (الخط، موضع QR، العلامة المائية الخلفية، وضع العكس الافتراضي)
    `layout_settings_json` JSON            NULL     COMMENT 'إعدادات الخط، الألوان، التموضع، الطباعة',

    `is_default`           TINYINT(1)      NOT NULL DEFAULT 0,
    `is_active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_card_templates_code` (`template_code`),
    KEY `idx_card_templates_active_default` (`is_active`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. جدول الإدارات والأقسام (مرجع اختياري مرن لتوحيد الأسماء)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dept_code`  VARCHAR(64)     NULL,
    `dept_name`  VARCHAR(191)    NOT NULL,
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departments_name` (`dept_name`),
    UNIQUE KEY `uq_departments_code` (`dept_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. جدول البطاقات وحامليها (البيانات الديناميكية الأساسية + التوسع عبر JSON)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cards` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_id`         BIGINT UNSIGNED NOT NULL COMMENT 'ربط بالقالب الثابت المستخدم',
    `department_id`       BIGINT UNSIGNED NULL     COMMENT 'ربط اختياري بدليل الإدارات',

    -- المعرف الفريد والسيريال نمبر
    `serial_number`       VARCHAR(64)     NOT NULL COMMENT 'مثال: UHS-2026-745200',
    `is_serial_custom`    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '0=مولد تلقائياً، 1=معدل يدوياً',

    -- الحقول الديناميكية لحامل البطاقة (ظاهرة على الوجه الأمامي)
    `holder_name`         VARCHAR(191)    NOT NULL COMMENT 'الاسم الكامل لحامل البطاقة',
    `department_name`     VARCHAR(191)    NOT NULL COMMENT 'اسم الإدارة المطبوع على البطاقة',
    `job_title`           VARCHAR(191)    NOT NULL COMMENT 'الوظيفة / المسمى الوظيفي',
    `phone_number`        VARCHAR(64)     NULL     COMMENT 'رقم الهاتف',
    `issue_date_label`    VARCHAR(64)     NOT NULL COMMENT 'تاريخ الإصدار كما يظهر بالبطاقة مثل ٢٠٢٦',
    `expiry_date`         DATE            NULL     COMMENT 'تاريخ انتهاء الصلاحية اختياري',

    -- وسائط وحمولة QR
    `photo_src`           MEDIUMTEXT      NULL     COMMENT 'مسار الصورة الشخصية أو Data URL',
    `qr_payload`          TEXT            NULL     COMMENT 'النص المشفر الفعلي داخل رمز QR',

    -- حاوية JSON مرنة لأي حقول ديناميكية إضافية أو استثناءات على مستوى البطاقة
    `dynamic_fields`      JSON            NULL     COMMENT 'حقول مرنة مثل {"national_id":"...","blood_type":"O+"}',
    `card_overrides_json` JSON            NULL     COMMENT 'تجاوز اختياري لعناصر القالب الثابت لهذه البطاقة فقط',

    -- الحالة والتدقيق
    `status`              ENUM('draft', 'active', 'suspended', 'expired', 'reprinted') NOT NULL DEFAULT 'active',
    `printed_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `last_printed_at`     TIMESTAMP       NULL     DEFAULT NULL,
    `deleted_at`          TIMESTAMP       NULL     DEFAULT NULL COMMENT 'حذف ناعم للحفاظ على السجل',
    `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cards_serial` (`serial_number`),
    KEY `idx_cards_template` (`template_id`),
    KEY `idx_cards_department` (`department_id`),
    KEY `idx_cards_holder_name` (`holder_name`),
    KEY `idx_cards_status_deleted` (`status`, `deleted_at`),

    CONSTRAINT `fk_cards_template`
        FOREIGN KEY (`template_id`) REFERENCES `card_templates` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_cards_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. تعريفات الحقول الديناميكية المخصصة (Metadata لحقول قابلة للإضافة مستقبلاً)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_field_definitions` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_id`    BIGINT UNSIGNED NULL     COMMENT 'NULL يعني حقل عام لجميع القوالب',
    `field_key`      VARCHAR(64)     NOT NULL COMMENT 'المفتاح البرمجي مثل blood_type',
    `field_label`    VARCHAR(150)    NOT NULL COMMENT 'التسمية الظاهرة للمستخدم مثل فصيلة الدم',
    `field_type`     ENUM('text', 'number', 'date', 'phone', 'select', 'textarea') NOT NULL DEFAULT 'text',
    `options_json`   JSON            NULL     COMMENT 'خيارات القائمة عند اختيار select',
    `default_value`  VARCHAR(255)    NULL,
    `is_required`    TINYINT(1)      NOT NULL DEFAULT 0,
    `show_on_card`   TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'هل يُعرض ضمن نصوص البطاقة؟',
    `include_in_qr`  TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'هل يُدرج في بيانات رمز QR؟',
    `sort_order`     INT             NOT NULL DEFAULT 100,
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_field_def_key` (`template_id`, `field_key`),
    KEY `idx_field_def_active_order` (`is_active`, `sort_order`),

    CONSTRAINT `fk_field_def_template`
        FOREIGN KEY (`template_id`) REFERENCES `card_templates` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. قيم الحقول الديناميكية المخصصة لكل بطاقة (EAV مفهرسة للبحث والتقارير)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_field_values` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `card_id`     BIGINT UNSIGNED NOT NULL,
    `field_id`    BIGINT UNSIGNED NOT NULL,
    `field_value` TEXT            NULL,
    `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_card_field_pair` (`card_id`, `field_id`),
    KEY `idx_cfv_field_value` (`field_id`, `field_value`(100)),

    CONSTRAINT `fk_cfv_card`
        FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_cfv_field_def`
        FOREIGN KEY (`field_id`) REFERENCES `card_field_definitions` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. دفعات الطباعة على ورق A4 وعناصرها (Print Batches & Snapshots)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `print_batches` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_code`     VARCHAR(64)     NOT NULL,
    `template_id`    BIGINT UNSIGNED NOT NULL,
    `paper_size`     VARCHAR(16)     NOT NULL DEFAULT 'A4',
    `orientation`    ENUM('portrait', 'landscape') NOT NULL DEFAULT 'portrait',
    `cards_per_page` TINYINT UNSIGNED NOT NULL DEFAULT 4,
    `mirror_mode`    ENUM('normal', 'text', 'full') NOT NULL DEFAULT 'text' COMMENT 'وضع عكس الاتجاه عند الطباعة',
    `total_cards`    INT UNSIGNED    NOT NULL DEFAULT 1,
    `notes`          VARCHAR(255)    NULL,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_print_batches_code` (`batch_code`),
    KEY `idx_print_batches_created` (`created_at`),

    CONSTRAINT `fk_print_batches_template`
        FOREIGN KEY (`template_id`) REFERENCES `card_templates` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_batch_items` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `batch_id`      BIGINT UNSIGNED  NOT NULL,
    `card_id`       BIGINT UNSIGNED  NULL COMMENT 'قد يكون NULL للبطاقات المؤقتة قبل الحفظ',
    `page_number`   TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'رقم صفحة A4 داخل الدفعة',
    `slot_index`    TINYINT UNSIGNED NOT NULL COMMENT 'ترتيب الخانة في الصفحة 1..4',
    `snapshot_json` JSON             NOT NULL COMMENT 'نسخة مجمدة من بيانات البطاقة لحظة الطباعة',
    `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_batch_page_slot` (`batch_id`, `page_number`, `slot_index`),
    KEY `idx_pbi_card` (`card_id`),

    CONSTRAINT `fk_pbi_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `print_batches` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_pbi_card`
        FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. البيانات الافتراضية الأولية المطابقة للقالب الحالي (Seed Data)
-- ----------------------------------------------------------------------------
INSERT INTO `card_templates` (
    `template_code`,
    `template_name`,
    `institution_name`,
    `logo_src`,
    `sig_caption`,
    `sig_style`,
    `sig_image_src`,
    `sig_transform_json`,
    `back_heading`,
    `back_rules_json`,
    `layout_settings_json`,
    `is_default`,
    `is_active`
) VALUES (
    'uhs-default',
    'القالب الرسمي - جامعة العلوم الصحية',
    'جامعة العلوم الصحية',
    NULL,
    'توقيع المدير الإداري',
    'silver',
    NULL,
    JSON_OBJECT('scale', 108, 'x', 0, 'y', 2, 'rot', 0, 'opacity', 96),
    'إرشادات هامة',
    JSON_ARRAY(
        'يجب إبلاغ الجامعة فوراً في حالة فقدان البطاقة',
        'يُسمح لحامل البطاقة بدخول كافة المرافق الحكومية والمستشفيات',
        'إذا عثرت على هذه البطاقة يرجى تسليمها إلى إدارة الجامعة\nأو الاتصال بالرقم: ٠١٢٣٤٥٦٧٨٩'
    ),
    JSON_OBJECT(
        'fontFamily', 'Tajawal',
        'qrPosition', JSON_OBJECT('side', 'front', 'align', 'bottom-left', 'leftPx', 236, 'bottomPx', 10, 'sizePx', 74),
        'backWatermark', JSON_OBJECT('widthPx', 200, 'heightPx', 200, 'leftPx', 174, 'topPx', 132, 'opacity', 0.16),
        'printDefaults', JSON_OBJECT('paper', 'A4', 'orientation', 'portrait', 'cardsPerPage', 4, 'mirrorMode', 'text')
    ),
    1,
    1
) ON DUPLICATE KEY UPDATE
    `template_name` = VALUES(`template_name`),
    `updated_at`    = CURRENT_TIMESTAMP;

INSERT INTO `departments` (`dept_code`, `dept_name`, `is_active`)
VALUES ('IT-COM', 'إدارة تقنية المعلومات والاتصالات', 1)
ON DUPLICATE KEY UPDATE `dept_code` = VALUES(`dept_code`);

-- ملاحظة: تبدأ جداول البطاقات (cards) فارغة دون بيانات تجريبية، وتُحفظ فيها البطاقات الفعلية عند الإضافة أو الطباعة.

INSERT INTO `schema_migrations` (`version`, `description`)
VALUES ('001_create_id_card_schema', 'إنشاء جداول القوالب الثابتة والبيانات الديناميكية للبطاقات وسجل الطباعة')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
