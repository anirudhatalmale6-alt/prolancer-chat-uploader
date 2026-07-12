<?php 
// Initialize variables
$result = [];
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if (is_user_logged_in() && $order_id) {
    $buyer_id = (int)get_user_meta(get_current_user_id(), 'buyer_id', true);
    global $wpdb;
    $table = 'prolancer_service_orders';
    
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} 
                WHERE `id` = %d 
                AND `buyer_id` = %d 
                AND `status` = 'complete' 
                ORDER BY timestamp DESC 
                LIMIT 1",
                $order_id,
                $buyer_id
            ),
            ARRAY_A
        );
    }
} ?>

<div class="white-padding mb-4">
  <h2 class="mb-0"><?php echo esc_html__('Completed Service Details', 'prolancer'); ?></h2>
</div>

<?php if (!empty($result)) { ?>
  <div class="table-responsive">
    <table class="prolancer-table">
      <thead>
        <tr>
          <th scope="col"><?php echo esc_html__('Title', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Delivery time', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Price', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Seller', 'prolancer'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $service_id = (int)$result['service_id'];
        $delivery_time_id = (int)$result['delivery_time_id'];
        $seller_id = (int)$result['seller_id'];
        $service = get_post($service_id);
        $seller_author_id = get_post_field('post_author', $seller_id);
        $seller_profile_attachment = get_post_meta($seller_id, 'seller_profile_attachment', true);
        $seller_image = $seller_profile_attachment ? wp_get_attachment_image(prolancer_get_image_id($seller_profile_attachment), [50, 50], false) : '';
        $delivery_term = get_term_by('id', $delivery_time_id, 'delivery-time');
        $service_price = $result['service_price'];
        ?>
        <tr>
          <td>
            
              <?php echo esc_html($service->post_title); ?>
            
          </td>
          <td><?php echo $delivery_term ? esc_html($delivery_term->name) : ''; ?></td>
          <td>
            <?php if (class_exists('WooCommerce') && $service_price) {
                echo wc_price($service_price);
            } ?>
          </td>
          <td>
            
              <?php if (!empty($seller_image)) {
                  echo wp_kses($seller_image, [
                      "img" => [
                          "src" => [],
                          "alt" => [],
                          "style" => [],
                          "class" => [],
                          "width" => [],
                          "height" => []
                      ]
                  ]);
              } else {
                  echo get_avatar($seller_author_id, 50);
              } ?>
            
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php 
  $table = 'prolancer_service_messages';
  $messages = [];

  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
      $messages = $wpdb->get_results(
          $wpdb->prepare(
              "SELECT * FROM {$table} WHERE `order_id` = %d",
              $order_id
          )
      ) ?: [];
  }

  if (!empty($messages)) { ?>
    <div class="chat-box white-padding mt-4">
      <?php
      $message_sender = (int)get_user_meta(get_current_user_id(), 'buyer_id', true);
      
      foreach ($messages as $message) {
          $sender_id = (int)$message->sender_id;
          $is_sender = ($sender_id == $message_sender);
          $sender_profile = $is_sender ? 'buyer' : 'seller';
          $profile_name = get_post_meta($sender_id, $sender_profile.'_profile_name', true);
          $profile_attachment = get_post_meta($sender_id, $sender_profile.'_profile_attachment', true);
          $sender_image = $profile_attachment ? wp_get_attachment_image(prolancer_get_image_id($profile_attachment), [50, 50], false) : '';
          $sender_author_id = get_post_field('post_author', $sender_id);
      ?>
      <div class="chat-list <?php echo $is_sender ? 'message_sender' : 'message_receiver'; ?>">
        <div class="row">
          <?php if ($is_sender) { ?>
            <div class="col-9">
              <p><?php echo pcu_message_text( $message->message ); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
            <div class="col-3 text-end">
              
                <?php echo esc_html($profile_name); ?>
                <?php if (!empty($sender_image)) {
                    echo wp_kses($sender_image, [
                        "img" => [
                            "src" => [],
                            "alt" => [],
                            "style" => [],
                            "class" => [],
                            "width" => [],
                            "height" => []
                        ]
                    ]);
                } else {
                    echo get_avatar($sender_author_id, 50);
                } ?>
              
            </div>
          <?php } else { ?>
            <div class="col-3">
              
                <?php if (!empty($sender_image)) {
                    echo wp_kses($sender_image, [
                        "img" => [
                            "src" => [],
                            "alt" => [],
                            "style" => [],
                            "class" => [],
                            "width" => [],
                            "height" => []
                        ]
                    ]);
                } else {
                    echo get_avatar($sender_author_id, 50);
                } ?>
                <?php echo esc_html($profile_name); ?>
              
            </div>
            <div class="col-9">
              <p><?php echo pcu_message_text( $message->message ); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
          <?php } ?>
        </div>
      </div>
      <?php } ?>
    </div>
  <?php } else { ?>
    <div class="white-padding mt-4 mb-4">
      <p><?php echo esc_html__('No Message found!', 'prolancer'); ?></p>
    </div>
  <?php } ?>
<?php } else { ?>
  <div class="white-padding">
    <p class="mb-0"><?php echo esc_html__('No result found!', 'prolancer'); ?></p>
  </div>
<?php } ?>