<?php
/**
 * Plugin Name: PicPrune Form Uploads
 * Description: Optimizes form image uploads and converts HEIC/HEIF attachments to JPG before email delivery.
 * Version: 1.3.0
 * Author: PicPrune Form Uploads Contributors
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: picprune-form-uploads
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PicPruneFormUploads
{
    private const OPTION_NAME = 'picprune_form_uploads_options';
    private const DEFAULTS = [
        'enabled' => 1,
        'jpeg_quality' => 72,
        'webp_quality' => 72,
        'png_compression' => 7,
        'max_width' => 1600,
        'max_height' => 1600,
        'min_size_kb' => 250,
        'keep_original_if_larger' => 1,
    ];

    /** @var array<string, bool> */
    private static array $processed_files = [];

    /** @var array<string, string> Original Contact Form 7 temp file path => converted JPG path. */
    private static array $converted_cf7_files = [];

    public static function init(): void
    {
        add_filter('wp_handle_upload', [__CLASS__, 'compress_wp_upload'], 20);
        add_filter('wpcf7_posted_data', [__CLASS__, 'optimize_posted_data_upload_urls'], 99);
        add_action('wpcf7_before_send_mail', [__CLASS__, 'compress_contact_form_7_uploads'], 5);
        add_filter('wpcf7_mail_components', [__CLASS__, 'replace_contact_form_7_mail_attachments'], 20, 3);
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'plugin_action_links']);
    }

    /**
     * Compress images that pass through WordPress' normal upload pipeline.
     *
     * @param array<string, mixed> $upload
     * @return array<string, mixed>
     */
    public static function compress_wp_upload(array $upload): array
    {
        if (empty($upload['file']) || !is_string($upload['file'])) {
            return $upload;
        }

        $mime = isset($upload['type']) && is_string($upload['type']) ? $upload['type'] : null;
        $converted_path = self::convert_heic_heif_to_jpeg($upload['file'], $mime);
        if ($converted_path !== null) {
            $old_file = $upload['file'];
            $upload['file'] = $converted_path;
            $upload['type'] = 'image/jpeg';

            if (!empty($upload['url']) && is_string($upload['url'])) {
                $upload['url'] = str_replace(basename($old_file), basename($converted_path), $upload['url']);
            }

            self::compress_image_file($converted_path, 'image/jpeg');
            return $upload;
        }

        self::compress_image_file($upload['file'], $mime);

        return $upload;
    }

    /**
     * Optimize uploaded-file URLs stored in CF7 posted data.
     *
     * This supports add-ons such as Drag and Drop Multiple File Upload for Contact Form 7,
     * which uploads files by AJAX, stores upload URLs in posted data, and later builds
     * mail attachments from those URLs.
     *
     * @param mixed $posted_data
     * @return mixed
     */
    public static function optimize_posted_data_upload_urls($posted_data)
    {
        if (!is_array($posted_data)) {
            return $posted_data;
        }

        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl']) || empty($uploads['basedir']) || !is_string($uploads['baseurl']) || !is_string($uploads['basedir'])) {
            return $posted_data;
        }

        return self::optimize_posted_data_value($posted_data, $uploads['baseurl'], $uploads['basedir']);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function optimize_posted_data_value($value, string $base_url, string $base_dir)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::optimize_posted_data_value($item, $base_url, $base_dir);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return self::optimize_upload_url_string($value, $base_url, $base_dir);
    }

    private static function optimize_upload_url_string(string $value, string $base_url, string $base_dir): string
    {
        $url = wp_unslash($value);
        $base_url = untrailingslashit($base_url);
        if (strpos($url, $base_url . '/') !== 0) {
            return $value;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $base_path = wp_parse_url($base_url, PHP_URL_PATH);
        if (!is_string($path)) {
            return $value;
        }

        $relative_path = $path;
        if (is_string($base_path) && $base_path !== '' && strpos($path, $base_path) === 0) {
            $relative_path = substr($path, strlen($base_path));
        }
        $relative_path = ltrim(rawurldecode($relative_path), '/');
        if ($relative_path === '' || strpos($relative_path, '../') !== false) {
            return $value;
        }

        $base_real = realpath($base_dir);
        $file_real = realpath(wp_normalize_path(trailingslashit($base_dir) . $relative_path));
        if ($base_real === false || $file_real === false || strpos(wp_normalize_path($file_real), wp_normalize_path($base_real)) !== 0) {
            return $value;
        }

        $converted_path = self::convert_heic_heif_to_jpeg($file_real, null);
        if ($converted_path !== null) {
            self::compress_image_file($converted_path, 'image/jpeg');
            return self::upload_path_to_url($converted_path, $base_url, $base_dir) ?: $value;
        }

        self::compress_image_file($file_real, null);
        return $value;
    }

    private static function upload_path_to_url(string $file_path, string $base_url, string $base_dir): ?string
    {
        $base_real = realpath($base_dir);
        $file_real = realpath($file_path);
        if ($base_real === false || $file_real === false) {
            return null;
        }

        $base_real = wp_normalize_path($base_real);
        $file_real = wp_normalize_path($file_real);
        if (strpos($file_real, $base_real) !== 0) {
            return null;
        }

        $relative = ltrim(substr($file_real, strlen($base_real)), '/');
        if ($relative === '') {
            return null;
        }

        return untrailingslashit($base_url) . '/' . str_replace('%2F', '/', rawurlencode($relative));
    }

    /**
     * Contact Form 7 keeps uploaded files in a temporary folder before sending mail.
     * Compress those temporary files before CF7 builds the message attachments.
     *
     * @param mixed $contact_form
     */
    public static function compress_contact_form_7_uploads($contact_form): void
    {
        if (!class_exists('WPCF7_Submission')) {
            return;
        }

        $submission = \WPCF7_Submission::get_instance();
        if (!$submission || !method_exists($submission, 'uploaded_files')) {
            return;
        }

        $uploaded_files = $submission->uploaded_files();
        if (!is_array($uploaded_files)) {
            return;
        }

        foreach ($uploaded_files as $field_files) {
            $files = is_array($field_files) ? $field_files : [$field_files];
            foreach ($files as $file) {
                if (is_string($file)) {
                    $real_file = realpath($file);
                    $converted_path = self::convert_heic_heif_to_jpeg($file, null);
                    if ($converted_path !== null) {
                        self::$converted_cf7_files[$file] = $converted_path;
                        if ($real_file !== false) {
                            self::$converted_cf7_files[$real_file] = $converted_path;
                        }
                        self::compress_image_file($converted_path, 'image/jpeg');
                        continue;
                    }

                    self::compress_image_file($file, null);
                }
            }
        }
    }

    /**
     * Replace Contact Form 7 mail attachments with converted JPG files.
     *
     * @param array<string, mixed> $components
     * @param mixed $contact_form
     * @param mixed $mail
     * @return array<string, mixed>
     */
    public static function replace_contact_form_7_mail_attachments(array $components, $contact_form = null, $mail = null): array
    {
        if (empty(self::$converted_cf7_files) || empty($components['attachments']) || !is_array($components['attachments'])) {
            return $components;
        }

        foreach ($components['attachments'] as $index => $attachment) {
            if (!is_string($attachment)) {
                continue;
            }

            $real_path = realpath($attachment) ?: $attachment;
            if (isset(self::$converted_cf7_files[$attachment])) {
                $components['attachments'][$index] = self::$converted_cf7_files[$attachment];
            } elseif (isset(self::$converted_cf7_files[$real_path])) {
                $components['attachments'][$index] = self::$converted_cf7_files[$real_path];
            }
        }

        return $components;
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            __('PicPrune Form Uploads', 'picprune-form-uploads'),
            __('PicPrune Form Uploads', 'picprune-form-uploads'),
            'manage_options',
            'picprune-form-uploads',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(
            'picprune_form_uploads',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_options'],
                'default' => self::DEFAULTS,
            ]
        );
    }

    /**
     * @param mixed $input
     * @return array<string, int>
     */
    public static function sanitize_options($input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'jpeg_quality' => self::clamp_int($input['jpeg_quality'] ?? self::DEFAULTS['jpeg_quality'], 40, 95),
            'webp_quality' => self::clamp_int($input['webp_quality'] ?? self::DEFAULTS['webp_quality'], 40, 95),
            'png_compression' => self::clamp_int($input['png_compression'] ?? self::DEFAULTS['png_compression'], 0, 9),
            'max_width' => self::clamp_int($input['max_width'] ?? self::DEFAULTS['max_width'], 0, 8000),
            'max_height' => self::clamp_int($input['max_height'] ?? self::DEFAULTS['max_height'], 0, 8000),
            'min_size_kb' => self::clamp_int($input['min_size_kb'] ?? self::DEFAULTS['min_size_kb'], 0, 51200),
            'keep_original_if_larger' => empty($input['keep_original_if_larger']) ? 0 : 1,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function options(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return array_merge(self::DEFAULTS, self::sanitize_options(array_merge(self::DEFAULTS, $saved)));
    }

    private static function convert_heic_heif_to_jpeg(string $file_path, ?string $mime_type): ?string
    {
        $settings = self::options();
        if (empty($settings['enabled'])) {
            return null;
        }

        $real_path = realpath($file_path);
        if ($real_path === false || !is_file($real_path) || !is_readable($real_path)) {
            return null;
        }

        $mime_type = $mime_type ?: self::detect_mime_type($real_path);
        if (!self::is_heic_heif_file($real_path, $mime_type)) {
            return null;
        }

        if (!class_exists('Imagick')) {
            return null;
        }

        $target_path = self::jpeg_path_for($real_path);
        if ($target_path === null) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->readImage($real_path);

            if (method_exists($image, 'setIteratorIndex')) {
                $image->setIteratorIndex(0);
            }

            self::auto_orient_imagick_image($image);
            self::resize_imagick_image($image, (int) $settings['max_width'], (int) $settings['max_height']);

            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality((int) $settings['jpeg_quality']);
            $image->setInterlaceScheme(\Imagick::INTERLACE_JPEG);
            $image->writeImage($target_path);
            $image->clear();
            $image->destroy();
        } catch (\Throwable $exception) {
            if (isset($image) && $image instanceof \Imagick) {
                $image->clear();
                $image->destroy();
            }
            wp_delete_file($target_path);
            return null;
        }

        clearstatcache(true, $target_path);
        if (!is_file($target_path) || filesize($target_path) === false || filesize($target_path) <= 0) {
            wp_delete_file($target_path);
            return null;
        }

        wp_delete_file($real_path);

        return $target_path;
    }

    private static function is_heic_heif_file(string $file_path, ?string $mime_type): bool
    {
        if (in_array($mime_type, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/x-heic', 'image/x-heif'], true)) {
            return true;
        }

        $extension = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));
        return in_array($extension, ['heic', 'heif'], true);
    }

    private static function jpeg_path_for(string $source_path): ?string
    {
        $directory = dirname($source_path);
        $base_name = pathinfo($source_path, PATHINFO_FILENAME);
        $base_name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base_name) ?: 'converted-image';
        $file_name = $base_name . '.jpg';

        if (function_exists('wp_unique_filename')) {
            $file_name = wp_unique_filename($directory, $file_name);
        } else {
            $candidate = $file_name;
            $counter = 1;
            while (file_exists($directory . DIRECTORY_SEPARATOR . $candidate)) {
                $candidate = $base_name . '-' . $counter . '.jpg';
                $counter++;
            }
            $file_name = $candidate;
        }

        if (!function_exists('wp_is_writable')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $target_path = $directory . DIRECTORY_SEPARATOR . $file_name;
        return wp_is_writable($directory) ? $target_path : null;
    }

    private static function auto_orient_imagick_image($image): void
    {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
            return;
        }

        if (!method_exists($image, 'getImageOrientation')) {
            return;
        }

        switch ($image->getImageOrientation()) {
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
        }

        if (method_exists($image, 'setImageOrientation')) {
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        }
    }

    private static function resize_imagick_image($image, int $max_width, int $max_height): void
    {
        if ($max_width <= 0 && $max_height <= 0) {
            return;
        }

        $width = (int) $image->getImageWidth();
        $height = (int) $image->getImageHeight();
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $width_ratio = $max_width > 0 ? $max_width / $width : 1;
        $height_ratio = $max_height > 0 ? $max_height / $height : 1;
        $ratio = min($width_ratio, $height_ratio, 1);
        if ($ratio >= 1) {
            return;
        }

        $new_width = max(1, (int) round($width * $ratio));
        $new_height = max(1, (int) round($height * $ratio));
        $image->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1, true);
    }

    private static function heic_conversion_available(): bool
    {
        if (!class_exists('Imagick')) {
            return false;
        }

        try {
            $formats = \Imagick::queryFormats();
        } catch (\Throwable $exception) {
            return false;
        }

        $formats = array_map('strtoupper', $formats);
        return in_array('HEIC', $formats, true) || in_array('HEIF', $formats, true);
    }

    private static function compress_image_file(string $file_path, ?string $mime_type): bool
    {
        $settings = self::options();
        if (empty($settings['enabled'])) {
            return false;
        }

        $real_path = realpath($file_path);
        if (!function_exists('wp_is_writable')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ($real_path === false || isset(self::$processed_files[$real_path]) || !is_file($real_path) || !is_readable($real_path) || !wp_is_writable($real_path)) {
            return false;
        }

        self::$processed_files[$real_path] = true;

        $original_size = filesize($real_path);
        if ($original_size === false || $original_size <= 0) {
            return false;
        }

        $min_bytes = max(0, (int) $settings['min_size_kb']) * 1024;
        if ($min_bytes > 0 && $original_size < $min_bytes) {
            return false;
        }

        $mime_type = $mime_type ?: self::detect_mime_type($real_path);
        if (!self::is_supported_image_mime($mime_type)) {
            return false;
        }

        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor($real_path);
        if (is_wp_error($editor)) {
            return false;
        }

        $size = $editor->get_size();
        $max_width = (int) $settings['max_width'];
        $max_height = (int) $settings['max_height'];
        if (($max_width > 0 || $max_height > 0) && is_array($size)) {
            $width = isset($size['width']) ? (int) $size['width'] : 0;
            $height = isset($size['height']) ? (int) $size['height'] : 0;
            if (($max_width > 0 && $width > $max_width) || ($max_height > 0 && $height > $max_height)) {
                $editor->resize($max_width > 0 ? $max_width : null, $max_height > 0 ? $max_height : null, false);
            }
        }

        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality(self::quality_for_mime($mime_type, $settings));
        }

        $temp_file = wp_tempnam($real_path);
        if (!$temp_file) {
            return false;
        }

        $saved = $editor->save($temp_file, $mime_type);
        if (is_wp_error($saved) || empty($saved['path']) || !is_string($saved['path']) || !is_file($saved['path'])) {
            wp_delete_file($temp_file);
            return false;
        }

        $compressed_path = $saved['path'];
        self::optimize_png_if_needed($compressed_path, $mime_type, (int) $settings['png_compression']);

        $compressed_size = filesize($compressed_path);
        if ($compressed_size === false || $compressed_size <= 0) {
            wp_delete_file($compressed_path);
            return false;
        }

        if (!empty($settings['keep_original_if_larger']) && $compressed_size >= $original_size) {
            wp_delete_file($compressed_path);
            return false;
        }

        if (!copy($compressed_path, $real_path)) {
            wp_delete_file($compressed_path);
            return false;
        }
        wp_delete_file($compressed_path);

        clearstatcache(true, $real_path);
        return true;
    }

    private static function detect_mime_type(string $file_path): ?string
    {
        if (function_exists('wp_check_filetype_and_ext')) {
            $check = wp_check_filetype_and_ext($file_path, basename($file_path));
            if (!empty($check['type']) && is_string($check['type'])) {
                return $check['type'];
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file_path);
            if (is_string($mime)) {
                return $mime;
            }
        }

        return null;
    }

    private static function is_supported_image_mime(?string $mime_type): bool
    {
        return in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /**
     * @param array<string, int> $settings
     */
    private static function quality_for_mime(?string $mime_type, array $settings): int
    {
        if ($mime_type === 'image/webp') {
            return (int) $settings['webp_quality'];
        }

        return (int) $settings['jpeg_quality'];
    }

    private static function optimize_png_if_needed(string $file_path, ?string $mime_type, int $compression): void
    {
        if ($mime_type !== 'image/png' || !function_exists('imagecreatefrompng') || !function_exists('imagepng')) {
            return;
        }

        $image = @imagecreatefrompng($file_path);
        if (!$image) {
            return;
        }

        @imagesavealpha($image, true);
        @imagepng($image, $file_path, max(0, min(9, $compression)));
        @imagedestroy($image);
    }

    private static function clamp_int($value, int $min, int $max): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) {
            $value = $min;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public static function plugin_action_links(array $links): array
    {
        $settings_url = admin_url('options-general.php?page=picprune-form-uploads');
        array_unshift($links, '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'picprune-form-uploads') . '</a>');
        return $links;
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::options();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('PicPrune Form Uploads', 'picprune-form-uploads'); ?></h1>
            <p><?php esc_html_e('Compress JPEG, PNG, and WebP images uploaded through WordPress and Contact Form 7 before they are emailed or stored. HEIC/HEIF uploads are converted to compressed JPG when the server supports Imagick HEIC/HEIF decoding.', 'picprune-form-uploads'); ?></p>
            <?php if (!self::heic_conversion_available()) : ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('HEIC/HEIF to JPG conversion is not currently available on this server. Ask the host to enable the PHP Imagick extension with HEIC/HEIF support. JPEG, PNG, and WebP compression still works.', 'picprune-form-uploads'); ?></p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('picprune_form_uploads'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable compression', 'picprune-form-uploads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enabled]" value="1" <?php checked(1, $options['enabled']); ?>>
                                <?php esc_html_e('Optimize form image uploads', 'picprune-form-uploads'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="picprune_jpeg_quality"><?php esc_html_e('JPEG quality', 'picprune-form-uploads'); ?></label></th>
                        <td><input id="picprune_jpeg_quality" type="number" min="40" max="95" name="<?php echo esc_attr(self::OPTION_NAME); ?>[jpeg_quality]" value="<?php echo esc_attr((string) $options['jpeg_quality']); ?>"> <span class="description">40-95; lower means smaller files.</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="picprune_webp_quality"><?php esc_html_e('WebP quality', 'picprune-form-uploads'); ?></label></th>
                        <td><input id="picprune_webp_quality" type="number" min="40" max="95" name="<?php echo esc_attr(self::OPTION_NAME); ?>[webp_quality]" value="<?php echo esc_attr((string) $options['webp_quality']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="picprune_png_compression"><?php esc_html_e('PNG compression', 'picprune-form-uploads'); ?></label></th>
                        <td><input id="picprune_png_compression" type="number" min="0" max="9" name="<?php echo esc_attr(self::OPTION_NAME); ?>[png_compression]" value="<?php echo esc_attr((string) $options['png_compression']); ?>"> <span class="description">0-9; 9 is smallest but slower.</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum dimensions', 'picprune-form-uploads'); ?></th>
                        <td>
                            <input type="number" min="0" max="8000" name="<?php echo esc_attr(self::OPTION_NAME); ?>[max_width]" value="<?php echo esc_attr((string) $options['max_width']); ?>" style="width: 90px;"> ×
                            <input type="number" min="0" max="8000" name="<?php echo esc_attr(self::OPTION_NAME); ?>[max_height]" value="<?php echo esc_attr((string) $options['max_height']); ?>" style="width: 90px;"> px
                            <p class="description"><?php esc_html_e('Use 0 for either side to avoid limiting that dimension. Default 1600 × 1600 keeps uploaded images useful while greatly reducing size.', 'picprune-form-uploads'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="picprune_min_size_kb"><?php esc_html_e('Only compress files larger than', 'picprune-form-uploads'); ?></label></th>
                        <td><input id="picprune_min_size_kb" type="number" min="0" max="51200" name="<?php echo esc_attr(self::OPTION_NAME); ?>[min_size_kb]" value="<?php echo esc_attr((string) $options['min_size_kb']); ?>"> KB</td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Safety', 'picprune-form-uploads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[keep_original_if_larger]" value="1" <?php checked(1, $options['keep_original_if_larger']); ?>>
                                <?php esc_html_e('Keep the original if compression would make the file larger', 'picprune-form-uploads'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

PicPruneFormUploads::init();
