<?php
/** Content request form and queue submission handler.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Admin;

use AIContentManager\Integrations\Wpml;
use AIContentManager\Services\ContentQueue;
use AIContentManager\Services\ContentTypeRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Renders content requests and stores them in the internal queue. */
final class ContentPage {
    /** @var ContentQueue */
    private $queue;

    /** @var ContentTypeRegistry */
    private $content_types;

    /** @var Wpml */
    private $wpml;
    private $form = array();
    private $feedback = array();

    /** @param ContentQueue $queue Queue service. */
    public function __construct( $queue, $content_types, $wpml ) {
        $this->queue         = $queue;
        $this->content_types = $content_types;
        $this->wpml          = $wpml;
        add_action( 'admin_post_acm_enqueue_content', array( $this, 'handle_submission' ) );
    }

    /** Load form behavior only on the content request page. */
    public function enqueue_assets( $hook_suffix ) {
        wp_enqueue_script( 'acm-content-page', ACM_PLUGIN_URL . 'assets/js/content-page.js', array(), ACM_VERSION, true );
        $limits = \AIContentManager\Services\GenerationPolicy::values();
        wp_localize_script( 'acm-content-page', 'acmContentForm', array( 'limit' => $limits['max_generation_items'] ? min( 100, $limits['max_generation_items'] ) : 100,
            'strings' => array( 'count' => __( 'تعداد درخواست‌های قابل ثبت: ', 'ai-content-manager' ), 'singleKeyword' => __( 'کلمه کلیدی اصلی', 'ai-content-manager' ), 'sharedKeyword' => __( 'کلمه کلیدی اصلی مشترک', 'ai-content-manager' ), 'singleSecondary' => __( 'کلمات کلیدی کمکی', 'ai-content-manager' ), 'sharedSecondary' => __( 'کلمات کلیدی کمکی مشترک', 'ai-content-manager' ), 'duplicate' => __( 'موضوع تکراری وجود دارد؛ موضوع‌ها را اصلاح کنید.', 'ai-content-manager' ), 'tooMany' => __( 'تعداد موضوع‌ها بیش از سقف مجاز است.', 'ai-content-manager' ), 'categoryMismatch' => __( 'دسته‌بندی انتخاب‌شده با زبان یا نوع محتوا سازگار نیست؛ آن را صریحاً اصلاح کنید یا «بدون انتخاب» را انتخاب کنید.', 'ai-content-manager' ) ) ) );
    }

    /** Render the request form. */
    public function render() {
        if ( ! current_user_can( 'manage_ai_content' ) ) {
            return;
        }
        $feedback = get_transient( 'acm_content_feedback_' . get_current_user_id() );
        $this->feedback = is_array( $feedback ) ? $feedback : array();
        $this->form = $this->feedback['values'] ?? array();
        if ( $this->feedback ) { delete_transient( 'acm_content_feedback_' . get_current_user_id() ); }
        $submission_id = $this->value( 'submission_id' );
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $submission_id ) ) { $submission_id = wp_generate_uuid4(); }

        $languages      = $this->wpml->active_languages();
        $default_language = $this->wpml->default_language();
        $types          = $this->content_types->all();
        $settings       = get_option( Settings::OPTION_NAME, array() );
        $settings       = is_array( $settings ) ? $settings : array();
        $default_approval = isset( $settings['publishing_mode'] ) ? $settings['publishing_mode'] : ( ! empty( $settings['auto_publish'] ) ? 'auto_publish' : 'require_approval' );
        $default_approval = $this->value( 'approval_mode', $default_approval );
        $default_language = $this->value( 'language', $default_language );
        ?>
        <div class="wrap">
            <h2><?php esc_html_e( 'تولید محتوا', 'ai-content-manager' ); ?></h2>
            <?php $this->render_notice(); ?>
            <p><?php esc_html_e( 'در این بخش فقط درخواست محتوا ثبت می‌شود. تولید دستی و ایجاد پیش‌نویس وردپرس دو عملیات جداگانه در تب مدیریت صف هستند. هیچ مقاله یا موضوعی هنگام ثبت فرم با هوش مصنوعی تولید و هیچ نوشته‌ای خودکار منتشر نمی‌شود.', 'ai-content-manager' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="acm_enqueue_content">
                <?php wp_nonce_field( 'acm_enqueue_content' ); ?>
                <input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission_id ); ?>">
                <?php wp_nonce_field( 'acm_submission_' . $submission_id, 'submission_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="acm-content-type"><?php esc_html_e( 'نوع محتوا', 'ai-content-manager' ); ?></label></th>
                            <td><select id="acm-content-type" name="content_type" required>
                                <?php foreach ( $types as $type => $label ) : ?>
                                    <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $this->value( 'content_type', 'post' ), $type ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-mode"><?php esc_html_e( 'حالت اجرا', 'ai-content-manager' ); ?></label></th>
                            <td><select id="acm-mode" name="mode">
                                <option value="manual" <?php selected( $this->value( 'mode', 'manual' ), 'manual' ); ?>><?php esc_html_e( 'دستی', 'ai-content-manager' ); ?></option>
                                <option value="automatic" <?php selected( $this->value( 'mode' ), 'automatic' ); ?>><?php esc_html_e( 'خودکار', 'ai-content-manager' ); ?></option>
                                <option value="hybrid" <?php selected( $this->value( 'mode' ), 'hybrid' ); ?>><?php esc_html_e( 'ترکیبی', 'ai-content-manager' ); ?></option>
                            </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-topic-input-mode"><?php esc_html_e( 'روش ثبت موضوع', 'ai-content-manager' ); ?></label></th>
                            <td><select id="acm-topic-input-mode" name="topic_input_mode"><option value="single" <?php selected( $this->value( 'topic_input_mode', 'single' ), 'single' ); ?>><?php esc_html_e( 'یک موضوع', 'ai-content-manager' ); ?></option><option value="multiple" <?php selected( $this->value( 'topic_input_mode' ), 'multiple' ); ?>><?php esc_html_e( 'چند موضوع مستقل', 'ai-content-manager' ); ?></option></select><p class="description"><?php esc_html_e( 'زبان، لحن، تعداد تقریبی کلمات و کلمات کلیدی در یک ارسال برای همه موضوع‌ها مشترک‌اند. هر موضوع دقیقاً یک آیتم صف می‌سازد؛ پیشنهاد موضوع با هوش مصنوعی انجام نمی‌شود.', 'ai-content-manager' ); ?></p></td>
                        </tr>
                        <tr id="acm-single-topic-row">
                            <th scope="row"><label for="acm-topic"><?php esc_html_e( 'موضوع', 'ai-content-manager' ); ?></label></th>
                            <td><input id="acm-topic" name="topic" type="text" class="regular-text" maxlength="250" value="<?php echo esc_attr( $this->value( 'topic' ) ); ?>"><p class="description"><?php esc_html_e( 'در حالت یک موضوع دقیقاً یک درخواست ثبت می‌شود.', 'ai-content-manager' ); ?></p></td>
                        </tr>
                        <tr id="acm-multiple-topics-row">
                            <th scope="row"><label for="acm-topics"><?php esc_html_e( 'موضوع‌های مستقل', 'ai-content-manager' ); ?></label></th>
                            <td><textarea id="acm-topics" name="topics" rows="6" class="large-text"><?php echo esc_html( $this->value( 'topics' ) ); ?></textarea><p class="description"><?php esc_html_e( 'هر موضوع در یک خط؛ خطوط خالی نادیده گرفته می‌شوند. موضوع تکراری بدون اطلاع حذف نمی‌شود و ارسال تا اصلاح آن رد می‌شود.', 'ai-content-manager' ); ?></p><p id="acm-topic-count" role="status" aria-live="polite"></p><noscript><?php esc_html_e( 'نمایش شمارش زنده به JavaScript نیاز دارد؛ تعداد و همه موضوع‌ها هنگام ارسال در سرور بررسی می‌شوند.', 'ai-content-manager' ); ?></noscript></td>
                        </tr>
                        <tr>
                            <th scope="row"><label id="acm-primary-keyword-label" for="acm-primary-keyword"><?php echo esc_html( 'multiple' === $this->value( 'topic_input_mode' ) ? __( 'کلمه کلیدی اصلی مشترک', 'ai-content-manager' ) : __( 'کلمه کلیدی اصلی', 'ai-content-manager' ) ); ?></label></th>
                            <td><input id="acm-primary-keyword" name="primary_keyword" type="text" class="regular-text" maxlength="150" value="<?php echo esc_attr( $this->value( 'primary_keyword' ) ); ?>"><p class="description"><?php esc_html_e( 'فقط یک عبارت؛ چندکلمه‌ای مجاز است. فاصله جداکننده نیست. چند عبارت با ویرگول فارسی، انگلیسی یا خط جدید را در کلمات کلیدی کمکی بنویسید.', 'ai-content-manager' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-keyword-mode"><?php esc_html_e( 'حالت کلمات کلیدی', 'ai-content-manager' ); ?></label></th>
                            <td>
                                <select id="acm-keyword-mode" name="keyword_mode">
                                    <option value="manual" <?php selected( $this->value( 'keyword_mode', 'manual' ), 'manual' ); ?>><?php esc_html_e( 'دستی', 'ai-content-manager' ); ?></option>
                                    <option value="ai_suggested" <?php selected( $this->value( 'keyword_mode' ), 'ai_suggested' ); ?>><?php esc_html_e( 'پیشنهاد هوشمند (فقط آماده‌سازی؛ هنوز اجرا نمی‌شود)', 'ai-content-manager' ); ?></option>
                                    <option value="hybrid" <?php selected( $this->value( 'keyword_mode' ), 'hybrid' ); ?>><?php esc_html_e( 'ترکیبی', 'ai-content-manager' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'پیشنهاد خودکار کلمات کلیدی هنوز اجرا نمی‌شود.', 'ai-content-manager' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label id="acm-secondary-keywords-label" for="acm-secondary-keywords"><?php echo esc_html( 'multiple' === $this->value( 'topic_input_mode' ) ? __( 'کلمات کلیدی کمکی مشترک', 'ai-content-manager' ) : __( 'کلمات کلیدی کمکی', 'ai-content-manager' ) ); ?></label></th>
                            <td><textarea id="acm-secondary-keywords" name="secondary_keywords" class="large-text" rows="3"><?php echo esc_html( $this->value( 'secondary_keywords' ) ); ?></textarea><p class="description"><?php esc_html_e( 'هر عبارت در یک خط یا با ویرگول فارسی یا انگلیسی؛ موارد خالی و تکراری حذف می‌شوند.', 'ai-content-manager' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-language"><?php esc_html_e( 'زبان اصلی', 'ai-content-manager' ); ?></label></th>
                            <td>
                                <select id="acm-language" name="language" required>
                                    <?php foreach ( $languages as $code => $label ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default_language, $code ); ?>><?php echo esc_html( $label . ' (' . $code . ')' ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ( ! $this->wpml->is_available() ) : ?>
                                    <p class="description"><?php esc_html_e( 'WPML شناسایی نشد؛ زبان اصلی وردپرس و گزینه‌های فارسی، انگلیسی و عربی برای مقاله در دسترس‌اند.', 'ai-content-manager' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'زبان‌های مقصد (ترجمه هنوز اجرا نمی‌شود)', 'ai-content-manager' ); ?></th>
                            <td>
                                <?php foreach ( $languages as $code => $label ) : ?>
                                    <?php if ( $code !== $default_language ) : ?>
                                        <label><input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $this->list_value( 'target_languages' ), true ) ); ?>> <?php echo esc_html( $label . ' (' . $code . ')' ); ?></label><br>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-word-count"><?php esc_html_e( 'تعداد تقریبی کلمات', 'ai-content-manager' ); ?></label></th>
                            <td><input id="acm-word-count" name="word_count" type="number" min="100" max="10000" step="100" value="<?php echo esc_attr( $this->value( 'word_count', '1000' ) ); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-category"><?php esc_html_e( 'دسته‌بندی', 'ai-content-manager' ); ?></label></th>
                            <td><select id="acm-category" name="category_choice"><option value=""><?php esc_html_e( 'بدون انتخاب دسته‌بندی', 'ai-content-manager' ); ?></option><?php $this->render_category_options( $types ); ?></select><p class="description"><?php esc_html_e( 'فقط دسته‌بندی هم‌زبان با محتوا مجاز است. انتخاب خالی، دسته پیش‌فرض وردپرس را حذف نمی‌کند. انتخاب ناسازگار خودکار پاک یا جایگزین نمی‌شود.', 'ai-content-manager' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acm-tone"><?php esc_html_e( 'لحن محتوا', 'ai-content-manager' ); ?></label></th>
                            <td><select id="acm-tone" name="tone">
                                <option value="informative" <?php selected( $this->value( 'tone', 'informative' ), 'informative' ); ?>><?php esc_html_e( 'اطلاع‌رسان', 'ai-content-manager' ); ?></option>
                                <option value="professional" <?php selected( $this->value( 'tone' ), 'professional' ); ?>><?php esc_html_e( 'حرفه‌ای', 'ai-content-manager' ); ?></option>
                                <option value="conversational" <?php selected( $this->value( 'tone' ), 'conversational' ); ?>><?php esc_html_e( 'محاوره‌ای', 'ai-content-manager' ); ?></option>
                                <option value="persuasive" <?php selected( $this->value( 'tone' ), 'persuasive' ); ?>><?php esc_html_e( 'ترغیب‌کننده', 'ai-content-manager' ); ?></option>
                                <option value="formal" <?php selected( $this->value( 'tone' ), 'formal' ); ?>><?php esc_html_e( 'رسمی', 'ai-content-manager' ); ?></option>
                            </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'گزینه‌های محتوا', 'ai-content-manager' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="web_research" value="1" <?php checked( '1' === $this->value( 'web_research' ) ); ?>> <?php esc_html_e( 'پژوهش وب (هنوز اجرا نمی‌شود)', 'ai-content-manager' ); ?></label><br>
                                <label><input type="checkbox" name="featured_image" value="1" <?php checked( '1' === $this->value( 'featured_image' ) ); ?>> <?php esc_html_e( 'درخواست تصویر شاخص (هنوز اجرا نمی‌شود)', 'ai-content-manager' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'سیاست انتشار', 'ai-content-manager' ); ?></th>
                            <td>
                                <label><input type="radio" name="approval_mode" value="require_approval" <?php checked( $default_approval, 'require_approval' ); ?>> <?php esc_html_e( 'نیازمند تأیید (پیش‌فرض)', 'ai-content-manager' ); ?></label><br>
                                <label><input type="radio" name="approval_mode" value="auto_publish" <?php checked( $default_approval, 'auto_publish' ); ?>> <?php esc_html_e( 'انتشار خودکار (فقط ذخیره تنظیم؛ هنوز اجرا نمی‌شود)', 'ai-content-manager' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'زمان‌بندی پیشنهادی (هنوز اجرا نمی‌شود)', 'ai-content-manager' ); ?></th>
                            <td>
                                <?php
                                $days = array(
                                    'mon' => __( 'دوشنبه', 'ai-content-manager' ), 'tue' => __( 'سه‌شنبه', 'ai-content-manager' ),
                                    'wed' => __( 'چهارشنبه', 'ai-content-manager' ), 'thu' => __( 'پنجشنبه', 'ai-content-manager' ),
                                    'fri' => __( 'جمعه', 'ai-content-manager' ), 'sat' => __( 'شنبه', 'ai-content-manager' ), 'sun' => __( 'یکشنبه', 'ai-content-manager' ),
                                );
                                foreach ( $days as $day => $label ) :
                                    ?>
                                    <label><input type="checkbox" name="schedule_days[]" value="<?php echo esc_attr( $day ); ?>" <?php checked( in_array( $day, $this->list_value( 'schedule_days' ), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
                                    <?php
                                endforeach;
                                ?>
                                <p><label for="acm-schedule-time"><?php esc_html_e( 'ساعت', 'ai-content-manager' ); ?></label> <input id="acm-schedule-time" name="schedule_time" type="time" value="<?php echo esc_attr( $this->value( 'schedule_time', '09:00' ) ); ?>"></p>
                                <label for="acm-interval-count"><?php esc_html_e( 'فاصله بین مقالات', 'ai-content-manager' ); ?></label>
                                <input id="acm-interval-count" name="interval_count" type="number" min="1" max="365" value="<?php echo esc_attr( $this->value( 'interval_count', '1' ) ); ?>" class="small-text">
                                <select name="interval_unit"><option value="days" <?php selected( $this->value( 'interval_unit', 'days' ), 'days' ); ?>><?php esc_html_e( 'روز', 'ai-content-manager' ); ?></option><option value="hours" <?php selected( $this->value( 'interval_unit' ), 'hours' ); ?>><?php esc_html_e( 'ساعت', 'ai-content-manager' ); ?></option></select>
                                <p class="description"><?php esc_html_e( 'این مقادیر فقط ذخیره می‌شوند؛ Cron یا انتشار زمان‌بندی‌شده فعال نیست.', 'ai-content-manager' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'افزودن به صف', 'ai-content-manager' ) ); ?>
            </form>
        </div>
        <?php
    }

    /** Add hierarchical taxonomy terms for currently supported post types. */
    private function render_category_options( $types ) {
        $languages = $this->wpml->active_languages();
        $options = ( new \AIContentManager\Services\QueueCategory( null, $this->wpml ) )->options( $types, $languages );
        $selected = $this->value( 'category_choice' );
        if ( is_wp_error( $options ) ) { echo '<option value="">' . esc_html( $options->get_error_message() ) . '</option>'; $options = array(); }
        foreach ( $options as $value => $option ) {
            $labels = array_intersect_key( $languages, array_flip( $option['languages'] ) );
            echo '<option data-content-type="' . esc_attr( $option['type'] ) . '" data-languages="' . esc_attr( implode( ' ', $option['languages'] ) ) . '" value="' . esc_attr( $value ) . '"';
            selected( $selected, $value );
            echo '>' . esc_html( $option['label'] . ' — ' . implode( '، ', $labels ) ) . '</option>';
        }
        if ( '' !== $selected && ! isset( $options[ $selected ] ) ) {
            echo '<option data-invalid="1" value="' . esc_attr( $selected ) . '" selected>' . esc_html__( 'انتخاب قبلی نامعتبر یا در دسترس نیست؛ برای اصلاح دوباره انتخاب کنید: ', 'ai-content-manager' ) . esc_html( $selected ) . '</option>';
        }
    }

    /** Validate and insert the submitted request as pending queue items. */
    public function handle_submission() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) ) {
            wp_die( esc_html__( 'اجازه انجام این کار را ندارید.', 'ai-content-manager' ), 403 );
        }
        check_admin_referer( 'acm_enqueue_content' );

        $types        = $this->content_types->all();
        $content_type = isset( $_POST['content_type'] ) && is_string( $_POST['content_type'] ) ? sanitize_key( wp_unslash( $_POST['content_type'] ) ) : '';
        $languages    = $this->wpml->active_languages();
        $language     = isset( $_POST['language'] ) && is_string( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
        $topic        = isset( $_POST['topic'] ) && is_string( $_POST['topic'] ) ? wp_unslash( $_POST['topic'] ) : '';
        $count        = $this->request_count();
        foreach ( array( 'count', 'batch_size' ) as $legacy_key ) { if ( isset( $_POST[ $legacy_key ] ) && ( ! is_scalar( $_POST[ $legacy_key ] ) || (int) $_POST[ $legacy_key ] !== 1 ) ) { $count = 0; } }
        if ( $count !== 1 ) { $this->validation_error( __( 'ارسال قدیمی با تعداد بیشتر از یک پذیرفته نیست؛ هر موضوع مستقل را در یک خط ثبت کنید.', 'ai-content-manager' ) ); }
        $topic_mode = isset( $_POST['topic_input_mode'] ) && is_string( $_POST['topic_input_mode'] ) ? $_POST['topic_input_mode'] : 'single';
        if ( ! in_array( $topic_mode, array( 'single', 'multiple' ), true ) ) { $this->validation_error( __( 'روش ثبت موضوع معتبر نیست.', 'ai-content-manager' ) ); }
        $topics_raw = isset( $_POST['topics'] ) && is_string( $_POST['topics'] ) ? wp_unslash( $_POST['topics'] ) : '';
        if ( strlen( $topics_raw ) > 100000 ) { $this->validation_error( __( 'حجم فهرست موضوع‌ها بیش از حد مجاز است.', 'ai-content-manager' ) ); }
        $topics = 'multiple' === $topic_mode ? preg_split( '/\R/u', $topics_raw ) : array( $topic );
        $submission_id = isset( $_POST['submission_id'] ) && is_string( $_POST['submission_id'] ) ? $_POST['submission_id'] : '';
        $submission_nonce = isset( $_POST['submission_nonce'] ) && is_string( $_POST['submission_nonce'] ) ? $_POST['submission_nonce'] : '';
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $submission_id ) || ! wp_verify_nonce( $submission_nonce, 'acm_submission_' . $submission_id ) ) { $this->validation_error( __( 'شناسه یا مهلت ارسال فرم معتبر نیست؛ فرم را تازه‌سازی و دوباره ارسال کنید.', 'ai-content-manager' ) ); }
        if ( ! isset( $types[ $content_type ] ) || ! isset( $languages[ $language ] ) ) { $this->validation_error( __( 'نوع محتوا یا زبان انتخاب‌شده معتبر نیست.', 'ai-content-manager' ) ); }

        $keyword_mode = isset( $_POST['keyword_mode'] ) && is_string( $_POST['keyword_mode'] ) ? sanitize_key( wp_unslash( $_POST['keyword_mode'] ) ) : 'manual';
        if ( ! in_array( $keyword_mode, array( 'manual', 'ai_suggested', 'hybrid' ), true ) ) {
            $keyword_mode = 'manual';
        }

        $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'manual';
        if ( ! in_array( $mode, array( 'manual', 'automatic', 'hybrid' ), true ) ) {
            $mode = 'manual';
        }

        $target_languages = isset( $_POST['target_languages'] ) && is_array( $_POST['target_languages'] ) ? array_filter( wp_unslash( $_POST['target_languages'] ), 'is_string' ) : array();
        $target_languages = array_map( 'sanitize_text_field', $target_languages );
        $target_languages = array_values( array_intersect( array_unique( $target_languages ), array_keys( $languages ) ) );
        $target_languages = array_values( array_diff( $target_languages, array( $language ) ) );
        $keywords         = isset( $_POST['secondary_keywords'] ) && is_string( $_POST['secondary_keywords'] ) ? wp_unslash( $_POST['secondary_keywords'] ) : '';
        $saved_settings   = get_option( Settings::OPTION_NAME, array() );
        $saved_settings   = is_array( $saved_settings ) ? $saved_settings : array();
        $default_approval = isset( $saved_settings['publishing_mode'] ) ? $saved_settings['publishing_mode'] : ( ! empty( $saved_settings['auto_publish'] ) ? 'auto_publish' : 'require_approval' );
        $approval_mode    = isset( $_POST['approval_mode'] ) && is_string( $_POST['approval_mode'] ) ? sanitize_key( wp_unslash( $_POST['approval_mode'] ) ) : $default_approval;
        if ( ! in_array( $approval_mode, array( 'require_approval', 'auto_publish' ), true ) ) {
            $approval_mode = 'require_approval';
        }

        $days = isset( $_POST['schedule_days'] ) && is_array( $_POST['schedule_days'] ) ? array_filter( wp_unslash( $_POST['schedule_days'] ), 'is_string' ) : array();
        $days = array_map( 'sanitize_key', $days );
        $days = array_values( array_intersect( array_unique( $days ), array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) ) );
        $time = isset( $_POST['schedule_time'] ) && is_string( $_POST['schedule_time'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_time'] ) ) : '09:00';
        if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
            $time = '09:00';
        }
        $interval_unit = isset( $_POST['interval_unit'] ) && is_string( $_POST['interval_unit'] ) ? sanitize_key( wp_unslash( $_POST['interval_unit'] ) ) : 'days';
        if ( ! in_array( $interval_unit, array( 'days', 'hours' ), true ) ) {
            $interval_unit = 'days';
        }

        $category = $this->parse_category( $content_type, $language );
        if ( is_wp_error( $category ) ) { $this->validation_error( $category->get_error_message() ); }
        $request  = array(
            'content_type'      => $content_type,
            'mode'              => $mode,
            'topic'             => $topic,
            'primary_keyword'   => isset( $_POST['primary_keyword'] ) && is_string( $_POST['primary_keyword'] ) ? wp_unslash( $_POST['primary_keyword'] ) : '',
            'secondary_keywords' => $keywords,
            'keyword_mode'      => $keyword_mode,
            'language'          => $language,
            'target_languages'  => $target_languages,
            'word_count'        => isset( $_POST['word_count'] ) ? min( 10000, max( 100, absint( $_POST['word_count'] ) ) ) : 1000,
            'category'          => $category,
            'tone'              => isset( $_POST['tone'] ) && is_string( $_POST['tone'] ) && in_array( $_POST['tone'], array( 'informative', 'professional', 'conversational', 'persuasive', 'formal' ), true ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : 'informative',
            'web_research'      => ! empty( $_POST['web_research'] ),
            'featured_image'    => ! empty( $_POST['featured_image'] ),
            'approval_mode'     => $approval_mode,
            'schedule'          => array(
                'days'          => $days,
                'time'          => $time,
                'interval_count' => isset( $_POST['interval_count'] ) ? min( 365, max( 1, absint( $_POST['interval_count'] ) ) ) : 1,
                'interval_unit' => $interval_unit,
            ),
        );

        $result = $this->queue->enqueue_topics( $request, $topics, $submission_id );
        if ( is_wp_error( $result ) ) {
            $this->validation_error( $result->get_error_message() );
        }

        delete_transient( 'acm_content_feedback_' . get_current_user_id() );
        $this->redirect( 'queued', count( $result['item_ids'] ) );
    }

    /** Parse the selected content type's taxonomy and term. */
    private function parse_category( $content_type, $language ) {
        $choice = $_POST['category_choice'] ?? '';
        return ( new \AIContentManager\Services\QueueCategory( null, $this->wpml ) )->parse_choice( is_string( $choice ) ? wp_unslash( $choice ) : $choice, $content_type, $language );
    }

    /** Resolve a preset or custom batch size. */
    private function request_count() {
        $preset = isset( $_POST['batch_preset'] ) && is_string( $_POST['batch_preset'] ) ? sanitize_key( wp_unslash( $_POST['batch_preset'] ) ) : '1';
        if ( 'custom' === $preset ) {
            return isset( $_POST['custom_count'] ) ? absint( $_POST['custom_count'] ) : 0;
        }

        return in_array( $preset, array( '1', '5', '10', '20' ), true ) ? absint( $preset ) : 0;
    }

    /** Redirect back to the request page with a result notice. */
    private function redirect( $notice, $count = 0 ) {
        $url = add_query_arg(
            array_filter( array( 'page' => 'acm-content', 'tab' => 'content', 'acm_notice' => $notice, 'count' => $count ) ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /** Render request submission feedback. */
    private function render_notice() {
        if ( ! empty( $this->feedback['message'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $this->feedback['message'] ) . '</p></div>'; }
        $notice = isset( $_GET['acm_notice'] ) ? sanitize_key( wp_unslash( $_GET['acm_notice'] ) ) : '';
        if ( 'queued' === $notice ) {
            $count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d درخواست به صف اضافه شد.', 'ai-content-manager' ), $count ) ) . '</p></div>';
        } elseif ( 'items_limit' === $notice ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'تعداد آیتم‌ها از سقف مجاز در تنظیمات کنترل مصرف بیشتر است.', 'ai-content-manager' ) . '</p></div>';
        } elseif ( 'invalid' === $notice || 'error' === $notice ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'درخواست معتبر نبود یا ذخیره آن انجام نشد.', 'ai-content-manager' ) . '</p></div>';
        }
    }

    private function value( $name, $default = '' ) { return isset( $this->form[ $name ] ) && is_string( $this->form[ $name ] ) ? $this->form[ $name ] : $default; }
    private function list_value( $name ) { return isset( $this->form[ $name ] ) && is_array( $this->form[ $name ] ) ? $this->form[ $name ] : array(); }

    private function validation_error( $message ) {
        $allowed = array( 'topic', 'topics', 'topic_input_mode', 'submission_id', 'content_type', 'mode', 'primary_keyword', 'secondary_keywords', 'keyword_mode', 'language', 'target_languages', 'word_count', 'category_choice', 'tone', 'web_research', 'featured_image', 'approval_mode', 'schedule_days', 'schedule_time', 'interval_count', 'interval_unit' );
        $values = array();
        foreach ( $allowed as $key ) {
            if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) { $values[ $key ] = substr( wp_unslash( $_POST[ $key ] ), 0, 100000 ); }
            elseif ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) { $values[ $key ] = array_slice( array_filter( wp_unslash( $_POST[ $key ] ), 'is_string' ), 0, 100 ); }
        }
        set_transient( 'acm_content_feedback_' . get_current_user_id(), array( 'message' => $message, 'values' => $values ), 600 );
        $this->redirect( 'validation' );
    }
}
