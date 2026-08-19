<?php

class Mailmunch_Woocommerce {
  const CONSENT_META = '_mailmunch_marketing_consent';
  const CONSENT_PLACEMENT_DEFAULT = 'woocommerce_review_order_before_submit';

  protected $api;
  protected $plugin_name;
  protected $prefix;

  /** @var bool True while applying an inbound MailMunch → WP customer sync (prevents echo). */
  protected $syncing_from_mailmunch = false;

  /** @var array<int,array> Customer payloads cached before delete_user (WC deleted webhooks only send {id}). */
  protected $deleted_customer_snapshots = array();

  /** @var array<int,array> Order payloads cached before trash/delete (WC deleted webhooks only send {id}). */
  protected $deleted_order_snapshots = array();

  /** Snapshots must outlive an Action Scheduler backlog, so give them a generous TTL. */
  const SNAPSHOT_TTL = DAY_IN_SECONDS;

  public function __construct( $plugin_name, $api = null ) {
    $this->plugin_name = $plugin_name;
    $this->api = $api;
    $this->prefix = MAILMUNCH_PREFIX . '_';
  }

  /**
   * Lazily build the API client.
   *
   * Mailmunch_Api::__construct() calls ensureUser() + findOrCreateSite(), which issue
   * blocking wp_remote_* requests with a 120 second timeout. register_hooks() runs on
   * every single request, so the client must never be built there — only on the handful
   * of paths that genuinely talk to MailMunch.
   */
  protected function api() {
    if ( ! $this->api instanceof Mailmunch_Api ) {
      $this->api = new Mailmunch_Api();
    }
    return $this->api;
  }

  /**
   * Site id straight from options — no API client, so this is safe on hot paths
   * such as webhook delivery.
   */
  protected function get_site_id() {
    return get_option( $this->prefix . 'site_id' );
  }

  public function register_hooks( $loader ) {
    $loader->add_action( 'admin_menu', $this, 'add_menu' );
    $loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_scripts' );
    $loader->add_action( 'wp_ajax_mailmunch_woo_connect', $this, 'ajax_connect' );
    $loader->add_action( 'wp_ajax_mailmunch_woo_disconnect', $this, 'ajax_disconnect' );
    $loader->add_action( 'wp_ajax_mailmunch_woo_repair_webhooks', $this, 'ajax_repair_webhooks' );
    $loader->add_action( 'woocommerce_created_customer', $this, 'save_user_consent', 5, 1 );
    $loader->add_action( 'set_user_role', $this, 'save_user_consent_on_role', 5, 3 );
    $loader->add_action( 'user_register', $this, 'save_user_consent', 5, 1 );
    $loader->add_action( 'edit_user_profile_update', $this, 'save_user_consent', 5, 1 );
    $loader->add_action( 'personal_options_update', $this, 'save_user_consent', 5, 1 );
    $loader->add_action( 'woocommerce_checkout_update_order_meta', $this, 'save_checkout_consent', 5, 2 );
    $loader->add_action( $this->get_consent_placement(), $this, 'render_consent_checkbox' );
    if ( $this->show_consent_on_registration() ) {
      $loader->add_action( 'woocommerce_register_form', $this, 'render_consent_checkbox' );
    }
    $loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
    $loader->add_action( 'user_new_form', $this, 'render_admin_user_consent', 10, 1 );
    $loader->add_action( 'edit_user_profile', $this, 'render_admin_user_consent_profile', 10, 1 );
    $loader->add_action( 'show_user_profile', $this, 'render_admin_user_consent_profile', 10, 1 );

    // WC *.deleted payloads are only {id} — snapshot while the resource still exists.
    add_action( 'delete_user', array( $this, 'cache_customer_before_delete' ), 1, 1 );
    add_action( 'woocommerce_before_trash_order', array( $this, 'cache_order_before_delete' ), 1, 1 );
    add_action( 'woocommerce_before_delete_order', array( $this, 'cache_order_before_delete' ), 1, 1 );
    add_action( 'wp_trash_post', array( $this, 'cache_order_before_trash_post' ), 1, 1 );

    // Shape WooCommerce-native webhooks (auto-created on Connect) for MailMunch Lambda.
    add_filter( 'woocommerce_webhook_should_deliver', array( $this, 'filter_webhook_should_deliver' ), 10, 3 );
    add_filter( 'woocommerce_webhook_payload', array( $this, 'filter_webhook_payload' ), 10, 4 );
    add_filter( 'woocommerce_webhook_http_args', array( $this, 'filter_webhook_http_args' ), 10, 3 );
    // Local/dev: deliver immediately to MAILMUNCH_WEBHOOKS_URL (Lambda). Async Action Scheduler
    // often never runs in Docker WP, so webhooks silently never leave WordPress.
    add_filter( 'woocommerce_webhook_deliver_async', array( $this, 'filter_webhook_deliver_async' ), 10, 3 );

    // MM → WP inbound sync: admin-ajax for local/dev (plain permalinks / Docker); REST for production.
    add_action( 'wp_ajax_nopriv_mailmunch_woo_sync_customer', array( $this, 'ajax_sync_customer' ) );
    add_action( 'wp_ajax_mailmunch_woo_sync_customer', array( $this, 'ajax_sync_customer' ) );
  }

  /**
   * True when MAILMUNCH_URL points at a local/dev API (inbound sync URL + sync webhook delivery).
   */
  protected function is_local_mailmunch() {
    $host = wp_parse_url( MAILMUNCH_URL, PHP_URL_HOST );
    return in_array( strtolower( (string) $host ), array( 'local.mailmunch.co', 'localhost', '127.0.0.1' ), true );
  }

  /**
   * Deliver MailMunch webhooks synchronously in local/dev so they reach Lambda immediately.
   * Delivery URL stays MAILMUNCH_WEBHOOKS_URL; only the async queue is bypassed.
   */
  public function filter_webhook_deliver_async( $async, $webhook, $arg ) {
    if ( $webhook && $this->is_mailmunch_webhook( $webhook->get_id() ) && $this->is_local_mailmunch() ) {
      return false;
    }
    return $async;
  }

  public function get_sync_token() {
    return get_option( $this->prefix . 'woo_sync_token' );
  }

  public function is_connected() {
    return ! empty( $this->get_sync_token() );
  }

  public function enqueue_scripts( $hook ) {
    if ( $hook === 'mailmunch_page_mailmunch-woocommerce' ) {
      wp_enqueue_script( 'jquery' );
    }
  }

  public function add_menu() {
    if ( ! class_exists( 'WooCommerce' ) ) {
      return;
    }

    add_submenu_page(
      'mailmunch',
      'WooCommerce Sync',
      'WooCommerce Sync',
      'manage_options',
      'mailmunch-woocommerce',
      array( $this, 'render_page' )
    );
  }

  public function render_page() {
    $consent_saved = $this->save_consent_settings();
    $connected = $this->is_connected();
    $status = array();
    if ( $connected ) {
      // NOTE: deliberately no ensure_webhooks() here. Rendering a page is a GET and must not
      // delete/recreate webhooks as a side effect; use the Repair button below instead.
      $this->api()->setRequestType( 'get' );
      $response = $this->api()->ping( '/wordpress/woocommerce/status?site_id=' . $this->api()->getSiteId() );
      if ( ! is_wp_error( $response ) ) {
        $status = json_decode( $response['body'], true );
      }
    }
    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce = wp_create_nonce( 'mailmunch_woo_sync' );
    ?>
    <div class="wrap">
      <h1>MailMunch WooCommerce Sync</h1>
      <p id="mailmunch-woo-message" class="notice" style="display:none;"></p>
      <?php if ( $connected ) : ?>
        <p><strong>Status:</strong> Connected</p>
        <?php if ( ! empty( $status['store_url'] ) ) : ?>
          <p><strong>Store:</strong> <?php echo esc_html( $status['store_url'] ); ?></p>
        <?php endif; ?>
        <?php
        $webhook_ids = $this->get_webhook_ids();
        $expected_webhooks = count( $this->webhook_topics() );
        ?>
        <p>
          <strong>Webhooks:</strong>
          <?php echo esc_html( count( $webhook_ids ) . ' of ' . $expected_webhooks ); ?>
          registered (WooCommerce → Settings → Advanced → Webhooks)
        </p>
        <?php if ( count( $webhook_ids ) !== $expected_webhooks ) : ?>
          <p class="notice notice-warning" style="padding:8px 12px;">
            Some webhooks are missing, so store changes may not reach MailMunch.
            <button type="button" class="button button-secondary" id="mailmunch-woo-repair">Repair webhooks</button>
          </p>
        <?php endif; ?>
        <button type="button" class="button button-secondary" id="mailmunch-woo-disconnect">Disconnect</button>
      <?php else : ?>
        <p>Connect your WooCommerce store to sync customers with MailMunch.</p>
        <button type="button" class="button button-primary" id="mailmunch-woo-connect">Connect Store</button>
      <?php endif; ?>

      <?php $this->render_consent_settings( $consent_saved ); ?>
    </div>
    <script>
    jQuery(function($) {
      var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
      var nonce = <?php echo wp_json_encode( $nonce ); ?>;
      function post(action, $button, loadingText) {
        var originalText = $button.text();
        $button.prop('disabled', true).text(loadingText);
        $('#mailmunch-woo-message').hide();
        return $.ajax({
          url: ajaxUrl,
          type: 'POST',
          dataType: 'json',
          data: { action: action, nonce: nonce }
        }).done(function(response) {
          if (response && response.success) {
            window.location.reload();
            return;
          }
          $('#mailmunch-woo-message').removeClass('notice-success notice-error').addClass('notice-error')
            .text((response && response.data) ? response.data : 'Connection failed.').show();
          $button.prop('disabled', false).text(originalText);
        }).fail(function(xhr) {
          var errorMessage = 'Could not connect. Check that MailMunch API is running at <?php echo esc_js( MAILMUNCH_URL ); ?>.';
          if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMessage = xhr.responseJSON.data;
          }
          $('#mailmunch-woo-message').removeClass('notice-success notice-error').addClass('notice-error')
            .text(errorMessage).show();
          $button.prop('disabled', false).text(originalText);
        });
      }
      $('#mailmunch-woo-connect').on('click', function() {
        post('mailmunch_woo_connect', $(this), 'Connecting...');
      });
      $('#mailmunch-woo-disconnect').on('click', function() {
        post('mailmunch_woo_disconnect', $(this), 'Disconnecting...');
      });
      $('#mailmunch-woo-repair').on('click', function() {
        post('mailmunch_woo_repair_webhooks', $(this), 'Repairing...');
      });
    });
    </script>
    <?php
  }

  /**
   * Shared nonce + capability gate for the settings-page ajax actions.
   */
  protected function authorize_admin_ajax_request() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mailmunch_woo_sync' ) ) {
      wp_send_json_error( 'Invalid security token. Refresh the page and try again.' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( 'Unauthorized' );
    }
  }

  public function ajax_connect() {
    $this->authorize_admin_ajax_request();

    $connect_params = array(
      'site_id' => $this->api()->getSiteId(),
      'store_url' => home_url(),
      'store_name' => get_bloginfo( 'name' ),
      'plugin_version' => MAILMUNCH_VERSION,
      'sync_customer_url' => $this->sync_customer_inbound_url(),
    );
    if ( $this->is_local_mailmunch() ) {
      // Rails/Sidekiq on mailmunch_default can reach the wordpress service directly.
      $connect_params['internal_sync_customer_url'] = $this->sync_customer_inbound_url( 'http://wordpress' );
    }

    $this->api()->setRequestType( 'post' );
    $response = $this->api()->ping( '/wordpress/woocommerce/connect', $connect_params );

    if ( is_wp_error( $response ) ) {
      wp_send_json_error( $response->get_error_message() );
    }

    $body = json_decode( $response['body'], true );
    if ( empty( $body['sync_token'] ) ) {
      wp_send_json_error( $body['error'] ?? 'Connection failed' );
    }

    update_option( $this->prefix . 'woo_sync_token', $body['sync_token'] );
    // Report a partial webhook set rather than storing it silently — the reloaded page
    // shows the shortfall and a Repair button.
    $body['webhooks_complete'] = $this->ensure_webhooks( $body['sync_token'] );
    wp_send_json_success( $body );
  }

  /**
   * Explicit, nonce-guarded webhook re-provisioning. Webhooks are never created as a
   * side effect of rendering the settings page.
   */
  public function ajax_repair_webhooks() {
    $this->authorize_admin_ajax_request();

    $token = $this->get_sync_token();
    if ( empty( $token ) ) {
      wp_send_json_error( 'Not connected. Connect the store first.' );
    }
    if ( ! $this->ensure_webhooks( $token ) ) {
      wp_send_json_error( 'Some WooCommerce webhooks could not be created. Check the WooCommerce logs and try again.' );
    }
    wp_send_json_success();
  }

  public function ajax_disconnect() {
    $this->authorize_admin_ajax_request();

    $this->api()->setRequestType( 'post' );
    $this->api()->ping( '/wordpress/woocommerce/disconnect', array(
      'site_id' => $this->api()->getSiteId(),
    ) );
    $this->delete_webhooks();
    delete_option( $this->prefix . 'woo_sync_token' );
    wp_send_json_success();
  }

  /**
   * Allowed classic-checkout hooks for the marketing consent checkbox.
   *
   * @return array<string,string> hook => label
   */
  protected function consent_placement_hooks() {
    return array(
      'woocommerce_review_order_before_submit' => __( 'Before Complete Order button', 'mailmunch' ),
      'woocommerce_review_order_after_submit' => __( 'After Complete Order button', 'mailmunch' ),
      'woocommerce_after_checkout_billing_form' => __( 'After billing details', 'mailmunch' ),
      'woocommerce_after_checkout_shipping_form' => __( 'After shipping details', 'mailmunch' ),
      'woocommerce_after_order_notes' => __( 'After order notes', 'mailmunch' ),
      'woocommerce_checkout_before_terms_and_conditions' => __( 'Before terms and conditions', 'mailmunch' ),
      'woocommerce_checkout_after_terms_and_conditions' => __( 'After terms and conditions', 'mailmunch' ),
    );
  }

  protected function get_consent_placement() {
    $placement = get_option( $this->prefix . 'woo_consent_placement', self::CONSENT_PLACEMENT_DEFAULT );
    if ( ! array_key_exists( $placement, $this->consent_placement_hooks() ) ) {
      return self::CONSENT_PLACEMENT_DEFAULT;
    }
    return $placement;
  }

  protected function show_consent_on_registration() {
    return get_option( $this->prefix . 'woo_consent_show_on_registration', 'yes' ) !== 'no';
  }

  protected function consent_default_checked() {
    return get_option( $this->prefix . 'woo_consent_default_checked', 'no' ) === 'yes';
  }

  /**
   * Checked state for the storefront checkbox: stored answer wins when present,
   * otherwise the default-checked setting (unchecked unless the merchant opted in).
   */
  protected function consent_checkbox_should_be_checked() {
    if ( is_user_logged_in() ) {
      $meta = get_user_meta( get_current_user_id(), self::CONSENT_META, true );
      if ( $meta === 'yes' || $meta === 'no' ) {
        return $meta === 'yes';
      }
    }
    return $this->consent_default_checked();
  }

  /**
   * Persist consent checkbox settings from the WooCommerce Sync page.
   *
   * @return bool True when a valid settings POST was saved.
   */
  protected function save_consent_settings() {
    if ( ! isset( $_POST['mailmunch_woo_consent_nonce'] )
      || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mailmunch_woo_consent_nonce'] ) ), 'mailmunch_woo_consent_settings' )
      || ! current_user_can( 'manage_options' ) ) {
      return false;
    }

    $placement = isset( $_POST['woo_consent_placement'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_consent_placement'] ) ) : '';
    if ( ! array_key_exists( $placement, $this->consent_placement_hooks() ) ) {
      $placement = self::CONSENT_PLACEMENT_DEFAULT;
    }
    update_option( $this->prefix . 'woo_consent_placement', $placement );

    $show_on_registration = ( isset( $_POST['woo_consent_show_on_registration'] ) && sanitize_text_field( wp_unslash( $_POST['woo_consent_show_on_registration'] ) ) === 'yes' ) ? 'yes' : 'no';
    update_option( $this->prefix . 'woo_consent_show_on_registration', $show_on_registration );

    $default_checked = ( isset( $_POST['woo_consent_default_checked'] ) && sanitize_text_field( wp_unslash( $_POST['woo_consent_default_checked'] ) ) === 'yes' ) ? 'yes' : 'no';
    update_option( $this->prefix . 'woo_consent_default_checked', $default_checked );

    return true;
  }

  protected function render_consent_settings( $saved ) {
    $placement = $this->get_consent_placement();
    $show_on_registration = $this->show_consent_on_registration() ? 'yes' : 'no';
    $default_checked = $this->consent_default_checked() ? 'yes' : 'no';
    ?>
      <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Marketing consent settings saved.', 'mailmunch' ); ?></p></div>
      <?php endif; ?>
      <h2><?php esc_html_e( 'Marketing consent', 'mailmunch' ); ?></h2>
      <p><?php esc_html_e( 'These options apply to the classic WooCommerce checkout. The Checkout block does not support custom checkbox placement.', 'mailmunch' ); ?></p>
      <form method="post">
        <?php wp_nonce_field( 'mailmunch_woo_consent_settings', 'mailmunch_woo_consent_nonce' ); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="woo_consent_placement"><?php esc_html_e( 'Checkout placement', 'mailmunch' ); ?></label>
            </th>
            <td>
              <select name="woo_consent_placement" id="woo_consent_placement">
                <?php foreach ( $this->consent_placement_hooks() as $hook => $label ) : ?>
                  <option value="<?php echo esc_attr( $hook ); ?>" <?php selected( $placement, $hook ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="woo_consent_show_on_registration"><?php esc_html_e( 'Show on registration form', 'mailmunch' ); ?></label>
            </th>
            <td>
              <select name="woo_consent_show_on_registration" id="woo_consent_show_on_registration">
                <option value="yes" <?php selected( $show_on_registration, 'yes' ); ?>><?php esc_html_e( 'Yes', 'mailmunch' ); ?></option>
                <option value="no" <?php selected( $show_on_registration, 'no' ); ?>><?php esc_html_e( 'No', 'mailmunch' ); ?></option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="woo_consent_default_checked"><?php esc_html_e( 'Default checkbox state', 'mailmunch' ); ?></label>
            </th>
            <td>
              <select name="woo_consent_default_checked" id="woo_consent_default_checked">
                <option value="no" <?php selected( $default_checked, 'no' ); ?>><?php esc_html_e( 'Unchecked', 'mailmunch' ); ?></option>
                <option value="yes" <?php selected( $default_checked, 'yes' ); ?>><?php esc_html_e( 'Checked', 'mailmunch' ); ?></option>
              </select>
            </td>
          </tr>
        </table>
        <?php submit_button( __( 'Save consent settings', 'mailmunch' ) ); ?>
      </form>
    <?php
  }

  public function render_consent_checkbox() {
    // Stored consent wins so an existing subscriber is not silently opted out,
    // and someone who already declined is not re-opted by the default-checked setting.
    $checked = $this->consent_checkbox_should_be_checked();
    ?>
    <p class="form-row mailmunch-marketing-consent">
      <input type="hidden" name="mailmunch_marketing_consent_present" value="1" />
      <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
        <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="mailmunch_marketing_consent" value="1" <?php checked( $checked ); ?> />
        <span><?php esc_html_e( 'Subscribe to MailMunch marketing emails', 'mailmunch' ); ?></span>
      </label>
    </p>
    <?php
  }

  public function render_admin_user_consent( $type ) {
    if ( $type !== 'add-new-user' ) {
      return;
    }
    $this->output_admin_user_consent_fields( false );
  }

  public function render_admin_user_consent_profile( $user ) {
    if ( ! $user || empty( $user->ID ) ) {
      return;
    }
    $checked = get_user_meta( $user->ID, self::CONSENT_META, true ) === 'yes';
    $this->output_admin_user_consent_fields( $checked );
  }

  protected function output_admin_user_consent_fields( $checked ) {
    ?>
    <h2><?php esc_html_e( 'MailMunch', 'mailmunch' ); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e( 'Marketing', 'mailmunch' ); ?></th>
        <td>
          <input type="hidden" name="mailmunch_marketing_consent_present" value="1" />
          <input type="checkbox" name="mailmunch_marketing_consent" id="mailmunch_marketing_consent" value="1" <?php checked( $checked ); ?> />
          <label for="mailmunch_marketing_consent"><?php esc_html_e( 'Subscribe to marketing emails', 'mailmunch' ); ?></label>
        </td>
      </tr>
    </table>
    <?php
  }

  public function save_user_consent_on_role( $user_id, $role, $old_roles ) {
    if ( $role === 'customer' ) {
      $this->save_user_consent( $user_id );
    }
  }

  public function save_user_consent( $user_id ) {
    if ( ! $this->consent_field_was_posted() ) {
      return;
    }
    update_user_meta( $user_id, self::CONSENT_META, $this->posted_marketing_consent() ? 'yes' : 'no' );
  }

  public function save_checkout_consent( $order_id, $data ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
      return;
    }

    $user_id = $order->get_customer_id();

    // The consent checkbox only renders on the *classic* checkout (placement is
    // configurable). The block checkout — the WooCommerce default since 8.3 —
    // still fires this hook but never renders the field, and an unticked checkbox
    // is simply absent from $_POST. Writing the posted value unconditionally
    // would therefore revoke consent on every order. Only record a decision when
    // the customer was actually shown the field.
    if ( ! $this->consent_field_was_posted() ) {
      // Carry the account-level answer onto the order for reporting, but change nothing.
      if ( $user_id > 0 && ! $order->meta_exists( self::CONSENT_META ) ) {
        $order->update_meta_data( self::CONSENT_META, $this->stored_marketing_consent( $user_id ) ? 'yes' : 'no' );
        $order->save();
      }
      return;
    }

    $consent = $this->posted_marketing_consent() ? 'yes' : 'no';
    $order->update_meta_data( self::CONSENT_META, $consent );
    $order->save();
    if ( $user_id > 0 ) {
      update_user_meta( $user_id, self::CONSENT_META, $consent );
    }
  }

  /**
   * True when the request actually carried a MailMunch consent field, i.e. the customer
   * was shown the checkbox and their answer is meaningful. An unticked checkbox posts
   * nothing, so the hidden "_present" marker is what distinguishes "said no" from
   * "was never asked".
   */
  protected function consent_field_was_posted() {
    return isset( $_POST['mailmunch_marketing_consent_present'] )
      || isset( $_POST['mailmunch_marketing_consent'] )
      || isset( $_POST['woocommerce_marketing'] );
  }

  public function register_rest_routes() {
    register_rest_route( 'mailmunch/v1', '/sync/customer', array(
      'methods' => 'POST',
      'callback' => array( $this, 'sync_customer' ),
      'permission_callback' => array( $this, 'authorize_rest_request' ),
    ) );
  }

  /**
   * URL Mailmunch should POST to for MM → WooCommerce customer sync.
   * Local/dev uses admin-ajax so plain permalinks and Docker routing still work.
   */
  protected function sync_customer_inbound_url( $base_url = null ) {
    if ( $this->is_local_mailmunch() ) {
      return $this->build_admin_ajax_sync_url( $base_url );
    }

    return rest_url( 'mailmunch/v1/sync/customer' );
  }

  protected function build_admin_ajax_sync_url( $base_url = null ) {
    if ( $base_url ) {
      return rtrim( $base_url, '/' ) . '/wp-admin/admin-ajax.php?action=mailmunch_woo_sync_customer';
    }

    return add_query_arg( 'action', 'mailmunch_woo_sync_customer', admin_url( 'admin-ajax.php' ) );
  }

  protected function read_authorization_token() {
    $auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ( empty( $auth ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
      $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ( $auth && stripos( $auth, 'Bearer ' ) === 0 ) {
      return trim( substr( $auth, 7 ) );
    }

    if ( ! empty( $_SERVER['HTTP_X_MAILMUNCH_SYNC_TOKEN'] ) ) {
      return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MAILMUNCH_SYNC_TOKEN'] ) );
    }

    // Deliberately no $_GET fallback: this token authorises an unauthenticated endpoint
    // that creates WordPress users, and query strings leak into access logs, proxy logs
    // and Referer headers. Header-only.
    return '';
  }

  protected function read_sync_token_from_request( $request = null ) {
    $token = $this->read_authorization_token();
    if ( ! empty( $token ) ) {
      return $token;
    }

    if ( $request instanceof WP_REST_Request ) {
      $header_token = $request->get_header( 'x-mailmunch-sync-token' );
      if ( ! empty( $header_token ) ) {
        return trim( $header_token );
      }

      $auth = $request->get_header( 'authorization' );
      if ( $auth && stripos( $auth, 'Bearer ' ) === 0 ) {
        return trim( substr( $auth, 7 ) );
      }
    }

    return '';
  }

  protected function authorize_inbound_sync_request( $request = null ) {
    $token = $this->read_sync_token_from_request( $request );
    $stored = $this->get_sync_token();
    return ! empty( $token ) && ! empty( $stored ) && hash_equals( $stored, $token );
  }

  protected function read_inbound_sync_payload() {
    if ( ! empty( $_POST ) && is_array( $_POST ) && ! empty( $_POST['email'] ) ) {
      return wp_unslash( $_POST );
    }

    $raw = file_get_contents( 'php://input' );
    if ( ! is_string( $raw ) || $raw === '' ) {
      return array();
    }

    $payload = json_decode( $raw, true );
    return is_array( $payload ) ? $payload : array();
  }

  protected function read_inbound_idempotency_key() {
    if ( ! empty( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) ) {
      return sanitize_text_field( wp_unslash( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) );
    }

    return '';
  }

  public function ajax_sync_customer() {
    if ( ! $this->authorize_inbound_sync_request() ) {
      status_header( 401 );
      wp_send_json( array( 'error' => 'Unauthorized' ) );
    }

    $this->syncing_from_mailmunch = true;

    try {
      $result = $this->process_inbound_customer_sync_payload(
        $this->read_inbound_sync_payload(),
        $this->read_inbound_idempotency_key()
      );
      if ( is_wp_error( $result ) ) {
        $status = (int) ( $result->get_error_data()['status'] ?? 422 );
        status_header( $status );
        wp_send_json( array( 'error' => $result->get_error_message() ) );
      }
      wp_send_json( $result );
    } finally {
      $this->syncing_from_mailmunch = false;
    }
  }

  public function authorize_rest_request( $request ) {
    return $this->authorize_inbound_sync_request( $request );
  }

  public function sync_customer( WP_REST_Request $request ) {
    $this->syncing_from_mailmunch = true;

    try {
      $result = $this->process_inbound_customer_sync_payload(
        $request->get_json_params(),
        $request->get_header( 'Idempotency-Key' )
      );
      if ( is_wp_error( $result ) ) {
        return $result;
      }
      return rest_ensure_response( $result );
    } finally {
      $this->syncing_from_mailmunch = false;
    }
  }

  /**
   * Idempotency wrapper. The key is only burned once the sync has actually succeeded —
   * marking it up front would turn a transient failure (invalid email, wc_create_new_customer
   * error, fatal) into a permanent one, because MailMunch's retry would be answered with
   * "duplicate, all good" and the customer would never be created.
   */
  protected function process_inbound_customer_sync_payload( $payload, $idempotency_key = null ) {
    $cache_key = $idempotency_key ? 'mailmunch_wc_idem_' . md5( $idempotency_key ) : '';

    if ( $cache_key && get_transient( $cache_key ) ) {
      return array( 'success' => true, 'duplicate' => true );
    }

    $result = $this->run_inbound_customer_sync( $payload );

    if ( $cache_key && ! is_wp_error( $result ) ) {
      set_transient( $cache_key, 1, DAY_IN_SECONDS );
    }

    return $result;
  }

  protected function run_inbound_customer_sync( $payload ) {
    $payload = is_array( $payload ) ? $payload : array();

    $email = sanitize_email( $payload['email'] ?? '' );
    if ( empty( $email ) ) {
      return new WP_Error( 'invalid_email', 'Email is required', array( 'status' => 400 ) );
    }

    $customer_data = $payload['customer'] ?? array();
    $billing = $customer_data['billing'] ?? array();
    $marketing_consent = ! empty( $payload['marketing_consent'] );
    $user = get_user_by( 'email', $email );
    $customer_id = $user ? $user->ID : 0;

    // get_user_by() matches *any* WordPress user, not just shoppers. The store owner is
    // almost always on their own MailMunch list, and we must not rewrite an
    // administrator's name and billing address with list data.
    if ( $customer_id && ! $this->is_woocommerce_customer( $customer_id ) ) {
      return array(
        'success' => true,
        'noop' => true,
        'message' => 'Email belongs to a non-customer WordPress user — left untouched',
      );
    }

    if ( ! $customer_id ) {
      $guest_orders = wc_get_orders( array(
        'billing_email' => $email,
        'customer_id' => 0,
        'limit' => 1,
      ) );
      if ( ! empty( $guest_orders ) && empty( $payload['force_create'] ) ) {
        return array(
          'success' => true,
          'noop' => true,
          'message' => 'Guest order contact — no WooCommerce customer account',
        );
      }

      // wc_create_new_customer() fires woocommerce_created_customer, which WooCommerce
      // wires to the "Your account has been created" transactional email. Syncing a
      // MailMunch list must not mail every contact an account notice for a store they
      // never signed up to, so suppress that one email for the duration of the call.
      add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
      try {
        $customer_id = wc_create_new_customer(
          $email,
          '',
          wp_generate_password( 12, true ),
          array(
            'first_name' => sanitize_text_field( $customer_data['first_name'] ?? '' ),
            'last_name' => sanitize_text_field( $customer_data['last_name'] ?? '' ),
          )
        );
      } finally {
        remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
      }

      if ( is_wp_error( $customer_id ) ) {
        return new WP_Error( 'create_failed', $customer_id->get_error_message(), array( 'status' => 422 ) );
      }
    }

    $customer = new WC_Customer( $customer_id );
    if ( ! empty( $customer_data['first_name'] ) ) {
      $customer->set_first_name( sanitize_text_field( $customer_data['first_name'] ) );
    }
    if ( ! empty( $customer_data['last_name'] ) ) {
      $customer->set_last_name( sanitize_text_field( $customer_data['last_name'] ) );
    }
    foreach ( array(
      'company' => 'set_billing_company',
      'address_1' => 'set_billing_address_1',
      'city' => 'set_billing_city',
      'state' => 'set_billing_state',
      'country' => 'set_billing_country',
      'postcode' => 'set_billing_postcode',
      'phone' => 'set_billing_phone',
    ) as $field => $setter ) {
      if ( ! empty( $billing[ $field ] ) ) {
        $customer->$setter( sanitize_text_field( $billing[ $field ] ) );
      }
    }
    $customer->save();

    update_user_meta( $customer_id, self::CONSENT_META, $marketing_consent ? 'yes' : 'no' );

    return array(
      'success' => true,
      'woocommerce_customer_id' => $customer_id,
      'message' => 'Customer synced from MailMunch',
    );
  }

  /**
   * True only when the WP user has the WooCommerce "customer" role.
   */
  protected function is_woocommerce_customer( $user_id ) {
    $user = get_userdata( $user_id );
    return $user && in_array( 'customer', (array) $user->roles, true );
  }

  /**
   * Snapshot customer payload before deletion — WC customer.deleted body is only {id}.
   */
  public function cache_customer_before_delete( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id || ! $this->is_woocommerce_customer( $user_id ) || ! $this->is_connected() ) {
      return;
    }

    $payload = $this->build_customer_payload( $user_id );
    if ( empty( $payload['customer']['email'] ) ) {
      return;
    }

    $this->deleted_customer_snapshots[ $user_id ] = $payload;
    set_transient( $this->prefix . 'deleted_customer_' . $user_id, $payload, self::SNAPSHOT_TTL );
  }

  /**
   * @return array|null
   */
  protected function get_deleted_customer_snapshot( $user_id ) {
    $user_id = absint( $user_id );
    if ( isset( $this->deleted_customer_snapshots[ $user_id ] ) ) {
      return $this->deleted_customer_snapshots[ $user_id ];
    }
    $cached = get_transient( $this->prefix . 'deleted_customer_' . $user_id );
    return is_array( $cached ) ? $cached : null;
  }

  protected function clear_deleted_customer_snapshot( $user_id ) {
    $user_id = absint( $user_id );
    unset( $this->deleted_customer_snapshots[ $user_id ] );
    delete_transient( $this->prefix . 'deleted_customer_' . $user_id );
  }

  /**
   * Legacy CPT trash hook — only snapshot shop orders.
   */
  public function cache_order_before_trash_post( $post_id ) {
    $post_id = absint( $post_id );
    if ( ! $post_id || get_post_type( $post_id ) !== 'shop_order' ) {
      return;
    }
    $this->cache_order_before_delete( $post_id );
  }

  /**
   * Snapshot order ingest payload before trash/delete — WC order.deleted body is only {id}.
   *
   * @param int|WC_Order $order_id Order ID or order object.
   */
  public function cache_order_before_delete( $order_id ) {
    if ( ! $this->is_connected() ) {
      return;
    }

    $order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
    if ( ! $order || ! $order->get_billing_email() ) {
      return;
    }

    $id = (int) $order->get_id();
    $payload = $this->build_order_ingest_payload( $order );
    $this->deleted_order_snapshots[ $id ] = $payload;
    set_transient( $this->prefix . 'deleted_order_' . $id, $payload, self::SNAPSHOT_TTL );
  }

  /**
   * @return array|null
   */
  protected function get_deleted_order_snapshot( $order_id ) {
    $order_id = absint( $order_id );
    if ( isset( $this->deleted_order_snapshots[ $order_id ] ) ) {
      return $this->deleted_order_snapshots[ $order_id ];
    }
    $cached = get_transient( $this->prefix . 'deleted_order_' . $order_id );
    return is_array( $cached ) ? $cached : null;
  }

  protected function clear_deleted_order_snapshot( $order_id ) {
    $order_id = absint( $order_id );
    unset( $this->deleted_order_snapshots[ $order_id ] );
    delete_transient( $this->prefix . 'deleted_order_' . $order_id );
  }

  /**
   * Shape an order using WooCommerce's guest rule:
   * customer_id == 0 → guest; customer_id > 0 → registered (order belongs to that WP user).
   * Billing email alone never creates a WC customer when someone is already logged in.
   */
  protected function build_order_ingest_payload( $order ) {
    $customer_id = absint( $order->get_customer_id() );
    if ( $customer_id > 0 ) {
      return $this->build_customer_payload( $customer_id, $order );
    }
    return $this->build_guest_order_payload( $order );
  }

  /**
   * Shopify-like order + product details for automations / mappings.
   */
  protected function build_order_details( $order ) {
    $created = $order->get_date_created();
    $line_items = array();

    foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
      $product = $item->get_product();
      $quantity = (int) $item->get_quantity();
      $line_total = (float) $item->get_total();
      $unit_price = $quantity > 0 ? ( $line_total / $quantity ) : (float) $item->get_total();
      $image_url = '';

      if ( $product ) {
        $image_id = $product->get_image_id();
        if ( $image_id ) {
          $image_url = wp_get_attachment_image_url( $image_id, 'full' );
        }
      }

      $name = $item->get_name();
      $line_items[] = array(
        'id' => (int) $item_id,
        'product_id' => (int) $item->get_product_id(),
        'variation_id' => (int) $item->get_variation_id(),
        'sku' => $product ? (string) $product->get_sku() : '',
        'name' => $name,
        'title' => $name,
        'quantity' => $quantity,
        'price' => wc_format_decimal( $unit_price, wc_get_price_decimals() ),
        'line_price' => wc_format_decimal( $line_total, wc_get_price_decimals() ),
        'image_url' => $image_url ? $image_url : '',
      );
    }

    return array(
      'id' => $order->get_id(),
      'number' => $order->get_order_number(),
      'status' => $order->get_status(),
      'currency' => $order->get_currency(),
      'presentment_currency' => $order->get_currency(),
      'total' => $order->get_total(),
      'total_price' => $order->get_total(),
      'subtotal' => $order->get_subtotal(),
      'total_tax' => $order->get_total_tax(),
      'total_shipping' => $order->get_shipping_total(),
      'discount_total' => $order->get_discount_total(),
      'payment_method' => $order->get_payment_method(),
      'payment_method_title' => $order->get_payment_method_title(),
      'created_at' => $created ? $created->date( 'c' ) : gmdate( 'c' ),
      'line_items' => $line_items,
      'shipping' => array(
        'first_name' => $order->get_shipping_first_name(),
        'last_name' => $order->get_shipping_last_name(),
        'company' => $order->get_shipping_company(),
        'address_1' => $order->get_shipping_address_1(),
        'address_2' => $order->get_shipping_address_2(),
        'city' => $order->get_shipping_city(),
        'state' => $order->get_shipping_state(),
        'country' => $order->get_shipping_country(),
        'postcode' => $order->get_shipping_postcode(),
      ),
    );
  }

  protected function posted_marketing_consent() {
    // Admin Add/Edit User form always posts this hidden field.
    if ( isset( $_POST['mailmunch_marketing_consent_present'] ) ) {
      return ! empty( $_POST['mailmunch_marketing_consent'] );
    }
    if ( ! empty( $_POST['mailmunch_marketing_consent'] ) || ! empty( $_POST['woocommerce_marketing'] ) ) {
      return true;
    }
    if ( isset( $_POST['mailmunch_marketing_consent'] ) || isset( $_POST['woocommerce_marketing'] ) ) {
      $value = $_POST['mailmunch_marketing_consent'] ?? $_POST['woocommerce_marketing'] ?? '';
      return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }
    return false;
  }

  protected function stored_marketing_consent( $user_id, $order = null ) {
    if ( get_user_meta( $user_id, self::CONSENT_META, true ) === 'yes' ) {
      return true;
    }
    if ( get_user_meta( $user_id, 'email_marketing_consent', true ) === 'yes' ) {
      return true;
    }
    if ( $order && $order->get_meta( self::CONSENT_META ) === 'yes' ) {
      return true;
    }
    return false;
  }

  protected function build_customer_payload( $customer_id, $order = null ) {
    $customer = new WC_Customer( $customer_id );
    $wp_user = get_userdata( $customer_id );

    // Always use "now" so MailMunch ingest does not drop the event as stale
    // when WooCommerce date_modified does not change (e.g. marketing checkbox only).
    $timestamp = gmdate( 'c' );

    if ( isset( $_POST['mailmunch_marketing_consent_present'] ) || isset( $_POST['mailmunch_marketing_consent'] ) || isset( $_POST['woocommerce_marketing'] ) ) {
      $marketing_consent = $this->posted_marketing_consent();
    } else {
      $marketing_consent = $this->stored_marketing_consent( $customer->get_id(), $order );
    }

    // Prefer freshly posted admin fields, then WC customer, then WP user.
    $first_name = '';
    $last_name = '';
    $email = '';
    if ( isset( $_POST['email'] ) ) {
      $email = sanitize_email( wp_unslash( $_POST['email'] ) );
    }
    if ( isset( $_POST['first_name'] ) ) {
      $first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
    }
    if ( isset( $_POST['last_name'] ) ) {
      $last_name = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
    }

    $email = strtolower( $email ?: ( $customer->get_email() ?: ( $wp_user ? $wp_user->user_email : '' ) ) );
    $first_name = $first_name !== '' ? $first_name : ( $customer->get_first_name() ?: ( $wp_user ? $wp_user->first_name : '' ) );
    $last_name = $last_name !== '' ? $last_name : ( $customer->get_last_name() ?: ( $wp_user ? $wp_user->last_name : '' ) );

    // Account email stays on customer.email; checkout/billing email is separate (can differ when logged in).
    $billing_email = '';
    if ( $order ) {
      $billing_email = strtolower( (string) $order->get_billing_email() );
    } elseif ( method_exists( $customer, 'get_billing_email' ) ) {
      $billing_email = strtolower( (string) $customer->get_billing_email() );
    }

    return array(
      'event_id' => '',
      'updated_at' => $timestamp,
      'customer' => array(
        'id' => $customer->get_id(),
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'is_guest' => false,
        'marketing_consent' => $marketing_consent,
        'billing' => array(
          'email' => $billing_email,
          'company' => $customer->get_billing_company(),
          'address_1' => $customer->get_billing_address_1(),
          'city' => $customer->get_billing_city(),
          'state' => $customer->get_billing_state(),
          'country' => $customer->get_billing_country(),
          'postcode' => $customer->get_billing_postcode(),
          'phone' => $customer->get_billing_phone(),
        ),
      ),
      'order' => $order ? $this->build_order_details( $order ) : null,
    );
  }

  protected function build_guest_order_payload( $order ) {
    $billing_email = strtolower( (string) $order->get_billing_email() );
    return array(
      'event_id' => '',
      'updated_at' => gmdate( 'c' ),
      'customer' => array(
        'id' => null,
        'email' => $billing_email,
        'first_name' => $order->get_billing_first_name(),
        'last_name' => $order->get_billing_last_name(),
        'is_guest' => true,
        'marketing_consent' => $this->posted_marketing_consent() || $order->get_meta( self::CONSENT_META ) === 'yes',
        'billing' => array(
          'email' => $billing_email,
          'company' => $order->get_billing_company(),
          'address_1' => $order->get_billing_address_1(),
          'city' => $order->get_billing_city(),
          'state' => $order->get_billing_state(),
          'country' => $order->get_billing_country(),
          'postcode' => $order->get_billing_postcode(),
          'phone' => $order->get_billing_phone(),
        ),
      ),
      'order' => $this->build_order_details( $order ),
    );
  }

  /**
   * The WooCommerce topics MailMunch subscribes to. order.deleted / order.restored are
   * required: cache_order_before_delete() and the order.deleted branches of
   * filter_webhook_should_deliver() / filter_webhook_payload() exist solely to serve them,
   * and without them a deleted order is never reported to MailMunch.
   */
  protected function webhook_topics() {
    return array(
      'customer.created' => 'MailMunch — Customer created',
      'customer.updated' => 'MailMunch — Customer updated',
      'customer.deleted' => 'MailMunch — Customer deleted',
      'order.created' => 'MailMunch — Order created',
      'order.updated' => 'MailMunch — Order updated',
      'order.deleted' => 'MailMunch — Order deleted',
      'order.restored' => 'MailMunch — Order restored',
    );
  }

  /**
   * Auto-create WooCommerce webhooks on Connect so admins don't add them manually.
   *
   * @return bool True when every topic was registered.
   */
  protected function ensure_webhooks( $sync_token ) {
    if ( ! class_exists( 'WC_Webhook' ) ) {
      return false;
    }

    $this->delete_webhooks();

    $delivery_url = MAILMUNCH_WEBHOOKS_URL;
    $topics = $this->webhook_topics();

    $ids = array();
    foreach ( $topics as $topic => $name ) {
      try {
        $webhook = new WC_Webhook();
        $webhook->set_name( $name );
        $webhook->set_user_id( get_current_user_id() );
        $webhook->set_topic( $topic );
        $webhook->set_delivery_url( $delivery_url );
        $webhook->set_secret( $sync_token );
        $webhook->set_status( 'active' );
        if ( method_exists( $webhook, 'set_api_version' ) ) {
          $webhook->set_api_version( 'wp_api_v2' );
        }
        $id = $webhook->save();
      } catch ( Exception $e ) {
        $id = 0;
      }

      if ( $id ) {
        $ids[] = (int) $id;
      }
    }

    // Persist whatever was created so a partial set can still be cleaned up on
    // disconnect, and report the shortfall so the caller can surface a Repair action
    // instead of silently leaving the store half-wired.
    update_option( $this->prefix . 'woo_webhook_ids', $ids );

    return count( $ids ) === count( $topics );
  }

  protected function delete_webhooks() {
    $ids = $this->get_webhook_ids();
    foreach ( $ids as $id ) {
      $webhook = wc_get_webhook( $id );
      if ( $webhook ) {
        $webhook->delete( true );
      }
    }
    delete_option( $this->prefix . 'woo_webhook_ids' );
  }

  protected function get_webhook_ids() {
    $ids = get_option( $this->prefix . 'woo_webhook_ids', array() );
    return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
  }

  protected function is_mailmunch_webhook( $webhook_id ) {
    return in_array( absint( $webhook_id ), $this->get_webhook_ids(), true );
  }

  /**
   * Deliver MailMunch-managed WooCommerce webhooks (customer role / orders with email only).
   */
  public function filter_webhook_should_deliver( $deliver, $webhook, $arg ) {
    if ( ! $webhook || ! $this->is_mailmunch_webhook( $webhook->get_id() ) ) {
      return $deliver;
    }

    // Skip echo while applying an inbound MailMunch → WP customer sync.
    if ( $this->syncing_from_mailmunch ) {
      return false;
    }

    $topic = $webhook->get_topic();
    if ( $topic === 'customer.deleted' ) {
      return (bool) ( $this->is_woocommerce_customer( $arg ) || $this->get_deleted_customer_snapshot( $arg ) );
    }
    if ( strpos( $topic, 'customer.' ) === 0 ) {
      return $this->is_woocommerce_customer( $arg );
    }
    if ( $topic === 'order.deleted' ) {
      $order = wc_get_order( $arg );
      if ( $order && $order->get_billing_email() ) {
        return true;
      }
      return (bool) $this->get_deleted_order_snapshot( $arg );
    }
    if ( strpos( $topic, 'order.' ) === 0 ) {
      $order = wc_get_order( $arg );
      return (bool) ( $order && $order->get_billing_email() );
    }
    return $deliver;
  }

  /**
   * Replace native WC webhook payload with MailMunch ingest payload.
   */
  public function filter_webhook_payload( $payload, $resource, $resource_id, $webhook_id ) {
    if ( ! $this->is_mailmunch_webhook( $webhook_id ) ) {
      return $payload;
    }

    $webhook = wc_get_webhook( $webhook_id );
    if ( ! $webhook ) {
      return $payload;
    }

    $topic = $webhook->get_topic();
    $mailmunch_payload = null;

    if ( $topic === 'customer.deleted' ) {
      // WC deleted payloads are {id:N} only — use pre-delete snapshot.
      $snapshot = $this->get_deleted_customer_snapshot( $resource_id );
      if ( $snapshot ) {
        $mailmunch_payload = $snapshot;
        $this->clear_deleted_customer_snapshot( $resource_id );
      } elseif ( $this->is_woocommerce_customer( $resource_id ) ) {
        $mailmunch_payload = $this->build_customer_payload( $resource_id );
      }
    } elseif ( $resource === 'customer' || strpos( $topic, 'customer.' ) === 0 ) {
      if ( $this->is_woocommerce_customer( $resource_id ) ) {
        $mailmunch_payload = $this->build_customer_payload( $resource_id );
      }
    } elseif ( $topic === 'order.deleted' ) {
      $order = wc_get_order( $resource_id );
      if ( $order ) {
        $mailmunch_payload = $this->build_order_ingest_payload( $order );
      } else {
        $mailmunch_payload = $this->get_deleted_order_snapshot( $resource_id );
      }
      $this->clear_deleted_order_snapshot( $resource_id );
    } elseif ( $resource === 'order' || strpos( $topic, 'order.' ) === 0 ) {
      // created / updated / restored — WC sends full resource; we reshape.
      $order = wc_get_order( $resource_id );
      if ( $order ) {
        $mailmunch_payload = $this->build_order_ingest_payload( $order );
      }
    }

    if ( ! is_array( $mailmunch_payload ) ) {
      // Never fall through to WooCommerce's native body: it would still be delivered to
      // MAILMUNCH_WEBHOOKS_URL with our Authorization and event-type headers, but in a
      // shape the Lambda does not understand and with no Idempotency-Key. Send a
      // well-formed "resource unavailable" event instead so ingest can log and drop it.
      return array(
        'event_id' => $this->event_id_for( $topic, $resource_id, 'unavailable' ),
        'updated_at' => gmdate( 'c' ),
        'topic' => $topic,
        'resource' => $resource,
        'resource_id' => (int) $resource_id,
        'unavailable' => true,
      );
    }

    $mailmunch_payload['event_id'] = $this->event_id_for( $topic, $resource_id, $mailmunch_payload );

    return $mailmunch_payload;
  }

  /**
   * Deterministic, content-addressed event id.
   *
   * A random uuid per payload build deduplicates nothing: WooCommerce binds
   * customer.created to both user_register and woocommerce_created_customer, so a single
   * signup delivers the same event twice, and with a fresh uuid each time MailMunch
   * processes it twice. Hashing the payload content instead means identical states
   * collapse to one Idempotency-Key while genuine changes still produce a new one.
   *
   * updated_at is excluded because it is deliberately "now" on every build.
   *
   * @param string       $topic       WooCommerce webhook topic.
   * @param int          $resource_id Customer or order id.
   * @param array|string $content     Payload to fingerprint, or a marker string.
   */
  protected function event_id_for( $topic, $resource_id, $content ) {
    if ( is_array( $content ) ) {
      unset( $content['event_id'], $content['updated_at'] );
      $content = wp_json_encode( $content );
    }

    $hash = md5( $topic . '|' . (int) $resource_id . '|' . (string) $content );

    // Keep the UUID shape the ingest side already receives — only the derivation changes.
    return sprintf(
      '%s-%s-4%s-%s%s-%s',
      substr( $hash, 0, 8 ),
      substr( $hash, 8, 4 ),
      substr( $hash, 13, 3 ),
      dechex( hexdec( substr( $hash, 16, 1 ) ) & 0x3 | 0x8 ),
      substr( $hash, 17, 3 ),
      substr( $hash, 20, 12 )
    );
  }

  /**
   * Add MailMunch auth + event-type headers for auto-created webhooks.
   */
  public function filter_webhook_http_args( $http_args, $arg, $webhook_id ) {
    if ( ! $this->is_mailmunch_webhook( $webhook_id ) ) {
      return $http_args;
    }

    $token = $this->get_sync_token();
    $webhook = wc_get_webhook( $webhook_id );
    $topic = $webhook ? $webhook->get_topic() : '';

    if ( empty( $http_args['headers'] ) || ! is_array( $http_args['headers'] ) ) {
      $http_args['headers'] = array();
    }

    $http_args['headers']['Authorization'] = 'Bearer ' . $token;
    $http_args['headers']['Content-Type'] = 'application/json';
    $http_args['headers']['X-Mailmunch-Event-Type'] = $topic;
    // Read the option directly — this runs on every webhook delivery, and constructing
    // Mailmunch_Api here would add a blocking remote call to each one.
    $http_args['headers']['X-Mailmunch-Site-Id'] = (string) $this->get_site_id();
    $http_args['timeout'] = 15;

    $body = json_decode( $http_args['body'] ?? '', true );
    if ( ! empty( $body['event_id'] ) ) {
      $http_args['headers']['Idempotency-Key'] = $body['event_id'];
    }

    return $http_args;
  }
}
