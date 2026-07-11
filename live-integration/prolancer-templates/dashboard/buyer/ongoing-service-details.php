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
                AND `status` = 'ongoing' 
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
  <h2 class="mb-0"><?php echo esc_html__('Ongoing Service Details', 'prolancer'); ?></h2>
</div>

<?php if (!empty($result)) { ?>
  <div class="white-padding">
    <div class="table-responsive">
      <table class="prolancer-table">
        <thead>
          <tr>
            <th scope="col"><?php echo esc_html__('Title', 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__('Delivery time', 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__('Price', 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__('Seller', 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__('Action', 'prolancer'); ?></th>
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
              <a href="<?php echo esc_url(get_the_permalink($service_id)); ?>" target="_blank">
                <?php echo esc_html($service->post_title); ?>
              </a>
            </td>
            <td><?php echo $delivery_term ? esc_html($delivery_term->name) : ''; ?></td>
            <td>
              <?php if (class_exists('WooCommerce') && $service_price) {
                echo wc_price($service_price);
              } ?>
            </td>
            <td>
              <a href="<?php echo esc_url(get_the_permalink($seller_id)); ?>" target="_blank">
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
              </a>
            </td>
            <td>
              <ul class="list-inline mb-0">
                <li class="list-inline-item">
                  <button type="button" class="small-btn" data-bs-toggle="modal" data-bs-target="#complete<?php echo esc_attr($seller_id); ?>">
                    <?php echo esc_html__('Complete', 'prolancer'); ?>
                  </button>
                  <a href="#" class="prolancer-btn small-btn text-white bg-danger" id="service-order-cancel" 
                     data-nonce="<?php echo esc_attr(wp_create_nonce('service_order_cancel_nonce')); ?>" 
                     data-seller-id="<?php echo esc_attr($seller_id); ?>" 
                     data-order-id="<?php echo esc_attr($order_id); ?>">
                    <?php echo esc_html__('Cancel', 'prolancer'); ?>
                  </a>
                  
                  <!-- Modal -->
                  <div class="modal fade" id="complete<?php echo esc_attr($seller_id); ?>" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                      <form id="review-form" enctype="multipart/form-data">
                        <div class="modal-content">
                          <div class="modal-header">
                            <h5 class="modal-title" id="staticBackdropLabel"><?php echo esc_html__('Write a review', 'prolancer'); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <div class="rating-stars mb-2"></div>
                            <input class="d-none" type="text" id="rating-stars" name="rating-stars" value="" required>
                            <h6 class="mb-3"><?php echo esc_html__('Your Feedback', 'prolancer'); ?></h6>
                            <textarea name="review" placeholder="<?php echo esc_attr__('Review...', 'prolancer'); ?>" required></textarea>
                            <input type="hidden" name="service-id" value="<?php echo esc_attr($service_id); ?>">
                            <input type="hidden" name="buyer-id" value="<?php echo esc_attr($buyer_id); ?>">
                            <button type="submit" id="service-order-complete" 
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('service_order_complete_nonce')); ?>" 
                                    data-seller-id="<?php echo esc_attr($seller_id); ?>" 
                                    data-order-id="<?php echo esc_attr($order_id); ?>">
                              <?php echo esc_html__('Submit', 'prolancer'); ?>
                            </button>
                          </div>
                        </div>
                      </form>
                    </div>
                  </div>
                </li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
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
    <div class="chat-box white-padding mt-4 mb-4">
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
              <p><?php echo esc_html($message->message); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
            <div class="col-3 text-end">
              <a href="<?php echo esc_url(get_the_permalink($sender_id)); ?>" class="d-block" target="_blank">
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
              </a>
            </div>
          <?php } else { ?>
            <div class="col-3">
              <a href="<?php echo esc_url(get_the_permalink($sender_id)); ?>" target="_blank">
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
              </a>
            </div>
            <div class="col-9">
              <p><?php echo esc_html($message->message); ?></p>
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
  
  <form id="send-service-message-form">
    <textarea name="message" cols="30" rows="10" placeholder="<?php echo esc_attr__('Type your message here...', 'prolancer'); ?>"></textarea>
    <input id="upload-message-attachments" type="file" class="form-control mt-3 mb-4" 
           accept="image/*,.pdf,.doc,.docx,.ppt,.pptx" 
           data-order-id="<?php echo esc_attr($order_id); ?>" 
           data-post-id="<?php echo esc_attr($service->ID); ?>" 
           data-nonce="<?php echo esc_attr(wp_create_nonce('upload_file_nonce')); ?>">
    <?php pcu_attach_button(); ?>
    <input type="hidden" name="attachment_id" class="attachment-id" value="">
    <a href="#" class="send-service-message prolancer-btn" 
       data-nonce="<?php echo esc_attr(wp_create_nonce('send_service_message_nonce')); ?>" 
       data-order-id="<?php echo esc_attr($order_id); ?>" 
       data-sender-id="<?php echo esc_attr(get_user_meta(get_current_user_id(), 'buyer_id', true)); ?>" 
       data-receiver-id="<?php echo esc_attr($seller_id); ?>">
      <?php echo esc_html__('Send message', 'prolancer'); ?>
    </a>
  </form>
<?php } else { ?>
  <div class="white-padding">
    <p class="mb-0"><?php echo esc_html__('No result found!', 'prolancer'); ?></p>
  </div>
<?php } ?>