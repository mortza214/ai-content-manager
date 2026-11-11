<?php
/** Settings registration and screen rendering.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Admin;

use AIContentManager\Integrations\OpenAi;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Registers and renders plugin configuration with the WordPress Settings API. */
final class Settings {
    const OPTION_NAME = 'acm_settings';

    /** Register option, sections, and fields. */
    public function register() {
        register_setting(
            'acm_settings_group',
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize' ),
                'default'           => $this->defaults(),
            )
        );

        add_settings_section( 'acm_general', __( 'عمومی', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_section( 'acm_content', __( 'محتوا', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_section( 'acm_workflow', __( 'فرایند انتشار (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_section( 'acm_features', __( 'سئو و رسانه (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_section( 'acm_integrations', __( 'یکپارچه‌سازی‌ها', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_section( 'acm_openai', __( 'رابط OpenAI', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );

        $this->add_field( 'mode', __( 'حالت اجرا', 'ai-content-manager' ), 'acm_general', 'render_mode_field' );
        $this->add_field( 'post_types', __( 'انواع محتوای پشتیبانی‌شده', 'ai-content-manager' ), 'acm_content', 'render_post_types_field' );
        $this->add_field( 'batch_size', __( 'اندازه گروه (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_content', 'render_batch_size_field' );
        $this->add_field( 'publishing_mode', __( 'سیاست انتشار (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_workflow', 'render_publishing_mode_field' );
        $this->add_field( 'scheduled_publishing', __( 'انتشار زمان‌بندی‌شده (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_workflow', 'render_scheduled_publishing_field' );
        $this->add_field( 'schedule_frequency', __( 'تناوب زمان‌بندی (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_workflow', 'render_schedule_frequency_field' );
        $this->add_field( 'seo_audit', __( 'بررسی سئو (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_features', 'render_seo_audit_field' );
        $this->add_field( 'featured_images', __( 'تصویر شاخص (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_features', 'render_featured_images_field' );
        $this->add_field( 'web_research', __( 'پژوهش وب (هنوز اجرا نمی‌شود)', 'ai-content-manager' ), 'acm_features', 'render_web_research_field' );
        $this->add_field( 'integration_status', __( 'یکپارچه‌سازی‌های شناسایی‌شده', 'ai-content-manager' ), 'acm_integrations', 'render_integrations_field' );
        $this->add_field( 'yoast_on_draft', __( 'تکمیل اطلاعات Yoast هنگام ایجاد پیش‌نویس', 'ai-content-manager' ), 'acm_integrations', 'render_yoast_field' );
        $this->add_field( 'openai_api_key', __( 'کلید API', 'ai-content-manager' ), 'acm_openai', 'render_openai_api_key_field' );
        $this->add_field( 'openai_model', __( 'مدل', 'ai-content-manager' ), 'acm_openai', 'render_openai_model_field' );
        $this->add_field( 'openai_test', __( 'اتصال', 'ai-content-manager' ), 'acm_openai', 'render_openai_test_field' );
        add_settings_section( 'acm_budget', __( 'کنترل مصرف OpenAI', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_field( 'acm_budget_limits', __( 'محدودیت‌ها', 'ai-content-manager' ), array( $this, 'render_budget_fields' ), 'ai-content-manager', 'acm_budget' );
        add_settings_section( 'acm_recovery', __( 'بازیابی صف', 'ai-content-manager' ), '__return_false', 'ai-content-manager' );
        add_settings_field( 'acm_recovery_options', __( 'تنظیمات بازیابی', 'ai-content-manager' ), array( $this, 'render_recovery_fields' ), 'ai-content-manager', 'acm_recovery' );
    }

    /** Add one option field to its settings section. */
    private function add_field( $key, $label, $section, $callback ) {
        add_settings_field(
            'acm_' . $key,
            $label,
            array( $this, $callback ),
            'ai-content-manager',
            $section
        );
    }

    /** Render the settings screen. */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_ai_content' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h2><?php esc_html_e( 'تنظیمات', 'ai-content-manager' ); ?></h2>
            <?php
            if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
                add_settings_error( self::OPTION_NAME, 'acm_settings_saved', __( 'تنظیمات ذخیره شد. پیام‌های اعتبارسنجی احتمالی را بررسی کنید.', 'ai-content-manager' ), 'success' );
            }
            settings_errors( self::OPTION_NAME );
            ?>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'acm_settings_group' );
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( AdminPage::url( 'settings' ) ) . '">';
                do_settings_sections( 'ai-content-manager' );
                submit_button( __( 'ذخیره تنظیمات', 'ai-content-manager' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /** Enqueue the connection test script only on this plugin's settings page. */
    public function enqueue_assets( $hook_suffix ) {
        wp_enqueue_script( 'acm-admin-settings', ACM_PLUGIN_URL . 'assets/js/admin-settings.js', array(), ACM_VERSION, true );
        wp_localize_script(
            'acm-admin-settings',
            'acmOpenAiTest',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'acm_test_openai_connection' ),
                'strings' => array(
                    'testing' => __( 'در حال بررسی اتصال…', 'ai-content-manager' ),
                    'success' => __( 'اتصال با موفقیت برقرار شد.', 'ai-content-manager' ),
                    'failure' => __( 'اتصال ناموفق بود.', 'ai-content-manager' ),
                ),
            )
        );
    }

    /** Sanitize values submitted through the Settings API. */
    public function sanitize( $input ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_ai_content' ) ) {
            add_settings_error( self::OPTION_NAME, 'acm_forbidden', __( 'مجوز تغییر تنظیمات هوشمند را ندارید.', 'ai-content-manager' ), 'error' );
            return get_option( self::OPTION_NAME, array() );
        }
        $input    = is_array( $input ) ? $input : array();
        $defaults = $this->defaults();
        $output   = array();
        $mode     = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : $defaults['mode'];

        $output['mode'] = in_array( $mode, array( 'manual', 'automatic', 'hybrid' ), true ) ? $mode : $defaults['mode'];

        $allowed_post_types = array( 'post', 'page' );
        if ( post_type_exists( 'product' ) ) {
            $allowed_post_types[] = 'product';
        }
        $submitted_types       = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_filter( $input['post_types'], 'is_string' ) : array();
        $submitted_types       = array_map( 'sanitize_key', $submitted_types );
        $output['post_types']   = array_values( array_intersect( $submitted_types, $allowed_post_types ) );
        $output['batch_size']   = isset( $input['batch_size'] ) ? min( 50, max( 1, absint( $input['batch_size'] ) ) ) : $defaults['batch_size'];
        $publishing_mode = isset( $input['publishing_mode'] ) ? sanitize_key( $input['publishing_mode'] ) : $defaults['publishing_mode'];
        $output['publishing_mode'] = in_array( $publishing_mode, array( 'require_approval', 'auto_publish' ), true ) ? $publishing_mode : $defaults['publishing_mode'];
        $output['scheduled_publishing'] = ! empty( $input['scheduled_publishing'] );

        $frequency = isset( $input['schedule_frequency'] ) ? sanitize_key( $input['schedule_frequency'] ) : $defaults['schedule_frequency'];
        $output['schedule_frequency'] = in_array( $frequency, array( 'hourly', 'daily', 'weekly' ), true ) ? $frequency : $defaults['schedule_frequency'];
        $output['seo_audit']       = ! empty( $input['seo_audit'] );
        $output['yoast_on_draft'] = isset( $input['yoast_on_draft'] ) && in_array( $input['yoast_on_draft'], array( '1', 1, true ), true );
        $output['featured_images'] = ! empty( $input['featured_images'] );
        $output['web_research']    = ! empty( $input['web_research'] );

        $models                   = OpenAi::models();
        $model                    = isset( $input['openai_model'] ) && is_string( $input['openai_model'] ) ? sanitize_text_field( $input['openai_model'] ) : $defaults['openai_model'];
        $output['openai_model']    = isset( $models[ $model ] ) ? $model : $defaults['openai_model'];

        if ( isset( $input['openai_api_key'] ) && is_string( $input['openai_api_key'] ) && '' !== trim( $input['openai_api_key'] ) ) {
            $result = ( new OpenAi() )->store_api_key( $input['openai_api_key'] );
            if ( is_wp_error( $result ) ) {
                add_settings_error( self::OPTION_NAME, 'acm_openai_key_error', $result->get_error_message(), 'error' );
            }
        }

        return array_merge( $output, \AIContentManager\Services\GenerationPolicy::sanitize( $input ) );
    }

    public function render_budget_fields() {
        $values = \AIContentManager\Services\GenerationPolicy::values();
        $labels = array( 'daily_request_limit' => __( 'سقف درخواست روزانه', 'ai-content-manager' ),
            'monthly_request_limit' => __( 'سقف درخواست ماهانه', 'ai-content-manager' ),
            'daily_token_limit' => __( 'سقف توکن روزانه', 'ai-content-manager' ),
            'monthly_token_limit' => __( 'سقف توکن ماهانه', 'ai-content-manager' ),
            'max_output_tokens' => __( 'حداکثر توکن خروجی هر درخواست', 'ai-content-manager' ),
            'max_input_tokens' => __( 'حداکثر تخمینی توکن ورودی هر درخواست', 'ai-content-manager' ),
            'max_generation_items' => __( 'حداکثر آیتم در هر درخواست ثبت محتوا', 'ai-content-manager' ) );
        foreach ( $labels as $key => $label ) {
            echo '<p><label for="acm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label> <input id="acm-' . esc_attr( $key ) . '" type="number" min="0" max="1000000000" step="1" name="acm_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $values[ $key ] ) . '"></p>';
        }
        echo '<p class="description">' . esc_html__( 'صفر، همان محدودیت را غیرفعال می‌کند. تولید همچنان تک‌آیتمی است. سقف فنی فعلی خروجی ۱۲۰۰۰ توکن و ثبت صف ۱۰۰ آیتم است. تخمین ورودی دقیق نیست؛ برای بودجه، ورودی تخمینی به‌علاوه حداکثر خروجی پیشاپیش رزرو می‌شود.', 'ai-content-manager' ) . '</p>';
    }

    public function render_recovery_fields() {
        $values = \AIContentManager\Services\GenerationPolicy::values();
        echo '<p><label for="acm-generating-timeout">' . esc_html__( 'مهلت وضعیت تولید (دقیقه)', 'ai-content-manager' ) . '</label> <input id="acm-generating-timeout" type="number" min="2" max="10080" name="acm_settings[generating_timeout_minutes]" value="' . esc_attr( $values['generating_timeout_minutes'] ) . '"></p>';
        echo '<label for="acm-recovery-mode">' . esc_html__( 'روش بازیابی', 'ai-content-manager' ) . '</label> <select id="acm-recovery-mode" name="acm_settings[recovery_mode]">';
        foreach ( array( 'failed' => __( 'انتقال به ناموفق', 'ai-content-manager' ), 'pending' => __( 'بازگشت به در انتظار', 'ai-content-manager' ) ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . ( $key === $values['recovery_mode'] ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__( 'بازیابی فقط با دکمه دستی مدیریت صف انجام می‌شود؛ درخواست API یا Cron ایجاد نمی‌کند. بازگشت به صف ممکن است هزینه تلاش مجدد داشته باشد؛ رزرو مصرف نامشخص تلاش قبلی حفظ می‌شود.', 'ai-content-manager' ) . '</p>';
    }

    /** Return default values, including draft-first publishing. */
    private function defaults() {
        return array(
            'mode'                  => 'manual',
            'post_types'            => array( 'post', 'page' ),
            'batch_size'            => 5,
            'publishing_mode'       => 'require_approval',
            'scheduled_publishing'  => false,
            'schedule_frequency'    => 'daily',
            'seo_audit'             => true,
            'yoast_on_draft'        => false,
            'featured_images'        => false,
            'web_research'           => false,
            'openai_model'           => 'gpt-4o-mini',
        );
    }

    /** Get the effective settings, merged with defaults for upgrades. */
    private function values() {
        $saved = get_option( self::OPTION_NAME, array() );
        $saved = is_array( $saved ) ? $saved : array();
        if ( ! isset( $saved['publishing_mode'] ) && isset( $saved['auto_publish'] ) ) {
            $saved['publishing_mode'] = $saved['auto_publish'] ? 'auto_publish' : 'require_approval';
        }
        return wp_parse_args( $saved, $this->defaults() );
    }

    /** Optional metadata completion never changes existing drafts on settings save. */
    public function render_yoast_field() {
        $values = $this->values();
        echo '<label><input type="checkbox" name="acm_settings[yoast_on_draft]" value="1" ' . ( $values['yoast_on_draft'] ? 'checked' : '' ) . '> ' . esc_html__( 'تکمیل اطلاعات Yoast هنگام ایجاد پیش‌نویس', 'ai-content-manager' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'فقط عبارت اصلی و توضیحات متای خالی، پس از تکمیل پیش‌نویس پر می‌شوند. تغییر این گزینه نوشته‌های قبلی را تغییر نمی‌دهد.', 'ai-content-manager' ) . '</p>';
        echo '<p>' . esc_html( ( new \AIContentManager\Integrations\Yoast() )->available() ? __( 'Yoast SEO فعال است.', 'ai-content-manager' ) : __( 'Yoast SEO فعال نیست', 'ai-content-manager' ) ) . '</p>';
    }

    /** Render the operating mode selector. */
    public function render_mode_field() {
        $values  = $this->values();
        $options = array(
            'manual'    => __( 'دستی', 'ai-content-manager' ),
            'automatic' => __( 'خودکار', 'ai-content-manager' ),
            'hybrid'    => __( 'ترکیبی', 'ai-content-manager' ),
        );
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mode]">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /** Render supported post type checkboxes. */
    public function render_post_types_field() {
        $values     = $this->values();
        $post_types = array(
            'post' => __( 'نوشته‌ها', 'ai-content-manager' ),
            'page' => __( 'برگه‌ها', 'ai-content-manager' ),
        );
        if ( post_type_exists( 'product' ) ) {
            $post_types['product'] = __( 'محصولات (WooCommerce)', 'ai-content-manager' );
        }

        foreach ( $post_types as $slug => $label ) :
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $values['post_types'], true ) ); ?>>
                <?php echo esc_html( $label ); ?>
            </label><br>
            <?php
        endforeach;
    }

    /** Render the batch size input. */
    public function render_batch_size_field() {
        $values = $this->values();
        ?>
        <input type="number" min="1" max="50" step="1" class="small-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[batch_size]" value="<?php echo esc_attr( $values['batch_size'] ); ?>">
        <p class="description"><?php esc_html_e( 'حداکثر آیتم هر گروه؛ پردازش گروهی هنوز اجرا نمی‌شود.', 'ai-content-manager' ); ?></p>
        <?php
    }

    /** Render the default publishing policy. */
    public function render_publishing_mode_field() {
        $values = $this->values();
        ?>
        <label><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[publishing_mode]" value="require_approval" <?php checked( $values['publishing_mode'], 'require_approval' ); ?>> <?php esc_html_e( 'نیازمند تأیید', 'ai-content-manager' ); ?></label><br>
        <label><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[publishing_mode]" value="auto_publish" <?php checked( $values['publishing_mode'], 'auto_publish' ); ?>> <?php esc_html_e( 'انتشار خودکار', 'ai-content-manager' ); ?></label>
        <p class="description"><?php esc_html_e( 'پیش‌فرض نیازمند تأیید است؛ این نسخه هیچ محتوایی را منتشر نمی‌کند.', 'ai-content-manager' ); ?></p>
        <?php
    }

    /** Render scheduled publishing preference. */
    public function render_scheduled_publishing_field() {
        $values = $this->values();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[scheduled_publishing]" value="1" <?php checked( $values['scheduled_publishing'] ); ?>>
            <?php esc_html_e( 'انتشار زمان‌بندی‌شده؛ هنوز اجرا نمی‌شود.', 'ai-content-manager' ); ?>
        </label>
        <?php
    }

    /** Render the configured scheduling cadence. */
    public function render_schedule_frequency_field() {
        $values  = $this->values();
        $options = array(
            'hourly' => __( 'ساعتی', 'ai-content-manager' ),
            'daily'  => __( 'روزانه', 'ai-content-manager' ),
            'weekly' => __( 'هفتگی', 'ai-content-manager' ),
        );
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[schedule_frequency]">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['schedule_frequency'], $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /** Render an SEO audit preference. */
    public function render_seo_audit_field() {
        $values = $this->values();
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[seo_audit]" value="1" <?php checked( $values['seo_audit'] ); ?>> <?php esc_html_e( 'بررسی سئوی محتوای موجود؛ هنوز اجرا نمی‌شود.', 'ai-content-manager' ); ?></label>
        <?php
    }

    /** Render featured image preference. */
    public function render_featured_images_field() {
        $values = $this->values();
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[featured_images]" value="1" <?php checked( $values['featured_images'] ); ?>> <?php esc_html_e( 'درخواست تصویر شاخص؛ هنوز اجرا نمی‌شود.', 'ai-content-manager' ); ?></label>
        <?php
    }

    /** Render optional web research preference. */
    public function render_web_research_field() {
        $values = $this->values();
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[web_research]" value="1" <?php checked( $values['web_research'] ); ?>> <?php esc_html_e( 'پژوهش وب اختیاری؛ هنوز اجرا نمی‌شود.', 'ai-content-manager' ); ?></label>
        <?php
    }

    /** Render a write-only API key field with a configured-state indicator. */
    public function render_openai_api_key_field() {
        $configured = ( new OpenAi() )->has_api_key();
        ?>
        <input type="password" autocomplete="new-password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]" value="" placeholder="<?php echo esc_attr( $configured ? __( 'کلید ذخیره شده؛ برای جایگزینی، کلید جدید را وارد کنید', 'ai-content-manager' ) : __( 'کلید API را وارد کنید', 'ai-content-manager' ) ); ?>">
        <p class="description"><?php echo esc_html( $configured ? __( 'کلید ذخیره شده است. مقدار کلید نمایش داده نمی‌شود.', 'ai-content-manager' ) : __( 'کلید پیش از ذخیره‌سازی رمزگذاری می‌شود.', 'ai-content-manager' ) ); ?></p>
        <?php
    }

    /** Render the selectable API model. */
    public function render_openai_model_field() {
        $values = $this->values();
        ?>
        <select id="acm-openai-model" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_model]">
            <?php foreach ( OpenAi::models() as $model => $label ) : ?>
                <option value="<?php echo esc_attr( $model ); ?>" <?php selected( $values['openai_model'], $model ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /** Render the API connection test control and accessible result region. */
    public function render_openai_test_field() {
        ?>
        <button type="button" class="button" id="acm-test-openai-connection"><?php esc_html_e( 'بررسی اتصال', 'ai-content-manager' ); ?></button>
        <span id="acm-openai-test-result" role="status" aria-live="polite"></span>
        <p class="description"><?php esc_html_e( 'ابتدا کلید API را ذخیره کنید. با کلیک روی بررسی اتصال، دسترسی مدل انتخاب‌شده بررسی می‌شود و محتوایی تولید نمی‌شود.', 'ai-content-manager' ); ?></p>
        <?php
    }

    /** Show integration availability without requiring optional plugins. */
    public function render_integrations_field() {
        $integrations = array(
            __( 'رابط OpenAI', 'ai-content-manager' ) => ( new OpenAi() )->has_api_key() ? __( 'تنظیم شده', 'ai-content-manager' ) : __( 'تنظیم نشده', 'ai-content-manager' ),
            __( 'WPML', 'ai-content-manager' )      => ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) ? __( 'شناسایی شده', 'ai-content-manager' ) : __( 'شناسایی نشده', 'ai-content-manager' ),
            __( 'Yoast SEO', 'ai-content-manager' )  => ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) ? __( 'شناسایی شده', 'ai-content-manager' ) : __( 'شناسایی نشده', 'ai-content-manager' ),
            __( 'WooCommerce', 'ai-content-manager' ) => function_exists( 'WC' ) ? __( 'شناسایی شده', 'ai-content-manager' ) : __( 'شناسایی نشده', 'ai-content-manager' ),
        );
        ?>
        <ul>
            <?php foreach ( $integrations as $name => $status ) : ?>
                <li><strong><?php echo esc_html( $name ); ?>:</strong> <?php echo esc_html( $status ); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="description"><?php esc_html_e( 'وضعیت OpenAI از کلید ذخیره‌شده و وضعیت افزونه‌ها از نصب آن‌ها تشخیص داده می‌شود. بازشدن این صفحه درخواست API ارسال نمی‌کند.', 'ai-content-manager' ); ?></p>
        <?php
    }
}
