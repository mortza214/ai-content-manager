<?php
/** Queue manager admin screen.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Admin;

use AIContentManager\Integrations\Wpml;
use AIContentManager\Services\ContentTypeRegistry;
use AIContentManager\Services\QueueRepository;
use AIContentManager\Services\QueueStatus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Displays queue records with status, language, and type filters. */
final class QueueManagerPage {
    /** @var QueueRepository */
    private $repository;

    /** @var ContentTypeRegistry */
    private $content_types;

    /** @var Wpml */
    private $wpml;
    private $recovery;
    private $usage;
    private $seo_page;
    private $category_page;
    private $categories;
    private $campaigns;

    /** @param QueueRepository $repository Queue persistence layer. */
    public function __construct( $repository, $content_types, $wpml, $campaigns = null ) {
        $this->campaigns = $campaigns;
        $this->repository    = $repository;
        $this->content_types = $content_types;
        $this->wpml          = $wpml;
        $this->recovery = new \AIContentManager\Services\QueueRecovery( $repository );
        $this->usage = new UsageDashboard( $repository );
        $this->seo_page = new QueueSeoPage( $repository, $this );
        $this->categories = new \AIContentManager\Services\QueueCategory( $repository, $wpml );
        $this->category_page = new QueueCategoryPage( $repository, $this, $wpml );
        add_action( 'admin_post_acm_recover_queue', array( $this, 'handle_recovery' ) );
    }

    /** Render the filtered and paginated queue table. */
    public function render() {
        if ( ! current_user_can( 'manage_ai_content' ) ) {
            return;
        }

        $filters = $this->filters();
        $page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $total   = $this->repository->count( $filters );
        $items   = $this->repository->find( $filters, $per_page, ( $page - 1 ) * $per_page );
        $types   = $this->content_types->all();
        $languages = $this->wpml->active_languages();
        if ( $this->campaigns ) { $this->campaigns->render(); }
        $this->render_preview();
        $this->seo_page->render();
        $this->category_page->render();
        ?>
        <div class="wrap">
            <h2><?php esc_html_e( 'مدیریت صف', 'ai-content-manager' ); ?></h2>
            <?php $this->render_action_notice(); ?>
            <?php $this->render_recovery(); $this->usage->render(); ?>
            <p><?php esc_html_e( 'ثبت درخواست در تب تولید محتوا انجام می‌شود. تولید مقاله خروجی را در صف ذخیره می‌کند. ایجاد پیش‌نویس وردپرس یک عملیات دستی جداگانه است و هیچ محتوایی خودکار منتشر نمی‌شود. زباله‌دان صف، نوشته مرتبط یا مصرف API را تغییر نمی‌دهد.', 'ai-content-manager' ); ?></p>
            <p><a href="<?php echo esc_url( AdminPage::url( 'queue' ) ); ?>"><?php esc_html_e( 'صف عادی', 'ai-content-manager' ); ?></a> | <a href="<?php echo esc_url( AdminPage::url( 'queue', array( 'status' => 'trashed' ) ) ); ?>"><?php esc_html_e( 'زباله‌دان صف', 'ai-content-manager' ); ?></a></p>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="acm-queue-filters">
                <input type="hidden" name="page" value="acm-content">
                <input type="hidden" name="tab" value="queue">
                <label for="acm-filter-status"><?php esc_html_e( 'وضعیت', 'ai-content-manager' ); ?></label>
                <select id="acm-filter-status" name="status">
                    <option value=""><?php esc_html_e( 'همه وضعیت‌ها', 'ai-content-manager' ); ?></option>
                    <?php foreach ( QueueStatus::all() as $status => $label ) : ?>
                        <option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="acm-filter-language"><?php esc_html_e( 'زبان', 'ai-content-manager' ); ?></label>
                <select id="acm-filter-language" name="language">
                    <option value=""><?php esc_html_e( 'همه زبان‌ها', 'ai-content-manager' ); ?></option>
                    <?php foreach ( $languages as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filters['language'], $code ); ?>><?php echo esc_html( $label . ' (' . $code . ')' ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="acm-filter-type"><?php esc_html_e( 'نوع محتوا', 'ai-content-manager' ); ?></label>
                <select id="acm-filter-type" name="content_type">
                    <option value=""><?php esc_html_e( 'همه انواع', 'ai-content-manager' ); ?></option>
                    <?php foreach ( $types as $type => $label ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['content_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'فیلتر', 'ai-content-manager' ), 'secondary', 'filter_action', false ); ?>
            </form>

            <form id="acm-queue-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="acm_queue_action"><input type="hidden" name="bulk" value="1">
                <?php wp_nonce_field( 'acm_queue_bulk' ); $this->render_return_fields( $filters, $page ); ?>
                <label for="acm-bulk-action"><?php esc_html_e( 'عملیات روی آیتم‌های انتخاب‌شده', 'ai-content-manager' ); ?></label>
                <select id="acm-bulk-action" name="queue_action"><option value="<?php echo 'trashed' === $filters['status'] ? 'restore' : 'trash'; ?>"><?php echo esc_html( 'trashed' === $filters['status'] ? __( 'بازگردانی', 'ai-content-manager' ) : __( 'انتقال به زباله‌دان', 'ai-content-manager' ) ); ?></option></select>
                <?php submit_button( __( 'اعمال عملیات گروهی', 'ai-content-manager' ), 'secondary', 'bulk_submit', false ); ?>
            </form>

            <div class="acm-table-scroll"><table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'انتخاب', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'عملیات', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'شناسه', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'موضوع', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'سازنده', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'نوع', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'زبان', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'حالت', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'وضعیت', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'نوشته مرتبط', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'گروه', 'ai-content-manager' ); ?></th>
                    <th><?php esc_html_e( 'تاریخ ثبت', 'ai-content-manager' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( $items ) : ?>
                        <?php foreach ( $items as $item ) : ?>
                            <?php
                            $payload = json_decode( $item['payload'], true );
                            $payload = is_array( $payload ) ? $payload : array();
                            ?>
                            <tr>
                                <td><input type="checkbox" form="acm-queue-bulk-form" name="item_ids[]" value="<?php echo esc_attr( $item['item_id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'انتخاب آیتم %d', 'ai-content-manager' ), $item['item_id'] ) ); ?>"></td>
                                <td>
                                    <?php if ( 'pending' === $item['status'] && empty( $item['campaign_id'] ) ) : ?>
                                        <button type="button" class="button acm-generate" data-item-id="<?php echo esc_attr( $item['item_id'] ); ?>"><?php esc_html_e( 'تولید مقاله', 'ai-content-manager' ); ?></button>
                                        <span class="acm-generation-message" role="status"></span>
                                    <?php elseif ( in_array( $item['status'], array( 'generated', 'failed', 'trashed' ), true ) ) : ?>
                                        <a class="button" href="<?php echo esc_url( wp_nonce_url( AdminPage::url( 'queue', array_merge( $filters, array( 'paged' => $page, 'preview_item' => $item['item_id'] ) ) ), 'acm_preview_' . $item['item_id'] ) ); ?>"><?php echo esc_html( 'generated' === $item['status'] ? __( 'پیش‌نمایش', 'ai-content-manager' ) : __( 'جزئیات خطا', 'ai-content-manager' ) ); ?></a>
                                    <?php endif; ?>
                                    <?php $this->render_item_actions( $item, $filters, $page ); ?>
                                </td>
                                <td><?php echo esc_html( $item['item_id'] ); ?></td>
                                <td><?php echo esc_html( isset( $payload['topic'] ) ? $payload['topic'] : '—' ); ?><p><?php echo esc_html__( 'دسته‌بندی صف: ', 'ai-content-manager' ) . esc_html( $this->categories->describe( $payload['category'] ?? null ) ); ?></p></td>
                                <td><?php $creator = get_userdata( (int) ( $item['created_by'] ?? 0 ) ); echo esc_html( $creator ? $creator->display_name . ' (#' . (int) ( $item['created_by'] ?? 0 ) . ')' : __( 'نامشخص یا کاربر حذف‌شده', 'ai-content-manager' ) ); ?></td>
                                <td><?php echo esc_html( isset( $types[ $item['content_type'] ] ) ? $types[ $item['content_type'] ] : $item['content_type'] ); ?></td>
                                <td><?php echo esc_html( isset( $languages[ $item['language'] ] ) ? $languages[ $item['language'] ] . ' (' . $item['language'] . ')' : $item['language'] ); ?></td>
                                <td><?php $modes = array( 'manual' => __( 'دستی', 'ai-content-manager' ), 'automatic' => __( 'خودکار', 'ai-content-manager' ), 'hybrid' => __( 'ترکیبی', 'ai-content-manager' ) ); echo esc_html( $modes[ $payload['mode'] ?? 'manual' ] ?? __( 'نامشخص', 'ai-content-manager' ) ); ?></td>
                                <td><?php echo esc_html( isset( QueueStatus::all()[ $item['status'] ] ) ? QueueStatus::all()[ $item['status'] ] : $item['status'] ); ?></td>
                                <td><?php $this->render_linked_post( $item ); ?></td>
                                <td><?php echo esc_html( substr( $item['batch_id'], 0, 8 ) ); if ( ! empty( $item['campaign_id'] ) ) { echo '<p>' . esc_html( sprintf( __( 'کمپین #%d؛ مرحله: %s؛ بازبینی انسانی الزامی', 'ai-content-manager' ), $item['campaign_id'], CampaignsPage::stage_label( $item['campaign_stage'] ) ) ) . '</p>'; } ?></td>
                                <td><?php echo esc_html( get_date_from_gmt( $item['created_at'], 'Y-m-d H:i' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="12"><?php esc_html_e( 'آیتمی برای نمایش وجود ندارد.', 'ai-content-manager' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table></div>

            <?php
            $total_pages = (int) ceil( $total / $per_page );
            if ( $total_pages > 1 ) {
                $base = AdminPage::url( 'queue', array_merge( $filters, array( 'paged' => '%#%' ) ) );
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages, 'prev_text' => __( 'قبلی', 'ai-content-manager' ), 'next_text' => __( 'بعدی', 'ai-content-manager' ) ) ) ) . '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    private function render_recovery() {
        if ( isset( $_GET['acm_recovered'] ) && is_scalar( $_GET['acm_recovered'] ) ) {
            echo '<p role="status">' . esc_html( sprintf( __( 'تعداد آیتم‌های بازیابی‌شده: %d', 'ai-content-manager' ), absint( $_GET['acm_recovered'] ) ) ) . '</p>';
        }
        if ( isset( $_GET['acm_recovery_error'] ) ) { echo '<p role="alert">' . esc_html__( 'بازیابی انجام نشد؛ مجوز، قفل یا ارتباط دیتابیس را بررسی و دوباره تلاش کنید.', 'ai-content-manager' ) . '</p>'; }
        $count = $this->recovery->count();
        if ( is_wp_error( $count ) ) { echo '<p>' . esc_html( $count->get_error_message() ) . '</p>'; return; }
        echo '<p>' . esc_html( sprintf( __( 'آیتم‌های متوقف‌شده: %d (حداکثر ۱۰۰ آیتم در هر بازیابی)', 'ai-content-manager' ), $count ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="acm_recover_queue">';
        wp_nonce_field( 'acm_recover_queue' );
        submit_button( __( 'بازیابی آیتم‌های متوقف‌شده', 'ai-content-manager' ), 'secondary', 'recover', false );
        echo '</form>';
    }

    public function handle_recovery() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) ) {
            wp_die( esc_html__( 'مجوز بازیابی صف را ندارید.', 'ai-content-manager' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'acm_recover_queue' );
        $result = $this->recovery->recover();
        $args = is_wp_error( $result ) ? array( 'acm_recovery_error' => 1 ) : array( 'acm_recovered' => $result );
        wp_safe_redirect( AdminPage::url( 'queue', $args ) );
        exit;
    }

    /** Render safe HTML and metadata; raw JSON is gated server-side. */
    private function render_preview() {
        $id = isset( $_GET['preview_item'] ) && is_scalar( $_GET['preview_item'] ) ? absint( $_GET['preview_item'] ) : 0;
        if ( ! $id ) { return; }
        check_admin_referer( 'acm_preview_' . $id );
        $item = $this->repository->get( $id );
        if ( ! $item ) { return; }
        $output = json_decode( $item['generated_output'] ?? '', true );
        $metadata = json_decode( $item['api_metadata'] ?? '', true );
        echo '<div class="wrap"><h2>' . esc_html__( 'پیش‌نمایش خروجی', 'ai-content-manager' ) . '</h2>';
        $payload = json_decode( $item['payload'] ?? '', true );
        echo '<p><strong>' . esc_html__( 'دسته‌بندی انتخاب‌شده در صف: ', 'ai-content-manager' ) . '</strong>' . esc_html( $this->categories->describe( $payload['category'] ?? null ) ) . '</p>';
        if ( ! empty( $item['campaign_id'] ) ) {
            echo '<p>' . esc_html( 'کمپین #' . $item['campaign_id'] . '؛ ' . CampaignsPage::stage_label( $item['campaign_stage'] ) . '؛ بازبینی انسانی الزامی است.' ) . '</p><details><summary>منابع و شواهد پژوهش</summary>';
            $research = json_decode( $item['research_output'] ?? '', true );
            foreach ( $research['sources'] ?? array() as $source ) {
                $safe = \AIContentManager\Services\WebResearch::safe_url( $source['url'] ?? '' );
                echo '<p>' . esc_html( ( $source['title'] ?? 'عنوان هنوز بررسی نشده' ) . '؛ ' . ( ! empty( $source['verified'] ) ? 'شواهد در صفحه یافت شد' : 'هنوز تأیید نشده' ) . '؛ دسترسی (UTC): ' . ( $source['accessed_at'] ?? 'ثبت نشده' ) ) . '</p>';
                if ( $safe ) { echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( $source['url'] ) . '">' . esc_html( $source['url'] ) . '</a>'; }
                foreach ( $source['evidence'] ?? array() as $evidence ) { echo '<blockquote>' . esc_html( $evidence ) . '</blockquote>'; }
            }
            foreach ( $research['claims'] ?? array() as $claim ) { echo '<p>' . esc_html( 'ادعا: ' . $claim['claim'] . '؛ شاهد: ' . $claim['evidence'] ) . '</p>'; }
            echo '<p>یافتن نقل‌قول، اثبات درستی ادعا نیست؛ اعتبار منبع و برداشت مقاله باید انسانی بررسی شوند.</p></details>';
        }
        if ( 'trashed' === $item['status'] ) { echo '<p>' . esc_html__( 'این آیتم در زباله‌دان صف است؛ پیش‌نمایش فقط خواندنی است و تولید یا ایجاد پیش‌نویس انجام نمی‌شود.', 'ai-content-manager' ) . '</p>'; }
        if ( ( 'generated' === $item['status'] || ( 'trashed' === $item['status'] && 'generated' === ( $item['previous_status'] ?? '' ) ) ) && is_array( $output ) ) {
            echo '<h2>' . esc_html( $output['title'] ?? '' ) . '</h2>';
            echo '<p>' . esc_html( $output['excerpt'] ?? '' ) . '</p>';
            echo '<div>' . wp_kses( $output['content'] ?? '', \AIContentManager\Services\ArticleOutputValidator::allowed_html() ) . '</div>';
            foreach ( array( 'slug' => __( 'نامک', 'ai-content-manager' ), 'primary_keyword' => __( 'کلمه کلیدی اصلی', 'ai-content-manager' ), 'suggested_meta_description' => __( 'توضیحات متا', 'ai-content-manager' ), 'image_prompt' => __( 'پیشنهاد تصویر', 'ai-content-manager' ) ) as $field => $label ) {
                $value = $output[ $field ] ?? '';
                echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( is_string( $value ) ? $value : __( 'پیشنهاد ذخیره‌شده معتبر نیست؛ نیازمند بررسی است.', 'ai-content-manager' ) ) . '</p>';
            }
            echo '<p>' . esc_html__( 'کلمات کلیدی فرعی: ', 'ai-content-manager' ) . esc_html( implode( '، ', $output['secondary_keywords'] ?? array() ) ) . '</p>';
            echo '<h3>' . esc_html__( 'تیترها', 'ai-content-manager' ) . '</h3><ul>';
            foreach ( $output['headings'] ?? array() as $heading ) {
                echo '<li>' . esc_html( 'H' . $heading['level'] . ': ' . $heading['text'] ) . '</li>';
            }
            echo '</ul><h3>' . esc_html__( 'پرسش‌های متداول', 'ai-content-manager' ) . '</h3>';
            foreach ( $output['faq'] ?? array() as $faq ) {
                echo '<p><strong>' . esc_html( $faq['question'] ) . '</strong><br>' . esc_html( $faq['answer'] ) . '</p>';
            }
            if ( current_user_can( 'manage_options' ) && in_array( 'administrator', (array) wp_get_current_user()->roles, true ) ) {
                echo '<details><summary>' . esc_html__( 'JSON خام (فقط مدیر)', 'ai-content-manager' ) . '</summary><pre dir="ltr" style="white-space:pre-wrap">' . esc_html( $item['generated_output'] ) . '</pre></details>';
            }
        }
        if ( is_array( $metadata ) ) {
            $labels = array( 'model' => __( 'مدل', 'ai-content-manager' ), 'input_tokens' => __( 'توکن ورودی', 'ai-content-manager' ), 'output_tokens' => __( 'توکن خروجی', 'ai-content-manager' ), 'total_tokens' => __( 'مجموع توکن', 'ai-content-manager' ),
                'requested_at' => __( 'زمان شروع درخواست (UTC)', 'ai-content-manager' ), 'request_time' => __( 'مدت درخواست (ثانیه)', 'ai-content-manager' ), 'http_status' => __( 'کد HTTP', 'ai-content-manager' ), 'request_id' => __( 'شناسه درخواست', 'ai-content-manager' ), 'error_message' => __( 'پیام خطا', 'ai-content-manager' ) );
            echo '<h3>' . esc_html__( 'اطلاعات درخواست', 'ai-content-manager' ) . '</h3><dl>';
            foreach ( $labels as $key => $label ) {
                echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( isset( $metadata[ $key ] ) ? (string) $metadata[ $key ] : __( 'نامشخص', 'ai-content-manager' ) ) . '</dd>';
            }
            echo '</dl>';
        }
        echo '</div>';
    }

    private function render_item_actions( $item, $filters, $page ) {
        if ( ! empty( $item['post_id'] ) ) {
            $post = get_post( (int) $item['post_id'] );
            if ( 'trashed' !== $item['status'] && $post && $post->post_type === $item['content_type'] && current_user_can( 'edit_post', $post->ID ) ) {
                echo '<a class="button" href="' . esc_url( wp_nonce_url( AdminPage::url( 'queue', array_merge( $filters, array( 'paged' => $page, 'seo_item' => $item['item_id'] ) ) ), 'acm_seo_compare_' . $item['item_id'] ) ) . '">' . esc_html__( 'بررسی و اعمال اطلاعات سئو', 'ai-content-manager' ) . '</a>';
                if ( 'draft' === $post->post_status ) {
                    echo '<a class="button" href="' . esc_url( wp_nonce_url( AdminPage::url( 'queue', array( 'category_item' => $item['item_id'] ) ), 'acm_category_compare_' . $item['item_id'] ) ) . '">' . esc_html__( 'بررسی و اصلاح دسته‌بندی', 'ai-content-manager' ) . '</a>';
                }
            } elseif ( 'trashed' === $item['status'] ) {
                echo '<button type="button" class="button" disabled>' . esc_html__( 'اطلاعات سئو در زباله‌دان صف غیرفعال است', 'ai-content-manager' ) . '</button>';
            }
        }
        $actions = array();
        if ( 'generated' === $item['status'] ) { $actions['draft'] = empty( $item['post_id'] ) ? __( 'ایجاد پیش‌نویس وردپرس', 'ai-content-manager' ) : __( 'بررسی یا تکمیل پیش‌نویس', 'ai-content-manager' ); }
        if ( in_array( $item['status'], array( 'pending', 'failed', 'generated' ), true ) ) { $actions['trash'] = __( 'انتقال به زباله‌دان', 'ai-content-manager' ); }
        if ( 'trashed' === $item['status'] ) { $actions['restore'] = __( 'بازگردانی', 'ai-content-manager' ); }
        foreach ( $actions as $operation => $label ) {
            echo '<form class="acm-inline-action" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="acm_queue_action"><input type="hidden" name="queue_action" value="' . esc_attr( $operation ) . '"><input type="hidden" name="item_id" value="' . esc_attr( $item['item_id'] ) . '">';
            wp_nonce_field( 'acm_queue_' . $operation );
            $this->render_return_fields( $filters, $page );
            echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
        }
    }

    private function render_return_fields( $filters, $page ) {
        foreach ( array_merge( $filters, array( 'paged' => $page ) ) as $key => $value ) {
            echo '<input type="hidden" name="return_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
        }
    }

    public function return_args( $input ) {
        $source = array();
        foreach ( array( 'status', 'language', 'content_type' ) as $key ) { $source[ $key ] = $input[ 'return_' . $key ] ?? ''; }
        $args = array_filter( $this->filters( $source ) );
        $args['paged'] = isset( $input['return_paged'] ) && is_scalar( $input['return_paged'] ) ? max( 1, absint( $input['return_paged'] ) ) : 1;
        return $args;
    }

    private function render_action_notice() {
        $key = 'acm_queue_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) ) { return; }
        delete_transient( $key );
        echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : ( ! empty( $notice['warning'] ) ? 'notice-warning' : 'notice-success' ) ) . '"><p>' . esc_html( $notice['message'] ) . '</p>';
        if ( ! empty( $notice['details'] ) ) { echo '<details><summary>' . esc_html__( 'جزئیات هر آیتم', 'ai-content-manager' ) . '</summary><ul>'; foreach ( $notice['details'] as $detail ) { echo '<li>' . esc_html( $detail ) . '</li>'; } echo '</ul></details>'; }
        echo '</div>';
    }

    private function render_linked_post( $item ) {
        $id = (int) ( $item['post_id'] ?? 0 );
        if ( ! $id ) { echo esc_html__( 'بدون نوشته مرتبط', 'ai-content-manager' ); return; }
        $post = get_post( $id );
        if ( ! $post ) { echo esc_html( sprintf( __( 'نوشته #%d حذف شده؛ جایگزین خودکار ساخته نمی‌شود.', 'ai-content-manager' ), $id ) ); return; }
        $labels = array( 'draft' => __( 'پیش‌نویس', 'ai-content-manager' ), 'trash' => __( 'زباله‌دان وردپرس', 'ai-content-manager' ), 'publish' => __( 'منتشرشده', 'ai-content-manager' ), 'future' => __( 'زمان‌بندی‌شده', 'ai-content-manager' ), 'pending' => __( 'در انتظار بازبینی', 'ai-content-manager' ), 'private' => __( 'خصوصی', 'ai-content-manager' ) );
        echo esc_html( '#' . $id . ': ' . ( $labels[ $post->post_status ] ?? __( 'وضعیت دیگر', 'ai-content-manager' ) ) );
        if ( 'ready' !== ( $item['draft_state'] ?? '' ) ) { echo '<p>' . esc_html__( 'تکمیل تنظیمات پیش‌نویس هنوز تأیید نشده است.', 'ai-content-manager' ) . '</p>'; }
        if ( 'trash' !== $post->post_status && current_user_can( 'edit_post', $id ) ) {
            $link = get_edit_post_link( $id, 'raw' );
            if ( $link ) { echo '<p><a href="' . esc_url( $link ) . '">' . esc_html( 'draft' === $post->post_status ? __( 'ویرایش پیش‌نویس', 'ai-content-manager' ) : __( 'ویرایش نوشته مرتبط', 'ai-content-manager' ) ) . '</a></p>'; }
        }
    }

    /** Preserve only validated navigation fields during legacy GET redirects. */
    public function navigation_args() {
        $args = array_filter( $this->filters() );
        foreach ( array( 'paged', 'preview_item' ) as $key ) {
            if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && absint( $_GET[ $key ] ) > 0 ) {
                $args[ $key ] = absint( $_GET[ $key ] );
            }
        }
        if ( isset( $args['preview_item'], $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ) {
            $args['_wpnonce'] = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
        }
        // The destination still calls check_admin_referer for the original item.
        return $args;
    }

    /** Return only supported filter values. */
    private function filters( $source = null ) {
        $source = null === $source ? $_GET : $source;
        $status   = isset( $source['status'] ) && is_string( $source['status'] ) ? sanitize_key( wp_unslash( $source['status'] ) ) : '';
        $language = isset( $source['language'] ) && is_string( $source['language'] ) ? sanitize_text_field( wp_unslash( $source['language'] ) ) : '';
        $type     = isset( $source['content_type'] ) && is_string( $source['content_type'] ) ? sanitize_key( wp_unslash( $source['content_type'] ) ) : '';
        $statuses = QueueStatus::all();
        $languages = $this->wpml->active_languages();
        $types     = $this->content_types->all();

        return array(
            'status'       => isset( $statuses[ $status ] ) ? $status : '',
            'language'     => isset( $languages[ $language ] ) ? $language : '',
            'content_type' => isset( $types[ $type ] ) ? $type : '',
        );
    }
}
