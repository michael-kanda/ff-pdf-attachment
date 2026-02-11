<?php
/**
 * Plugin Name: FF PDF Attachment
 * Plugin URI: https://github.com/your-repo/ff-pdf-attachment
 * Description: Hängt automatisch ein kompaktes PDF mit allen Formulardaten an Fluent Forms E-Mail-Benachrichtigungen an. Keine externen Abhängigkeiten.
 * Version: 2.1.0
 * Author: Custom
 * License: GPL-2.0+
 * Text Domain: ff-pdf-attachment
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FF_PDF_ATT_VERSION', '2.1.0');
define('FF_PDF_ATT_PATH', plugin_dir_path(__FILE__));

require_once FF_PDF_ATT_PATH . 'includes/class-simple-pdf.php';

class FF_PDF_Attachment
{
    const EXCLUDED_PREFIXES = [
        '__fluent_form_embded_post_id',
        '_fluentform_',
        '_wp_http_referer',
        'g-recaptcha-response',
        'hcaptcha-response',
        'cf-turnstile-response',
        'h-captcha-response',
    ];

    private static $temp_files = [];
    private static $processed = [];

    public static function init()
    {
        // NUR den primären Hook (feuert bei jeder E-Mail-Notification)
        add_filter('fluentform/filter_email_attachments', [__CLASS__, 'attach_pdf'], 10, 4);

        add_action('shutdown', [__CLASS__, 'cleanup']);
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    /**
     * Hook: fluentform/filter_email_attachments
     */
    public static function attach_pdf($attachments, $notification, $form, $submittedData)
    {
        if (!is_array($attachments)) {
            $attachments = [];
        }

        // Formular-Filter
        $enabled_ids = self::get_enabled_form_ids();
        if (!empty($enabled_ids) && !in_array((int) $form->id, $enabled_ids, true)) {
            return $attachments;
        }

        // Duplikat-Schutz: gleiches Formular + gleiche Daten = gleiches PDF wiederverwenden
        $hash = md5(serialize($submittedData) . $form->id);
        if (isset(self::$processed[$hash])) {
            $existing = self::$processed[$hash];
            if (file_exists($existing)) {
                $attachments[] = $existing;
            }
            return $attachments;
        }

        try {
            $pdf_path = self::generate_pdf($submittedData, $form);
            if ($pdf_path && file_exists($pdf_path)) {
                $attachments[] = $pdf_path;
                self::$temp_files[] = $pdf_path;
                self::$processed[$hash] = $pdf_path;
            }
        } catch (\Exception $e) {
            error_log('[FF PDF Attachment] Fehler: ' . $e->getMessage());
        }

        return $attachments;
    }

    private static function generate_pdf($data, $form)
    {
        $pdf = new SimplePDF();

        $color = get_option('ff_pdf_primary_color', '#2563eb');
        if (!empty($color)) {
            $pdf->setPrimaryColor($color);
        }

        $pdf->addPage();

        $form_title = isset($form->title) ? $form->title : 'Formular';
        $site_name  = get_bloginfo('name');
        $date       = wp_date('d.m.Y \u\m H:i \U\h\r');

        $pdf->addHeader($form_title, $site_name . '  -  Eingegangen am ' . $date);

        $field_labels    = self::get_field_labels($form);
        $repeater_configs = self::get_repeater_configs($form);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        foreach ($data as $key => $value) {
            if (self::is_excluded_field($key)) {
                continue;
            }
            if (is_null($value)) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $label = isset($field_labels[$key]) ? $field_labels[$key] : self::humanize_key($key);

            // ── Repeater-Felder ──
            if (is_array($value) && self::is_repeater_data($value)) {
                $sub_labels = isset($repeater_configs[$key]) ? $repeater_configs[$key] : [];
                self::add_repeater_rows($pdf, $label, $value, $sub_labels);
                continue;
            }

            // ── Normale Arrays (Checkboxen, Name-Feld etc.) ──
            if (is_array($value) || is_object($value)) {
                $flat = self::flatten_value((array) $value);
                if (!empty(trim($flat))) {
                    $pdf->addTableRow($label, $flat);
                }
                continue;
            }

            // ── Einfacher Wert ──
            $pdf->addTableRow($label, trim(strval($value)));
        }

        $pdf->addFooter('Generiert von ' . $site_name . '  |  Formular #' . intval($form->id));

        // Speichern
        $safe_title = sanitize_file_name($form->title ?? 'formular');
        $filename   = substr($safe_title, 0, 50) . '_' . wp_date('Y-m-d_H-i-s') . '_' . wp_rand(1000, 9999) . '.pdf';

        $upload_dir = wp_upload_dir();
        $pdf_dir    = $upload_dir['basedir'] . '/ff-pdf-temp';

        if (!is_dir($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
            @file_put_contents($pdf_dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
            @file_put_contents($pdf_dir . '/index.php', '<?php // Silence is golden.');
        }

        $pdf_path = $pdf_dir . '/' . $filename;
        file_put_contents($pdf_path, $pdf->output());
        return $pdf_path;
    }

    // ── Repeater-Erkennung und -Ausgabe ────────────────────────────

    /**
     * Prüfen ob ein Array Repeater-Daten enthält:
     * Numerisch indiziertes Array von assoziativen Arrays.
     */
    private static function is_repeater_data($value)
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }
        // Numerisch indiziert?
        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }
        // Mindestens ein Element muss ein assoziatives Array sein
        foreach ($value as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $rk) {
                    if (is_string($rk) && !is_numeric($rk)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Repeater-Daten als nummerierte Zeilen ins PDF schreiben.
     */
    private static function add_repeater_rows($pdf, $section_label, $rows, $sub_labels)
    {
        $count = count($rows);

        foreach ($rows as $idx => $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }

            $num = $idx + 1;

            foreach ($row as $sub_key => $sub_value) {
                if (is_null($sub_value) || (is_string($sub_value) && trim($sub_value) === '')) {
                    continue;
                }

                $sub_label = isset($sub_labels[$sub_key])
                    ? $sub_labels[$sub_key]
                    : self::humanize_key($sub_key);

                // Label-Format: "Mitarbeiter 1 - Vorname" oder bei nur 1 Eintrag: "Mitarbeiter - Vorname"
                if ($count > 1) {
                    $display_label = $section_label . ' ' . $num . ' - ' . $sub_label;
                } else {
                    $display_label = $section_label . ' - ' . $sub_label;
                }

                if (is_array($sub_value)) {
                    $sub_value = self::flatten_value($sub_value);
                }

                $val = trim(strval($sub_value));
                if (!empty($val)) {
                    $pdf->addTableRow($display_label, $val);
                }
            }
        }
    }

    // ── Repeater-Config aus Formular laden ─────────────────────────

    private static function get_repeater_configs($form)
    {
        $configs = [];
        try {
            $ff = null;
            if (isset($form->form_fields)) {
                $ff = is_string($form->form_fields)
                    ? json_decode($form->form_fields, true)
                    : (array) $form->form_fields;
            }
            if (!empty($ff['fields'])) {
                self::extract_repeater_configs($ff['fields'], $configs);
            }
        } catch (\Exception $e) {}
        return $configs;
    }

    private static function extract_repeater_configs($fields, &$configs)
    {
        if (!is_array($fields)) return;

        foreach ($fields as $field) {
            if (!is_array($field)) continue;

            $element = $field['element'] ?? '';
            $name    = $field['attributes']['name'] ?? '';

            if (in_array($element, ['repeater_field', 'repeat_field'], true) && !empty($name)) {
                $sub = [];
                if (!empty($field['fields']) && is_array($field['fields'])) {
                    foreach ($field['fields'] as $sf) {
                        if (is_array($sf)) {
                            $sn = $sf['attributes']['name'] ?? '';
                            $sl = $sf['settings']['label'] ?? '';
                            if (!empty($sn) && !empty($sl)) {
                                $sub[$sn] = wp_strip_all_tags($sl);
                            }
                        }
                    }
                }
                if (!empty($field['columns']) && is_array($field['columns'])) {
                    foreach ($field['columns'] as $col) {
                        if (!empty($col['fields'])) {
                            foreach ($col['fields'] as $sf) {
                                if (is_array($sf)) {
                                    $sn = $sf['attributes']['name'] ?? '';
                                    $sl = $sf['settings']['label'] ?? '';
                                    if (!empty($sn) && !empty($sl)) {
                                        $sub[$sn] = wp_strip_all_tags($sl);
                                    }
                                }
                            }
                        }
                    }
                }
                $configs[$name] = $sub;
            }

            // Rekursion
            if (!empty($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $col) {
                    if (!empty($col['fields'])) {
                        self::extract_repeater_configs($col['fields'], $configs);
                    }
                }
            }
            if (!empty($field['fields']) && is_array($field['fields']) && !in_array($element, ['repeater_field', 'repeat_field'], true)) {
                self::extract_repeater_configs($field['fields'], $configs);
            }
        }
    }

    // ── Labels extrahieren ─────────────────────────────────────────

    private static function get_field_labels($form)
    {
        $labels = [];
        try {
            $ff = null;
            if (isset($form->form_fields)) {
                $ff = is_string($form->form_fields)
                    ? json_decode($form->form_fields, true)
                    : (array) $form->form_fields;
            }
            if (!empty($ff['fields'])) {
                self::extract_labels($ff['fields'], $labels);
            }
        } catch (\Exception $e) {}
        return $labels;
    }

    private static function extract_labels($fields, &$labels)
    {
        if (!is_array($fields)) return;

        foreach ($fields as $field) {
            if (!is_array($field)) continue;

            $name  = $field['attributes']['name'] ?? '';
            $label = $field['settings']['label'] ?? '';

            if (!empty($name) && !empty($label)) {
                $labels[$name] = wp_strip_all_tags($label);
            }

            if (!empty($field['fields']) && is_array($field['fields'])) {
                foreach ($field['fields'] as $sub) {
                    if (is_array($sub)) {
                        $sn = $sub['attributes']['name'] ?? '';
                        $sl = $sub['settings']['label'] ?? '';
                        if (!empty($sn) && !empty($sl)) {
                            $labels[$sn] = wp_strip_all_tags($sl);
                        }
                        if (!empty($sub['fields'])) {
                            self::extract_labels($sub['fields'], $labels);
                        }
                    }
                }
            }

            if (!empty($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $col) {
                    if (!empty($col['fields'])) {
                        self::extract_labels($col['fields'], $labels);
                    }
                }
            }
        }
    }

    // ── Hilfsfunktionen ────────────────────────────────────────────

    private static function flatten_value($value, $depth = 0)
    {
        if ($depth > 4) return '';
        if (is_string($value)) return $value;
        if (!is_array($value)) return strval($value);

        $parts = [];
        foreach ($value as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $nested = self::flatten_value((array) $v, $depth + 1);
                if (!empty(trim($nested))) {
                    $prefix = (is_string($k) && !is_numeric($k)) ? self::humanize_key($k) . ': ' : '';
                    $parts[] = $prefix . $nested;
                }
            } elseif (!is_null($v) && trim(strval($v)) !== '') {
                $prefix = (is_string($k) && !is_numeric($k)) ? self::humanize_key($k) . ': ' : '';
                $parts[] = $prefix . strval($v);
            }
        }
        return implode("\n", $parts);
    }

    private static function is_excluded_field($key)
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (strpos($key, $prefix) !== false) return true;
        }
        return false;
    }

    private static function humanize_key($key)
    {
        $key = str_replace(['_', '-'], ' ', $key);
        $key = preg_replace('/\s+/', ' ', $key);
        return ucfirst(trim($key));
    }

    public static function cleanup()
    {
        foreach (self::$temp_files as $file) {
            if (file_exists($file)) @unlink($file);
        }
        self::$temp_files = [];

        $pdf_dir = wp_upload_dir()['basedir'] . '/ff-pdf-temp';
        if (is_dir($pdf_dir)) {
            $files = glob($pdf_dir . '/*.pdf');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (filemtime($f) < (time() - 7200)) @unlink($f);
                }
            }
        }
    }

    private static function get_enabled_form_ids()
    {
        $opt = get_option('ff_pdf_enabled_forms', '');
        return !empty($opt) ? array_map('intval', array_filter(explode(',', $opt))) : [];
    }

    // ── Admin ──────────────────────────────────────────────────────

    public static function add_settings_page()
    {
        add_options_page('FF PDF Attachment', 'FF PDF Attachment', 'manage_options', 'ff-pdf-attachment', [__CLASS__, 'render_settings_page']);
    }

    public static function register_settings()
    {
        register_setting('ff_pdf_settings', 'ff_pdf_enabled_forms', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ff_pdf_settings', 'ff_pdf_primary_color', ['sanitize_callback' => 'sanitize_hex_color']);
    }

    public static function render_settings_page()
    {
        ?>
        <div class="wrap">
            <h1>FF PDF Attachment v<?php echo FF_PDF_ATT_VERSION; ?></h1>
            <div class="notice notice-success"><p>&#10003; Plugin aktiv. Keine Dependencies nötig.</p></div>
            <form method="post" action="options.php">
                <?php settings_fields('ff_pdf_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="ff_pdf_enabled_forms">Formular-IDs</label></th>
                        <td>
                            <input type="text" id="ff_pdf_enabled_forms" name="ff_pdf_enabled_forms"
                                value="<?php echo esc_attr(get_option('ff_pdf_enabled_forms', '')); ?>"
                                class="regular-text" placeholder="z.B. 3,7,12" />
                            <p class="description">Komma-getrennt. Leer = alle Formulare.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ff_pdf_primary_color">Akzentfarbe</label></th>
                        <td>
                            <input type="text" id="ff_pdf_primary_color" name="ff_pdf_primary_color"
                                value="<?php echo esc_attr(get_option('ff_pdf_primary_color', '#2563eb')); ?>"
                                class="small-text" placeholder="#2563eb" />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Speichern'); ?>
            </form>
        </div>
        <?php
    }
}

add_action('plugins_loaded', function () {
    if (defined('FLUENTFORM') || defined('FLUENTFORM_VERSION')) {
        FF_PDF_Attachment::init();
    }
});
